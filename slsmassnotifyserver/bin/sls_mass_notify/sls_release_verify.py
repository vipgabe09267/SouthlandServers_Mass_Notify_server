#!/usr/bin/python3
"""Verify a publisher-signed release without executing downloaded code."""
import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

PUBLIC_KEY = Path(__file__).with_name('release-signing.pub')


def digest(path):
    result = hashlib.sha256()
    with Path(path).open('rb') as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b''):
            result.update(block)
    return result.hexdigest()


def verify(manifest, signature, installer, package, version, public_key=PUBLIC_KEY):
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-beta)?', version):
        raise ValueError('Invalid release version')
    if Path(manifest).stat().st_size > 16384 or Path(signature).stat().st_size != 64:
        raise ValueError('Invalid release manifest/signature size')
    result = subprocess.run(['/usr/bin/openssl', 'pkeyutl', '-verify', '-rawin', '-pubin',
                             '-inkey', str(public_key), '-in', str(manifest), '-sigfile', str(signature)],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
    if result.returncode:
        raise ValueError('Release publisher signature is invalid')
    # Only inspect the manifest after authenticating its exact bytes.
    data = json.loads(Path(manifest).read_bytes())
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
