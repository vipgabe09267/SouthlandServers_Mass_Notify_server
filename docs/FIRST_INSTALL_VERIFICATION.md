# Verify a first installation

The first installation has no trusted SLS verifier on the PBX. Verify the
downloaded installer with the host's OpenSSL and Python before executing it.
Upgrades through the installed protected updater already verify the release
manifest, installer and package before execution.

Confirm the publisher key below against your independently retained SLS key
record. A key downloaded with an installer cannot establish its own authority.
The current publisher key remains unchanged:

```text
-----BEGIN PUBLIC KEY-----
MCowBQYDK2VwAyEAFMDOgOaBGcaI8d+v0w/NX4RbwGlBsoktNc2V2F8UkUQ=
-----END PUBLIC KEY-----
```

The following commands use a root-private directory and execute no downloaded
code until both signature and installer hash checks succeed. The `0.1.8-beta`
candidate is not published yet; use this version only after release approval.

```bash
 /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/root LANG=C.UTF-8 \
 /usr/bin/bash --noprofile --norc <<'SLS_BOOTSTRAP'
 (
  set -euo pipefail
  umask 077
  SLS_INSTALL_DIR="$(/usr/bin/mktemp -d /tmp/sls-verified-install.XXXXXXXX)"
  cd "$SLS_INSTALL_DIR"
  SLS_VERSION='0.1.8-beta'
  SLS_TAG="slsmassnotifyserver-$SLS_VERSION"
  SLS_RELEASE="https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/$SLS_TAG"
  /usr/bin/curl --disable --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 60 --max-filesize 16384 -fsSL -o release-manifest.json "$SLS_RELEASE/release-manifest.json"
  /usr/bin/curl --disable --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 60 --max-filesize 64 -fsSL -o release-manifest.sig "$SLS_RELEASE/release-manifest.sig"
  /usr/bin/curl --disable --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 120 --max-filesize 1048576 -fsSL -o sls-install.sh "https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/$SLS_TAG/tools/install_release.sh"
  /usr/bin/cat >publisher.pub <<'KEY'
-----BEGIN PUBLIC KEY-----
MCowBQYDK2VwAyEAFMDOgOaBGcaI8d+v0w/NX4RbwGlBsoktNc2V2F8UkUQ=
-----END PUBLIC KEY-----
KEY
  /usr/bin/openssl pkeyutl -verify -pubin -inkey publisher.pub -rawin \
    -in release-manifest.json -sigfile release-manifest.sig
  /usr/bin/python3 -I - "$SLS_VERSION" <<'PY'
import base64, hashlib, json, pathlib, re, sys, time

def require(condition, message):
    if not condition:
        raise SystemExit(message)

def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'Release manifest contains duplicate members')
        result[key] = value
    return result

def reject_constant(value):
    raise SystemExit('Release manifest contains a non-finite number')

path = pathlib.Path('release-manifest.json')
require(path.stat().st_size <= 16384, 'Release manifest exceeds 16 KiB')
manifest = json.loads(path.read_text(encoding='utf-8'), object_pairs_hook=unique_object,
                      parse_constant=reject_constant)
require(isinstance(manifest, dict) and type(manifest.get('schema')) is int and manifest['schema'] == 1, 'Unsupported release schema')
require(set(manifest) == {'schema', 'version', 'tag', 'package', 'package_sha256', 'installer_sha256', 'signing'},
        'Release manifest fields are incomplete or unsupported')
require(manifest.get('version') == sys.argv[1], 'Release version mismatch')
require(manifest.get('tag') == 'slsmassnotifyserver-' + sys.argv[1], 'Release tag mismatch')
require(manifest.get('package') == 'slsmassnotifyserver-' + sys.argv[1] + '.tgz', 'Release package mismatch')
expected = manifest.get('installer_sha256', '')
require(isinstance(expected, str) and re.fullmatch('[a-f0-9]{64}', expected), 'Missing installer hash')
require(isinstance(manifest.get('package_sha256'), str) and re.fullmatch('[a-f0-9]{64}', manifest['package_sha256']),
        'Missing package hash')
public = pathlib.Path('publisher.pub').read_text().splitlines()
der = base64.b64decode(''.join(public[1:-1]), validate=True)
signing = manifest.get('signing')
require(isinstance(signing, dict) and set(signing) == {'key_id', 'issued_at', 'expires_at'},
        'Missing or unsupported signed validity metadata')
require(signing.get('key_id') == hashlib.sha256(der).hexdigest(), 'Publisher key mismatch')
issued, expires = signing.get('issued_at'), signing.get('expires_at')
require(type(issued) is int and type(expires) is int, 'Missing signed validity dates')
now = int(time.time())
require(0 <= issued <= now + 300 and issued < expires and now < expires, 'Release validity failed; check PBX time')
with pathlib.Path('sls-install.sh').open('rb') as source:
    require(hashlib.file_digest(source, 'sha256').hexdigest() == expected, 'Installer hash mismatch')
PY
  SLS_MASS_NOTIFY_TGZ_URL="$SLS_RELEASE/$SLS_TAG.tgz" /usr/bin/bash ./sls-install.sh
)
SLS_BOOTSTRAP
```

Keep failed verification or installation evidence for review. Never remove
signature/hash checks to make a download run. For an established installation,
follow [publisher policy and recovery](RELEASE_TRUST.md); this first-install
procedure does not rewind a PBX's key revocations or approved generations.
