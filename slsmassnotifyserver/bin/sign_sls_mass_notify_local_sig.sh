#!/bin/bash
set -euo pipefail

MODULE="${1:-$(basename "$(pwd)")}"
MODULE_DIR="/var/www/html/admin/modules/${MODULE}"
MODULE_SIG="${MODULE_DIR}/module.sig"
SIGN_HOME="/root/.gnupg-sls-mass-notify"
SIGNEDBY="Southland Servers Mass Notifications Local Signing <root@$(hostname -f 2>/dev/null || hostname)>"
WORKDIR="$(mktemp -d "/tmp/${MODULE}-sls-local-sign.XXXXXX")"
chmod 700 "$WORKDIR"

cleanup() {
	rm -rf "$WORKDIR"
}
trap cleanup EXIT

if [ ! -d "$MODULE_DIR" ]; then
	echo "Missing module directory: $MODULE_DIR" >&2
	exit 1
fi

FREEPBX_GPG_HOME="$(php -r '
global $amp_conf;
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
include "/etc/freepbx.conf";
$gpg = FreePBX::GPG();
$ref = new ReflectionClass($gpg);
if ($ref->hasMethod("getGpgLocation")) {
	$method = $ref->getMethod("getGpgLocation");
	$method->setAccessible(true);
	echo $method->invoke($gpg);
} else {
	echo "/var/lib/asterisk/.gnupg";
}
exit(0);
')"

if [ -z "$FREEPBX_GPG_HOME" ]; then
	FREEPBX_GPG_HOME="/var/lib/asterisk/.gnupg"
fi

install -d -m 700 "$SIGN_HOME"
if ! GNUPGHOME="$SIGN_HOME" gpg --list-secret-keys --with-colons 2>/dev/null | grep -q '^sec:'; then
	cat > "$WORKDIR/keyparams" <<EOF
Key-Type: RSA
Key-Length: 3072
Name-Real: Southland Servers Mass Notifications Local Signing
Name-Email: root@$(hostname -f 2>/dev/null || hostname)
Expire-Date: 0
%no-protection
%commit
EOF
	GNUPGHOME="$SIGN_HOME" gpg --batch --generate-key "$WORKDIR/keyparams" >/dev/null 2>&1
fi

KEYID="$(GNUPGHOME="$SIGN_HOME" gpg --list-secret-keys --with-colons | awk -F: '/^sec:/ {print $5; exit}')"
FINGERPRINT="$(GNUPGHOME="$SIGN_HOME" gpg --list-secret-keys --with-colons | awk -F: '/^fpr:/ {print $10; exit}')"
if [ -z "$KEYID" ] || [ -z "$FINGERPRINT" ]; then
	echo "No private signing key found in $SIGN_HOME" >&2
	exit 1
fi

TIMESTAMP="$(php -r 'printf("%.4f", microtime(true));')"
HEADER=';################################################
;#        FreePBX Module Signature File         #
;################################################
;# Do not alter the contents of this file!  If  #
;# this file is tampered with, the module will  #
;# fail validation and be marked as invalid!    #
;################################################'

{
	printf '%s\n' "$HEADER"
	printf '[config]\n'
	printf 'version=1\n'
	printf 'hash=sha256\n'
	printf 'signedwith=%s\n' "$KEYID"
	printf "signedby='%s'\n" "$SIGNEDBY"
	printf 'repo=local\n'
	printf 'timestamp=%s\n' "$TIMESTAMP"
	printf '[hashes]\n'
	cd "$MODULE_DIR"
	find . -type f ! -name 'module.sig' ! -name '*.pyc' ! -path '*/__pycache__/*' -printf '%P\n' | LC_ALL=C sort | while IFS= read -r relative_file; do
		printf '%s = %s\n' "$relative_file" "$(sha256sum "$relative_file" | awk '{print $1}')"
	done
} > "$WORKDIR/module.plain"

GNUPGHOME="$SIGN_HOME" gpg --batch --yes --pinentry-mode loopback --passphrase '' \
	--local-user "$KEYID" --clearsign \
	--output "$WORKDIR/module.sig" "$WORKDIR/module.plain"

install -m 644 -o asterisk -g asterisk "$WORKDIR/module.sig" "$MODULE_SIG"

GNUPGHOME="$SIGN_HOME" gpg --armor --export "$KEYID" > "$WORKDIR/public.asc"

trust_home_as_user() {
	local home="$1"
	local user="$2"
	local group="$3"

	[ -n "$home" ] || return 0
	install -d -m 700 -o "$user" -g "$group" "$home"
	if [ "$user" = "root" ]; then
		timeout 30 gpg --homedir "$home" --batch --import "$WORKDIR/public.asc" >/dev/null 2>&1
		printf '%s:6:\n' "$FINGERPRINT" | timeout 30 gpg --homedir "$home" --batch --import-ownertrust >/dev/null 2>&1
	else
		# The work directory is intentionally root-only. Stream the public key so
		# the FreePBX user never needs pathname access to that directory.
		timeout 30 su -s /bin/bash "$user" -c "gpg --homedir '$home' --batch --import" < "$WORKDIR/public.asc" >/dev/null 2>&1
		printf '%s:6:\n' "$FINGERPRINT" | timeout 30 su -s /bin/bash "$user" -c "gpg --homedir '$home' --batch --import-ownertrust" >/dev/null 2>&1
	fi
	if [ "$user" = "root" ]; then
		timeout 30 gpg --homedir "$home" --batch --list-keys "$FINGERPRINT" >/dev/null 2>&1
	else
		timeout 30 su -s /bin/bash "$user" -c "gpg --homedir '$home' --batch --list-keys '$FINGERPRINT'" >/dev/null 2>&1
	fi
	chown -R "$user:$group" "$home" 2>/dev/null || true
	chmod 700 "$home" 2>/dev/null || true
}

declare -A TRUSTED_HOMES=()
for home in "$FREEPBX_GPG_HOME" "/home/asterisk/.gnupg" "/var/lib/asterisk/.gnupg"; do
	if [ -n "$home" ] && [ -z "${TRUSTED_HOMES[$home]+x}" ]; then
		TRUSTED_HOMES[$home]=1
		trust_home_as_user "$home" asterisk asterisk
	fi
done
trust_home_as_user "/root/.gnupg" root root

SIGN_MODULE="$MODULE" php -r '
global $amp_conf;
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
include "/etc/freepbx.conf";
$module = getenv("SIGN_MODULE");
$result = FreePBX::GPG()->verifyModule($module);
FreePBX::Database()
	->prepare("UPDATE modules SET signature = ? WHERE modulename = ?")
	->execute([json_encode($result), $module]);
echo json_encode($result, JSON_PRETTY_PRINT), "\n";
$status = (int)($result["status"] ?? 0);
if (($status & 129) !== 129) {
	exit(1);
}
exit(0);
'
