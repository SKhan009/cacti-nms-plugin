import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("icct_setup", Path(__file__).with_name("setup.py"))
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)


class BundleSafety(unittest.TestCase):
    def fixture(self, directory):
        target = dict(family="rhel", id="rhel", version="9.4", arch="aarch64")
        package = Path(directory) / "test.rpm"
        package.write_bytes(b"package test fixture")
        data = dict(
            format=1,
            target=target,
            packages=setup.MANIFEST["packages"]["rhel"],
            files={package.name: hashlib.sha256(package.read_bytes()).hexdigest()},
        )
        (Path(directory) / "bundle.json").write_text(json.dumps(data))
        return target, data

    def test_valid_and_corrupt_bundle(self):
        with tempfile.TemporaryDirectory() as directory:
            target, data = self.fixture(directory)
            self.assertEqual(len(setup.verify_bundle(directory, target)), 1)
            (Path(directory) / "test.rpm").write_bytes(b"changed")
            with self.assertRaisesRegex(ValueError, "changed"):
                setup.verify_bundle(directory, target)

    def test_wrong_arch_and_traversal_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            target, data = self.fixture(directory)
            with self.assertRaisesRegex(ValueError, "architecture"):
                setup.verify_bundle(directory, dict(target, arch="x86_64"))
            data["files"] = {"../escape.rpm": "0" * 64}
            (Path(directory) / "bundle.json").write_text(json.dumps(data))
            with self.assertRaisesRegex(ValueError, "path"):
                setup.verify_bundle(directory, target)

    def test_offline_package_commands_cannot_download(self):
        for family, executable, flag in [
            ("rhel", "dnf", "--disablerepo=*"),
            ("debian", "apt-get", "--no-remove"),
        ]:
            with (
                patch.object(setup, "verify_bundle", return_value=[Path("/bundle/test.rpm")]),
                patch.object(setup, "run") as run,
            ):
                setup.install_packages(dict(family=family), "/bundle")
                command = run.call_args[0][0]
                self.assertEqual(command[0], executable)
                self.assertIn(flag, command)
                if family == "debian":
                    self.assertTrue(any(str(arg).startswith("Dir::Etc::sourceparts=/tmp/icct-offline-apt-") or "icct-offline-apt-" in str(arg) for arg in command if str(arg).startswith("Dir::Etc::sourceparts=")))
                    self.assertTrue(any(str(arg).startswith("Dir::State::lists=") for arg in command))
                    self.assertIn("--no-remove", command)

    def test_install_never_implicitly_uses_network(self):
        with patch.object(setup, "run") as run:
            with self.assertRaisesRegex(ValueError, "Offline install requires"):
                setup.install_packages(dict(family="rhel"), None)
            run.assert_not_called()

    def test_bundle_collects_already_installed_dependencies(self):
        with tempfile.TemporaryDirectory() as parent:
            directory = Path(parent) / "out"
            target = dict(family="rhel", id="rhel", version="9.4", arch="aarch64")

            def fake_run(command):
                self.assertIn("--alldeps", command)
                self.assertIn("--resolve", command)
                (directory / "resolved.rpm").write_bytes(b"complete package fixture")

            with patch.object(setup, "run", side_effect=fake_run):
                setup.download_bundle(directory, target)
            self.assertEqual(len(setup.verify_bundle(directory, target)), 1)
            with self.assertRaisesRegex(ValueError, "empty"):
                setup.download_bundle(directory, target)

    def test_bundle_build_does_not_require_cacti_installation(self):
        target = dict(family='rhel', id='rhel', version='9.8', arch='aarch64')
        with patch.object(setup.sys, 'argv', ['setup.py','bundle','--bundle','/tmp/test-bundle']), patch.object(setup.os, 'geteuid', return_value=0), patch.object(setup, 'os_info', return_value=target), patch.object(setup, 'validate_root', side_effect=AssertionError('Cacti not required for bundle build')), patch.object(setup, 'download_bundle') as download:
            setup.main()
            download.assert_called_once_with('/tmp/test-bundle', target)

    def test_optional_component_isolated_and_bundle_profile_checked(self):
        with patch.object(setup, 'run') as run:
            setup.install_packages(dict(family='rhel'), None, online=True, component='websocket')
            self.assertEqual(run.call_args[0][0], ['dnf','-y','install','python3-websockets'])
        with self.assertRaisesRegex(ValueError, 'approved native build'):
            setup.component_packages(dict(family='debian'), 'guacamole')
        with tempfile.TemporaryDirectory() as directory:
            target, data = self.fixture(directory)
            with self.assertRaisesRegex(ValueError, 'package list'):
                setup.verify_bundle(directory, target, 'redis')

    def test_component_bundle_is_bound_to_profile_and_checked_before_mutation(self):
        with tempfile.TemporaryDirectory() as directory:
            target,data = self.fixture(directory)
            data['component'] = 'redis'
            data['packages'] = setup.component_packages(target,'redis')
            (Path(directory)/'bundle.json').write_text(json.dumps(data))
            self.assertEqual(setup.verify_component_bundle(directory,target),'redis')
            (Path(directory)/'test.rpm').write_bytes(b'changed')
            with patch.object(setup.sys,'argv',['setup.py','install','--online','--component-bundle',directory]), patch.object(setup,'os_info',return_value=target), patch.object(setup,'validate_root',return_value=Path('/cacti')), patch.object(setup,'run') as run:
                with self.assertRaisesRegex(ValueError,'changed'):
                    setup.main()
                run.assert_not_called()

    def test_complete_offline_setup_rejects_missing_profiles_before_packages(self):
        target=dict(family='rhel',id='rhel',version='9.8',arch='aarch64')
        with patch.object(setup.sys,'argv',['setup.py','install','--all-components','--bundle','/base']), patch.object(setup,'os_info',return_value=target), patch.object(setup,'validate_root',return_value=Path('/cacti')), patch.object(setup,'run') as run:
            with self.assertRaisesRegex(ValueError,'requires bundles'):
                setup.main()
            run.assert_not_called()

    def test_distribution_package_names_are_resolved_without_changing_debian(self):
        original=list(setup.MANIFEST['packages']['debian'])
        ubuntu=dict(family='debian',id='ubuntu',version='24.04',arch='aarch64')
        packages=setup.component_packages(ubuntu)
        self.assertIn('libjpeg-turbo8',packages)
        self.assertIn('libssh2-1t64',packages)
        self.assertNotIn('libjpeg62-turbo',packages)
        self.assertNotIn('libssh2-1',packages)
        self.assertIn('libssh2-1',setup.component_packages(dict(ubuntu,version='22.04')))
        self.assertEqual(setup.component_packages(dict(ubuntu,id='debian',version='12')),original)
        self.assertIn('libssh2-1t64',setup.component_packages(dict(ubuntu,id='debian',version='13')))
        self.assertEqual(setup.MANIFEST['packages']['debian'],original)

    def test_apt_ntp_profile_does_not_install_a_competing_clock_daemon(self):
        for distro in ['debian','ubuntu']:
            self.assertEqual(setup.component_packages(dict(family='debian',id=distro,version='24.04'),'ntp'),['ntpsec-ntpdig'])
        self.assertEqual(setup.component_packages(dict(family='rhel',id='rhel',version='9.8'),'ntp'),['chrony'])

    def test_selinux_write_labels_are_scoped_and_enforcement_is_preserved(self):
        path=Path('/cacti/resource/snmp_queries/icct_nms')
        def which(name): return '/usr/sbin/'+name
        def output(command,**kwargs):
            if command[0].endswith('getenforce'): return 'Enforcing\n'
            if command[0].endswith('matchpathcon'): return 'system_u:object_r:httpd_sys_content_t:s0\n'
            return ''
        with patch.object(setup.shutil,'which',side_effect=which),patch.object(setup.subprocess,'check_output',side_effect=output),patch.object(setup,'run') as run:
            setup.configure_selinux_writable([path])
            self.assertEqual(run.call_args_list[0].args[0],['/usr/sbin/semanage','fcontext','-a','-t','httpd_sys_rw_content_t','/cacti/resource/snmp_queries/icct_nms(/.*)?'])
            self.assertEqual(run.call_args_list[1].args[0],['/usr/sbin/restorecon','-RF',path])
            self.assertFalse(any('setenforce' in str(call) for call in run.call_args_list))

    def test_old_python_is_rejected_before_setup(self):
        with self.assertRaisesRegex(ValueError,'Python 3.9.0'):
            setup.check_python_runtime((3,8,20))
        setup.check_python_runtime((3,9,0))
        setup.check_python_runtime((3,12,3))

    def test_online_requires_explicit_selection(self):
        with patch.object(setup, 'run') as run:
            setup.install_packages(dict(family='rhel'), None, online=True)
            self.assertEqual(run.call_args[0][0][:3], ['dnf','-y','install'])
        with self.assertRaisesRegex(ValueError, 'combined'):
            setup.install_packages(dict(family='rhel'), '/bundle', online=True)


if __name__ == "__main__":
    unittest.main()
