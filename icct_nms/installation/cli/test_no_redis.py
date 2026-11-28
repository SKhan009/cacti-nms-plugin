"""Unused Redis must never enter installer actions or offline release media."""
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile
from unittest.mock import patch

import setup
import package
import package_runner as runner


class RedisRemoval(unittest.TestCase):
    def test_profiles_and_removal_scope_exclude_redis_on_both_platforms(self):
        for family in ('rhel', 'debian'):
            target = dict(family=family, id=family, version='9.8', arch='aarch64')
            profiles = runner.profiles(target)
            self.assertNotIn('redis', profiles)
            names = {name for packages in profiles.values() for name in packages}
            self.assertFalse(names & {'redis', 'redis-server', 'php-redis'})
            self.assertNotIn('redis', runner.REMOVAL_IMPACTS)
            with self.assertRaisesRegex(ValueError, 'Unknown component'):
                setup.component_packages(target, 'redis')

    def test_old_redis_bundle_is_rejected_before_installation(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            target = dict(family='rhel', id='rhel', version='9.8', arch='aarch64')
            (root / 'bundle.json').write_text(json.dumps(dict(format=1, target=target, component='redis', packages=['redis'], files={})))
            with patch.object(setup, 'run') as run:
                with self.assertRaisesRegex(ValueError, 'Unknown component bundle'):
                    setup.verify_component_bundle(root, target)
                with self.assertRaisesRegex(ValueError, 'Unknown component'):
                    setup.install_packages(target, root, component='redis')
                run.assert_not_called()

    def test_rebuilding_from_old_media_excludes_retired_component(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            source = root / 'source'
            (source / 'installation/cli').mkdir(parents=True)
            (source / 'setup.php').write_text('<?php')
            (source / 'installation/cli/deploy.py').write_text('# test fixture')
            media = root / 'media'
            base = media / 'packages'
            base.mkdir(parents=True)
            payload = b'package fixture, never executed'
            (base / 'base.rpm').write_bytes(payload)
            target = dict(family='rhel', id='rhel', version='9.8', arch='aarch64')
            (base / 'bundle.json').write_text(json.dumps(dict(format=1, target=target, component=None,
                packages=setup.component_packages(target), files={'base.rpm':hashlib.sha256(payload).hexdigest()})))
            retired = media / 'components/redis'
            retired.mkdir(parents=True)
            (retired / 'redis.rpm').write_bytes(b'retired package')
            (retired / 'bundle.json').write_text('{"component":"redis"}')
            archive = root / 'release.zip'
            release = package.build_archive(source, archive, media=media, allow_partial=True)
            self.assertNotIn('redis', release['package_components'])
            self.assertEqual(runner.media_profiles(media, target), ['base'])
            with zipfile.ZipFile(archive) as stream:
                self.assertFalse(any('redis' in name.lower() for name in stream.namelist()))

    def test_diagnostics_service_has_no_redis_startup_dependency(self):
        root = Path(__file__).resolve().parents[2]
        unit = (root / 'shared/deployment/icct-nms-diagnostics.service').read_text()
        self.assertNotIn('redis', unit.lower())
        self.assertIn('mariadb.service', unit)
        self.assertIn('diagnostic_listener.php', unit)


if __name__ == '__main__':
    unittest.main()
