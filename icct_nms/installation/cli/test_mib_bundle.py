"""The offline UPS download must match the plugin's validated source files."""
import hashlib
import json
from pathlib import Path
import re
import unittest
import zipfile


class MibBundleTest(unittest.TestCase):
    def test_complete_and_reproducible_contents(self):
        assets = Path(__file__).resolve().parents[2] / 'shared/assets/mibs'
        manifest = json.loads((assets / 'standard-mibs-sources.json').read_text())
        expected = {item['module'] + '.txt' for item in manifest['files']}
        self.assertEqual(expected, {'UPS-MIB.txt', 'SNMPv2-SMI.txt', 'SNMPv2-TC.txt', 'SNMPv2-CONF.txt'})
        with zipfile.ZipFile(assets / 'UPS-MIB-dependencies.zip') as archive:
            self.assertIsNone(archive.testzip())
            self.assertEqual(set(archive.namelist()), expected | {'standard-mibs-sources.json', 'README.txt'})
            self.assertEqual(archive.read('standard-mibs-sources.json'), (assets / 'standard-mibs-sources.json').read_bytes())
            for item in manifest['files']:
                with self.subTest(module=item['module']):
                    data = archive.read(item['module'] + '.txt')
                    self.assertEqual(data, (assets / (item['module'] + '.txt')).read_bytes())
                    self.assertEqual(hashlib.sha256(data).hexdigest(), item['sha256'])
                    self.assertRegex(item['url'], r'^https://raw\.githubusercontent\.com/.+/[a-f0-9]{40}/')
                    self.assertRegex(item['upstream_sha256'], r'^[a-f0-9]{64}$')
                    imports = re.search(r'\bIMPORTS\b(.*?);', data.decode(), re.S)
                    for dependency in re.findall(r'\bFROM\s+([A-Za-z][A-Za-z0-9-]*)', imports[1] if imports else ''):
                        self.assertIn(dependency + '.txt', expected)


if __name__ == '__main__':
    unittest.main()
