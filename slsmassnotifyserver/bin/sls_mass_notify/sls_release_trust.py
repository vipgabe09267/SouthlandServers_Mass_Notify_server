#!/usr/bin/python3
"""Root-reviewed publisher transitions, irreversible revocation and release expiry.

The existing self-signing key remains the bootstrap authority. Installing this
helper never changes keys or imports a downloaded trust policy automatically.
"""
import argparse
import base64
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import time

DIRECTORY = Path('/var/lib/sls-mass-notify-trust')
ANCHOR_PEM = b'-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAFMDOgOaBGcaI8d+v0w/NX4RbwGlBsoktNc2V2F8UkUQ=\n-----END PUBLIC KEY-----\n'
DER_PREFIX = bytes.fromhex('302a300506032b6570032100')
MAX_BYTES = 16384


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True) + '\n').encode('ascii')


def public_der(pem):
    result = subprocess.run(['/usr/bin/openssl', 'pkey', '-pubin', '-outform', 'DER'], input=pem,
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True, timeout=10)
    if len(result.stdout) != 44 or not result.stdout.startswith(DER_PREFIX):
        raise ValueError('Publisher keys must use Ed25519')
    return result.stdout


def key_entry(pem, not_before=0, expires_at=0, revoked_at=0):
    der = public_der(pem)
    return {'id': hashlib.sha256(der).hexdigest(), 'public_key': base64.b64encode(der).decode('ascii'),
            'not_before': not_before, 'expires_at': expires_at, 'revoked_at': revoked_at}


def validate_policy(value):
    if not isinstance(value, dict) or set(value) != {'schema', 'sequence', 'keys'} \
            or type(value['schema']) is not int or value['schema'] != 1 \
            or type(value['sequence']) is not int or not 0 <= value['sequence'] <= 1000000 \
            or not isinstance(value['keys'], list) or not 1 <= len(value['keys']) <= 4:
        raise ValueError('Invalid publisher policy schema or key capacity')
    identifiers = set()
    for entry in value['keys']:
        if not isinstance(entry, dict) or set(entry) != {'id', 'public_key', 'not_before', 'expires_at', 'revoked_at'}:
            raise ValueError('Invalid publisher key fields')
        try:
            der = base64.b64decode(entry['public_key'], validate=True)
        except (TypeError, ValueError) as error:
            raise ValueError('Invalid publisher key encoding') from error
        if len(der) != 44 or not der.startswith(DER_PREFIX) or entry['id'] != hashlib.sha256(der).hexdigest() \
                or entry['id'] in identifiers:
            raise ValueError('Invalid or duplicate publisher key identity')
        identifiers.add(entry['id'])
        for field in ('not_before', 'expires_at', 'revoked_at'):
            if type(entry[field]) is not int or not 0 <= entry[field] <= 4102444800:
                raise ValueError('Publisher key dates must be UTC Unix timestamps')
        if entry['expires_at'] and entry['expires_at'] <= entry['not_before']:
            raise ValueError('Publisher key validity window is empty')
    return value


def active(entry, now):
    return entry['not_before'] <= now and (not entry['expires_at'] or now < entry['expires_at']) \
        and (not entry['revoked_at'] or now < entry['revoked_at'])


def verify_bytes(body, signature, entry):
    if len(body) > MAX_BYTES or len(signature) != 64:
        raise ValueError('Invalid signed publisher metadata size')
    with tempfile.TemporaryDirectory(prefix='sls-publisher-signature-') as temporary:
        root = Path(temporary)
        (root / 'key.der').write_bytes(base64.b64decode(entry['public_key'], validate=True))
        (root / 'body').write_bytes(body)
        (root / 'signature').write_bytes(signature)
        result = subprocess.run(['/usr/bin/openssl', 'pkeyutl', '-verify', '-rawin', '-pubin', '-keyform', 'DER',
                                 '-inkey', str(root / 'key.der'), '-in', str(root / 'body'),
                                 '-sigfile', str(root / 'signature')], stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=10)
    return result.returncode == 0


def bounded_read(path, *, protected=False):
    flags = os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK
    descriptor = os.open(path, flags)
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > MAX_BYTES \
                or (protected and (info.st_uid != 0 or info.st_mode & 0o077)):
            raise ValueError('Publisher metadata must be a bounded regular file; trust state must be root-private')
        with os.fdopen(os.dup(descriptor), 'rb') as handle:
            body = handle.read(MAX_BYTES + 1)
        if len(body) > MAX_BYTES:
            raise ValueError('Publisher metadata exceeds 16 KiB')
        return body
    finally:
        os.close(descriptor)


class TrustStore:
    def __init__(self, directory=DIRECTORY, anchor=ANCHOR_PEM):
        self.directory = Path(directory)
        self.anchor = key_entry(anchor)
        self.state = self.directory / 'publisher-trust.json'
        self.marker = self.directory / 'release-trust.initialized'

    def protected_directory(self):
        if not self.directory.is_absolute() or self.directory.resolve() != self.directory:
            raise ValueError('Publisher trust directory cannot contain symbolic links')
        for path in (self.directory, *self.directory.parents):
            info = path.lstat()
            if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 \
                    or (info.st_mode & 0o022 and (path == self.directory or not info.st_mode & stat.S_ISVTX)):
                raise ValueError('Publisher trust directory and parents must be protected by root')
        if self.directory.stat().st_mode & 0o077:
            raise ValueError('Publisher trust directory must be root-private (0700)')

    def load(self):
        empty = {'policy': {'schema': 1, 'sequence': 0, 'keys': [self.anchor]}, 'retired_ids': []}
        if not self.directory.exists() and not self.directory.is_symlink():
            return empty, False
        self.protected_directory()
        state_exists = self.state.exists() or self.state.is_symlink()
        marker_exists = self.marker.exists() or self.marker.is_symlink()
        if not state_exists and not marker_exists:
            return empty, False
        if not state_exists or not marker_exists:
            raise ValueError('Established publisher trust state is incomplete; restore it before updating')
        if bounded_read(self.marker, protected=True) != (self.anchor['id'] + '\n').encode('ascii'):
            raise ValueError('Publisher trust bootstrap identity changed')
        state = json.loads(bounded_read(self.state, protected=True))
        if not isinstance(state, dict) or set(state) not in ({'policy', 'retired_ids'}, {'policy', 'retired_ids', 'last_recovery'}) \
                or not isinstance(state['retired_ids'], list) or len(state['retired_ids']) > 64 \
                or len(set(state['retired_ids'])) != len(state['retired_ids']) \
                or any(not isinstance(value, str) or not re.fullmatch('[a-f0-9]{64}', value) for value in state['retired_ids']):
            raise ValueError('Invalid publisher trust ledger')
        validate_policy(state['policy'])
        for entry in state['policy']['keys']:
            if entry['id'] in state['retired_ids'] and not entry['revoked_at']:
                raise ValueError('Revoked publisher authority cannot be restored')
        return state, True

    def _write(self, path, body):
        descriptor, temporary = tempfile.mkstemp(prefix='.publisher-', dir=self.directory)
        try:
            os.fchmod(descriptor, 0o600)
            with os.fdopen(descriptor, 'wb') as handle:
                handle.write(body); handle.flush(); os.fsync(handle.fileno())
            os.replace(temporary, path)
            parent = os.open(self.directory, os.O_DIRECTORY | os.O_RDONLY | os.O_NOFOLLOW)
            try:
                os.fsync(parent)
            finally:
                os.close(parent)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)

    def _lock(self):
        if os.geteuid() != 0:
            raise ValueError('Only root can initialize or change publisher trust')
        self.directory.mkdir(mode=0o700, exist_ok=True)
        self.protected_directory()
        descriptor = os.open(self.directory / 'publisher-trust.lock',
                             os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK, 0o600)
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != 0 or info.st_mode & 0o077:
            os.close(descriptor); raise ValueError('Unsafe publisher trust lock')
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        return descriptor

    def initialize(self):
        lock = self._lock()
        try:
            state, initialized = self.load()
            if initialized:
                return state
            # Write the permanent marker first: interruption fails closed rather
            # than silently returning to the single-key bootstrap policy.
            self._write(self.marker, (self.anchor['id'] + '\n').encode('ascii'))
            self._write(self.state, canonical(state))
            return state
        finally:
            os.close(lock)

    def import_transition(self, envelope, now=None):
        now = int(time.time() if now is None else now)
        if not isinstance(envelope, dict) or set(envelope) != {'schema', 'policy', 'proofs'} \
                or type(envelope['schema']) is not int or envelope['schema'] != 1 \
                or not isinstance(envelope['proofs'], list) or not 1 <= len(envelope['proofs']) <= 4:
            raise ValueError('Invalid signed publisher transition')
        policy = validate_policy(envelope['policy']); body = canonical(policy)
        lock = self._lock()
        try:
            state, initialized = self.load()
            if not initialized:
                raise ValueError('Initialize the current bootstrap key before importing a transition')
            if policy['sequence'] != state['policy']['sequence'] + 1:
                raise ValueError('Publisher transition sequence is stale or skips a reviewed transition')
            known = {key['id']: key for key in state['policy']['keys']}
            proposed = {key['id']: key for key in policy['keys']}
            proofs = {}
            for proof in envelope['proofs']:
                if not isinstance(proof, dict) or set(proof) != {'key_id', 'signature'} \
                        or not isinstance(proof['key_id'], str) or proof['key_id'] in proofs:
                    raise ValueError('Invalid or duplicate publisher transition proof')
                signature = base64.b64decode(proof['signature'], validate=True)
                key = known.get(proof['key_id']) or proposed.get(proof['key_id'])
                if not key or not verify_bytes(body, signature, key):
                    raise ValueError('Publisher transition signature is invalid')
                proofs[proof['key_id']] = True
            if not any(identifier in proofs and active(key, now) and identifier not in state['retired_ids']
                       for identifier, key in known.items()):
                raise ValueError('Transition requires a currently trusted, unrevoked publisher signature')
            for identifier, key in proposed.items():
                if identifier in state['retired_ids'] and (not key['revoked_at'] or key['revoked_at'] > now):
                    raise ValueError('Revoked publisher authority cannot be restored')
                if identifier not in known and identifier not in proofs:
                    raise ValueError('A new publisher key must prove possession of its private key')
                old = known.get(identifier)
                if old and old['revoked_at'] and (not key['revoked_at'] or key['revoked_at'] > old['revoked_at']):
                    raise ValueError('A publisher revocation cannot be delayed or removed')
            if not any(active(key, now) for key in proposed.values()):
                raise ValueError('The transition must retain an active release verification key')
            retired = set(state['retired_ids']) | (set(known) - set(proposed)) \
                | {identifier for identifier, key in proposed.items() if key['revoked_at'] and key['revoked_at'] <= now}
            if len(retired) > 64:
                raise ValueError('Publisher revocation ledger reached its protected capacity')
            updated = {'policy': policy, 'retired_ids': sorted(retired)}
            if 'last_recovery' in state:
                updated['last_recovery'] = state['last_recovery']
            self._write(self.state, canonical(updated))
            return updated
        finally:
            os.close(lock)

    def recover_offline(self, public_key, fingerprint, sequence, reason, now=None):
        """Explicit root recovery after independently reviewing a replacement key.

        This is intentionally unavailable through updates, .config or HTTP.
        All prior authorities are retired, including the compromised anchor.
        """
        now = int(time.time() if now is None else now)
        key = key_entry(public_key, not_before=now)
        if not isinstance(fingerprint, str) or not re.fullmatch('[a-f0-9]{64}', fingerprint) \
                or key['id'] != fingerprint or type(sequence) is not int \
                or not isinstance(reason, str) or not 10 <= len(reason.strip()) <= 200 \
                or any(ord(character) < 32 for character in reason):
            raise ValueError('Offline recovery requires the exact reviewed key fingerprint, current sequence and reason')
        lock = self._lock()
        try:
            state, initialized = self.load()
            if not initialized or state['policy']['sequence'] != sequence:
                raise ValueError('Offline recovery requires initialized trust and its current sequence')
            retired = set(state['retired_ids']) | {entry['id'] for entry in state['policy']['keys']}
            if key['id'] in retired or len(retired) > 64:
                raise ValueError('The replacement must be a new key; retired authorities cannot be restored')
            updated = {'policy': {'schema': 1, 'sequence': sequence + 1, 'keys': [key]}, 'retired_ids': sorted(retired),
                       'last_recovery': {'at': now, 'from_sequence': sequence, 'reason': reason.strip(), 'key_id': key['id']}}
            validate_policy(updated['policy'])
            self._write(self.state, canonical(updated))
            return updated
        finally:
            os.close(lock)


def authenticate_manifest(body, signature, anchor=ANCHOR_PEM, directory=DIRECTORY, now=None):
    now = int(time.time() if now is None else now)
    state, initialized = TrustStore(directory, anchor).load()
    # Try only root-approved keys. Unauthenticated manifest fields never select
    # paths, execute code, install keys or change the trust policy.
    key = next((entry for entry in state['policy']['keys'] if entry['id'] not in state['retired_ids']
                and active(entry, now) and verify_bytes(body, signature, entry)), None)
    if key is None:
        raise ValueError('Release publisher signature is invalid, expired or revoked')
    data = json.loads(body)
    if not isinstance(data, dict):
        raise ValueError('Invalid authenticated release manifest')
    metadata = data.get('signing')
    if metadata is None and not initialized:
        return data  # Existing published releases retain their bootstrap contract.
    if not isinstance(metadata, dict) or set(metadata) != {'key_id', 'issued_at', 'expires_at'} \
            or metadata['key_id'] != key['id'] or type(metadata['issued_at']) is not int \
            or type(metadata['expires_at']) is not int \
            or metadata['issued_at'] < key['not_before'] or metadata['issued_at'] > now + 300 \
            or not metadata['issued_at'] < metadata['expires_at'] <= metadata['issued_at'] + 2 * 366 * 86400 \
            or now >= metadata['expires_at']:
        raise ValueError('Signed release metadata is missing, expired or has an invalid validity window')
    return data


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['status', 'initialize', 'import-transition', 'recover-offline'])
    parser.add_argument('--transition', type=Path)
    parser.add_argument('--public-key', type=Path)
    parser.add_argument('--fingerprint')
    parser.add_argument('--current-sequence', type=int)
    parser.add_argument('--reason')
    args = parser.parse_args()
    try:
        store = TrustStore()
        if args.action == 'initialize':
            state = store.initialize(); initialized = True
        elif args.action == 'import-transition':
            if args.transition is None:
                parser.error('--transition is required')
            state = store.import_transition(json.loads(bounded_read(args.transition))); initialized = True
        elif args.action == 'recover-offline':
            if args.public_key is None:
                parser.error('--public-key is required for offline recovery')
            state = store.recover_offline(bounded_read(args.public_key), args.fingerprint,
                                         args.current_sequence, args.reason); initialized = True
        else:
            state, initialized = store.load()
        print(json.dumps({'initialized': initialized, **state}, sort_keys=True, indent=2))
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        parser.exit(1, 'Publisher trust failed: ' + str(error) + '\n')


if __name__ == '__main__':
    main()
