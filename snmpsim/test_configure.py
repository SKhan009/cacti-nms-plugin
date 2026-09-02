# File: test_configure.py
# Isolated regression tests for portable simulator configuration, validation failures, and upload/restart marker handling.
import argparse
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

import configure

spec = importlib.util.spec_from_file_location('reload_helper', Path(__file__).with_name('nms-snmpsim-reload.py'))
reload_helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(reload_helper)


class SimulatorTests(unittest.TestCase):
    # Create isolated temporary simulator paths and default arguments, registering cleanup for each test.
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.executable = self.root / 'responder'
        self.executable.write_text('#!/bin/sh\nexit 0\n')
        self.executable.chmod(0o755)
        self.data = self.root / 'data'
        self.data.mkdir()
        self.args = argparse.Namespace(executable=str(self.executable), data_dir=str(self.data),
            listen_address='127.0.0.1', client_address='127.0.0.1', port=1162,
            user='bstc', group='bstc', service_name='lab-simulator', output_dir=str(self.root / 'bundle'))

    # Verify generated JSON and systemd units consistently use the supplied installation settings.
    def test_custom_configuration_generates_matching_units(self):
        output = configure.generate(self.args)
        import json
        config = json.loads((output / 'snmpsim.json').read_text())
        self.assertEqual(config['port'], 1162)
        self.assertEqual(config['data_dir'], str(self.data))
        self.assertEqual(config['service'], 'lab-simulator.service')
        self.assertIn('User=bstc', (output / config['service']).read_text())
        self.assertIn('Unit=lab-simulator-reload.service', (output / 'lab-simulator-reload.timer').read_text())
        self.assertNotIn('1161', (output / config['service']).read_text())
        with self.assertRaises(FileExistsError):
            configure.generate(self.args)

    # Verify another installation uses its explicit address and port instead of inherited endpoint values.
    def test_second_installation_has_no_copied_endpoint(self):
        self.args.port = 10161
        self.args.client_address = '192.0.2.20'
        config = configure.build_config(self.args)
        self.assertEqual((config['client_address'], config['port']), ('192.0.2.20', 10161))

    # Verify unsafe or invalid simulator settings are rejected rather than silently replaced.
    def test_invalid_configuration_fails_closed(self):
        for field, value in [('port', 0), ('port', 65536), ('user', 'root'),
                             ('client_address', '0.0.0.0'), ('listen_address', 'bad'),
                             ('service_name', 'test;evil'), ('data_dir', '/missing-path'),
                             ('executable', '/missing-executable')]:
            args = argparse.Namespace(**vars(self.args))
            setattr(args, field, value)
            with self.subTest(field=field, value=value), self.assertRaises(ValueError):
                configure.build_config(args)

    # Verify an upload arriving during restart retains its separate pending activation request.
    def test_upload_during_restart_keeps_new_request(self):
        pending = self.data / '.reload.pending'
        pending.touch()
        # Simulate a new upload arriving while the responder restart is in progress.
        def restart(*args, **kwargs):
            pending.touch()
        with patch.object(reload_helper.subprocess, 'run', side_effect=restart) as run:
            reload_helper.activate({'data_dir': str(self.data), 'service': 'lab-simulator.service'})
        self.assertEqual(run.call_count, 2)
        self.assertTrue(pending.exists())
        self.assertFalse((self.data / '.reload.processing').exists())

    # Verify inactive or failed service checks preserve the pending activation request.
    def test_failed_or_stopped_service_keeps_request(self):
        (self.data / '.reload.pending').touch()
        with patch.object(reload_helper.subprocess, 'run', side_effect=subprocess.CalledProcessError(3, 'systemctl')):
            with self.assertRaises(subprocess.CalledProcessError):
                reload_helper.activate({'data_dir': str(self.data), 'service': 'lab-simulator.service'})
        self.assertTrue((self.data / '.reload.processing').exists())

    # Verify the reload helper does not control the service without a pending upload.
    def test_no_request_does_not_restart(self):
        with patch.object(reload_helper.subprocess, 'run') as run:
            reload_helper.activate({'data_dir': str(self.data), 'service': 'lab-simulator.service'})
        run.assert_not_called()


if __name__ == '__main__':
    unittest.main()
