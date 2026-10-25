#!/usr/bin/env python3
import importlib.util
import hashlib
import json
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
spec = importlib.util.spec_from_file_location('sbom', Path(__file__).with_name('build_sbom.py'))
sbom = importlib.util.module_from_spec(spec); spec.loader.exec_module(sbom)

class InventoryTests(unittest.TestCase):
    def test_release_inventory_is_reproducible_and_explicitly_scoped(self):
        a = sbom.inventory(); self.assertEqual(a, sbom.inventory())
        for relative in ['docs/piper-sbom.cdx.json', 'slsmassnotifyserver/docs/piper-sbom.cdx.json']:
            self.assertEqual(json.loads((sbom.ROOT / relative).read_text()), a,
                             relative + ' must match the current release version and dependency inventory')
        self.assertEqual(a['specVersion'], '1.6')
        lock = json.loads((sbom.ROOT / 'slsmassnotifyserver/identity-libs/composer.lock').read_text())
        passkeys=json.loads((sbom.ROOT/'slsmassnotifyserver/passkey-libs/composer.lock').read_text())
        php_packages={(row['name'],row['version']) for row in lock['packages']+passkeys['packages']}
        self.assertEqual(len(a['components']),17+len(php_packages))
        by_name = {row['name']: row for row in a['components']}
        self.assertEqual(by_name['chart.js']['version'], '2.9.4')
        self.assertNotIn('moment', by_name)
        embedded = {row['purl'] for row in a['components'] if any(prop['name'] == 'sls:embedded-in' for prop in row.get('properties', []))}
        self.assertEqual(embedded, set(a['dependencies'][0]['dependsOn']))
        self.assertIn('pkg:npm/color-name@1.1.3', embedded)
        self.assertIn('pkg:npm/color-name@1.1.4', embedded)
        for package in lock['packages']:
            by_name[package['name']]=next(row for row in a['components'] if row['name']==package['name'] and row['version']==package['version'])
            self.assertEqual(by_name[package['name']]['version'], package['version'])
            self.assertEqual(by_name[package['name']]['properties'][0]['value'], package['source']['reference'])
            properties = {row['name']: row['value'] for row in by_name[package['name']]['properties']}
            self.assertRegex(properties['sls:upstream-archive-sha256'], r'^[a-f0-9]{64}$')
        baseline_digest = hashlib.sha256(sbom.VENDOR_BASELINE.read_bytes()).hexdigest()
        metadata = {row['name']: row['value'] for row in a['metadata']['properties']}
        self.assertEqual(metadata['sls:composer-vendor-baseline-sha256'], baseline_digest)
        patch_manifest = json.loads(sbom.VENDOR_PATCHES.read_text())['packages']
        for name, patch in patch_manifest.items():
            properties = {row['name']: row['value'] for row in by_name[name]['properties']}
            self.assertEqual(properties['sls:modifications'], patch['modifications'])
            self.assertEqual(json.loads(properties['sls:patched-files-sha256']), patch['files'])
        self.assertIn('OS packages', json.dumps(a))
        self.assertNotIn('/home/', json.dumps(a))

    def test_vendor_patch_manifest_is_fail_closed(self):
        original = sbom.VENDOR_PATCHES
        manifest = json.loads(original.read_text())
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'patches.json'; sbom.VENDOR_PATCHES = path
            try:
                broken = json.loads(json.dumps(manifest))
                package = next(iter(broken['packages'].values()))
                package['files'][next(iter(package['files']))] = '0' * 64
                path.write_text(json.dumps(broken))
                with self.assertRaisesRegex(ValueError, 'reviewed hash'): sbom.inventory()
                broken = json.loads(json.dumps(manifest))
                package = next(iter(broken['packages'].values()))
                package['files'] = {'../composer.lock': '0' * 64}
                path.write_text(json.dumps(broken))
                with self.assertRaisesRegex(ValueError, 'identity is invalid'): sbom.inventory()
                broken = json.loads(json.dumps(manifest))
                next(iter(broken['packages'].values()))['source_reference'] = '0' * 40
                path.write_text(json.dumps(broken))
                with self.assertRaisesRegex(ValueError, 'source revision is stale'): sbom.inventory()
                broken = json.loads(json.dumps(manifest))
                next(iter(broken['packages'].values()))['files'] = {
                    'src/Overflow' + str(index) + '.php': '0' * 64 for index in range(101)
                }
                path.write_text(json.dumps(broken))
                with self.assertRaisesRegex(ValueError, 'metadata is invalid'): sbom.inventory()
                broken = json.loads(json.dumps(manifest)); broken['schema'] = True
                path.write_text(json.dumps(broken))
                with self.assertRaisesRegex(ValueError, 'invalid root'): sbom.inventory()
            finally:
                sbom.VENDOR_PATCHES = original

    def test_vendor_baseline_rejects_boolean_schema(self):
        identity = sbom.ROOT / 'slsmassnotifyserver/identity-libs'
        locked = sbom.keyed_composer_packages(
            sbom.strict_json((identity / 'composer.lock').read_text(), 'Composer lock')['packages'],
            'Locked Composer')
        patches = sbom.vendor_patches(identity, locked)
        original = sbom.VENDOR_BASELINE
        manifest = json.loads(original.read_text()); manifest['schema'] = True
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'baseline.json'; path.write_text(json.dumps(manifest))
            sbom.VENDOR_BASELINE = path
            try:
                with self.assertRaisesRegex(ValueError, 'invalid root'):
                    sbom.vendor_baseline(identity, locked, patches)
            finally:
                sbom.VENDOR_BASELINE = original

    def test_complete_vendor_baseline_rejects_unregistered_tree_changes(self):
        identity = sbom.ROOT / 'slsmassnotifyserver/identity-libs'
        locked_rows = sbom.strict_json((identity / 'composer.lock').read_text(), 'Composer lock')['packages']
        locked = sbom.keyed_composer_packages(locked_rows, 'Locked Composer')
        patches = sbom.vendor_patches(identity, locked)
        for change in ('modify', 'remove', 'add', 'generated'):
            with tempfile.TemporaryDirectory() as directory:
                copy = Path(directory) / 'identity-libs'
                shutil.copytree(identity, copy)
                target = copy / 'vendor/firebase/php-jwt/src/JWT.php'
                if change == 'modify':
                    target.write_bytes(target.read_bytes() + b'\n')
                elif change == 'remove':
                    target.unlink()
                elif change == 'generated':
                    generated = copy / 'vendor/composer/platform_check.php'
                    generated.write_bytes(generated.read_bytes() + b'\n')
                else:
                    (copy / 'vendor/firebase/php-jwt/UNREVIEWED').write_text('unexpected')
                with self.assertRaisesRegex(ValueError, 'reviewed baseline|files differ|Generated Composer file differs'):
                    sbom.vendor_baseline(copy, locked, patches)

    def test_security_manifests_reject_duplicate_json_members(self):
        with self.assertRaisesRegex(ValueError, 'duplicate JSON member'):
            sbom.strict_json('{"schema":1,"schema":1}', 'Fixture')
        with self.assertRaisesRegex(ValueError, 'non-standard numeric value'):
            sbom.strict_json('{"schema":NaN}', 'Fixture')
        with self.assertRaisesRegex(ValueError, 'not valid JSON'):
            sbom.strict_json('[' * 2000 + ']' * 2000, 'Fixture')

    def test_composer_package_identities_are_unique(self):
        valid = [{'name': 'vendor/package'}]
        self.assertEqual(sbom.keyed_composer_packages(valid, 'Test'), {'vendor/package': valid[0]})
        for rows in [None, [{}], valid * 2, [{'name': '../escape/package'}]]:
            with self.assertRaisesRegex(ValueError, 'invalid|duplicated'):
                sbom.keyed_composer_packages(rows, 'Test')

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

    def test_generated_document_is_world_readable(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'sbom.json'
            subprocess.run(
                [sys.executable, str(Path(sbom.__file__)), '--output', str(output)],
                check=True,
                stdout=subprocess.DEVNULL,
            )
            self.assertEqual(stat.S_IMODE(output.stat().st_mode), 0o644)
            self.assertEqual(json.loads(output.read_text()), sbom.inventory())

if __name__ == '__main__': unittest.main()
