#!/usr/bin/python3
"""Real signed transitions, expiry and offline recovery with disposable keys."""
import base64
import copy
import importlib.util
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import tarfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('publisher_trust_fixture', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_release_trust.py')
t = importlib.util.module_from_spec(spec)
spec.loader.exec_module(t)


class PublisherTrustTests(unittest.TestCase):
    def setUp(self):
        if os.geteuid() != 0 or os.environ.get('SLS_TEST_NAMESPACE') != 'entered':
            self.skipTest('Root-private fixtures require tools/run_isolated_tests.sh')
        temporary = tempfile.TemporaryDirectory(prefix='sls-publisher-test-', dir='/run')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.now = 1800000000
        self.keys = []
        for number in range(3):
            key = self.root / ('private-' + str(number))
            subprocess.run(['/usr/bin/openssl', 'genpkey', '-algorithm', 'ED25519', '-out', str(key)],
                           check=True, capture_output=True)
            pem = subprocess.run(['/usr/bin/openssl', 'pkey', '-in', str(key), '-pubout'],
                                 check=True, capture_output=True).stdout
            self.keys.append((key, pem, t.key_entry(pem)))
        self.directory = self.root / 'trust'
        self.store = t.TrustStore(self.directory, self.keys[0][1])

    def signed(self, body, number):
        plain, signature = self.root / 'body', self.root / 'signature'
        plain.write_bytes(body)
        subprocess.run(['/usr/bin/openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', str(self.keys[number][0]),
                        '-in', str(plain), '-out', str(signature)], check=True, capture_output=True)
        return signature.read_bytes()

    def release(self, number=0, signing=True, **metadata):
        data = {'schema': 1, 'version': '0.1.5-beta'}
        if signing:
            data['signing'] = {'key_id': self.keys[number][2]['id'], 'issued_at': self.now - 1,
                               'expires_at': self.now + 86400} | metadata
        body = t.canonical(data)
        return body, self.signed(body, number)

    def authenticate(self, release, now=None):
        return t.authenticate_manifest(*release, anchor=self.keys[0][1], directory=self.directory,
                                       now=self.now if now is None else now)

    def transition(self, policy, numbers=(0, 1)):
        return {'schema': 1, 'policy': copy.deepcopy(policy), 'proofs': [{'key_id': self.keys[number][2]['id'],
                    'signature': base64.b64encode(self.signed(t.canonical(policy), number)).decode('ascii')}
                    for number in numbers]}

    def overlap(self):
        self.store.initialize()
        policy = {'schema': 1, 'sequence': 1, 'keys': [self.keys[0][2], self.keys[1][2]]}
        self.store.import_transition(self.transition(policy), self.now)
        return policy

    def test_bootstrap_preserves_existing_releases_and_rejects_wrong_key(self):
        self.assertEqual(self.authenticate(self.release(signing=False))['version'], '0.1.5-beta')
        with self.assertRaisesRegex(ValueError, 'signature'):
            self.authenticate(self.release(1))
        self.assertFalse(self.directory.exists())

    def test_signed_metadata_expiration_future_dates_and_tamper(self):
        for mutation in ({'expires_at': self.now}, {'issued_at': self.now + 301}, {'issued_at': True},
                         {'expires_at': self.now + 3 * 366 * 86400}, {'key_id': 'a' * 64}):
            with self.subTest(mutation=mutation), self.assertRaisesRegex(ValueError, 'metadata'):
                self.authenticate(self.release(**mutation))
        body, signature = self.release()
        with self.assertRaisesRegex(ValueError, 'signature'):
            self.authenticate((body + b' ', signature))

    def test_initialization_is_idempotent_and_requires_metadata(self):
        initialized = self.store.initialize()
        self.assertEqual(self.store.initialize(), initialized)
        self.assertEqual(self.directory.stat().st_mode & 0o777, 0o700)
        self.assertEqual(self.store.state.stat().st_mode & 0o777, 0o600)
        with self.assertRaisesRegex(ValueError, 'metadata'):
            self.authenticate(self.release(signing=False))
        self.authenticate(self.release())

    def test_overlapping_keys_then_irreversible_revocation(self):
        policy = self.overlap()
        self.authenticate(self.release(0)); self.authenticate(self.release(1))
        policy = copy.deepcopy(policy); policy['sequence'] = 2; policy['keys'][0]['revoked_at'] = self.now
        state = self.store.import_transition(self.transition(policy, (1,)), self.now)
        self.assertIn(self.keys[0][2]['id'], state['retired_ids'])
        self.authenticate(self.release(1))
        with self.assertRaisesRegex(ValueError, 'revoked'):
            self.authenticate(self.release(0))
        policy['sequence'] = 3; policy['keys'][0]['revoked_at'] = 0
        with self.assertRaisesRegex(ValueError, 'restored'):
            self.store.import_transition(self.transition(policy, (1,)), self.now)

    def test_unknown_issuer_new_key_without_proof_replay_and_mutated_policy(self):
        self.store.initialize()
        policy = {'schema': 1, 'sequence': 1, 'keys': [self.keys[0][2], self.keys[1][2]]}
        with self.assertRaisesRegex(ValueError, 'possession'):
            self.store.import_transition(self.transition(policy, (0,)), self.now)
        with self.assertRaisesRegex(ValueError, 'signature'):
            self.store.import_transition(self.transition(policy, (2,)), self.now)
        envelope = self.transition(policy); envelope['policy']['sequence'] = 2
        with self.assertRaisesRegex(ValueError, 'sequence'):
            self.store.import_transition(envelope, self.now)
        envelope = self.transition(policy); envelope['policy']['keys'][1]['not_before'] = self.now + 30
        with self.assertRaisesRegex(ValueError, 'signature'):
            self.store.import_transition(envelope, self.now)
        self.overlap()
        with self.assertRaisesRegex(ValueError, 'sequence'):
            self.store.import_transition(self.transition(policy), self.now)

    def test_not_yet_valid_and_expired_key(self):
        self.store.initialize()
        entry = self.keys[1][2] | {'not_before': self.now + 10, 'expires_at': self.now + 100}
        policy = {'schema': 1, 'sequence': 1, 'keys': [self.keys[0][2], entry]}
        self.store.import_transition(self.transition(policy), self.now)
        with self.assertRaisesRegex(ValueError, 'signature'):
            self.authenticate(self.release(1))
        release = self.release(1, issued_at=self.now + 10)
        self.authenticate(release, self.now + 10)
        with self.assertRaisesRegex(ValueError, 'signature'):
            self.authenticate(release, self.now + 100)

    def test_missing_initialized_state_links_fifo_and_unprotected_directory(self):
        self.store.initialize(); body = self.store.state.read_bytes(); self.store.state.unlink()
        with self.assertRaisesRegex(ValueError, 'incomplete'):
            self.authenticate(self.release())
        self.store.state.symlink_to(self.root / 'missing')
        with self.assertRaises(OSError):
            self.store.load()
        self.store.state.unlink(); os.mkfifo(self.store.state, 0o600)
        with self.assertRaisesRegex(ValueError, 'regular'):
            self.store.load()
        self.store.state.unlink(); self.store.state.write_bytes(body); self.store.state.chmod(0o600)
        self.directory.chmod(0o777)
        with self.assertRaisesRegex(ValueError, 'protected'):
            self.authenticate(self.release())
        self.directory.chmod(0o700)

    def test_offline_recovery_requires_review_and_retires_every_old_key(self):
        self.overlap()
        with self.assertRaisesRegex(ValueError, 'fingerprint'):
            self.store.recover_offline(self.keys[2][1], 'a' * 64, 1, 'Fixture recovery after key compromise', self.now)
        with self.assertRaisesRegex(ValueError, 'sequence'):
            self.store.recover_offline(self.keys[2][1], self.keys[2][2]['id'], 0, 'Fixture recovery after key compromise', self.now)
        updated = self.store.recover_offline(self.keys[2][1], self.keys[2][2]['id'], 1,
                                            'Fixture recovery after key compromise', self.now)
        self.assertEqual(updated['policy']['sequence'], 2)
        self.assertEqual(updated['last_recovery']['from_sequence'], 1)
        for number in (0, 1):
            with self.assertRaisesRegex(ValueError, 'revoked'):
                self.authenticate(self.release(number))
        self.authenticate(self.release(2, issued_at=self.now))

    def test_actual_installer_uses_bootstrap_then_reviewed_keys_and_never_bypasses_revocation(self):
        source = (ROOT / 'tools/install_release.sh').read_text().split('verify_publisher_release() {', 1)[1]
        source = source.split("/usr/bin/python3 -I - <<'PY'\n", 1)[1].split('\nPY\n', 1)[0]
        original = "public_key = " + repr(t.ANCHOR_PEM)
        self.assertIn(original, source)
        source = source.replace(original, "public_key = " + repr(self.keys[0][1]))
        runtime = self.root / 'runtime'; runtime.mkdir(mode=0o700)
        helper = runtime / 'sls_release_trust.py'
        source = source.replace("Path('/usr/local/bin/sls_mass_notify/sls_release_trust.py')", 'Path(' + repr(str(helper)) + ')')
        source = source.replace("Path('/var/lib/sls-mass-notify-trust')", 'Path(' + repr(str(self.directory)) + ')')
        assets = self.root / 'assets'; assets.mkdir(mode=0o700)
        installer, package = self.root / 'installer', self.root / 'package'
        installer.write_bytes(b'fixture installer'); package.write_bytes(b'fixture archive')
        now = int(time.time())
        def execute(number=0, expired=False):
            value = {'schema':1, 'version':'0.1.5-beta', 'tag':'slsmassnotifyserver-0.1.5-beta',
                     'package':'slsmassnotifyserver-0.1.5-beta.tgz',
                     'installer_sha256':hashlib.sha256(installer.read_bytes()).hexdigest(),
                     'package_sha256':hashlib.sha256(package.read_bytes()).hexdigest(),
                     'signing':{'key_id':self.keys[number][2]['id'],'issued_at':now-30,'expires_at':now-1 if expired else now+86400}}
            body = t.canonical(value)
            (assets/'release-manifest.json').write_bytes(body)
            (assets/'release-manifest.sig').write_bytes(self.signed(body, number))
            return subprocess.run(['/usr/bin/python3','-I','-c',source], capture_output=True,
                env={'SLS_RELEASE_URL':'','SLS_RELEASE_DIRECTORY':str(assets),
                     'SLS_RELEASE_INSTALLER':str(installer),'SLS_RELEASE_TGZ':str(package)}, timeout=15)
        self.assertEqual(execute().returncode, 0)
        self.assertNotEqual(execute(expired=True).returncode, 0)
        self.overlap()
        helper.write_text((ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_release_trust.py').read_text()
                          .replace("DIRECTORY = Path('/var/lib/sls-mass-notify-trust')", 'DIRECTORY = Path(' + repr(str(self.directory)) + ')'))
        self.assertEqual(execute(1).returncode, 0)
        policy = copy.deepcopy(self.store.load()[0]['policy']); policy['sequence']=2; policy['keys'][0]['revoked_at']=self.now
        # Use the actual current clock for installer key validity.
        policy['keys'][0]['revoked_at']=now
        self.store.import_transition(self.transition(policy,(1,)),now)
        self.assertNotEqual(execute(0).returncode,0)
        self.assertEqual(execute(1).returncode,0)
        helper.unlink()
        self.assertIn(b'Established publisher trust',execute().stderr)

    def test_protected_artifact_enrollment_uses_rotated_authority_too(self):
        self.overlap()
        spec = importlib.util.spec_from_file_location('rotated_artifact_enrollment', ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_module_trust.py')
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        module.PUBLIC_KEY = self.keys[0][1]
        archive = self.root/'release.tgz'
        with tarfile.open(archive,'w:gz') as handle:
            body = b'<module><rawname>slsmassnotifyserver</rawname><version>0.1.5-beta</version></module>'
            entry = tarfile.TarInfo('slsmassnotifyserver/module.xml'); entry.size=len(body)
            handle.addfile(entry,io.BytesIO(body))
        value = {'schema':1,'version':'0.1.5-beta','tag':'slsmassnotifyserver-0.1.5-beta',
                 'package':'slsmassnotifyserver-0.1.5-beta.tgz','package_sha256':hashlib.sha256(archive.read_bytes()).hexdigest(),
                 'signing':{'key_id':self.keys[1][2]['id'],'issued_at':int(time.time())-1,'expires_at':int(time.time())+86400}}
        manifest, signature = self.root/'release-manifest.json',self.root/'release-manifest.sig'
        manifest.write_bytes(t.canonical(value)); signature.write_bytes(self.signed(manifest.read_bytes(),1))
        self.assertTrue(module.enroll_sls(self.directory,archive,manifest,signature))
        value['signing']['key_id']=self.keys[0][2]['id'];manifest.write_bytes(t.canonical(value));signature.write_bytes(self.signed(manifest.read_bytes(),0))
        policy = copy.deepcopy(self.store.load()[0]['policy']);policy['sequence']=2;policy['keys'][0]['revoked_at']=int(time.time())
        self.store.import_transition(self.transition(policy,(1,)),int(time.time()))
        with self.assertRaisesRegex(module.TrustError,'revoked'):
            module.enroll_sls(self.directory,archive,manifest,signature)


if __name__ == '__main__':
    unittest.main()
