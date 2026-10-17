#!/usr/bin/env python3
"""Create the two public signed-manifest assets after building a release TGZ."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import time
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('release_verifier', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_release_verify.py')
if spec is None or spec.loader is None:
    raise RuntimeError('Release verifier could not be loaded.')
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)

TRANSACTION_NAME = '.release-signing-transaction'
METADATA_NAME = 'transaction.json'
OUTPUTS = {
    'manifest': ('release-manifest.json', 'manifest.previous', 'manifest.stage'),
    'signature': ('release-manifest.sig', 'signature.previous', 'signature.stage'),
}


def _fsync_directory(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def _require_private_directory(path):
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o022:
        raise ValueError(f'Unsafe release directory: {path}')


def _read_regular(path, maximum):
    descriptor = os.open(path, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW)
    try:
        before = os.fstat(descriptor)
        if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_size > maximum:
            raise ValueError(f'Unsafe or oversized release input: {path}')
        chunks = []
        remaining = maximum + 1
        while remaining:
            block = os.read(descriptor, min(1048576, remaining))
            if not block:
                break
            chunks.append(block)
            remaining -= len(block)
        body = b''.join(chunks)
        after = os.fstat(descriptor)
    finally:
        os.close(descriptor)
    named = path.lstat()
    identity = lambda value: (
        value.st_dev, value.st_ino, value.st_size, value.st_mtime_ns,
        value.st_ctime_ns, value.st_nlink)
    if len(body) > maximum or identity(before) != identity(after) or identity(after) != identity(named):
        raise ValueError(f'Release input changed while reading: {path}')
    return body


def _digest_regular(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW)
    digest = hashlib.sha256()
    try:
        before = os.fstat(descriptor)
        if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1:
            raise ValueError(f'Unsafe release artifact: {path}')
        for block in iter(lambda: os.read(descriptor, 1048576), b''):
            digest.update(block)
        after = os.fstat(descriptor)
    finally:
        os.close(descriptor)
    named = path.lstat()
    identity = lambda value: (
        value.st_dev, value.st_ino, value.st_size, value.st_mtime_ns,
        value.st_ctime_ns, value.st_nlink)
    if identity(before) != identity(after) or identity(after) != identity(named):
        raise ValueError(f'Release artifact changed while hashing: {path}')
    return digest.hexdigest(), identity(after)


def _write_exclusive(path, body, mode):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    try:
        written = 0
        while written < len(body):
            count = os.write(descriptor, body[written:])
            if count <= 0:
                raise OSError(f'Incomplete release write: {path}')
            written += count
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def _safe_output(path, maximum):
    try:
        info = path.lstat()
    except FileNotFoundError:
        return False
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
            or info.st_uid != os.geteuid() or info.st_size > maximum):
        raise ValueError(f'Unsafe existing release output: {path}')
    return True


def _remove_regular(path, maximum):
    if _safe_output(path, maximum):
        path.unlink()


def _transaction_metadata(path):
    value = json.loads(_read_regular(path, 4096))
    expected = {'schema', 'phase', 'manifest_existed', 'signature_existed'}
    if (not isinstance(value, dict) or set(value) != expected or value.get('schema') != 1
            or value.get('phase') not in {'prepared', 'committed'}
            or not isinstance(value.get('manifest_existed'), bool)
            or not isinstance(value.get('signature_existed'), bool)):
        raise ValueError('Invalid release-signing transaction metadata.')
    return value


def _recover_transaction(distribution):
    transaction = distribution / TRANSACTION_NAME
    try:
        info = transaction.lstat()
    except FileNotFoundError:
        return
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
        raise ValueError('Unsafe release-signing transaction directory.')
    names = {entry.name for entry in transaction.iterdir()}
    allowed = {METADATA_NAME, 'transaction.next', 'manifest.previous', 'signature.previous', 'manifest.stage', 'signature.stage'}
    if not names:
        transaction.rmdir()
        _fsync_directory(distribution)
        return
    if not names <= allowed or METADATA_NAME not in names:
        raise ValueError('Unknown files are present in the release-signing transaction.')
    metadata = _transaction_metadata(transaction / METADATA_NAME)
    next_metadata = transaction / 'transaction.next'
    if next_metadata.exists():
        try:
            candidate = _transaction_metadata(next_metadata)
        except (OSError, ValueError, json.JSONDecodeError):
            _remove_regular(next_metadata, 4096)
        else:
            if candidate['phase'] != 'committed' or candidate != dict(metadata, phase='committed'):
                raise ValueError('Conflicting release-signing transaction metadata.')
            metadata = candidate
    if metadata['phase'] == 'prepared':
        for key, (target_name, previous_name, _) in OUTPUTS.items():
            target = distribution / target_name
            previous = transaction / previous_name
            existed = metadata[key + '_existed']
            if previous.exists():
                _safe_output(previous, 16384)
                _remove_regular(target, 16384)
                os.replace(previous, target)
            elif not existed:
                _remove_regular(target, 16384)
    else:
        for _, (target_name, _, _) in OUTPUTS.items():
            if not _safe_output(distribution / target_name, 16384):
                raise ValueError('A committed release-signing output is missing.')
    for _, (_, previous_name, stage_name) in OUTPUTS.items():
        _remove_regular(transaction / previous_name, 16384)
        _remove_regular(transaction / stage_name, 16384)
    _remove_regular(next_metadata, 4096)
    _remove_regular(transaction / METADATA_NAME, 4096)
    transaction.rmdir()
    _fsync_directory(distribution)


def _begin_transaction(distribution, manifest, signature):
    transaction = distribution / TRANSACTION_NAME
    transaction.mkdir(mode=0o700)
    try:
        metadata = {
            'schema': 1,
            'phase': 'prepared',
            'manifest_existed': _safe_output(manifest, 16384),
            'signature_existed': _safe_output(signature, 16384),
        }
        body = (json.dumps(metadata, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')
        _write_exclusive(transaction / METADATA_NAME, body, 0o600)
        _fsync_directory(transaction)
        _fsync_directory(distribution)
    except BaseException:
        for entry in transaction.iterdir():
            _remove_regular(entry, 16384)
        transaction.rmdir()
        _fsync_directory(distribution)
        raise
    return transaction, metadata


def _commit_transaction(distribution, transaction, metadata):
    for key, (target_name, previous_name, _) in OUTPUTS.items():
        target = distribution / target_name
        if metadata[key + '_existed']:
            os.replace(target, transaction / previous_name)
    _fsync_directory(transaction)
    _fsync_directory(distribution)
    os.replace(transaction / OUTPUTS['signature'][2], distribution / OUTPUTS['signature'][0])
    os.replace(transaction / OUTPUTS['manifest'][2], distribution / OUTPUTS['manifest'][0])
    _fsync_directory(distribution)
    committed = dict(metadata, phase='committed')
    next_metadata = transaction / 'transaction.next'
    body = (json.dumps(committed, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')
    _write_exclusive(next_metadata, body, 0o600)
    os.replace(next_metadata, transaction / METADATA_NAME)
    _fsync_directory(transaction)
    _recover_transaction(distribution)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--key', default=os.environ.get('SLS_RELEASE_SIGNING_KEY', '/root/.local/share/sls-release-signing/ed25519-private.key'))
    args = parser.parse_args()
    key = Path(args.key)
    info = key.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != os.geteuid() or info.st_mode & 0o077:
        parser.error('Release signing key must be a private regular file (0600).')
    if ROOT in key.resolve(strict=True).parents:
        parser.error('Release signing keys must be kept outside the repository.')
    key_material = verifier.trust.bounded_read(key, private_owner=os.geteuid())

    distribution = ROOT / 'dist'
    _require_private_directory(distribution)
    _recover_transaction(distribution)
    module_body = _read_regular(ROOT / 'slsmassnotifyserver/module.xml', 65536)
    version_value = ET.fromstring(module_body).findtext('version')
    version = version_value.strip() if isinstance(version_value, str) else ''
    if re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-beta)?', version) is None:
        parser.error('module.xml contains an invalid release version.')
    package = distribution / f'slsmassnotifyserver-{version}.tgz'
    installer = ROOT / 'tools/install_release.sh'
    package_digest, package_identity = _digest_regular(package)
    installer_digest, installer_identity = _digest_regular(installer)
    manifest = distribution / OUTPUTS['manifest'][0]
    signature = distribution / OUTPUTS['signature'][0]
    environment = {'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C', 'OPENSSL_CONF': '/dev/null'}
    public = subprocess.run(
        ['/usr/bin/openssl', 'pkey', '-in', '/dev/stdin', '-pubout'], check=True,
        input=key_material, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10,
        env=environment, cwd=distribution).stdout
    issued = int(time.time())
    data = {'schema': 1, 'version': version, 'tag': f'slsmassnotifyserver-{version}', 'package': package.name,
            'package_sha256': package_digest, 'installer_sha256': installer_digest,
            'signing': {'key_id': verifier.trust.key_entry(public)['id'], 'issued_at': issued,
                        'expires_at': issued + 366 * 86400}}
    transaction, metadata = _begin_transaction(distribution, manifest, signature)
    manifest_stage = transaction / OUTPUTS['manifest'][2]
    signature_stage = transaction / OUTPUTS['signature'][2]
    try:
        body = (json.dumps(data, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')
        _write_exclusive(manifest_stage, body, 0o600)
        subprocess.run(
            ['/usr/bin/openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', '/dev/stdin',
             '-in', str(manifest_stage), '-out', str(signature_stage)], input=key_material,
            check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10,
            env=environment, cwd=distribution)
        if not _safe_output(signature_stage, 64) or signature_stage.stat().st_size != 64:
            raise ValueError('OpenSSL produced an invalid release signature.')
        verifier.verify(manifest_stage, signature_stage, installer, package, version)
        if (_digest_regular(package) != (package_digest, package_identity)
                or _digest_regular(installer) != (installer_digest, installer_identity)):
            raise ValueError('A release artifact changed during signing.')
        os.chmod(manifest_stage, 0o644)
        os.chmod(signature_stage, 0o644)
        _commit_transaction(distribution, transaction, metadata)
    except BaseException:
        try:
            _recover_transaction(distribution)
        except BaseException as recovery_error:
            raise RuntimeError('Release signing failed and its prior output could not be restored.') from recovery_error
        raise
    print('Signed release manifest verified. Publish the TGZ, release-manifest.json and release-manifest.sig together.')


if __name__ == '__main__':
    main()
