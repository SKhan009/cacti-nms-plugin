"""Offline NTP setup must grant only its fixed read operation and preserve unmanaged policies."""

import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location(
    "ntp_access", Path(__file__).with_name("ntp_access.py")
)
access = importlib.util.module_from_spec(spec)
spec.loader.exec_module(access)


class ReadOnlyNtpAccess(unittest.TestCase):
    def test_exact_command_and_deduplicated_accounts(self):
        text = access.policy(["apache", "apache", "collector"])
        self.assertEqual(text.count("apache ALL="), 3)
        self.assertEqual(
            text.count("NOPASSWD: /usr/bin/chronyc -n -h /run/chrony/chronyd.sock ntpdata"), 2
        )
        self.assertNotIn("*", text)
        self.assertNotIn("(root)", text)
        for names in [[], ["ALL"], ["apache\nroot"], ["a,b"], ["-u"], ["user ALL=(ALL)"]]:
            with self.assertRaises(ValueError):
                access.policy(names)

    def test_unmanaged_and_symlink_are_preserved(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "policy"
            path.write_text("# operator policy\n")
            with (
                patch.object(access.os, "geteuid", return_value=0),
                patch.object(access.pwd, "getpwnam"),
                patch.object(access.os, "access", return_value=True),
            ):
                with self.assertRaises(ValueError):
                    access.configure(["apache"], path)
                self.assertEqual(path.read_text(), "# operator policy\n")
                link = Path(directory) / "link"
                link.symlink_to(path)
                with self.assertRaises(ValueError):
                    access.configure(["apache"], link)

    def test_failed_syntax_check_does_not_install(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "policy"
            with (
                patch.object(access.os, "geteuid", return_value=0),
                patch.object(access.pwd, "getpwnam"),
                patch.object(access.os, "access", return_value=True),
                patch.object(access.os, "chown"),
                patch.object(access.subprocess, "run", side_effect=OSError("invalid policy")),
            ):
                with self.assertRaises(OSError):
                    access.configure(["apache"], path)
                self.assertFalse(path.exists())
                self.assertEqual(list(Path(directory).iterdir()), [])


if __name__ == "__main__":
    unittest.main()
