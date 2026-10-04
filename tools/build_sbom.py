#!/usr/bin/env python3
"""Produce a deterministic CycloneDX inventory without executing installed packages."""
import argparse
import hashlib
import importlib.metadata
import json
import os
from pathlib import Path
import re
import tempfile
from urllib.parse import quote
import uuid
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
REQUIREMENTS = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/piper-requirements.txt'


def normalized(name):
    return re.sub(r'[-_.]+', '-', name).lower()


def pins_from(path):
    raw = path.read_bytes()
    if len(raw) > 65536:
        raise ValueError('Dependency pins exceed 64 KiB')
    pins = {}
    for line in raw.decode('utf-8').splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        match = re.fullmatch(r'([A-Za-z0-9][A-Za-z0-9._-]*)==([0-9]+(?:\.[0-9]+)*)', line)
        if not match or normalized(match[1]) in pins:
            raise ValueError('Dependency inventory requires unique exact version pins')
        pins[normalized(match[1])] = match[2]
    if not pins:
        raise ValueError('Dependency inventory is empty')
    return pins, hashlib.sha256(raw).hexdigest()


def version_key(value):
    if not re.fullmatch(r'[0-9]+(?:\.[0-9]+)*', value):
        raise ValueError('Dependency version is not a stable numeric release')
    parts = [int(part) for part in value.split('.')]
    while len(parts) > 1 and parts[-1] == 0:
        parts.pop()
    return parts


def inventory(requirements=REQUIREMENTS, site_packages=None):
    pins, digest = pins_from(requirements)
    actual = dict(pins)
    if site_packages is not None:
        if not site_packages.is_dir():
            raise ValueError('Installed site-packages directory is unavailable')
        actual = {}
        for dist in importlib.metadata.distributions(path=[str(site_packages)]):
            name = dist.metadata.get('Name', '')
            version = dist.metadata.get('Version', '')
            if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,199}', name) or len(version) > 100:
                raise ValueError('Installed dependency metadata has an invalid identity')
            name = normalized(name)
            if name in actual:
                raise ValueError('Duplicate installed dependency metadata: ' + name)
            actual[name] = version
        if len(actual) > 1000:
            raise ValueError('Installed dependency inventory exceeds its capacity')
        for name, required in pins.items():
            if name not in actual or version_key(actual[name]) != version_key(required):
                raise ValueError('Installed dependency does not match its release pin: ' + name)
    components = []
    for name, version in sorted(actual.items()):
        purl = 'pkg:pypi/' + quote(name, safe='-._') + '@' + quote(version, safe='.-+')
        component = {'type': 'library', 'bom-ref': purl, 'name': name, 'version': version, 'purl': purl}
        if name in pins:
            component['properties'] = [{'name': 'sls:required-version', 'value': pins[name]}]
        components.append(component)
    vendor = ROOT / 'slsmassnotifyserver/api/sls-mass-notify/sms/vendor/twilio'
    provenance = json.loads((vendor / 'UPSTREAM.json').read_text())
    commit = provenance['commit']
    if not re.fullmatch(r'[a-f0-9]{40}', commit):
        raise ValueError('Invalid Twilio source revision')
    for name in ['RequestValidator.php', 'Values.php', 'LICENSE']:
        digest_actual = hashlib.sha256((vendor / name).read_bytes()).hexdigest()
        if digest_actual != provenance['vendored_sha256'].get(name):
            raise ValueError('Vendored Twilio file differs from reviewed provenance: ' + name)
    components.append({'type': 'library', 'bom-ref': 'twilio-webhook-validator',
                       'name': 'twilio-php-webhook-validator', 'version': commit,
                       'licenses': [{'license': {'id': 'MIT'}}],
                       'externalReferences': [{'type': 'vcs', 'url': provenance['repository'] + '/tree/' + commit}],
                       'properties': [{'name': 'sls:modifications', 'value': provenance['modifications']},
                                      {'name': 'sls:included-files', 'value': 'RequestValidator.php, Values.php'}]})
    charts = ROOT / 'slsmassnotifyserver/assets/vendor/chartjs'
    chart_provenance = json.loads((charts / 'UPSTREAM.json').read_text())
    for name, expected in chart_provenance['sha256'].items():
        if hashlib.sha256((charts / name).read_bytes()).hexdigest() != expected:
            raise ValueError('Vendored Chart.js file differs from reviewed provenance: ' + name)
    components.append({'type': 'library', 'bom-ref': 'pkg:npm/chart.js@2.9.4',
                       'name': 'chart.js', 'version': '2.9.4', 'purl': 'pkg:npm/chart.js@2.9.4',
                       'licenses': [{'license': {'id': 'MIT'}}],
                       'externalReferences': [{'type': 'vcs', 'url': chart_provenance['repository'] + '/tree/v2.9.4'}]})
    identity = ROOT / 'slsmassnotifyserver/identity-libs'
    locked = json.loads((identity / 'composer.lock').read_text())
    installed = json.loads((identity / 'vendor/composer/installed.json').read_text())
    actual_php = {row['name']: row for row in installed['packages']}
    if set(actual_php) != {row['name'] for row in locked['packages']}:
        raise ValueError('Installed identity libraries differ from the Composer lock')
    for package in locked['packages']:
        name, release = package['name'], package['version']
        if actual_php[name]['version'] != release or not re.fullmatch(r'[a-f0-9]{40}', package['source']['reference']):
            raise ValueError('Identity library version or source revision differs: ' + name)
        purl = 'pkg:composer/' + quote(name, safe='/._-') + '@' + quote(release, safe='.-+')
        components.append({'type': 'library', 'bom-ref': purl, 'name': name, 'version': release, 'purl': purl,
                           'licenses': [{'license': {'id': value}} for value in package.get('license', [])],
                           'externalReferences': [{'type': 'vcs', 'url': package['source']['url']}],
                           'properties': [{'name': 'sls:source-revision', 'value': package['source']['reference']}]})
    version = ET.parse(ROOT / 'slsmassnotifyserver/module.xml').findtext('version')
    bom = {'bomFormat': 'CycloneDX', 'specVersion': '1.6', 'version': 1,
           'metadata': {'component': {'type': 'application', 'name': 'slsmassnotifyserver', 'version': version,
                                      'licenses': [{'expression': 'AGPL-3.0-or-later'}]},
                        'properties': [{'name': 'sls:inventory-kind', 'value': 'installed' if site_packages else 'release-pins'},
                                       {'name': 'sls:requirements-sha256', 'value': digest},
                                       {'name': 'sls:scope', 'value': 'Piper Python environment, PHP callback validator, locked PHP identity libraries and bundled dashboard chart library; OS packages, FreePBX modules and voice models are separate inventories.'}]},
           'components': components}
    seed = json.dumps(bom, sort_keys=True, separators=(',', ':'))
    bom['serialNumber'] = 'urn:uuid:' + str(uuid.uuid5(uuid.NAMESPACE_URL, seed))
    return bom


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--requirements', type=Path, default=REQUIREMENTS)
    parser.add_argument('--site-packages', type=Path, help='Read metadata from an installed environment; never import its packages')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        bom = inventory(args.requirements, args.site_packages)
        body = (json.dumps(bom, indent=2, sort_keys=True) + '\n').encode()
        fd, temporary = tempfile.mkstemp(prefix='.sbom-', dir=args.output.parent)
        try:
            with os.fdopen(fd, 'wb') as handle:
                handle.write(body); handle.flush(); os.fsync(handle.fileno())
            os.replace(temporary, args.output)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)
    except (OSError, ValueError, UnicodeError, ET.ParseError) as error:
        parser.exit(1, 'Dependency inventory failed: ' + str(error) + '\n')
    print(f'Wrote {len(bom["components"])} dependency components ({"installed" if args.site_packages else "release pins"}).')


if __name__ == '__main__':
    main()
