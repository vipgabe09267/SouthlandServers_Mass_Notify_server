#!/usr/bin/python3
"""Verify a publisher-signed release without executing downloaded code."""
import argparse
import hashlib
import importlib.util
import json
import re
import subprocess
import sys
from pathlib import Path

PUBLIC_KEY = Path(__file__).with_name('release-signing.pub')
sys.dont_write_bytecode = True
_spec = importlib.util.spec_from_file_location('sls_release_trust', Path(__file__).with_name('sls_release_trust.py'))
trust = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(trust)


def digest(path):
    result = hashlib.sha256()
    with Path(path).open('rb') as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b''):
            result.update(block)
    return result.hexdigest()


def verify(manifest, signature, installer, package, version, public_key=PUBLIC_KEY, *, trust_directory=trust.DIRECTORY, now=None):
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-beta)?', version):
        raise ValueError('Invalid release version')
    if Path(manifest).stat().st_size > 16384 or Path(signature).stat().st_size != 64:
        raise ValueError('Invalid release manifest/signature size')
    # Authenticate with the root-reviewed authority before inspecting identity
    # or hashes. This also enforces signed metadata expiry when present.
    data = trust.authenticate_manifest(trust.bounded_read(manifest), trust.bounded_read(signature),
        Path(public_key).read_bytes(), trust_directory, now)
    expected_name = f'slsmassnotifyserver-{version}.tgz'
    if not isinstance(data, dict) or data.get('schema') != 1 or data.get('version') != version \
            or data.get('tag') != f'slsmassnotifyserver-{version}' or data.get('package') != expected_name:
        raise ValueError('Signed release identity does not match the requested version')
    for key, path in [('installer_sha256', installer), ('package_sha256', package)]:
        expected = data.get(key)
        if not isinstance(expected, str) or not re.fullmatch('[0-9a-f]{64}', expected) or digest(path) != expected:
            raise ValueError('Signed release artifact hash mismatch: ' + key)
    return data


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('manifest', 'signature', 'installer', 'package', 'version'):
        parser.add_argument('--' + name, required=True)
    args = parser.parse_args()
    try:
        verify(args.manifest, args.signature, args.installer, args.package, args.version)
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        parser.exit(1, 'Release verification failed: ' + str(error) + '\n')
    print('Publisher signature and installer/package hashes verified.')


if __name__ == '__main__':
    main()
