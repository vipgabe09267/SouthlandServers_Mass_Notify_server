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
VENDOR_PATCHES = ROOT / 'slsmassnotifyserver/identity-libs/SLS_VENDOR_PATCHES.json'
VENDOR_BASELINE = ROOT / 'slsmassnotifyserver/identity-libs/SLS_VENDOR_BASELINE.json'


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


def strict_json(raw, source):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError(source + ' contains a duplicate JSON member')
            result[key] = value
        return result
    def constant(value):
        raise ValueError(source + ' contains a non-standard numeric value: ' + value)
    try:
        return json.loads(raw, object_pairs_hook=unique, parse_constant=constant)
    except (TypeError, UnicodeError, json.JSONDecodeError, RecursionError) as error:
        raise ValueError(source + ' is not valid JSON') from error


def vendor_path(value, source):
    if (not isinstance(value, str)
            or not re.fullmatch(r'[A-Za-z0-9_.+-]+(?:/[A-Za-z0-9_.+-]+)*', value)
            or any(part in {'.', '..'} for part in value.split('/'))):
        raise ValueError(source + ' file identity is invalid')
    return value


def vendor_patches(identity, locked):
    raw = VENDOR_PATCHES.read_bytes()
    if len(raw) > 65536:
        raise ValueError('Composer vendor patch manifest exceeds 64 KiB')
    manifest = strict_json(raw, 'Composer vendor patch manifest')
    if (set(manifest) != {'schema', 'packages'} or type(manifest['schema']) is not int
            or manifest['schema'] != 1 or not isinstance(manifest['packages'], dict)):
        raise ValueError('Composer vendor patch manifest has an invalid root')
    if len(manifest['packages']) > 50:
        raise ValueError('Composer vendor patch manifest exceeds its package capacity')
    patches = {}
    for name, record in manifest['packages'].items():
        if name not in locked or not isinstance(record, dict) or set(record) != {'source_reference', 'modifications', 'files'}:
            raise ValueError('Composer vendor patch entry is invalid: ' + str(name))
        if record['source_reference'] != locked[name]['source']['reference']:
            raise ValueError('Composer vendor patch source revision is stale: ' + name)
        description = record['modifications']
        files = record['files']
        if (not isinstance(description, str) or not 1 <= len(description) <= 500
                or any(ord(char) < 0x20 for char in description)
                or not isinstance(files, dict) or not 1 <= len(files) <= 100):
            raise ValueError('Composer vendor patch metadata is invalid: ' + name)
        for relative, expected in files.items():
            if (not isinstance(expected, str) or not re.fullmatch(r'[a-f0-9]{64}', expected)):
                raise ValueError('Composer vendor patch file identity is invalid: ' + name)
            vendor_path(relative, 'Composer vendor patch')
            path = identity / 'vendor' / name / relative
            if (path.is_symlink() or not path.is_file() or path.stat().st_size > 8 * 1024 * 1024
                    or hashlib.sha256(path.read_bytes()).hexdigest() != expected):
                raise ValueError('Composer vendor patch file differs from its reviewed hash: ' + name + '/' + relative)
        patches[name] = record
    return patches


def vendor_baseline(identity, locked, patches):
    raw = VENDOR_BASELINE.read_bytes()
    if len(raw) > 262144:
        raise ValueError('Composer vendor baseline exceeds 256 KiB')
    manifest = strict_json(raw, 'Composer vendor baseline')
    if (set(manifest) != {'schema', 'packages', 'generated_files'} or type(manifest['schema']) is not int
            or manifest['schema'] != 1
            or not isinstance(manifest['packages'], dict) or not isinstance(manifest['generated_files'], dict)
            or not 1 <= len(manifest['generated_files']) <= 100):
        raise ValueError('Composer vendor baseline has an invalid root')
    if set(manifest['packages']) != set(locked):
        raise ValueError('Composer vendor baseline does not cover the exact lock inventory')
    expected_tree = set()
    for name, record in manifest['packages'].items():
        if (not isinstance(record, dict)
                or set(record) != {'source_reference', 'archive_sha256', 'source_file_count', 'files', 'omitted_files'}
                or record['source_reference'] != locked[name]['source']['reference']
                or not isinstance(record['archive_sha256'], str)
                or not re.fullmatch(r'[a-f0-9]{64}', record['archive_sha256'])
                or type(record['source_file_count']) is not int
                or not 1 <= record['source_file_count'] <= 5000
                or not isinstance(record['files'], dict)
                or not 1 <= len(record['files']) <= 5000
                or not isinstance(record['omitted_files'], list)
                or len(record['omitted_files']) > 500):
            raise ValueError('Composer vendor baseline entry is invalid: ' + str(name))
        files = record['files']
        omitted = record['omitted_files']
        if len(set(omitted)) != len(omitted) or record['source_file_count'] != len(files) + len(omitted):
            raise ValueError('Composer vendor baseline counts are invalid: ' + name)
        for relative, expected in files.items():
            vendor_path(relative, 'Composer vendor baseline')
            expected_tree.add(name + '/' + relative)
            if not isinstance(expected, str) or not re.fullmatch(r'[a-f0-9]{64}', expected):
                raise ValueError('Composer vendor baseline hash is invalid: ' + name + '/' + str(relative))
        for relative in omitted:
            vendor_path(relative, 'Composer vendor baseline omission')
            if relative in files:
                raise ValueError('Composer vendor baseline omission is also installed: ' + name + '/' + relative)
        patch_files = patches.get(name, {}).get('files', {})
        if not set(patch_files).issubset(files):
            raise ValueError('Composer vendor patch is absent from its upstream baseline: ' + name)
        package_root = identity / 'vendor' / name
        if package_root.is_symlink() or not package_root.is_dir():
            raise ValueError('Composer vendor package directory is unsafe: ' + name)
        actual = set()
        for path in package_root.rglob('*'):
            if path.is_symlink():
                raise ValueError('Composer vendor tree contains a symbolic link: ' + name)
            if path.is_dir():
                continue
            if not path.is_file():
                raise ValueError('Composer vendor tree contains a special file: ' + name)
            actual.add(path.relative_to(package_root).as_posix())
        if actual != set(files):
            raise ValueError('Composer vendor files differ from the reviewed baseline: ' + name)
        for relative, upstream_hash in files.items():
            expected = patch_files.get(relative, upstream_hash)
            path = package_root / relative
            if path.stat().st_size > 8 * 1024 * 1024 or hashlib.sha256(path.read_bytes()).hexdigest() != expected:
                raise ValueError('Composer vendor file differs from the reviewed baseline: ' + name + '/' + relative)
    vendor_root = identity / 'vendor'
    for relative, expected in manifest['generated_files'].items():
        if (not isinstance(relative, str) or not re.fullmatch(r'(?:autoload\.php|composer/[A-Za-z0-9_.+-]+)', relative)
                or not isinstance(expected, str) or not re.fullmatch(r'[a-f0-9]{64}', expected)):
            raise ValueError('Generated Composer file identity is invalid')
        path = vendor_root / relative
        if path.is_symlink() or not path.is_file() or path.stat().st_size > 8 * 1024 * 1024 or hashlib.sha256(path.read_bytes()).hexdigest() != expected:
            raise ValueError('Generated Composer file differs from the reviewed baseline: ' + relative)
        expected_tree.add(relative)
    actual_tree = set()
    for path in vendor_root.rglob('*'):
        if path.is_symlink():
            raise ValueError('Composer vendor tree contains a symbolic link')
        if path.is_dir():
            continue
        if not path.is_file():
            raise ValueError('Composer vendor tree contains a special file')
        actual_tree.add(path.relative_to(vendor_root).as_posix())
    if actual_tree != expected_tree:
        raise ValueError('Composer vendor tree differs from the complete reviewed baseline')
    return manifest, hashlib.sha256(raw).hexdigest()


def keyed_composer_packages(rows, source):
    if not isinstance(rows, list) or len(rows) > 500:
        raise ValueError(source + ' package inventory is invalid')
    packages = {}
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError(source + ' package record is invalid')
        name = row.get('name')
        if (not isinstance(name, str)
                or not re.fullmatch(r'[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*', name)
                or name in packages):
            raise ValueError(source + ' package identity is invalid or duplicated')
        packages[name] = row
    return packages


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
    provenance = strict_json((vendor / 'UPSTREAM.json').read_text(), 'Twilio provenance')
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
    chart_provenance = strict_json((charts / 'UPSTREAM.json').read_text(), 'Chart.js provenance')
    for name, expected in chart_provenance['sha256'].items():
        if hashlib.sha256((charts / name).read_bytes()).hexdigest() != expected:
            raise ValueError('Vendored Chart.js file differs from reviewed provenance: ' + name)
    components.append({'type': 'library', 'bom-ref': 'pkg:npm/chart.js@2.9.4',
                       'name': 'chart.js', 'version': '2.9.4', 'purl': 'pkg:npm/chart.js@2.9.4',
                       'licenses': [{'license': {'id': 'MIT'}}],
                       'externalReferences': [{'type': 'vcs', 'url': chart_provenance['repository'] + '/tree/v2.9.4'}]})
    embedded = []
    for package in chart_provenance.get('embedded_components', []):
        if package.get('included') is False:
            continue
        name, release = package['name'], package['version']
        if not re.fullmatch(r'[a-z0-9][a-z0-9.-]{0,99}', name) or not re.fullmatch(r'\d+(?:\.\d+)+', release):
            raise ValueError('Invalid embedded browser dependency identity')
        purl = 'pkg:npm/' + name + '@' + release
        embedded.append(purl)
        components.append({'type': 'library', 'bom-ref': purl, 'name': name, 'version': release, 'purl': purl,
                           'licenses': [{'license': {'id': package['license']}}],
                           'properties': [{'name': 'sls:embedded-in', 'value': 'pkg:npm/chart.js@2.9.4'}],
                           'externalReferences': [{'type': 'distribution', 'url': chart_provenance['lock_source']}]})
    chart = (charts / 'Chart-2.9.4.min.js').read_bytes()
    if b'2.24.0' in chart or b'a.version="2.' in chart:
        raise ValueError('Unexpected embedded Moment dependency in the numeric dashboard chart')
    identity = ROOT / 'slsmassnotifyserver/identity-libs'
    locked = strict_json((identity / 'composer.lock').read_text(), 'Composer lock')
    installed = strict_json((identity / 'vendor/composer/installed.json').read_text(), 'Installed Composer inventory')
    actual_php = keyed_composer_packages(installed.get('packages'), 'Installed Composer')
    locked_php = keyed_composer_packages(locked.get('packages'), 'Locked Composer')
    if set(actual_php) != set(locked_php):
        raise ValueError('Installed identity libraries differ from the Composer lock')
    patches = vendor_patches(identity, locked_php)
    baseline, baseline_digest = vendor_baseline(identity, locked_php, patches)
    for package in locked['packages']:
        name, release = package['name'], package['version']
        if actual_php[name]['version'] != release or not re.fullmatch(r'[a-f0-9]{40}', package['source']['reference']):
            raise ValueError('Identity library version or source revision differs: ' + name)
        purl = 'pkg:composer/' + quote(name, safe='/._-') + '@' + quote(release, safe='.-+')
        properties = [
            {'name': 'sls:source-revision', 'value': package['source']['reference']},
            {'name': 'sls:upstream-archive-sha256', 'value': baseline['packages'][name]['archive_sha256']},
        ]
        if baseline['packages'][name]['omitted_files']:
            properties.append({'name': 'sls:omitted-upstream-files',
                               'value': json.dumps(baseline['packages'][name]['omitted_files'], separators=(',', ':'))})
        if name in patches:
            properties.extend([
                {'name': 'sls:modifications', 'value': patches[name]['modifications']},
                {'name': 'sls:patched-files-sha256',
                 'value': json.dumps(patches[name]['files'], sort_keys=True, separators=(',', ':'))},
            ])
        components.append({'type': 'library', 'bom-ref': purl, 'name': name, 'version': release, 'purl': purl,
                           'licenses': [{'license': {'id': value}} for value in package.get('license', [])],
                           'externalReferences': [{'type': 'vcs', 'url': package['source']['url']}],
                           'properties': properties})
    version = ET.parse(ROOT / 'slsmassnotifyserver/module.xml').findtext('version')
    bom = {'bomFormat': 'CycloneDX', 'specVersion': '1.6', 'version': 1,
           'metadata': {'component': {'type': 'application', 'name': 'slsmassnotifyserver', 'version': version,
                                      'licenses': [{'expression': 'AGPL-3.0-or-later'}]},
                        'properties': [{'name': 'sls:inventory-kind', 'value': 'installed' if site_packages else 'release-pins'},
                                       {'name': 'sls:requirements-sha256', 'value': digest},
                                       {'name': 'sls:composer-vendor-baseline-sha256', 'value': baseline_digest},
                                       {'name': 'sls:scope', 'value': 'Piper Python environment, PHP callback validator, locked PHP identity libraries and bundled dashboard chart library; OS packages, FreePBX modules and voice models are separate inventories.'}]},
           'components': components,
           'dependencies': [{'ref': 'pkg:npm/chart.js@2.9.4', 'dependsOn': sorted(embedded)}]}
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
                handle.write(body)
                os.fchmod(handle.fileno(), 0o644)
                handle.flush(); os.fsync(handle.fileno())
            os.replace(temporary, args.output)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)
    except (OSError, ValueError, UnicodeError, ET.ParseError) as error:
        parser.exit(1, 'Dependency inventory failed: ' + str(error) + '\n')
    print(f'Wrote {len(bom["components"])} dependency components ({"installed" if args.site_packages else "release pins"}).')


if __name__ == '__main__':
    main()
