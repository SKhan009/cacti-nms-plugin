import hashlib
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import package_runner as runner


class RuntimeInventory(unittest.TestCase):
    def test_worker_publishes_inventory_and_clears_stale_media_versions(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            (state / 'public').mkdir()
            (state / 'requests').mkdir()
            (state / 'config.json').write_text(json.dumps({'root': '/cacti', 'media': None}))
            (state / 'public/status.json').write_text(json.dumps({
                'time': 0, 'versions': {'stale': {}}, 'removable': ['php-cli'],
            }))
            target = dict(family='rhel', id='rhel', version='9.4', arch='x86_64')
            with (
                patch.object(runner, 'STATE', state),
                patch.object(runner, 'package_scope', return_value=({'base': ['php-cli']}, {}, set())),
                patch.object(runner, 'installed_packages', return_value={'php-cli': ['0:8.0.30-1.el9.x86_64:123']}),
                patch.object(runner, 'external_installations', return_value={}),
                patch.object(runner, 'current_syslog_port', return_value=514),
                patch.object(runner, 'package_versions', side_effect=AssertionError('No media to compare')),
            ):
                runner.work(target)
            result = json.loads((state / 'public/status.json').read_text())
            self.assertTrue(result['syslog_only'])
            self.assertEqual(result['versions']['base']['php-cli']['installed'], ['8.0.30-1.el9.x86_64'])
            self.assertEqual(result['removable'], [])
            self.assertNotIn('stale', result['versions'])
            self.assertEqual(result['target'], target)

    def test_initial_readiness_does_not_consume_a_queued_operation(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            (state / 'public').mkdir()
            (state / 'requests').mkdir()
            pending = state / 'requests/pending.json'
            queued = '{"action":"install","target":"redis"}'
            pending.write_text(queued)
            (state / 'config.json').write_text(json.dumps({'root': '/cacti', 'media': None}))
            target = dict(family='rhel', id='rhel', version='9.8', arch='aarch64')
            with (
                patch.object(runner, 'STATE', state),
                patch.object(runner, 'package_scope', return_value=({'base': []}, {}, set())),
                patch.object(runner, 'installed_packages', return_value={}),
                patch.object(runner, 'external_installations', return_value={}),
                patch.object(runner, 'current_syslog_port', return_value=1514),
                patch.object(runner, 'read_request', side_effect=AssertionError('Setup must not consume queued operations')),
            ):
                runner.work(target, inventory_only=True)
            result = json.loads((state / 'public/status.json').read_text())
            self.assertEqual(result['syslog_port'], 1514)
            self.assertTrue(result['syslog_only'])
            self.assertEqual(result['root'], '/cacti')
            self.assertGreater(result['time'], 0)
            self.assertEqual(pending.read_text(), queued)

    def test_inventory_versions_without_media_preserves_epoch_and_multiple_arches(self):
        result = runner.inventory_versions(
            {'base': ['php-cli', 'netperf']},
            {'php-cli': ['0:8.0.30-1.el9.aarch64:123', '2:8.0.31-2.el9.aarch64:456']},
        )['base']
        self.assertEqual(result['php-cli']['installed'],
                         ['2:8.0.31-2.el9.aarch64', '8.0.30-1.el9.aarch64'])
        self.assertEqual(result['php-cli']['bundled'], [])
        self.assertEqual(result['php-cli']['status'], 'Offline media unavailable')
        self.assertEqual(result['netperf']['status'], 'Missing')
        self.assertEqual(result['netperf']['installed'], [])

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
