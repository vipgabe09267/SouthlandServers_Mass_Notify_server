#!/bin/bash
set -euo pipefail

MODULE="${1:-$(basename "$(pwd)")}"
MODULE_DIR="/var/www/html/admin/modules/${MODULE}"
SECURE_DIR="/etc/freepbx.secure"
SECURE_SIG="${SECURE_DIR}/${MODULE}.sig"
MODULE_SIG="${MODULE_DIR}/module.sig"
SIGN_HOME="/root/.gnupg-sls-mass-notify"
SIGNEDBY="Southland Servers Mass Notifications Local Signing <root@$(hostname -f 2>/dev/null || hostname)>"
WORKDIR="$(mktemp -d "/tmp/${MODULE}-sls-local-sign.XXXXXX")"
chmod 755 "$WORKDIR"

cleanup() {
	rm -rf "$WORKDIR"
}
trap cleanup EXIT

if [ ! -d "$MODULE_DIR" ]; then
	echo "Missing module directory: $MODULE_DIR" >&2
	exit 1
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

install -d -m 755 "$SECURE_DIR"

{
	printf '%s\n' "$HEADER"
	printf '[config]\n'
	printf 'version=2\n'
	printf 'hash=sha256\n'
	printf 'type=local\n'
	printf 'signedwith=%s\n' "$KEYID"
	printf "signedby='%s'\n" "$SIGNEDBY"
	printf 'repo=local\n'
	printf 'timestamp=%s\n' "$TIMESTAMP"
	printf '[hashes]\n'
	cd "$MODULE_DIR"
	find . -type f ! -name 'module.sig' ! -name '*.pyc' ! -path '*/__pycache__/*' -printf '%P\n' | LC_ALL=C sort | while IFS= read -r relative_file; do
		printf '%s = %s\n' "$relative_file" "$(sha256sum "$relative_file" | awk '{print $1}')"
	done
} > "$WORKDIR/${MODULE}.secure.plain"

GNUPGHOME="$SIGN_HOME" gpg --batch --yes --pinentry-mode loopback --passphrase '' \
	--local-user "$KEYID" --clearsign \
	--output "$WORKDIR/${MODULE}.secure.sig" "$WORKDIR/${MODULE}.secure.plain"

install -m 644 -o root -g root "$WORKDIR/${MODULE}.secure.sig" "$SECURE_SIG"
SECURE_HASH="$(sha256sum "$SECURE_SIG" | awk '{print $1}')"

{
	printf '%s\n' "$HEADER"
	printf '[config]\n'
	printf 'version=2\n'
	printf 'hash=sha256\n'
	printf 'type=local\n'
	printf 'signedwith=%s\n' "$KEYID"
	printf "signedby='%s'\n" "$SIGNEDBY"
	printf 'repo=local\n'
	printf 'timestamp=%s\n' "$TIMESTAMP"
	printf '[hashes]\n'
	printf '%s.sig = %s\n' "$MODULE" "$SECURE_HASH"
} > "$WORKDIR/${MODULE}.module.plain"

GNUPGHOME="$SIGN_HOME" gpg --batch --yes --pinentry-mode loopback --passphrase '' \
	--local-user "$KEYID" --clearsign \
	--output "$WORKDIR/module.sig" "$WORKDIR/${MODULE}.module.plain"

install -m 664 -o asterisk -g asterisk "$WORKDIR/module.sig" "$MODULE_SIG"

install -d -m 700 -o asterisk -g asterisk /home/asterisk/.gnupg
GNUPGHOME="$SIGN_HOME" gpg --armor --export "$KEYID" > "$WORKDIR/public.asc"
chown asterisk:asterisk "$WORKDIR/public.asc"
su -s /bin/bash asterisk -c "gpg --homedir /home/asterisk/.gnupg --batch --import '$WORKDIR/public.asc' >/dev/null 2>&1 || true"
su -s /bin/bash asterisk -c "printf '${FINGERPRINT}:1:\n' | gpg --homedir /home/asterisk/.gnupg --import-ownertrust >/dev/null 2>&1 || true"

SIGN_MODULE="$MODULE" php -r '
include "/etc/freepbx.conf";
$module = getenv("SIGN_MODULE");
$mod = FreePBX::GPG()->verifyModule($module);
FreePBX::Database()
	->prepare("UPDATE modules SET signature = ? WHERE modulename = ?")
	->execute([json_encode($mod), $module]);
echo json_encode($mod, JSON_PRETTY_PRINT), "\n";
'
