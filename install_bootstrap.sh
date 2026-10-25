#!/usr/bin/env bash
set -euo pipefail
umask 077
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH

bootstrap_download() {
  local limit=75
  if [ "${3:-0}" = 1 ]; then limit=180; fi
  /usr/bin/timeout --signal=TERM --kill-after=3 "$limit" /usr/bin/python3 -I - "$1" "$2" "${3:-0}" <<'PY'
import os,re,sys,urllib.request,urllib.parse
from pathlib import Path
root=Path(sys.argv[1]);version=sys.argv[2];tag='slsmassnotifyserver-'+version
class Redirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,request,fp,code,message,headers,url):
        target=urllib.parse.urlsplit(url)
        if target.scheme!='https' or target.hostname not in ('github.com','raw.githubusercontent.com','objects.githubusercontent.com','release-assets.githubusercontent.com') or target.username or target.password or target.port not in (None,443):raise ValueError('Unapproved release redirect')
        return super().redirect_request(request,fp,code,message,headers,url)
client=urllib.request.build_opener(Redirect())
assets=[('release-manifest.json',16384,'https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/'+tag+'/release-manifest.json'),
        ('release-manifest.sig',64,'https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/'+tag+'/release-manifest.sig'),
        ('install_release.sh',1048576,'https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/'+tag+'/tools/install_release.sh')]
if sys.argv[3]=='1':
    if version!='0.1.8-beta':raise SystemExit('The installation correction is only for 0.1.8-beta.')
    base='https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/correction-0.1.8-beta/'
    assets=[(name,maximum,base+name) for name,maximum in
        [('release-manifest.json',16384),('release-manifest.sig',64),('install_release.sh',1048576),
         (tag+'.tgz',33554432),('sls_release_verify.py',32768),('sls_release_trust.py',32768)]]
try:
    for name,maximum,url in assets:
        with client.open(urllib.request.Request(url,headers={'User-Agent':'SLS-Verified-Bootstrap/1'}),timeout=20) as response:
            if response.status!=200:raise ValueError('Release asset is unavailable')
            body=response.read(maximum+1)
        if not 0<len(body)<=maximum:raise ValueError('Release asset exceeded its size bound')
        with (root/name).open('xb') as output:output.write(body);output.flush();os.fsync(output.fileno())
except Exception:
    raise SystemExit('The signed release could not be downloaded within its bounds. Confirm the release exists and HTTPS access to GitHub works; no installer was executed.') from None
PY
}

bootstrap_verify() {
  local directory="$1" version="$2"
  /usr/bin/openssl pkey -pubin -in "$directory/publisher.pem" -outform DER -out "$directory/publisher.der"
  /usr/bin/openssl pkeyutl -verify -pubin -inkey "$directory/publisher.pem" -rawin \
    -in "$directory/release-manifest.json" -sigfile "$directory/release-manifest.sig" >/dev/null || {
      printf 'Publisher signature verification failed. No installer was executed.\n' >&2; return 1;
    }
  /usr/bin/python3 -I - "$directory" "$version" <<'PY'
import hashlib,json,re,sys,time
from pathlib import Path
root=Path(sys.argv[1]);version=sys.argv[2]
def unique(pairs):
    value={}
    for name,item in pairs:
        if name in value:raise ValueError('Duplicate manifest field')
        value[name]=item
    return value
try:
    raw=(root/'release-manifest.json').read_bytes()
    if len(raw)>16384 or (root/'release-manifest.sig').stat().st_size!=64:raise ValueError('Manifest bounds')
    manifest=json.loads(raw,object_pairs_hook=unique)
    if set(manifest)!={'schema','version','tag','package','package_sha256','installer_sha256','signing'} or type(manifest['schema']) is not int or manifest['schema']!=1 or manifest['version']!=version or manifest['tag']!='slsmassnotifyserver-'+version or manifest['package']!='slsmassnotifyserver-'+version+'.tgz':raise ValueError('Release identity')
    signing=manifest['signing'];now=int(time.time())
    if not isinstance(signing,dict) or set(signing)!={'key_id','issued_at','expires_at'} or signing['key_id']!=hashlib.sha256((root/'publisher.der').read_bytes()).hexdigest() or type(signing['issued_at']) is not int or type(signing['expires_at']) is not int or not 0<signing['issued_at']<=now+60 or not signing['issued_at']<signing['expires_at']<=signing['issued_at']+366*86400 or signing['expires_at']<=now:raise ValueError('Signing validity')
    for field in ('installer_sha256','package_sha256'):
        if not isinstance(manifest[field],str) or not re.fullmatch(r'[a-f0-9]{64}',manifest[field]):raise ValueError('Artifact hash')
    installer=(root/'install_release.sh').read_bytes()
    if not 0<len(installer)<=1048576 or hashlib.sha256(installer).hexdigest()!=manifest['installer_sha256']:raise ValueError('Installer hash')
except (OSError,ValueError,TypeError,KeyError):
    raise SystemExit('Signed release identity, validity or installer hash did not match. No installer was executed.') from None
PY
}

bootstrap_verify_correction() {
  local directory="$1" version="$2"
  (
    cd "$directory"
    printf '%s\n' \
      '9d487266152c5f06306ddfb96bc58e839e85e082bf333536bbc3f62638cc45ce  sls_release_verify.py' \
      '05e22d8b2255c14c09ea273ba2b5be74325cb2cb0cfdb7747c98d4b55bc497c4  sls_release_trust.py' \
      | /usr/bin/sha256sum --check
  ) || { printf 'Correction verifier hash mismatch. No installer was executed.\n' >&2; return 1; }
  bootstrap_verify "$directory" "$version" || return 1
  /usr/bin/cp -- "$directory/publisher.pem" "$directory/release-signing.pub"
  # These exact source bytes are pinned above. The verifier reads the existing
  # publisher ledger, including revocations; it never initializes or rewinds it.
  /usr/bin/python3 -I -B "$directory/sls_release_verify.py" \
    --manifest "$directory/release-manifest.json" --signature "$directory/release-manifest.sig" \
    --installer "$directory/install_release.sh" --package "$directory/slsmassnotifyserver-$version.tgz" \
    --version "$version"
}

bootstrap_main() (
  [ "$EUID" -eq 0 ] || { printf 'Run the verified bootstrap as root on the PBX.\n' >&2; exit 2; }
  [ "$#" -ge 1 ] && [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-beta)?$ ]] || { printf 'Usage: bash install_bootstrap.sh VERSION [--correction] [--inspect | --recover TRANSACTION]\n' >&2; exit 2; }
  local version="$1" directory correction=0 package_sha; shift
  if [ "${1:-}" = --correction ]; then
    [ "$version" = 0.1.8-beta ] || { printf 'The installation correction is only for 0.1.8-beta.\n' >&2; exit 2; }
    correction=1; shift
  fi
  local -a installer_arguments=()
  if [ "$#" -eq 1 ] && [ "$1" = --inspect ]; then installer_arguments=(--inspect)
  elif [ "$#" -eq 2 ] && [ "$1" = --recover ] && [[ "$2" =~ ^/var/lib/sls-mass-notify-installer/transaction-[a-f0-9]{24}$ ]]; then installer_arguments=(--recover "$2")
  elif [ "$#" -ne 0 ]; then printf 'Use --correction for the 0.1.8-beta signed correction, optionally followed by --inspect or the exact --recover transaction path printed by the installer. No installer was executed.\n' >&2; exit 2; fi
  for command in /usr/bin/python3 /usr/bin/openssl /usr/bin/mktemp /usr/bin/timeout /bin/bash; do [ -x "$command" ] || { printf 'Missing bootstrap prerequisite: %s\n' "$command" >&2; exit 2; }; done
  if [ "$correction" = 0 ] && { [ -e /var/lib/sls-mass-notify-trust/publisher-trust.json ] || [ -L /var/lib/sls-mass-notify-trust/publisher-trust.json ] || [ -e /var/lib/sls-mass-notify-trust/release-trust.initialized ] || [ -L /var/lib/sls-mass-notify-trust/release-trust.initialized ]; }; then
    printf 'This PBX has an explicit publisher trust policy. Use its installed verified updater or the administrator-approved recovery procedure; the fresh bootstrap will not bypass that policy.\n' >&2; exit 2
  fi
  /usr/bin/python3 -I - <<'PY'
import os,stat
from pathlib import Path
trust=Path('/var/lib/sls-mass-notify-trust')
if trust.exists() or trust.is_symlink():
    if trust.resolve()!=trust:raise SystemExit('The publisher trust directory is not canonical; preserve it for administrator review.')
    for path in (trust,*trust.parents):
        info=path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid!=0 or (info.st_mode&0o022 and (path==trust or not info.st_mode&stat.S_ISVTX)):raise SystemExit('The publisher trust directory is unsafe; no installer was executed.')
    if trust.stat().st_mode&0o077:raise SystemExit('The publisher trust directory must remain root-private.')
value=os.lstat('/tmp')
if not stat.S_ISDIR(value.st_mode) or value.st_uid!=0 or not value.st_mode&stat.S_ISVTX or os.path.realpath('/tmp')!='/tmp':raise SystemExit('The bootstrap requires the canonical root-owned sticky /tmp directory.')
PY
  directory="$(/usr/bin/mktemp -d /tmp/sls-verified-bootstrap.XXXXXXXX)"
  trap 'rm -rf -- "$directory"' EXIT
  cat >"$directory/publisher.pem" <<'KEY'
-----BEGIN PUBLIC KEY-----
MCowBQYDK2VwAyEAFMDOgOaBGcaI8d+v0w/NX4RbwGlBsoktNc2V2F8UkUQ=
-----END PUBLIC KEY-----
KEY
  bootstrap_download "$directory" "$version" "$correction"
  if [ "$correction" = 1 ]; then
    bootstrap_verify_correction "$directory" "$version"
    package_sha="$(/usr/bin/sha256sum -- "$directory/slsmassnotifyserver-$version.tgz")"
    package_sha="${package_sha%% *}"
    printf 'Publisher policy, signature and correction files verified for %s.\n' "$version"
    SLS_MASS_NOTIFY_TGZ_URL='' \
      SLS_MASS_NOTIFY_TGZ="$directory/slsmassnotifyserver-$version.tgz" \
      SLS_MASS_NOTIFY_SHA256="$package_sha" \
      SLS_MASS_NOTIFY_MANIFEST="$directory/release-manifest.json" \
      SLS_MASS_NOTIFY_MANIFEST_SIGNATURE="$directory/release-manifest.sig" \
      /bin/bash "$directory/install_release.sh" "${installer_arguments[@]}"
    exit
  fi
  bootstrap_verify "$directory" "$version"
  printf 'Publisher signature and installer hash verified for %s.\n' "$version"
  SLS_MASS_NOTIFY_TGZ_URL="https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-$version/slsmassnotifyserver-$version.tgz" \
    /bin/bash "$directory/install_release.sh" "${installer_arguments[@]}"
)

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then bootstrap_main "$@"; fi
