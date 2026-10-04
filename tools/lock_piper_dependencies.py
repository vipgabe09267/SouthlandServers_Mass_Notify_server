#!/usr/bin/env python3
"""Maintainer-only: freeze PyPI wheel hashes for the existing exact release pins."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import urllib.request
from build_sbom import pins_from, normalized, version_key, REQUIREMENTS


class RegistryRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, url):
        if not url.startswith('https://pypi.org/'):
            raise ValueError('Unexpected package registry redirect')
        return super().redirect_request(request, fp, code, message, headers, url)


def release_wheels(name, version, opener):
    with opener.open('https://pypi.org/pypi/' + name + '/' + version + '/json', timeout=30) as response:
        raw = response.read(4194305)
    if len(raw) > 4194304:
        raise ValueError('Registry release metadata exceeds its size limit')
    data = json.loads(raw)
    if normalized(data['info']['name']) != name or version_key(data['info']['version']) != version_key(version):
        raise ValueError('Registry release identity does not match the requested pin')
    wheels = []
    for item in data['urls']:
        if item.get('packagetype') != 'bdist_wheel' or item.get('yanked'):
            continue
        filename = item.get('filename', '')
        digest = item.get('digests', {}).get('sha256', '')
        if not re.fullmatch(r'[A-Za-z0-9_.+-]+\.whl', filename) or not re.fullmatch(r'[a-f0-9]{64}', digest):
            raise ValueError('Invalid registry wheel identity')
        if not item['url'].startswith('https://files.pythonhosted.org/') or not 0 < item['size'] <= 536870912:
            raise ValueError('Invalid registry wheel location or size')
        wheels.append({'filename': filename, 'sha256': digest, 'size': item['size'], 'url': item['url']})
    if not wheels:
        raise ValueError('No non-yanked binary wheels exist for ' + name + '==' + version)
    return sorted(wheels, key=lambda row: row['filename'])


def render(pins, releases):
    lines = ['# Generated from exact release pins and PyPI SHA-256 metadata.',
             '# All listed non-yanked wheel variants are locked; pip still enforces Python/platform tags.',
             '# Source builds and unreviewed future artifacts are not permitted.']
    for name, version in sorted(pins.items()):
        hashes = sorted({row['sha256'] for row in releases[name]['wheels']})
        lines.append(name + '==' + version + ' \\')
        lines.extend('    --hash=sha256:' + value + (' \\' if index < len(hashes)-1 else '') for index,value in enumerate(hashes))
    return '\n'.join(lines) + '\n'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-directory', type=Path, required=True)
    args = parser.parse_args()
    pins, digest = pins_from(REQUIREMENTS)
    opener = urllib.request.build_opener(RegistryRedirect())
    releases = {name: {'version': version, 'wheels': release_wheels(name, version, opener)} for name,version in pins.items()}
    directory = args.output_directory
    directory.mkdir(parents=True, exist_ok=True)
    (directory/'piper-requirements.lock').write_text(render(pins, releases))
    packaging = {name: pins[name] for name in ('pip','setuptools','wheel','packaging')}
    (directory/'piper-packaging.lock').write_text(render(packaging, releases))
    (directory/'piper-artifacts.json').write_text(json.dumps({'schema':1,'requirements_sha256':digest,'packages':releases},indent=2,sort_keys=True)+'\n')
    print('Locked',len(pins),'packages and',sum(len(row['wheels']) for row in releases.values()),'wheel artifacts.')

if __name__ == '__main__': main()
