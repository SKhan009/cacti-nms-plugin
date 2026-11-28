"""Offline dependency transactions and complete-release acceptance gates."""
from pathlib import Path
import struct
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import MagicMock, patch

import build_release
import deploy
import offline_rpm
import setup


def rpm(name, arch='x86_64'):
    return SimpleNamespace(name=name, arch=arch)


class OfflineTransactions(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.payload = Path(self.directory.name) / 'test.rpm'
        self.payload.write_bytes(b'unit test only; never installed')
        self.base = MagicMock()
        self.base.__enter__.return_value = self.base
        self.base.sack.query.return_value.installed.return_value = [rpm('php-cli'), rpm('rrdtool')]
        self.base.add_remote_rpms.return_value = [rpm('php-cli'), rpm('iperf3'), rpm('libnew')]
        self.base.transaction.install_set = [rpm('iperf3'), rpm('libnew')]
        self.base.transaction.remove_set = []
        self.dnf = SimpleNamespace(Base=MagicMock(return_value=self.base))
        self.target = build_release.TARGET
        patcher = patch.dict(sys.modules, {'dnf': self.dnf})
        patcher.start()
        self.addCleanup(patcher.stop)

    def install(self, names=('php-cli', 'iperf3'), **kwargs):
        return offline_rpm.install_missing([self.payload], names, self.target, **kwargs)

    def test_only_missing_requirements_are_selected_and_no_repositories_are_loaded(self):
        self.assertEqual(self.install(), ['iperf3', 'libnew'])
        self.base.fill_sack.assert_called_once_with(load_system_repo=True, load_available_repos=False)
        self.base.read_all_repos.assert_not_called()
        self.base.download_packages.assert_not_called()
        self.base.package_install.assert_called_once_with(self.base.add_remote_rpms.return_value[1])
        self.base.resolve.assert_called_once_with(allow_erasing=False)
        self.base.do_transaction.assert_called_once()
        self.assertFalse(self.base.conf.plugins)
        self.assertFalse(self.base.conf.install_weak_deps)

    def test_repeated_install_reuses_all_installed_packages(self):
        self.base.sack.query.return_value.installed.return_value.append(rpm('iperf3'))
        self.assertEqual(self.install(), [])
        self.base.add_remote_rpms.assert_not_called()
        self.base.do_transaction.assert_not_called()

    def test_conflicting_dependency_cannot_replace_cacti_or_system_packages(self):
        for removed, added in [([rpm('php-cli')], [rpm('iperf3'), rpm('php-cli')]),
                               ([rpm('glibc')], [rpm('iperf3')]),
                               ([], [rpm('iperf3'), rpm('rrdtool')])]:
            self.base.transaction.remove_set = removed
            self.base.transaction.install_set = added
            with self.assertRaisesRegex(ValueError, 'replace installed packages'):
                self.install()
        self.base.do_transaction.assert_not_called()

    def test_missing_foreign_or_duplicate_requested_rpm_is_rejected(self):
        for candidates in [[], [rpm('iperf3', 'aarch64')], [rpm('iperf3'), rpm('iperf3')]]:
            self.base.add_remote_rpms.return_value = candidates
            with self.assertRaisesRegex(ValueError, 'compatible bundled RPM'):
                self.install()
        self.base.do_transaction.assert_not_called()

    def test_dependency_resolution_failure_and_omission_never_install(self):
        self.base.resolve.side_effect = ValueError('missing library')
        with self.assertRaisesRegex(ValueError, 'missing library'):
            self.install()
        self.base.resolve.side_effect = None
        self.base.transaction.install_set = []
        with self.assertRaisesRegex(ValueError, 'omitted'):
            self.install()
        self.base.do_transaction.assert_not_called()

    def test_preview_resolves_but_does_not_install(self):
        self.assertEqual(self.install(preview=True), ['iperf3', 'libnew'])
        self.base.do_transaction.assert_not_called()

    def test_setup_uses_verified_media_and_missing_package_transaction(self):
        # The actual setup entry point loads its sibling module from disk.
        with patch.object(setup, 'verify_bundle', return_value=[self.payload]) as verify, patch.object(
                setup, 'component_packages', return_value=['php-cli', 'iperf3']), patch.object(setup, 'run') as run:
            setup.install_packages(self.target, '/verified')
            verify.assert_called_once_with('/verified', self.target)
            self.base.do_transaction.assert_called_once()
            run.assert_not_called()


class CompleteReleaseAudit(unittest.TestCase):
    def test_wrong_build_host_stops_before_downloading_or_packaging(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(build_release.setup, 'os_info',
                return_value=dict(build_release.TARGET, arch='aarch64')), patch.object(
                build_release, 'prepare_media') as prepare:
            with self.assertRaisesRegex(ValueError, 'Build host must match'):
                build_release.build(Path(temp) / 'media', Path(temp) / 'release.zip', build_release.TARGET)
            prepare.assert_not_called()
            self.assertEqual(list(Path(temp).iterdir()), [])

    def test_native_machine_is_checked_from_elf_bytes(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'runtime'
            for machine, valid in [(62, True), (183, False)]:
                header = bytearray(20)
                header[:6] = b'\x7fELF\x02\x01'
                header[18:20] = struct.pack('<H', machine)
                path.write_bytes(header)
                if valid:
                    build_release.verify_elf(path, 'x86_64')
                else:
                    with self.assertRaisesRegex(ValueError, 'compatible 64-bit ELF'):
                        build_release.verify_elf(path, 'x86_64')
            path.write_bytes(b'not a native runtime')
            with self.assertRaises(ValueError):
                build_release.verify_elf(path, 'x86_64')

    def test_closure_audit_ignores_installed_build_host_packages(self):
        with tempfile.TemporaryDirectory() as temp:
            media = Path(temp)
            payload = media / 'test.rpm'
            payload.write_bytes(b'unit test only')
            base = MagicMock()
            base.__enter__.return_value = base
            base.add_remote_rpms.return_value = [rpm('iperf3')]
            base.transaction.install_set = [rpm('iperf3')]
            with (patch.dict(sys.modules, {'dnf': SimpleNamespace(Base=lambda: base)}),
                  patch.object(build_release, 'profiles', return_value=[None]),
                  patch.object(build_release.setup, 'verify_bundle', return_value=[payload]),
                  patch.object(build_release.setup, 'component_packages', return_value=['iperf3']),
                  patch.object(build_release, 'rpm_identity', return_value=('iperf3', 'x86_64', 'iperf3-1.x86_64')),
                  patch.object(build_release.native, 'verify_bundle', side_effect=[
                      {'component': 'pathchar', 'files': {'sbin/pchar': {}}},
                      {'component': 'guacamole-strict', 'files': {'sbin/guacd': {}, 'lib/libguac-client-ssh.so': {}}}]),
                  patch.object(build_release, 'verify_elf')):
                report = build_release.audit(media, build_release.TARGET)
            base.fill_sack.assert_called_once_with(load_system_repo=False, load_available_repos=False)
            base.do_transaction.assert_not_called()
            self.assertFalse(report['runtime_tested'])
            self.assertEqual(report['unique_rpms'], 1)

    def test_missing_requested_package_and_foreign_rpm_fail_audit(self):
        with tempfile.TemporaryDirectory() as temp:
            payload = Path(temp) / 'test.rpm'
            payload.write_bytes(b'unit test only')
            with (patch.dict(sys.modules, {'dnf': SimpleNamespace(Base=MagicMock())}),
                  patch.object(build_release, 'profiles', return_value=[None]),
                  patch.object(build_release.setup, 'verify_bundle', return_value=[payload]),
                  patch.object(build_release.setup, 'component_packages', return_value=['iperf3'])):
                for identity, message in [(('wrong', 'x86_64', 'wrong-1'), 'lacks requested'),
                                          (('iperf3', 'aarch64', 'iperf3-1'), 'architecture')]:
                    with patch.object(build_release, 'rpm_identity', return_value=identity):
                        with self.assertRaisesRegex(ValueError, message):
                            build_release.audit(Path(temp), build_release.TARGET)

    def test_standard_cacti_root_detection_rejects_missing_or_ambiguous_installations(self):
        for matches in [[], ['/usr/share/cacti', '/var/www/html/cacti']]:
            with patch.object(Path, 'is_file', lambda p: str(p.parent.parent) in matches):
                with self.assertRaisesRegex(ValueError, 'no unique'):
                    setup.discover_cacti_root()
        with patch.object(Path, 'is_file', lambda p: str(p.parent.parent) == '/usr/share/cacti'):
            self.assertEqual(setup.discover_cacti_root(), Path('/usr/share/cacti'))

    def test_release_mode_errors_stop_before_destination_access(self):
        for flags, manifest, error in [
            (['--offline'], {'target': None}, 'source-only archive'),
            ([], {'target': build_release.TARGET, 'partial_media': True}, 'incomplete media'),
            (['--no-packages'], {'target': build_release.TARGET}, 'cannot use --no-packages'),
        ]:
            with (patch.object(sys, 'argv', ['install.py', *flags]),
                  patch.object(deploy, 'verify_release', return_value=manifest),
                  patch.object(deploy, 'validate_destination') as destination):
                with self.assertRaisesRegex(ValueError, error):
                    deploy.main()
                destination.assert_not_called()


if __name__ == '__main__':
    unittest.main()
