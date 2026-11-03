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
            ("debian", "apt-get", "--no-download"),
        ]:
            with (
                patch.object(setup, "verify_bundle", return_value=[Path("/bundle/test.rpm")]),
                patch.object(setup, "run") as run,
            ):
                setup.install_packages(dict(family=family), "/bundle")
                command = run.call_args[0][0]
                self.assertEqual(command[0], executable)
                self.assertIn(flag, command)

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

    def test_online_requires_explicit_selection(self):
        with patch.object(setup, 'run') as run:
            setup.install_packages(dict(family='rhel'), None, online=True)
            self.assertEqual(run.call_args[0][0][:3], ['dnf','-y','install'])
        with self.assertRaisesRegex(ValueError, 'combined'):
            setup.install_packages(dict(family='rhel'), '/bundle', online=True)


if __name__ == "__main__":
    unittest.main()
