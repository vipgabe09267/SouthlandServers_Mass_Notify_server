#!/usr/bin/env python3
"""Create the two public signed-manifest assets after building a release TGZ."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('release_verifier', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_release_verify.py')
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--key', default=os.environ.get('SLS_RELEASE_SIGNING_KEY', '/root/.local/share/sls-release-signing/ed25519-private.key'))
    args = parser.parse_args()
    key = Path(args.key)
    info = key.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o077:
        parser.error('Release signing key must be a private regular file (0600).')
    if ROOT in key.resolve().parents:
        parser.error('Release signing keys must be kept outside the repository.')
    version = ET.parse(ROOT / 'slsmassnotifyserver/module.xml').getroot().findtext('version').strip()
    package = ROOT / 'dist' / f'slsmassnotifyserver-{version}.tgz'
    installer = ROOT / 'tools/install_release.sh'
    manifest = ROOT / 'dist/release-manifest.json'
    signature = ROOT / 'dist/release-manifest.sig'
    data = {'schema': 1, 'version': version, 'tag': f'slsmassnotifyserver-{version}', 'package': package.name,
            'package_sha256': verifier.digest(package), 'installer_sha256': verifier.digest(installer)}
    manifest.write_text(json.dumps(data, sort_keys=True, separators=(',', ':')) + '\n')
    subprocess.run(['/usr/bin/openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', str(key),
                    '-in', str(manifest), '-out', str(signature)], check=True, timeout=10)
    verifier.verify(manifest, signature, installer, package, version)
    print('Signed release manifest verified. Publish the TGZ, release-manifest.json and release-manifest.sig together.')


if __name__ == '__main__':
    main()
