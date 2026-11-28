"""Destination-driven media selection and installed dependency reuse."""
import importlib.util
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import Mock, patch


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


launcher = load('icct_auto_launcher', Path(__file__).with_name('launcher.py'))
setup = load('icct_auto_setup', Path(__file__).with_name('setup.py'))


class AutoInstallation(unittest.TestCase):
    def test_package_lists_reject_binary_paths_and_options(self):
        for invalid in ('/usr/sbin/snmptrapd', '../receiver', '--assumeyes', 'pkg name'):
            with patch.dict(setup.MANIFEST['packages'], {'rhel': [invalid]}):
                with self.assertRaisesRegex(ValueError, 'Invalid package name'):
                    setup.component_packages({'family': 'rhel'})
        with patch.dict(setup.MANIFEST['packages'], {'debian': ['libstdc++6', 'php-cli', 'libc6:amd64']}):
            self.assertEqual(len(setup.component_packages({'family': 'debian'})), 3)
        with patch.dict(setup.MANIFEST['packages'], {'debian': ['/sbin/snmptrapd']}):
            with self.assertRaisesRegex(ValueError, 'Invalid package name'):
                setup.check_python_runtime()

    def test_both_entry_points_use_the_same_installer(self):
        root = Path(__file__).resolve().parents[3]
        for entry in (root / 'install.py', root / 'icct_nms/install.py'):
            result = subprocess.run([setup.sys.executable, str(entry), '--help'], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn('--cacti-root', result.stdout)

    def test_package_command_includes_existing_media_unless_source_only_is_explicit(self):
        package = load('icct_cli_package', Path(__file__).with_name('package.py'))
        result = dict(files={}, target=None, complete_media=False, package_components=[], native_components=[])
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            media = root / 'installation/bundles/media'
            (media / 'packages').mkdir(parents=True)
            (media / 'packages/bundle.json').write_text('{}')
            for flags, expected in [([], str(media)), (['--source-only'], None)]:
                with patch.object(package, 'ROOT', root), patch.object(setup.sys, 'argv',
                        ['package.py', '--output', str(root.parent / 'fixture.zip'), *flags]), patch.object(
                        package, 'build_archive', return_value=result) as build:
                    package.main()
                    self.assertEqual(build.call_args.kwargs['media'], expected)

    def test_partial_bundle_accepts_absence_but_rejects_corruption(self):
        package = load('icct_partial_package', Path(__file__).with_name('package.py'))
        root = Path(__file__).resolve().parents[2]
        target = self.target()
        with tempfile.TemporaryDirectory() as temp:
            media = Path(temp) / 'media'
            directory = media / 'packages'
            directory.mkdir(parents=True)
            payload = directory / 'fixture.rpm'
            payload.write_bytes(b'fixture never installed')
            (directory / 'bundle.json').write_text(json.dumps(dict(
                format=1, target=target, component=None, packages=setup.component_packages(target),
                files={payload.name: hashlib.sha256(payload.read_bytes()).hexdigest()})))
            result = package.build_archive(root, Path(temp) / 'partial.zip', media=media, allow_partial=True)
            self.assertTrue(result['partial_media'])
            self.assertFalse(result['complete_media'])
            self.assertEqual(result['package_components'], [])
            payload.write_bytes(b'corrupt')
            with self.assertRaisesRegex(ValueError, 'changed offline package'):
                package.build_archive(root, Path(temp) / 'bad.zip', media=media, allow_partial=True)

    def test_existing_os_detector_reads_distribution_family_release_and_cpu(self):
        for os_release, family, identifier, version, arch in [
            ('ID=rocky\nID_LIKE="rhel centos fedora"\nVERSION_ID="9.4"', 'rhel', 'rocky', '9.4', 'x86_64'),
            ('ID=ubuntu\nID_LIKE=debian\nVERSION_ID="24.04"', 'debian', 'ubuntu', '24.04', 'aarch64'),
        ]:
            with patch.object(setup.Path, 'read_text', return_value=os_release), patch.object(
                    setup.platform, 'machine', return_value=arch):
                self.assertEqual(setup.os_info(), self.target(family, identifier, version, arch))
        with patch.object(setup.Path, 'read_text', return_value='ID=unrecognized\nVERSION_ID=1'):
            with self.assertRaisesRegex(ValueError, 'Unsupported OS'):
                setup.os_info()

    def target(self, family='rhel', identifier='rhel', version='9.8', arch='aarch64'):
        return dict(family=family, id=identifier, version=version, arch=arch)

    def media(self, root, name, target):
        path = root / name / 'packages'
        path.mkdir(parents=True)
        (path / 'bundle.json').write_text(json.dumps({'target': target}))
        return path.parent

    def test_selects_exact_os_release_and_arch_without_folder_name_rules(self):
        targets = [self.target(), self.target(arch='x86_64'),
                   self.target('debian', 'ubuntu', '24.04', 'aarch64'),
                   self.target('debian', 'debian', '12', 'x86_64')]
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            for number, target in enumerate(targets):
                expected = self.media(root, str(number), target)
                self.assertEqual(launcher.matching_media(root, target), expected)
            self.assertIsNone(launcher.matching_media(root, self.target(version='9.4')))
            self.assertIsNone(launcher.matching_media(root, self.target(identifier='rocky')))

    def test_ambiguity_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            for name in ('a', 'b'):
                self.media(root, name, self.target())
            with self.assertRaisesRegex(ValueError, 'Multiple matching'):
                launcher.matching_media(root, self.target())

    def test_unmatched_target_uses_native_repositories_only_when_allowed(self):
        fake = Mock()
        fake.os_info.return_value = self.target(arch='x86_64')
        with tempfile.TemporaryDirectory() as temp:
            self.media(Path(temp), 'arm', self.target())
            with self.assertRaisesRegex(ValueError, 'No matching offline'):
                launcher.installation_source(fake, temp, ['--plan'])
            self.assertEqual(launcher.installation_source(fake, temp, ['--online']),
                             (None, ['--online']))
            with self.assertRaisesRegex(ValueError, 'No matching offline'):
                launcher.installation_source(fake, temp, ['--plan'], offline=True)
            with self.assertRaisesRegex(ValueError, 'cannot be combined'):
                launcher.installation_source(fake, temp, ['--online'], offline=True)

    def test_matching_target_stays_offline(self):
        fake = Mock()
        fake.os_info.return_value = self.target()
        with tempfile.TemporaryDirectory() as temp:
            expected = self.media(Path(temp), 'arbitrary', self.target())
            self.assertEqual(launcher.installation_source(fake, temp, ['--plan']),
                             (expected, ['--plan']))
            fake.os_info.reset_mock()
            self.assertEqual(launcher.installation_source(fake, temp, ['--application-only']),
                             (None, ['--application-only']))
            fake.os_info.assert_not_called()

    def test_queries_package_database_and_rejects_unconfigured_debs(self):
        for family, outputs in [('rhel', [(0, 'installed'), (1, '')]),
                                ('debian', [(0, 'install ok installed'), (0, 'deinstall ok config-files')])]:
            with patch.object(setup.shutil, 'which', return_value='/package-query'), patch.object(
                    setup.subprocess, 'run', side_effect=[subprocess.CompletedProcess([], code, text)
                                                        for code, text in outputs]):
                self.assertEqual(setup.missing_packages({'family': family}, ['present', 'missing']), ['missing'])

    def test_repository_commands_only_install_missing_packages(self):
        for family, executable in [('rhel', 'dnf'), ('debian', 'apt-get')]:
            with patch.object(setup, 'missing_packages', return_value=['missing']), patch.object(setup, 'run') as run:
                setup.install_packages({'family': family}, None, online=True)
                command = run.call_args.args[0]
                self.assertEqual(command[0], executable)
                self.assertEqual(command[-1], 'missing')
            with patch.object(setup, 'missing_packages', return_value=[]), patch.object(setup, 'run') as run:
                setup.install_packages({'family': family}, None, online=True)
                run.assert_not_called()

    def test_repository_profiles_exclude_foreign_native_builds(self):
        for family in ('rhel', 'debian'):
            names = setup.repository_components({'family': family})
            self.assertIn('websocket', names)
            self.assertIn('transfer', names)
            self.assertNotIn('pathchar', names)
            self.assertNotIn('guacamole-strict', names)
            self.assertTrue(all(setup.MANIFEST['optional_components'][name]['packages'][family]
                                for name in names))

    def test_complete_debian_media_omits_unsupported_rpm_components(self):
        root = Path(__file__).resolve().parents[2]
        package = load('icct_auto_package', Path(__file__).with_name('package.py'))
        native = load('icct_auto_native', Path(__file__).with_name('native.py'))
        target = self.target('debian', 'debian', '12', 'x86_64')
        with tempfile.TemporaryDirectory() as temp:
            media = Path(temp) / 'media'
            names = setup.repository_components(target)
            for component in [None] + names:
                directory = media / ('components/' + component if component else 'packages')
                directory.mkdir(parents=True)
                payload = directory / 'fixture.deb'
                payload.write_bytes(b'test fixture; never installed')
                (directory / 'bundle.json').write_text(json.dumps(dict(
                    format=1, target=target, component=component,
                    packages=setup.component_packages(target, component),
                    files={payload.name: hashlib.sha256(payload.read_bytes()).hexdigest()})))
            for name, profile in native.PROFILES.items():
                directory = media / 'native' / name
                executable = 'sbin/guacd' if name == 'guacamole-strict' else 'sbin/pchar'
                payload = directory / 'payload' / executable
                payload.parent.mkdir(parents=True)
                payload.write_bytes(b'test native fixture; never executed')
                (directory / 'native.json').write_text(json.dumps(dict(
                    format=1, target=target, component=name, version=profile['version'],
                    source_sha256=profile['sha256'], patch_sha256=native.digest(root / profile['patch']),
                    files={executable: {'mode': 0o755, 'sha256': native.digest(payload)}})))
            result = package.build_archive(root, Path(temp) / 'debian.zip', media=media)
            self.assertEqual(set(result['package_components']), set(names))
            self.assertTrue(result['complete_media'])
            self.assertEqual(result['target'], target)


if __name__ == '__main__':
    unittest.main()
