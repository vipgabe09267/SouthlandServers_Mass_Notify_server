#!/usr/bin/env python3
"""Check the verified release SBOM against OSV without installing dependencies."""
import argparse
import json
from pathlib import Path
import sys
import urllib.request
from build_sbom import inventory

ENDPOINT = 'https://api.osv.dev/v1/querybatch'
MAX_RESPONSE = 8 * 1024 * 1024


def queries(bom):
    components = [row for row in bom['components'] if row.get('purl')]
    return components, [{'package': {'purl': row['purl']}} for row in components]


def request(payload):
    body = json.dumps(payload, separators=(',', ':')).encode()
    req = urllib.request.Request(ENDPOINT, body, {'Content-Type': 'application/json', 'User-Agent': 'SLS-release-dependency-audit/1'}, method='POST')
    with urllib.request.urlopen(req, timeout=30) as response:
        raw = response.read(MAX_RESPONSE + 1)
        if response.geturl() != ENDPOINT or len(raw) > MAX_RESPONSE:
            raise ValueError('Unexpected or oversized advisory response')
    return json.loads(raw)


def audit(bom, transport=request):
    components, pending = queries(bom)
    findings = [set() for row in components]
    for page in range(20):
        active = [(index, query) for index, query in enumerate(pending) if query is not None]
        if not active: break
        result = transport({'queries': [query for index, query in active]})
        rows = result.get('results')
        if not isinstance(rows, list) or len(rows) != len(active):
            raise ValueError('Incomplete advisory response')
        for (index, query), row in zip(active, rows):
            if not isinstance(row, dict) or not isinstance(row.get('vulns', []), list):
                raise ValueError('Invalid advisory response')
            for vulnerability in row.get('vulns', []):
                identifier = vulnerability.get('id')
                if not isinstance(identifier, str) or not identifier or len(identifier) > 200:
                    raise ValueError('Invalid advisory identity')
                findings[index].add(identifier)
            token = row.get('next_page_token')
            if token is not None and (not isinstance(token, str) or len(token) > 4096):
                raise ValueError('Invalid advisory pagination')
            pending[index] = {**query, 'page_token': token} if token else None
    else:
        raise ValueError('Advisory pagination exceeded its limit')
    return {'schema': 1, 'source': ENDPOINT, 'components_checked': len(components),
            'scope': 'Declared and embedded release dependencies; not OS packages, FreePBX modules or exploit reachability.',
            'findings': [{'purl': component['purl'], 'advisories': sorted(ids)} for component, ids in zip(components, findings) if ids]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--response-fixture', type=Path, help='Offline response for scanner validation')
    args = parser.parse_args()
    try:
        transport = request if args.response_fixture is None else lambda _: json.loads(args.response_fixture.read_text())
        result = audit(inventory(), transport)
        print(json.dumps(result, indent=2, sort_keys=True))
        return 1 if result['findings'] else 0
    except (OSError, ValueError, KeyError) as error:
        print('Dependency advisory check incomplete: ' + str(error), file=sys.stderr)
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
