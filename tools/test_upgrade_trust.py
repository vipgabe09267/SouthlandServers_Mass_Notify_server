#!/usr/bin/python3
"""Signed old-to-new upgrade fixtures; no live PBX paths, network or services."""
import argparse
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
BIN = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'


def package(path, name, contents):
    with tarfile.open(path, 'w:gz') as archive:
        for relative, body in contents.items():
            member = tarfile.TarInfo(name + '/' + relative)
            member.size = len(body)
            member.mode = 0o644
            archive.addfile(member, io.BytesIO(body))
    path.chmod(0o600)


class UpgradeTrustTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory(prefix='sls-upgrade-signed-')
        cls.base = Path(cls.temporary.name)
        cls.helpers = cls.base / 'helpers'
        cls.helpers.mkdir(mode=0o700)
        for name in ('sls_upgrade_trust', 'sls_module_trust', 'sls_release_trust', 'sls_installer_recovery'):
            shutil.copyfile(BIN / (name + '.py'), cls.helpers / (name + '.py'))
        spec = importlib.util.spec_from_file_location('upgrade_fixture', cls.helpers / 'sls_upgrade_trust.py')
        cls.upgrade = importlib.util.module_from_spec(spec); spec.loader.exec_module(cls.upgrade)
        cls.trust = cls.upgrade.TRUST
        cls.key = cls.base / 'ed25519.pem'
        subprocess.run(['openssl', 'genpkey', '-algorithm', 'Ed25519', '-out', str(cls.key)], check=True, capture_output=True)
        cls.trust.PUBLIC_KEY = subprocess.run(['openssl', 'pkey', '-in', str(cls.key), '-pubout'], check=True, capture_output=True).stdout
        cls.gpg = cls.base / 'gnupg'; cls.gpg.mkdir(mode=0o700)
        cls.gpg_command = ['gpg', '--no-options', '--homedir', str(cls.gpg), '--batch', '--pinentry-mode', 'loopback', '--passphrase', '']
        subprocess.run(cls.gpg_command + ['--quick-generate-key', 'SLS upgrade fixture <fixture@example.invalid>', 'rsa2048', 'sign', '0'], check=True, capture_output=True, timeout=30)
        key = subprocess.run(cls.gpg_command + ['--armor', '--export'], check=True, capture_output=True).stdout
        (cls.helpers / 'freepbx-mirror-signing.pub').write_bytes(key)
        fingerprints = subprocess.run(cls.gpg_command + ['--with-colons', '--fingerprint'], check=True, capture_output=True, text=True).stdout
        cls.upgrade.UPSTREAM_SIGNER = next(line.split(':')[9] for line in fingerprints.splitlines() if line.startswith('fpr:'))
        cls.upgrade.UPSTREAM_KEY_SHA256 = cls.trust.digest(key)

    @classmethod
    def tearDownClass(cls):
        subprocess.run(['gpgconf', '--homedir', str(cls.gpg), '--kill', 'gpg-agent'], capture_output=True, timeout=10)
        cls.temporary.cleanup()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='case-', dir=self.base)
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.fs = self.root / 'fs'; self.web = self.fs / 'var/www/html'
        self.offline = self.root / 'offline'; self.offline.mkdir(mode=0o700)
        self.stock = {}
        for module in ('dashboard', 'framework'):
            contents = {'module.xml': ('<module><rawname>' + module + '</rawname><version>17.0.5</version></module>').encode(), 'install.php': b'<?php // authenticated stock fixture\n'}
            if module == 'framework':
                contents.update({self.upgrade.MENU_NAME: b"<?php\n\telse if ($a == 'other')\n\t\treturn true;\n", self.upgrade.SPINNER: b'GIF89a fixture',
                                 'amp_conf/bin/fwconsole': b'installer-only mapping', 'upgrades/15.0/upgrade.php': b'installer-only upgrade', 'installlib/installer.class.php': b'installer only', 'install': b'installer only'})
            self.stock[module] = contents
            plain = self.root / (module + '.tgz'); package(plain, module, contents)
            subprocess.run(self.gpg_command + ['--output', str(self.offline / (module + '-17.0.5.tgz.gpg')), '--sign', str(plain)], check=True, capture_output=True, timeout=20)
            for name, body in contents.items():
                if module == 'framework' and not self.upgrade.deployed_framework(name): continue
                target = self.web / (name[16:] if module == 'framework' and name.startswith('amp_conf/htdocs/') else 'admin/modules/' + module + '/' + name)
                target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(body)
        self.old = {'module.xml': b'<module><rawname>slsmassnotifyserver</rawname><version>0.1.4-beta</version></module>',
                    'bin/sls_mass_notify_maintenance.sh': b'authenticated old maintenance\n', 'bin/sls_mass_notify_update.sh': b'authenticated old updater\n',
                    **{'dashboard/' + name: ('old widget ' + name).encode() for name in self.upgrade.WIDGETS}}
        self.new = {**self.old, 'module.xml': b'<module><rawname>slsmassnotifyserver</rawname><version>0.1.5-beta</version></module>',
                    **{'dashboard/' + name: ('new widget ' + name).encode() for name in self.upgrade.WIDGETS}}
        self.previous = self.release('previous', '0.1.4-beta', self.old)
        self.candidate = self.release('candidate', '0.1.5-beta', self.new)
        for name, body in self.old.items():
            target = self.web / 'admin/modules/slsmassnotifyserver' / name
            target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(body)
        for name in self.upgrade.WIDGETS:
            target = self.web / 'admin/modules/dashboard' / name
            target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(self.old['dashboard/' + name])
        menu = self.web / 'admin/views/menu_items.php'; menu.write_bytes(self.upgrade.menu_overlay(menu.read_bytes()))
        self.data = self.fs / 'var/lib/asterisk/SLS_Mass_Notifications_Plugin'; self.data.mkdir(parents=True)
        self.preserved = {'mass-notifications.config': b'active protected configuration', 'mass-notifications.pending.config': b'pending protected configuration', 'delivery-history.json': b'current delivery receipts'}
        for name, body in self.preserved.items(): (self.data / name).write_bytes(body)
        for name, digest in self.upgrade.runtime_files(self.old).items():
            body = self.old['bin/' + Path(name).name]
            target = self.fs / name[1:]; target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(body); target.chmod(0o755)
        self.cron = b'12 1 * * * /usr/bin/true\n* * * * * /usr/bin/timeout 900 /usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh\n17 */6 * * * /usr/bin/timeout 1800 /usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh\n'
        self.original_root_approval = self.upgrade.previous_root_approval
        self.workspace = self.root / 'review'
        self.args = argparse.Namespace(workspace=self.workspace, trust_root=self.root / 'live-trust', archive=self.candidate[0], manifest=self.candidate[1], signature=self.candidate[2])
        self.environment = {'SLS_MASS_NOTIFY_UPSTREAM_PACKAGES': str(self.offline),
                            'SLS_MASS_NOTIFY_PREVIOUS_TGZ': str(self.previous[0]), 'SLS_MASS_NOTIFY_PREVIOUS_MANIFEST': str(self.previous[1]), 'SLS_MASS_NOTIFY_PREVIOUS_MANIFEST_SIGNATURE': str(self.previous[2])}

    def release(self, directory, version, contents):
        root = self.root / directory; root.mkdir(mode=0o700)
        archive = root / ('slsmassnotifyserver-' + version + '.tgz'); package(archive, 'slsmassnotifyserver', contents)
        manifest = root / 'release-manifest.json'; signature = root / 'release-manifest.sig'
        manifest.write_text(json.dumps({'schema': 1, 'version': version, 'tag': 'slsmassnotifyserver-' + version, 'package': archive.name, 'package_sha256': self.trust.digest(archive.read_bytes())}))
        subprocess.run(['openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', str(self.key), '-in', str(manifest), '-out', str(signature)], check=True, capture_output=True)
        return archive, manifest, signature

    def prepare(self):
        with patch.object(self.upgrade, 'WEB', self.web), patch.dict(os.environ, self.environment, clear=True), patch.object(self.upgrade, 'previous_root_approval', side_effect=lambda data, contents: self.original_root_approval(data, contents, prefix=self.fs, cron=self.cron)):
            self.upgrade.prepare(self.args)

    def assert_preserved(self):
        for name, body in self.preserved.items(): self.assertEqual((self.data / name).read_bytes(), body)
        self.assertFalse(self.args.trust_root.exists())

    def test_absent_trust_signed_previous_overlays_and_mappings_bootstrap_without_mutation(self):
        self.prepare(); self.assert_preserved()
        approved = json.loads((self.workspace / 'approvals.json').read_bytes())
        self.assertEqual(approved['approved']['dashboard']['files'][self.upgrade.WIDGETS[0]]['sha256'], self.trust.digest(self.new['dashboard/' + self.upgrade.WIDGETS[0]]))
        self.assertEqual(approved['prior']['dashboard']['files'][self.upgrade.WIDGETS[0]]['sha256'], self.trust.digest(self.old['dashboard/' + self.upgrade.WIDGETS[0]]))
        framework = approved['approved']['framework']
        self.assertNotIn('upgrades/15.0/upgrade.php', framework['files'])
        self.assertNotIn('amp_conf/bin/fwconsole', framework['files'])
        self.assertEqual(framework['files'][self.upgrade.MENU_NAME]['target'], 'admin/views/menu_items.php')
        with patch.object(self.upgrade, 'WEB', self.web): self.upgrade.verify_previous(self.workspace)

    def test_previous_root_mismatch_and_unknown_executable_stop_before_mutation(self):
        target = self.fs / 'usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh'
        target.write_bytes(b'unknown runtime')
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'differs from its signed'): self.prepare()
        self.assert_preserved()
        target.write_bytes(self.old['bin/sls_mass_notify_update.sh'])
        self.args.workspace = self.root / 'retry-review'
        (target.parent / 'extra.py').write_bytes(b'unknown import')
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'unapproved files'): self.prepare()
        self.assert_preserved()
        report = json.loads((self.args.workspace / 'runtime-review-required.json').read_bytes())
        self.assertFalse(report['approved'])
        self.assertEqual(report['differences'][0]['path'], '/usr/local/bin/sls_mass_notify/extra.py')

    def test_missing_signed_helper_restored_before_snapshot_without_changing_existing_files(self):
        target = self.fs / 'usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh'
        target.unlink()
        self.prepare(); self.assert_preserved()
        self.assertFalse(target.exists(), 'Trust preparation must remain read-only')
        approved = json.loads((self.workspace / 'previous-root-approval.json').read_bytes())
        self.assertEqual(approved['missing_files'], ['/usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh'])
        self.upgrade.normalize_previous_runtime(self.workspace, prefix=self.fs)
        self.assertEqual(target.read_bytes(), self.old['bin/sls_mass_notify_update.sh'])
        self.assertEqual(target.stat().st_mode & 0o777, 0o755)
        self.assertEqual(target.stat().st_nlink, 1)
        self.assert_preserved()
        self.upgrade.normalize_previous_runtime(self.workspace, prefix=self.fs)

    def test_absent_runtime_rebuilt_only_from_authenticated_previous_sources(self):
        runtime = self.fs / 'usr/local/bin/sls_mass_notify'
        shutil.rmtree(runtime)
        self.prepare(); self.assertFalse(runtime.exists())
        self.upgrade.normalize_previous_runtime(self.workspace, prefix=self.fs)
        self.assertEqual(self.upgrade.runtime_inventory(runtime), {Path(name).name for name in self.upgrade.runtime_files(self.old)})
        self.assert_preserved()

    def test_cache_quarantine_keeps_bytecode_out_of_execution_approval_and_rollback(self):
        # Extend the signed fixture with an imported helper, as in the real014 runtime.
        self.old['bin/sls_mass_notify/sls_config.py'] = b'VALUE = 1\n'
        self.previous = self.release('previous-python', '0.1.4-beta', self.old)
        for name in ('bin/sls_mass_notify/sls_config.py',):
            path = self.web / 'admin/modules/slsmassnotifyserver' / name
            path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(self.old[name])
        runtime = self.fs / 'usr/local/bin/sls_mass_notify'
        (runtime / 'sls_config.py').write_bytes(self.old['bin/sls_mass_notify/sls_config.py'])
        self.environment['SLS_MASS_NOTIFY_PREVIOUS_TGZ'], self.environment['SLS_MASS_NOTIFY_PREVIOUS_MANIFEST'], self.environment['SLS_MASS_NOTIFY_PREVIOUS_MANIFEST_SIGNATURE'] = map(str, self.previous)
        cache = runtime / '__pycache__/sls_config.cpython-311.pyc'
        cache.parent.mkdir(); cache.write_bytes(b'untrusted bytecode must not execute or be restored')
        self.prepare(); self.assertTrue(cache.exists()); self.assert_preserved()
        approved = json.loads((self.workspace / 'previous-root-approval.json').read_bytes())
        self.assertNotIn('/usr/local/bin/sls_mass_notify/__pycache__/sls_config.cpython-311.pyc', approved['files'])
        self.assertEqual(len(approved['quarantine_caches']), 1)
        self.upgrade.normalize_previous_runtime(self.workspace, prefix=self.fs)
        self.assertFalse(cache.exists())
        evidence = json.loads((self.workspace / 'runtime-cache-recovery/inventory.json').read_bytes())
        self.assertFalse(evidence['execution_approved'])
        digest = self.trust.digest(('/usr/local/bin/sls_mass_notify/__pycache__/' + cache.name).encode())
        self.assertEqual((self.workspace / 'runtime-cache-recovery' / digest).read_bytes(), b'untrusted bytecode must not execute or be restored')
        self.assert_preserved()

    def test_unknown_cache_content_links_and_writable_entries_remain_blocked(self):
        runtime = self.fs / 'usr/local/bin/sls_mass_notify'
        cache = runtime / '__pycache__/injected.py'
        cache.parent.mkdir(); cache.write_bytes(b'unknown code')
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'unapproved files'): self.prepare()
        self.assert_preserved(); cache.unlink()
        cache.symlink_to(runtime / 'sls_mass_notify_update.sh')
        self.args.workspace = self.root / 'link-review'
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'Unsafe link'): self.prepare()
        self.assert_preserved(); cache.unlink()
        cache.write_bytes(b'unknown code'); cache.chmod(0o666)
        self.args.workspace = self.root / 'writable-review'
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'Unprotected previous'): self.prepare()
        self.assert_preserved()

    def test_repair_never_overwrites_a_changed_file_or_trusts_tampered_payload(self):
        target = self.fs / 'usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh'
        target.unlink(); self.prepare()
        repair = self.workspace / 'previous-runtime-repairs' / self.trust.digest('/usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh'.encode())
        original = repair.read_bytes(); repair.write_bytes(b'changed private repair payload')
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'changed after preflight'):
            self.upgrade.normalize_previous_runtime(self.workspace, prefix=self.fs)
        self.assertFalse(target.exists()); repair.write_bytes(original)
        target.write_bytes(b'new unapproved destination')
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'changed after preflight'):
            self.upgrade.normalize_previous_runtime(self.workspace, prefix=self.fs)
        self.assertEqual(target.read_bytes(), b'new unapproved destination'); self.assert_preserved()

    def test_runtime_normalization_precedes_snapshot_and_inventory_only_remains_read_only(self):
        installer = (ROOT / 'tools/install_release.sh').read_text()
        preparation = installer.split('prepare_authenticated_installer() {', 1)[1].split('\n# The protected log opener', 1)[0]
        self.assertLess(preparation.index('SLS_MASS_NOTIFY_INVENTORY_ONLY'), preparation.index('--normalize-runtime'))
        self.assertLess(preparation.index('--normalize-runtime'), preparation.index('prepare_install_recovery ||'))

    def test_fresh_normalization_leaves_unrelated_runtime_untouched(self):
        self.workspace.mkdir(mode=0o700)
        self.upgrade.write_json(self.workspace / 'previous-root-approval.json',
                                {'schema': 1, 'source': 'fresh-install', 'files': {}, 'root_jobs': []})
        self.upgrade.normalize_previous_runtime(self.workspace, prefix=self.fs)
        self.assertEqual(self.upgrade.runtime_inventory(self.fs / 'usr/local/bin/sls_mass_notify'),
                         {Path(name).name for name in self.upgrade.runtime_files(self.old)})

    def test_failed_repair_write_does_not_publish_a_partial_runtime_helper(self):
        target = self.fs / 'usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh'
        target.unlink(); self.prepare()
        with patch.object(self.upgrade.os, 'fsync', side_effect=OSError('fixture: no space left on device')):
            with self.assertRaises(OSError):
                self.upgrade.normalize_previous_runtime(self.workspace, prefix=self.fs)
        self.assertFalse(target.exists())
        self.assertEqual(list(target.parent.glob('.previous-runtime-*')), [])
        self.assert_preserved()

    def test_unknown_stock_changes_produce_review_report_without_becoming_approved(self):
        target = self.web / 'admin/modules/dashboard/install.php'; target.write_bytes(b'local reviewed work required')
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'Unreviewed PBX differences'): self.prepare()
        self.assertEqual(target.read_bytes(), b'local reviewed work required'); self.assert_preserved()
        report = json.loads((self.workspace / 'review-required.json').read_bytes())
        self.assertFalse(report['approved']); self.assertIn('install.php', [row['path'] for row in report['differences']])
        self.assertFalse((self.workspace / 'dashboard.json').exists())

    def test_spinner_alias_requires_same_signed_target_bytes(self):
        spinner = self.web / 'admin/images/spinner.gif'; spinner.unlink()
        target = self.web / 'admin/modules/core/images/spinner.gif'; target.parent.mkdir(parents=True); target.write_bytes(self.stock['framework'][self.upgrade.SPINNER])
        spinner.symlink_to(target)
        self.prepare()
        inventory = json.loads((self.workspace / 'framework.json').read_bytes())
        self.assertEqual(inventory['files'][self.upgrade.SPINNER]['link_target'], 'admin/modules/core/images/spinner.gif')
        target.write_bytes(b'changed GIF')
        self.args.workspace = self.root / 'changed-review'
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'Unreviewed PBX differences'): self.prepare()

    def test_tampered_previous_release_rejected_and_retry_accepts_signed_original(self):
        original = self.previous[2].read_bytes(); self.previous[2].write_bytes(b'0' * 64)
        with self.assertRaises(self.trust.TrustError): self.prepare()
        self.assert_preserved(); self.previous[2].write_bytes(original)
        self.args.workspace = self.root / 'retry-review'; self.prepare(); self.assert_preserved()

    def test_unverified_upstream_and_wrong_version_are_rejected(self):
        path = self.offline / 'dashboard-17.0.5.tgz.gpg'
        body = path.read_bytes(); path.write_bytes(body[:-32] + b'0' * 32)
        with self.assertRaises(self.upgrade.UpgradeError): self.prepare()
        self.assert_preserved(); path.write_bytes(body)
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'does not match'):
            self.upgrade.upstream_contents(path, 'dashboard', '17.0.99', self.root)

    def test_revoked_or_unapproved_prior_jobs_never_gain_restore_approval(self):
        self.cron += b'* * * * * /usr/local/bin/sls_mass_notify/arbitrary.php\n'
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'needs independent review'): self.prepare()
        self.assert_preserved()

    def test_local_overlay_preserved_when_explicit_independent_inventory_matches(self):
        self.prepare()
        local = self.web / 'admin/modules/framework/local-branding.php'; local.write_bytes(b'reviewed fixture branding')
        inventory = json.loads((self.workspace / 'framework.json').read_bytes())
        inventory['files']['local-branding.php'] = {'sha256': self.trust.digest(b'reviewed fixture branding'), 'target': 'admin/modules/framework/local-branding.php'}
        path = self.root / 'reviewed-framework.json'; digest = self.upgrade.write_json(path, inventory)
        self.environment.update(SLS_MASS_NOTIFY_FRAMEWORK_INVENTORY=str(path), SLS_MASS_NOTIFY_FRAMEWORK_INVENTORY_SHA256=digest)
        self.args.workspace = self.root / 'reviewed-retry'; self.prepare()
        self.assertEqual(local.read_bytes(), b'reviewed fixture branding'); self.assert_preserved()
        local.write_bytes(b'unknown later edit')
        with patch.object(self.upgrade, 'WEB', self.web), self.assertRaises(self.trust.TrustError): self.upgrade.verify_previous(self.args.workspace)

    def test_mismatched_installed_previous_module_is_not_auto_approved(self):
        target = self.web / 'admin/modules/slsmassnotifyserver/bin/sls_mass_notify_update.sh'; target.write_bytes(b'unknown live code')
        with self.assertRaisesRegex(self.upgrade.UpgradeError, 'Installed previous SLS file differs'): self.prepare()
        self.assert_preserved()


if __name__ == '__main__': unittest.main()
