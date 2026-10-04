#!/usr/bin/env python3
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
spec = importlib.util.spec_from_file_location('sbom', Path(__file__).with_name('build_sbom.py'))
sbom = importlib.util.module_from_spec(spec); spec.loader.exec_module(sbom)

class InventoryTests(unittest.TestCase):
    def test_release_inventory_is_reproducible_and_explicitly_scoped(self):
        a = sbom.inventory(); self.assertEqual(a, sbom.inventory())
        self.assertEqual(a['specVersion'], '1.6')
        lock = json.loads((sbom.ROOT / 'slsmassnotifyserver/identity-libs/composer.lock').read_text())
        self.assertEqual(len(a['components']), 12 + len(lock['packages']))
        by_name = {row['name']: row for row in a['components']}
        self.assertEqual(by_name['chart.js']['version'], '2.9.4')
        for package in lock['packages']:
            self.assertEqual(by_name[package['name']]['version'], package['version'])
            self.assertEqual(by_name[package['name']]['properties'][0]['value'], package['source']['reference'])
        self.assertIn('OS packages', json.dumps(a))
        self.assertNotIn('/home/', json.dumps(a))

    def test_actual_metadata_checked_without_importing_code(self):
        with tempfile.TemporaryDirectory() as directory:
            p = Path(directory); (p/'pins').write_text('package-a==1.0.0\n')
            (p/'package_a-1.0.dist-info').mkdir()
            (p/'package_a-1.0.dist-info/METADATA').write_text('Name: package-a\nVersion: 1.0\n')
            (p/'package_a.py').write_text('raise RuntimeError("never import")')
            (p/'evil.pth').write_text('import package_a')
            value = sbom.inventory(p/'pins', p)
            self.assertEqual(value['components'][0]['version'], '1.0')
            (p/'package_a-1.0.dist-info/METADATA').write_text('Name: package-a\nVersion: 2.0\n')
            with self.assertRaisesRegex(ValueError, 'does not match'): sbom.inventory(p/'pins', p)

    def test_invalid_duplicate_and_missing_dependencies_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            p = Path(directory)/'pins'
            for contents in ['', 'a>=1\n', 'a==1\na==1\n', 'A_b==1\na-b==2\n']:
                p.write_text(contents)
                with self.assertRaises(ValueError): sbom.inventory(p)
            p.write_text('missing==1\n')
            with self.assertRaisesRegex(ValueError, 'does not match'): sbom.inventory(p, p.parent)

if __name__ == '__main__': unittest.main()
