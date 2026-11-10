import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import package_runner as runner


class SyslogPortTest(unittest.TestCase):
    def test_only_plain_valid_ports_can_reach_the_privileged_worker(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "pending.json"
            for port in ("0", "06514", "65536", "1e3", "514.service", "514\n", "514", "65535"):
                with self.subTest(port=port):
                    request = dict(id="a" * 32, action="syslog-port", target=port)
                    path.write_text(json.dumps(request))
                    if port in ("514", "65535"):
                        self.assertEqual(runner.read_request(path, os.getuid()), request)
                    else:
                        with self.assertRaises(ValueError):
                            runner.read_request(path, os.getuid())

    def test_job_reports_the_port_only_after_the_receiver_applies_it(self):
        for observed, expected in ((20514, "complete"), (514, "failed")):
            with self.subTest(observed=observed), tempfile.TemporaryDirectory() as directory:
                state = Path(directory)
                (state / "public").mkdir()
                (state / "requests").mkdir()
                (state / "config.json").write_text(json.dumps({
                    "root": "/var/www/html/cacti", "media": "/unused", "uid": os.getuid(),
                }))
                pending = state / "requests/pending.json"
                pending.write_text(json.dumps(dict(id="a" * 32, action="syslog-port", target="20514")))
                with (
                    patch.object(runner, "STATE", state),
                    patch.object(runner, "package_scope", return_value=({}, {}, set())),
                    patch.object(runner, "apply_syslog_port") as apply,
                    patch.object(runner, "current_syslog_port", return_value=observed),
                    patch.object(runner, "installed_packages", return_value={}),
                    patch.object(runner, "package_versions", return_value={}),
                    patch.object(runner, "external_installations", return_value={}),
                    patch.object(runner.LOGGER, "exception"),
                ):
                    runner.work({"family": "rhel"})
                apply.assert_called_once_with("20514")
                status = json.loads((state / "public/status.json").read_text())
                self.assertEqual(status["job"]["status"], expected)
                self.assertEqual(status["syslog_port"], observed)
                self.assertFalse(pending.exists())


if __name__ == "__main__":
    unittest.main()
