#!/usr/bin/python3
"""Reject credentials and private-key material without printing their contents."""
import argparse
from pathlib import Path
import re
import sys

sys.dont_write_bytecode = True
TOKENS = re.compile(rb'(?:ghp_|github_pat_)[A-Za-z0-9_]{20,}')
PRIVATE_KEY = re.compile(
    rb'-----BEGIN (?:[A-Z0-9 ]+ )?PRIVATE KEY-----'
    rb'(?:\s|\\r|\\n)*[A-Za-z0-9+/=]{32,}'
)
MAX_BYTES = 8 * 1024 * 1024
MAX_FILES = 5000


def contains_private_material(body):
    # PEM delimiters used by cryptographic formatters are not private keys.
    return bool(TOKENS.search(body) or PRIVATE_KEY.search(body))


def scan(roots):
    findings, count = [], 0
    for root in roots:
        for path in sorted(root.rglob('*')):
            if path.is_symlink():
                findings.append((path, 'linked source'))
                continue
            if not path.is_file():
                continue
            count += 1
            if count > MAX_FILES:
                raise RuntimeError('Release source exceeds the scan allocation.')
            if path.stat().st_size > MAX_BYTES:
                findings.append((path, 'source file exceeds scan allocation'))
                continue
            if contains_private_material(path.read_bytes()):
                findings.append((path, 'credential or private-key material'))
    return findings


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('roots', nargs='+', type=Path)
    args = parser.parse_args()
    findings = scan(args.roots)
    for path, reason in findings:
        print(str(path) + ': ' + reason, file=sys.stderr)
    return 1 if findings else 0


if __name__ == '__main__':
    raise SystemExit(main())
