#!/usr/bin/python3
"""Sign a reviewed publisher policy with the existing key and each new key."""
import argparse
import base64
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('sls_publisher_trust', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_release_trust.py')
trust = importlib.util.module_from_spec(spec)
spec.loader.exec_module(trust)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--policy', type=Path, required=True)
    parser.add_argument('--key', type=Path, action='append', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    policy = trust.validate_policy(json.loads(trust.bounded_read(args.policy)))
    if not 1 <= len(args.key) <= 4:
        parser.error('Supply the current signing key and each newly added key (maximum four).')
    proofs = []
    with tempfile.TemporaryDirectory(prefix='sls-trust-policy-signing-') as temporary:
        body = Path(temporary) / 'policy'; body.write_bytes(trust.canonical(policy))
        for key in args.key:
            info = key.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != os.geteuid() \
                    or info.st_mode & 0o077 or ROOT in key.resolve().parents:
                parser.error('Private keys must be owner-private regular files outside the repository.')
            public = subprocess.run(['/usr/bin/openssl', 'pkey', '-in', str(key), '-pubout'],
                                    check=True, capture_output=True, timeout=10).stdout
            identifier = trust.key_entry(public)['id']
            signed = Path(temporary) / 'signature'
            subprocess.run(['/usr/bin/openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', str(key),
                            '-in', str(body), '-out', str(signed)], check=True, capture_output=True, timeout=10)
            proofs.append({'key_id': identifier, 'signature': base64.b64encode(signed.read_bytes()).decode('ascii')})
    envelope = {'schema': 1, 'policy': policy, 'proofs': proofs}
    # Refuse to overwrite an existing reviewed transition or follow a link.
    descriptor = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'wb') as handle:
        handle.write(trust.canonical(envelope)); handle.flush(); os.fsync(handle.fileno())
    print('Signed trust transition saved. Review its fingerprints and sequence before importing on any PBX.')


if __name__ == '__main__':
    main()
