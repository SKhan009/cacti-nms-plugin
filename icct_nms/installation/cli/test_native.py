import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('icct_native',Path(__file__).with_name('native.py'))
native = importlib.util.module_from_spec(spec)
spec.loader.exec_module(native)


class NativeBundleSafety(unittest.TestCase):
    def fixture(self, directory):
        directory = Path(directory)
        item = directory / 'payload/sbin/pchar'
        item.parent.mkdir(parents=True)
        item.write_bytes(b'test fixture, never executed')
        profile = native.PROFILES['pathchar']
        target = dict(family='rhel',id='rhel',version='9.8',arch='aarch64')
        data = dict(format=1,component='pathchar',version='1.5',target=target,source_sha256=profile['sha256'],patch_sha256=native.digest(native.ROOT/profile['patch']),files={'sbin/pchar':dict(sha256=native.digest(item),mode=0o755)})
        (directory / 'native.json').write_text(json.dumps(data))
        return target,data

    def test_changed_artifact_and_wrong_architecture_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            target,data = self.fixture(directory)
            native.verify_bundle(directory,target)
            with self.assertRaisesRegex(ValueError,'CPU differs'):
                native.verify_bundle(directory,dict(target,arch='x86_64'))
            (Path(directory)/'payload/sbin/pchar').write_bytes(b'corrupt')
            with self.assertRaisesRegex(ValueError,'changed'):
                native.verify_bundle(directory,target)

    def test_mode_and_path_escape_are_rejected(self):
        for name,mode in [('sbin/pchar',0o4755),('../escape',0o755)]:
            with tempfile.TemporaryDirectory() as directory:
                target,data = self.fixture(directory)
                data['files']={name:dict(sha256='0'*64,mode=mode)}
                (Path(directory)/'native.json').write_text(json.dumps(data))
                with self.assertRaisesRegex(ValueError,'Unsafe'):
                    native.verify_bundle(directory,target)

    def test_payload_symlink_cannot_bypass_manifest(self):
        with tempfile.TemporaryDirectory() as directory:
            target,data = self.fixture(directory)
            item=Path(directory)/'payload/sbin/pchar'
            item.rename(item.with_name('real'))
            item.symlink_to('real')
            with self.assertRaisesRegex(ValueError,'symlinked'):
                native.verify_bundle(directory,target)

    def test_staging_rechecks_bytes_changed_after_initial_verification(self):
        with tempfile.TemporaryDirectory() as directory:
            target,data = self.fixture(directory)
            original = native.shutil.copyfile
            def changed(source, destination):
                Path(source).write_bytes(b'changed after initial manifest check')
                return original(source,destination)
            with patch.object(native.setup,'os_info',return_value=target), patch.object(native.shutil,'copyfile',side_effect=changed):
                with self.assertRaisesRegex(ValueError,'changed'):
                    with native.frozen_bundle(directory):
                        self.fail('Changed build was executable.')

    def test_upstream_archive_links_and_traversal_are_rejected(self):
        for name,kind in [('../escape',tarfile.REGTYPE),('source/link',tarfile.SYMTYPE)]:
            with tempfile.TemporaryDirectory() as directory:
                archive=Path(directory)/'source.tar.gz'
                with tarfile.open(archive,'w:gz') as stream:
                    member=tarfile.TarInfo(name);member.type=kind
                    stream.addfile(member,io.BytesIO(b''))
                with self.assertRaisesRegex(ValueError,'Unsafe'):
                    native.extract_source(archive,Path(directory)/'out')


if __name__=='__main__': unittest.main()
