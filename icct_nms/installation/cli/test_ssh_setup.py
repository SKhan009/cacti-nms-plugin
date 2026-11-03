"""Offline SSH setup must preserve explicit configuration and fail closed."""

import importlib.util
from pathlib import Path
import tempfile
import shutil
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("ssh_adapter", ROOT / "protocols/ssh/configure.py")
ssh = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ssh)
spec = importlib.util.spec_from_file_location("ssh_setup", Path(__file__).with_name("setup.py"))
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)


class OfflineSshSetup(unittest.TestCase):
    def test_origin_requires_explicit_https_without_credentials_or_path(self):
        for origin in ["https://nms.example", "https://127.0.0.1:8443"]:
            self.assertEqual(ssh.validate_origin(origin), origin)
        for origin in [
            "http://nms.example",
            "https://user:secret@nms.example",
            "https://nms.example/",
            "https://nms.example?x=1",
            "https://nms.example:0",
            "https://nms.example:65536",
            "https://",
        ]:
            with self.assertRaises(ValueError):
                ssh.validate_origin(origin)

    def test_invalid_service_settings_fail_preflight(self):
        for user, directory, port in [
            ("root;id", "/var/lib/ssh", 4823),
            ("ssh", "/tmp/ssh", 4823),
            ("ssh", "/var/lib/ssh", 22),
            ("ssh", "/var/lib/ssh", 65536),
        ]:
            with self.assertRaises(ValueError):
                setup.validate_ssh_settings("https://nms.example", user, directory, port)
        setup.validate_ssh_settings("https://nms.example", "ssh", "/var/lib/ssh", 4823)

    def test_managed_units_are_idempotent_and_backed_up_once(self):
        with tempfile.TemporaryDirectory() as directory:
            unit = Path(directory) / "broker.service"
            unit.write_text("original")
            ssh.write_managed(unit, "configured")
            backup = unit.with_name(unit.name + ".before-icct-ssh")
            self.assertEqual(backup.read_text(), "original")
            inode = unit.stat().st_ino
            ssh.write_managed(unit, "configured")
            self.assertEqual(unit.stat().st_ino, inode)
            ssh.write_managed(unit, "updated")
            self.assertEqual(backup.read_text(), "original")

    def test_managed_destination_symlink_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "original"
            target.write_text("retain")
            link = Path(directory) / "broker.service"
            link.symlink_to(target)
            with self.assertRaises(ValueError):
                ssh.write_managed(link, "replace")
            self.assertEqual(target.read_text(), "retain")

    def test_disabled_selinux_does_not_mutate_policy(self):
        with (
            patch.object(ssh.shutil, "which", return_value="/usr/sbin/getenforce"),
            patch.object(ssh.subprocess, "check_output", return_value="Disabled\n"),
            patch.object(ssh.subprocess, "run") as run,
        ):
            ssh.configure_selinux(ROOT)
            run.assert_not_called()

    def test_selinux_uses_canonical_rhel_var_run_mapping(self):
        with (
            patch.object(ssh.shutil, "which", side_effect=lambda value: "/usr/bin/" + value),
            patch.object(ssh.pathlib.Path, "is_file", return_value=True),
            patch.object(ssh.subprocess, "check_output", side_effect=["Enforcing\n", ""]),
            patch.object(ssh.subprocess, "run") as run,
        ):
            ssh.configure_selinux(ROOT)
            rules = [
                call.args[0]
                for call in run.call_args_list
                if call.args[0][:2] == ["semanage", "fcontext"]
            ]
            self.assertEqual(len(rules), 1)
            self.assertEqual(rules[0][-1], "/var/run/icct-nms-ssh(/.*)?")
            self.assertFalse(any("setenforce" in call.args[0] for call in run.call_args_list))

    def test_missing_selinux_tools_fail_without_disabling_enforcement(self):
        with (
            patch.object(
                ssh.shutil,
                "which",
                side_effect=lambda value: (
                    "/usr/sbin/getenforce" if value == "getenforce" else None
                ),
            ),
            patch.object(ssh.subprocess, "check_output", return_value="Enforcing\n"),
            patch.object(ssh.subprocess, "run") as run,
        ):
            with self.assertRaisesRegex(ValueError, "SELinux"):
                ssh.configure_selinux(ROOT)
            run.assert_not_called()

    def test_offline_command_preserves_selected_identity_and_store(self):
        with (
            patch.object(setup, "run") as run,
            patch.object(setup, "poller_id", return_value=7),
            patch.object(setup, "ssh_bundle", return_value="/media/strict"),
        ):
            setup.configure_ssh(
                Path("/srv/cacti"),
                "apache",
                "web",
                "https://nms.example",
                ["/media/strict"],
                "existing-ssh",
                "/var/lib/existing-ssh",
                4823,
            )
            command = list(map(str, run.call_args.args[0]))
            for option, expected in {
                "--origin": "https://nms.example",
                "--web-user": "apache",
                "--web-group": "web",
                "--service-user": "existing-ssh",
                "--state-directory": "/var/lib/existing-ssh",
                "--poller-id": "7",
                "--native-bundle": "/media/strict",
            }.items():
                self.assertEqual(command[command.index(option) + 1], expected)
            self.assertFalse(any("online" in item or "download" in item for item in command))

    def test_missing_or_duplicate_strict_bundles_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            bundle = Path(directory)
            (bundle / "native.json").write_text('{"component":"pathchar"}')
            for bundles in [[], [bundle]]:
                with self.assertRaises(ValueError):
                    setup.ssh_bundle(bundles)
            (bundle / "native.json").write_text('{"component":"guacamole-strict"}')
            self.assertEqual(setup.ssh_bundle([bundle]), bundle)
            with self.assertRaises(ValueError):
                setup.ssh_bundle([bundle, bundle])

    def test_apache_guard_does_not_depend_on_htaccess(self):
        with (
            patch.object(ssh, "write_managed") as write,
            patch.object(ssh.subprocess, "run") as run,
        ):
            ssh.protect_http_configuration(Path("/srv/cacti/plugins/icct_nms"), "rhel")
            self.assertEqual(write.call_args.args[0], "/etc/httpd/conf.d/icct-nms-ssh-private.conf")
            self.assertIn("Require all denied", write.call_args.args[1])
            self.assertIn("ssh.config.local.json", write.call_args.args[1])
            self.assertEqual(run.call_args_list[0].args[0], ["httpd", "-t"])
            self.assertEqual(run.call_args_list[1].args[0], ["systemctl", "reload", "httpd"])

    def test_broker_waits_only_for_startup_and_never_hides_errors(self):
        command = ["local-probe"]
        pending = subprocess.CompletedProcess(command, 2)
        ready = subprocess.CompletedProcess(command, 0)
        with (
            patch.object(ssh.subprocess, "run", side_effect=[pending, pending, ready]) as run,
            patch.object(ssh.time, "sleep") as sleep,
        ):
            ssh.wait_for_broker(command)
            self.assertEqual(run.call_count, 3)
            self.assertEqual(sleep.call_count, 2)
        with patch.object(
            ssh.subprocess,
            "run",
            return_value=subprocess.CompletedProcess(command, 255),
        ) as run:
            with self.assertRaises(subprocess.CalledProcessError):
                ssh.wait_for_broker(command)
            self.assertEqual(run.call_count, 1)
        with (
            patch.object(ssh.subprocess, "run", return_value=pending) as run,
            patch.object(ssh.time, "sleep"),
        ):
            with self.assertRaises(RuntimeError):
                ssh.wait_for_broker(command)
            self.assertEqual(run.call_count, 40)

    @unittest.skipUnless(shutil.which("systemd-analyze"), "systemd unit verifier requires Linux")
    def test_generated_units_pass_real_systemd_validation(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            broker = (ROOT / "protocols/ssh/icct-nms-ssh.service.in").read_text()
            for tag, value in {
                "SERVICE_USER": "root",
                "WEB_GROUP": "root",
                "PHP_BINARY": sys.executable,
                "PLUGIN_PATH": directory,
                "CACTI_PATH": directory,
                "CACTI_LOG": str(root / "cacti.log"),
                "STATE_NAME": "icct-ssh-qa",
            }.items():
                broker = broker.replace("@" + tag + "@", value)
            daemon = (
                (ROOT / "protocols/ssh/guacamole/icct-nms-guacd.service.in")
                .read_text()
                .replace("@GUACD_BINARY@", sys.executable)
                .replace("@GUACD_PORT@", "4823")
                .replace("User=icct-nms-guacd", "User=root")
                .replace("Group=icct-nms-guacd", "Group=root")
            )
            (root / "icct-nms-ssh.service").write_text(broker)
            (root / "icct-nms-guacd.service").write_text(daemon)
            result = subprocess.run(
                [
                    "systemd-analyze",
                    "verify",
                    str(root / "icct-nms-ssh.service"),
                    str(root / "icct-nms-guacd.service"),
                ],
                capture_output=True,
                text=True,
            )
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_units_keep_broker_and_guacd_unprivileged_and_separate(self):
        broker = (ROOT / "protocols/ssh/icct-nms-ssh.service.in").read_text()
        daemon = (ROOT / "protocols/ssh/guacamole/icct-nms-guacd.service.in").read_text()
        self.assertIn("Requires=icct-nms-guacd.service", broker)
        self.assertIn("StateDirectory=@STATE_NAME@", broker)
        self.assertIn("User=icct-nms-guacd", daemon)
        self.assertIn("-b 127.0.0.1", daemon)
        self.assertIn("NoNewPrivileges=true", daemon)
        self.assertNotIn("User=root", daemon + broker)


if __name__ == "__main__":
    unittest.main()
