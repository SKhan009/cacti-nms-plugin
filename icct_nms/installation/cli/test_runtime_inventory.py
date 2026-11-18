import hashlib
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import package_runner as runner


class RuntimeInventory(unittest.TestCase):
    def test_discovered_pathchar_does_not_claim_rpm_ownership(self):
        target = dict(family='rhel', id='rhel', version='9.8', arch='aarch64')
        with patch.object(runner.shutil, 'which', side_effect=lambda name: '/custom/tools/pchar' if name == 'pchar' else None):
            self.assertEqual(runner.external_installations(target, {}), {'icct-nms-pathchar': 'Installed outside RPM'})
            self.assertEqual(runner.external_installations(target, {'icct-nms-pathchar': 'installed'}), {})

    def test_missing_service_never_selects_an_alternate_guacamole(self):
        target = dict(family='rhel', id='rhel', version='9.8', arch='aarch64')
        with patch.object(runner.shutil, 'which', return_value='/custom/systemctl'), patch.object(
            runner.subprocess, 'check_output', return_value=''
        ):
            result = runner.external_installations(target, {'icct-nms-pathchar': 'installed'})
            self.assertNotIn('icct-nms-guacamole-strict', result)

    @unittest.skipUnless(os.geteuid() == 0, 'Trusted native receipt fixtures require root ownership')
    def test_custom_service_prefix_is_verified_and_changed_files_fail(self):
        with tempfile.TemporaryDirectory() as directory:
            prefix = Path(directory) / 'custom-daemon-build'
            files = {}
            for name in ('sbin/guacd', 'lib/libguac-client-ssh.so'):
                path = prefix / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(b'isolated native fixture')
                path.chmod(0o644)
                files[name] = {'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}
            profile = runner.native.PROFILES['guacamole-strict']
            receipt = prefix / 'icct-verified.json'
            receipt.write_text(json.dumps(dict(component='guacamole-strict', source_sha256=profile['sha256'], patch_sha256=profile['patch_sha256'], files=files)))
            receipt.chmod(0o600)
            binary = prefix / 'sbin/guacd'
            self.assertTrue(runner.strict_runtime_verified(binary))
            binary.write_bytes(b'changed')
            self.assertFalse(runner.strict_runtime_verified(binary))
