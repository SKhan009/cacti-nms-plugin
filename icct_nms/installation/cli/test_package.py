import importlib.util
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile


def load(name):
    spec = importlib.util.spec_from_file_location('icct_' + name, Path(__file__).with_name(name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


package = load('package')
deploy = load('deploy')


class ReleasePackaging(unittest.TestCase):
    def fixture(self, root):
        for name in ['setup.php','installation/cli/setup.py','installation/cli/deploy.py',
                     'config.local.json','ssh.config.local.json','protocols/snmp/mibs/storage/session.json',
                     'shared/assets/images/device-types/uploads/private.png',
                     'shared/assets/images/device-types/uploads/.htaccess']:
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('fixture')

    def test_archive_excludes_secrets_and_runtime_data(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'plugin'
            self.fixture(root)
            archive = Path(directory) / 'release.zip'
            package.build_archive(root, archive)
            with zipfile.ZipFile(archive) as stream:
                names = stream.namelist()
                for private in ['config.local.json','ssh.config.local.json','session.json','private.png']:
                    self.assertFalse(any(name.endswith(private) for name in names))
                self.assertIn('icct_nms/shared/assets/images/device-types/uploads/.htaccess', names)
                target = Path(directory) / 'unpacked'
                stream.extractall(target)
            manifest = deploy.verify_release(target)
            self.assertIn('icct_nms/setup.php', manifest['files'])
            (target / 'icct_nms/setup.php').write_text('changed after transport')
            with self.assertRaisesRegex(ValueError, 'changed'):
                deploy.verify_release(target)

    def test_optional_packages_travel_with_release_and_reject_another_target(self):
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory);root=base/'source';self.fixture(root)
            target=dict(family='rhel',id='rhel',version='9.8',arch='aarch64')
            bundles=[]
            for component in [None,'redis']:
                folder=base/(component or 'base');folder.mkdir();bundles.append(folder)
                payload=folder/'fixture.rpm';payload.write_bytes(b'package fixture, not executed')
                manifest=dict(format=1,target=target,component=component,packages=package.setup.component_packages(target,component),files={'fixture.rpm':hashlib.sha256(payload.read_bytes()).hexdigest()})
                (folder/'bundle.json').write_text(json.dumps(manifest))
            archive=base/'release.zip'
            result=package.build_archive(root,archive,bundles[0],component_bundles=[bundles[1]])
            self.assertEqual(result['package_components'],['redis'])
            with zipfile.ZipFile(archive) as stream:
                self.assertIn('components/redis/bundle.json',stream.namelist())
                stream.extractall(base/'unpacked')
            deploy.verify_release(base/'unpacked')
            manifest['target']=dict(target,arch='x86_64')
            (bundles[1]/'bundle.json').write_text(json.dumps(manifest))
            with self.assertRaisesRegex(ValueError,'same OS and CPU'):
                package.build_archive(root,base/'wrong.zip',bundles[0],component_bundles=[bundles[1]])

    def test_source_and_destination_symlinks_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'plugin'
            self.fixture(root)
            (root / 'alias.php').symlink_to(root / 'setup.php')
            with self.assertRaisesRegex(ValueError, 'symlink'):
                package.source_files(root)
            cacti = Path(directory) / 'cacti'
            (cacti / 'include').mkdir(parents=True)
            (cacti / 'include/cli_check.php').touch()
            (cacti / 'plugins').mkdir()
            (cacti / 'plugins/icct_nms').symlink_to(root)
            with self.assertRaisesRegex(ValueError, 'symlink'):
                deploy.validate_destination(cacti, ['icct_nms/setup.php'])

    def test_private_configuration_is_never_a_deployment_target(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'include').mkdir()
            (root / 'include/cli_check.php').touch()
            for name in ['icct_nms/config.local.json','icct_nms/.env','icct_nms/protocols/snmp/mibs/storage/session.json','icct_nms/shared/assets/images/device-types/uploads/photo.png','icct_nms/../escape']:
                with self.assertRaises(ValueError):
                    deploy.validate_destination(root, [name])

    def test_release_path_traversal_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'release.json').write_text(json.dumps({'format':1,'files':{'../escape':'hash'}}))
            with self.assertRaisesRegex(ValueError, 'path'):
                deploy.verify_release(root)

    def test_deployment_retains_local_configuration_and_backs_up_source(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            source = base / 'source'
            self.fixture(source)
            archive = base / 'release.zip'
            package.build_archive(source, archive)
            release = base / 'release'
            with zipfile.ZipFile(archive) as stream:
                stream.extractall(release)
            manifest = deploy.verify_release(release)
            cacti = base / 'cacti'
            (cacti / 'include').mkdir(parents=True)
            (cacti / 'include/cli_check.php').touch()
            existing = cacti / 'plugins/icct_nms'
            existing.mkdir(parents=True)
            (existing / 'config.local.json').write_text('keep private config')
            (existing / 'setup.php').write_text('previous source')
            destination, backup = deploy.copy_source(release, cacti, manifest, base / 'backups')
            self.assertTrue(backup.is_file())
            self.assertEqual((destination / 'config.local.json').read_text(), 'keep private config')
            self.assertEqual((destination / 'setup.php').read_text(), 'fixture')
            self.assertFalse(list(destination.rglob('.icct-install-*')))


if __name__ == '__main__':
    unittest.main()
