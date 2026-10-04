#!/usr/bin/env python3
"""Check the running Piper interpreter against the packaged exact dependency pins."""
import argparse
import importlib.metadata
import json
from pathlib import Path
import re
import sys


def load_pins(path):
    with Path(path).open('rb') as handle:
        raw = handle.read(65537)
    if len(raw) > 65536:
        raise ValueError('Piper requirements exceed the size limit')
    pins = {}
    for line in raw.decode('utf-8').splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        match = re.fullmatch(r'([A-Za-z0-9][A-Za-z0-9._-]*)==([0-9]+(?:\.[0-9]+)*)', line)
        if not match:
            raise ValueError('Piper dependencies must use exact stable numeric name==version pins')
        name = re.sub(r'[-_.]+', '-', match.group(1)).lower()
        if name in pins:
            raise ValueError('Duplicate Piper dependency pin')
        pins[name] = match.group(2)
    if not {'piper-tts', 'pip', 'setuptools', 'wheel', 'packaging'} <= pins.keys():
        raise ValueError('Piper requirements omit a required runtime or packaging pin')
    return pins


def numeric_release(value):
    # The supported manifest uses stable numeric releases only. Comparing their
    # release tuples avoids importing the environment being repaired; trailing
    # zero components are equivalent (pip metadata uses 26.2 for 26.2.0).
    if not re.fullmatch(r'[0-9]+(?:\.[0-9]+)*', value):
        return None
    parts = [int(part) for part in value.split('.')]
    while len(parts) > 1 and parts[-1] == 0:
        parts.pop()
    return tuple(parts)


def mismatches(pins, version_lookup=importlib.metadata.version):
    errors = []
    for name, expected in pins.items():
        try:
            actual = version_lookup(name)
        except importlib.metadata.PackageNotFoundError:
            actual = None
        if actual is None or numeric_release(actual) != numeric_release(expected):
            errors.append({'package': name, 'required': expected, 'installed': actual})
    return errors


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--requirements', type=Path, default=Path(__file__).with_name('piper-requirements.txt'))
    parser.add_argument('--packaging-pins', action='store_true')
    parser.add_argument('--quiet', action='store_true')
    options = parser.parse_args(argv)
    try:
        pins = load_pins(options.requirements)
        if options.packaging_pins:
            for name in ('pip', 'setuptools', 'wheel'):
                print(name + '==' + pins[name])
            return 0
        errors = mismatches(pins)
    except (OSError, UnicodeError, ValueError):
        if not options.quiet:
            print(json.dumps({'ok': False, 'error': 'invalid_dependency_manifest'}))
        return 2
    if not options.quiet:
        print(json.dumps({'ok': not errors, 'mismatches': errors}, sort_keys=True))
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
