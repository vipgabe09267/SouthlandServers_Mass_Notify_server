#!/usr/bin/env python3
"""Real artifact verification and unsafe-tree fixtures; no PBX bootstrap/signing."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('trust_fixture', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_module_trust.py')
TRUST = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(TRUST)


class ModuleTrust(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-module-trust-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.store = self.base / 'trust'
        self.web = self.base / 'web'
        self.module = self.web / 'admin/modules/dashboard'
        self.module.mkdir(parents=True)
        self.files = {'module.xml': b'<module><version>1.0</version></module>', 'safe.php': b'<?php echo "reviewed";'}
        for name, body in self.files.items():
            (self.module / name).write_bytes(body)
        self.data = {'schema': 1, 'module': 'dashboard', 'version': '1.0', 'source': {'kind': 'reviewed-upstream-and-overlays', 'archive_sha256': 'a' * 64}, 'files': {name: {'sha256': TRUST.digest(body), 'target': 'admin/modules/dashboard/' + name} for name, body in self.files.items()}}
        self.enroll(self.data)

    def enroll(self, data):
        manifest = self.base / 'reviewed.json'
        body = (json.dumps(data) + '\n').encode()
        manifest.write_bytes(body)
        manifest.chmod(0o600)
        return TRUST.enroll_reviewed(self.store, manifest, TRUST.digest(body))

    def check(self):
        return TRUST.emit_hashes(self.store, 'dashboard', self.web)

    def test_expected_inventory_is_the_signature_source(self):
        value = self.check()
        self.assertIn('safe.php = ' + TRUST.digest(self.files['safe.php']), value)
        (self.module / 'module.sig').write_bytes(b'existing signature never supplies trust')
        self.assertEqual(self.check(), value)

    def test_unknown_added_removed_and_changed_files_fail(self):
        original = (self.store / 'dashboard.active.json').read_bytes()
        for operation in ('added', 'missing', 'changed'):
            with self.subTest(operation=operation):
                if operation == 'added':
                    (self.module / 'unexpected.php').write_bytes(b'unreviewed')
                elif operation == 'missing':
                    (self.module / 'safe.php').unlink()
                else:
                    (self.module / 'safe.php').write_bytes(b'unreviewed')
                with self.assertRaises(TRUST.TrustError): self.check()
                (self.module / 'unexpected.php').unlink(missing_ok=True)
                (self.module / 'safe.php').write_bytes(self.files['safe.php'])
                self.assertEqual((self.store / 'dashboard.active.json').read_bytes(), original)

    def test_special_files_and_links_are_never_signed(self):
        for kind in ('symlink', 'hardlink', 'fifo'):
            path = self.module / 'bad'
            with self.subTest(kind=kind):
                if kind == 'symlink': path.symlink_to(self.module / 'safe.php')
                elif kind == 'hardlink': os.link(self.module / 'safe.php', path)
                else: os.mkfifo(path)
                with self.assertRaises(TRUST.TrustError): self.check()
                path.unlink()

    def test_only_named_nonexecutable_generated_cache_is_ignored(self):
        cache = self.module / 'assets/less/cache'
        cache.mkdir(parents=True)
        (cache / 'lessphp_abcd1234.css').write_bytes(b'generated CSS')
        self.check()
        (cache / 'lessphp_abcd1234.php').write_bytes(b'<?php unsafe();')
        with self.assertRaises(TRUST.TrustError): self.check()

    def test_reviewed_branding_preserved_but_not_arbitrary_changes(self):
        self.files['branding.php'] = b'<?php echo htmlspecialchars("Approved Brand");'
        self.data['files']['branding.php'] = {'sha256': TRUST.digest(self.files['branding.php']), 'target': 'admin/modules/dashboard/branding.php'}
        (self.module / 'branding.php').write_bytes(self.files['branding.php'])
        self.enroll(self.data)
        self.check()
        (self.module / 'branding.php').write_bytes(b'<?php malicious();')
        with self.assertRaises(TRUST.TrustError): self.check()

    def test_unsigned_wrong_digest_or_unsafe_enrollment_preserves_prior(self):
        prior = (self.store / 'dashboard.active.json').read_bytes()
        source = self.base / 'reviewed.json'
        for value in ('0' * 64, '../path', ''):
            with self.assertRaises(TRUST.TrustError):
                TRUST.enroll_reviewed(self.store, source, value)
        linked = self.base / 'linked.json'
        linked.symlink_to(source)
        with self.assertRaises((TRUST.TrustError, OSError)):
            TRUST.enroll_reviewed(self.store, linked, TRUST.digest(source.read_bytes()))
        self.assertEqual((self.store / 'dashboard.active.json').read_bytes(), prior)

    def test_protected_manifest_permissions_and_digest_are_enforced(self):
        _, generation = TRUST.load(self.store, 'dashboard')
        path = generation / 'inventory.json'
        original = path.read_bytes()
        path.chmod(0o666)
        with self.assertRaises(TRUST.TrustError): self.check()
        path.chmod(0o600)
        path.write_bytes(original + b' ')
        with self.assertRaises(TRUST.TrustError): self.check()

    def test_explicit_version_transition_retains_old_generation(self):
        _, before = TRUST.load(self.store, 'dashboard')
        self.data['version'] = '1.1'
        self.enroll(self.data)
        loaded, after = TRUST.load(self.store, 'dashboard')
        self.assertEqual(loaded['version'], '1.1')
        self.assertNotEqual(before, after)
        self.assertTrue((before / 'inventory.json').is_file())
        self.data['version'] = '1.0'
        self.enroll(self.data)
        self.assertEqual(TRUST.load(self.store, 'dashboard')[1], before)

    def test_manifest_path_and_destination_escape_rejected(self):
        for name, target in (('../evil', 'admin/modules/dashboard/evil'), ('safe.php', '../etc/shadow'), ('safe.php', 'admin/modules/other/safe.php'), ('amp_conf/htdocs/admin/views/menu.php', 'admin/views/menu.php')):
            candidate = dict(self.data, files={name: {'sha256': 'a' * 64, 'target': target}})
            with self.assertRaises(TRUST.TrustError): TRUST.parse_manifest(json.dumps(candidate).encode())

    def test_framework_menu_outside_overlay_must_match_exact_hash(self):
        module = self.web / 'admin/modules/framework'
        module.mkdir()
        (module / 'module.xml').write_bytes(b'<module/>')
        menu = self.web / 'admin/views/menu_items.php'
        menu.parent.mkdir()
        menu.write_bytes(b'<?php /* exact approved upstream + deterministic SLS insertion */')
        data = {'schema': 1, 'module': 'framework', 'version': '17.0', 'source': self.data['source'], 'files': {'module.xml': {'sha256': TRUST.digest(b'<module/>'), 'target': 'admin/modules/framework/module.xml'}, 'amp_conf/htdocs/admin/views/menu_items.php': {'sha256': TRUST.digest(menu.read_bytes()), 'target': 'admin/views/menu_items.php'}}}
        self.enroll(data)
        TRUST.check(self.store, 'framework', self.web)
        menu.write_bytes(menu.read_bytes() + b'/* extra unapproved code */')
        with self.assertRaises(TRUST.TrustError): TRUST.check(self.store, 'framework', self.web)

    def test_uninstall_removes_only_explicitly_reviewed_widgets(self):
        name = 'sections/SlsMassNotifyAnnouncement.class.php'
        path = self.module / name
        path.parent.mkdir()
        path.write_bytes(b'approved widget')
        self.data['files'][name] = {'sha256': TRUST.digest(path.read_bytes()), 'target': 'admin/modules/dashboard/' + name}
        self.data['uninstall'] = {'remove': [name], 'replace': {}}
        self.enroll(self.data)
        self.check()
        path.unlink()
        with self.assertRaises(TRUST.TrustError): self.check()
        TRUST.check(self.store, 'dashboard', self.web, uninstalled=True)
        (self.module / 'safe.php').write_bytes(b'unapproved unrelated code')
        with self.assertRaises(TRUST.TrustError): TRUST.check(self.store, 'dashboard', self.web, uninstalled=True)
        self.data['uninstall']['remove'].append('safe.php')
        with self.assertRaises(TRUST.TrustError): self.enroll(self.data)

    def test_signature_backup_publish_restore_and_no_signature_rollback(self):
        target = self.module / 'module.sig'
        backup = self.base / 'prior.sig'
        candidate = self.base / 'candidate.sig'
        candidate.write_bytes(b'approved signed expected inventory')
        candidate.chmod(0o600)
        self.assertEqual(TRUST.signature_file('dashboard', self.web, 'backup', backup, 'asterisk'), 'absent')
        target.write_bytes(b'previous signature')
        self.assertEqual(TRUST.signature_file('dashboard', self.web, 'backup', backup, 'asterisk'), 'present')
        TRUST.signature_file('dashboard', self.web, 'publish', candidate, 'asterisk')
        self.assertEqual(target.read_bytes(), candidate.read_bytes())
        TRUST.signature_file('dashboard', self.web, 'restore', backup, 'asterisk')
        self.assertEqual(target.read_bytes(), b'previous signature')
        TRUST.signature_file('dashboard', self.web, 'remove', None, 'asterisk')
        self.assertFalse(target.exists())

    def test_signature_links_never_modify_external_victim(self):
        victim = self.base / 'victim'
        victim.write_bytes(b'private')
        victim.chmod(0o600)
        target = self.module / 'module.sig'
        target.symlink_to(victim)
        with self.assertRaises(OSError): TRUST.signature_file('dashboard', self.web, 'backup', self.base / 'backup', 'asterisk')
        candidate = self.base / 'candidate.sig'
        candidate.write_bytes(b'candidate'); candidate.chmod(0o600)
        TRUST.signature_file('dashboard', self.web, 'publish', candidate, 'asterisk')
        self.assertFalse(target.is_symlink())
        self.assertEqual(victim.read_bytes(), b'private')
        self.assertEqual(victim.stat().st_mode & 0o777, 0o600)

    def test_signature_directory_swap_cannot_redirect_root_write(self):
        candidate = self.base / 'candidate.sig'
        candidate.write_bytes(b'candidate'); candidate.chmod(0o600)
        victim = self.base / 'external'
        victim.mkdir()
        (victim / 'module.sig').write_bytes(b'external original')
        original_replace = TRUST.os.replace
        def swapped(source, target, *args, **kwargs):
            held = self.module.parent / 'held'
            self.module.rename(held)
            self.module.symlink_to(victim, target_is_directory=True)
            return original_replace(source, target, *args, **kwargs)
        with mock.patch.object(TRUST.os, 'replace', side_effect=swapped):
            TRUST.signature_file('dashboard', self.web, 'publish', candidate, 'asterisk')
        self.assertEqual((victim / 'module.sig').read_bytes(), b'external original')
        self.assertEqual((self.module.parent / 'held/module.sig').read_bytes(), b'candidate')

    def test_added_python_bytecode_is_not_authorized(self):
        cache = self.module / '__pycache__'
        cache.mkdir()
        (cache / 'attack.cpython-311.pyc').write_bytes(b'executable compiled code')
        with self.assertRaises(TRUST.TrustError): self.check()

    def test_reviewed_spinner_link_requires_exact_target_and_authenticated_bytes(self):
        module = self.web / 'admin/modules/framework'; module.mkdir()
        (module / 'module.xml').write_bytes(b'<module/>')
        target = self.web / 'admin/modules/core/images/spinner.gif'
        target.parent.mkdir(parents=True); target.write_bytes(b'GIF89a fixture')
        link = self.web / 'admin/images/spinner.gif'
        link.parent.mkdir(); link.symlink_to(target)
        data = {'schema': 1, 'module': 'framework', 'version': '17.0', 'source': self.data['source'], 'files': {'module.xml': {'sha256': TRUST.digest(b'<module/>'), 'target': 'admin/modules/framework/module.xml'}, 'amp_conf/htdocs/admin/images/spinner.gif': {'sha256': TRUST.digest(target.read_bytes()), 'target': 'admin/images/spinner.gif', 'link_target': 'admin/modules/core/images/spinner.gif'}}}
        self.enroll(data)
        TRUST.check(self.store, 'framework', self.web)
        target.write_bytes(b'changed GIF')
        with self.assertRaises(TRUST.TrustError): TRUST.check(self.store, 'framework', self.web)
        link.unlink(); link.symlink_to(self.base / 'unreviewed')
        with self.assertRaises(TRUST.TrustError): TRUST.check(self.store, 'framework', self.web)
        data['files']['amp_conf/htdocs/admin/images/spinner.gif']['link_target'] = '../../etc/shadow'
        with self.assertRaises(TRUST.TrustError): self.enroll(data)

    def publisher_fixture(self):
        private = self.base / 'fixture-private.pem'
        public = self.base / 'fixture-public.pem'
        subprocess.run(['/usr/bin/openssl', 'genpkey', '-algorithm', 'ED25519', '-out', str(private)], check=True, capture_output=True)
        subprocess.run(['/usr/bin/openssl', 'pkey', '-in', str(private), '-pubout', '-out', str(public)], check=True, capture_output=True)
        original = TRUST.PUBLIC_KEY
        TRUST.PUBLIC_KEY = public.read_bytes()
        self.addCleanup(setattr, TRUST, 'PUBLIC_KEY', original)
        archive = self.base / 'release.tgz'
        with tarfile.open(archive, 'w:gz') as tar:
            for name, body in [('module.xml', b'<module><rawname>slsmassnotifyserver</rawname><version>0.1.5-beta</version></module>'), ('bin/safe.py', b'print("immutable publisher source")\n')]:
                info = tarfile.TarInfo('slsmassnotifyserver/' + name)
                info.size = len(body)
                tar.addfile(info, io.BytesIO(body))
        manifest = self.base / 'release-manifest.json'
        manifest.write_text(json.dumps({'schema': 1, 'version': '0.1.5-beta', 'tag': 'slsmassnotifyserver-0.1.5-beta', 'package': 'slsmassnotifyserver-0.1.5-beta.tgz', 'package_sha256': TRUST.digest(archive.read_bytes())}))
        signature = self.base / 'release-manifest.sig'
        subprocess.run(['/usr/bin/openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', str(private), '-in', str(manifest), '-out', str(signature)], check=True, capture_output=True)
        return archive, manifest, signature

    def test_publisher_signature_creates_independent_immutable_generation(self):
        archive, manifest, signature = self.publisher_fixture()
        name = TRUST.enroll_sls(self.store, archive, manifest, signature)
        source = self.store / name / 'module/bin/safe.py'
        self.assertEqual(source.read_bytes(), b'print("immutable publisher source")\n')
        self.assertEqual(source.stat().st_mode & 0o777, 0o600)
        self.assertEqual(TRUST.enroll_sls(self.store, archive, manifest, signature), name)
        source.write_bytes(b'modified protected generation')
        with self.assertRaises(TRUST.TrustError): TRUST.enroll_sls(self.store, archive, manifest, signature)

    def test_publisher_wrong_signature_archive_and_identity_fail_without_activation(self):
        archive, manifest, signature = self.publisher_fixture()
        signature.write_bytes(b'x' * 64)
        with self.assertRaisesRegex(TRUST.TrustError, 'signature verification'):
            TRUST.enroll_sls(self.store, archive, manifest, signature)
        self.assertFalse((self.store / 'slsmassnotifyserver.active.json').exists())
        archive, manifest, signature = self.publisher_fixture()
        archive.write_bytes(archive.read_bytes() + b'corruption')
        with self.assertRaisesRegex(TRUST.TrustError, 'archive hash mismatch'):
            TRUST.enroll_sls(self.store, archive, manifest, signature)
        self.assertFalse((self.store / 'slsmassnotifyserver.active.json').exists())


if __name__ == '__main__':
    unittest.main()
