#!/usr/bin/env bash
set -Eeuo pipefail

umask 077
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH

MODULE="${1:-$(basename "$(pwd)")}"
SIGN_HOME="${SLS_LOCAL_SIGN_HOME:-/root/.gnupg-sls-mass-notify}"
LOCK_FILE="${SLS_LOCAL_SIGN_LOCK:-/run/lock/sls-mass-notify-signing.lock}"
WORKDIR=""
MODULE_DIR=""
MODULE_SIG=""
PREVIOUS_SIG=""
SIGNATURE_PUBLISHED=0
FREEPBX_WEB_USER=""
FREEPBX_WEB_GROUP=""
FREEPBX_WEB_ROOT=""
FREEPBX_GPG_HOME=""
FREEPBX_USER_HOME=""
FREEPBX_ASTSPOOLDIR=""
SIGNING_FINGERPRINT=""

log() {
	printf 'SLS local signer: %s\n' "$*" >&2
}

# The installer, updater, repair worker, and uninstaller deliberately hold one
# shared maintenance lock while invoking this signer. FreePBX signature checks
# can start a background GPG key refresh, so the signer must not pass that lock
# descriptor to its descendants after the parent transaction has already kept
# its own copy open.
close_inherited_maintenance_lock() {
	local lock_file="${SLS_MASS_NOTIFY_MAINTENANCE_LOCK:-/run/lock/sls-mass-notify-maintenance.lock}"
	local descriptor descriptor_path descriptor_target close_fd

	for descriptor_path in "/proc/${BASHPID}/fd/"*; do
		[ -e "$descriptor_path" ] || continue
		descriptor="${descriptor_path##*/}"
		[[ "$descriptor" =~ ^[0-9]+$ ]] && [ "$descriptor" -gt 2 ] || continue
		descriptor_target="$(readlink -f -- "$descriptor_path" 2>/dev/null || true)"
		[ "$descriptor_target" = "$lock_file" ] || continue
		close_fd="$descriptor"
		exec {close_fd}>&-
	done
}

restore_previous_signature() {
	local restore_target

	[ "$SIGNATURE_PUBLISHED" -eq 1 ] || return 0
	if [ -n "$PREVIOUS_SIG" ] && [ -f "$PREVIOUS_SIG" ]; then
		restore_target="${MODULE_DIR}/.module.sig.sls-restore.$$"
		if ! cp -p -- "$PREVIOUS_SIG" "$restore_target" \
			|| ! mv -f -- "$restore_target" "$MODULE_SIG"; then
			rm -f -- "$restore_target"
			log "CRITICAL: unable to restore the previous signature for $MODULE."
			return 1
		fi
	else
		if ! rm -f -- "$MODULE_SIG"; then
			log "CRITICAL: unable to remove the rejected signature for $MODULE."
			return 1
		fi
	fi
	SIGNATURE_PUBLISHED=0
}

cleanup() {
	local status=$?
	trap - EXIT
	if [ "$status" -ne 0 ]; then
		restore_previous_signature || true
	fi
	if [ -n "$WORKDIR" ] && [ -d "$WORKDIR" ]; then
		find "$WORKDIR" -depth -delete 2>/dev/null || true
	fi
	exit "$status"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

decode_metadata_field() {
	printf '%s' "$1" | base64 --decode
}

load_freepbx_metadata() {
	local metadata_file="$WORKDIR/freepbx-signing-metadata"
	local -a metadata

	if ! php -d pcre.jit=0 -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
$webUser = trim((string)\FreePBX::Config()->get("AMPASTERISKWEBUSER"));
$webRoot = rtrim(trim((string)\FreePBX::Config()->get("AMPWEBROOT")), "/");
$astSpool = rtrim(trim((string)\FreePBX::Config()->get("ASTSPOOLDIR")), "/");
$account = $webUser !== "" && function_exists("posix_getpwnam") ? posix_getpwnam($webUser) : false;
if (!is_array($account) || $webRoot === "") {
	fwrite(STDERR, "Unable to resolve the configured FreePBX web account or web root; PHP POSIX support is required.\n");
	exit(1);
}
$gpg = \FreePBX::GPG();
$gpgHome = rtrim(trim((string)$gpg->getGpgLocation()), "/");
$values = [
	$webUser,
	$webRoot,
	$gpgHome,
	rtrim((string)($account["dir"] ?? ""), "/"),
	$astSpool,
];
foreach ($values as $value) {
	echo base64_encode($value), "\n";
}
exit(0);
' >"$metadata_file"; then
		log "FreePBX could not provide its signing metadata."
		return 1
	fi

	mapfile -t metadata <"$metadata_file"
	if [ "${#metadata[@]}" -ne 5 ]; then
		log "FreePBX returned incomplete signing metadata."
		return 1
	fi
	FREEPBX_WEB_USER="$(decode_metadata_field "${metadata[0]}")"
	FREEPBX_WEB_ROOT="$(decode_metadata_field "${metadata[1]}")"
	FREEPBX_GPG_HOME="$(decode_metadata_field "${metadata[2]}")"
	FREEPBX_USER_HOME="$(decode_metadata_field "${metadata[3]}")"
	FREEPBX_ASTSPOOLDIR="$(decode_metadata_field "${metadata[4]}")"
	FREEPBX_WEB_GROUP="$(id -gn "$FREEPBX_WEB_USER" 2>/dev/null || true)"
}

validate_absolute_path() {
	local path="$1"
	[ -n "$path" ] \
		&& [[ "$path" = /* ]] \
		&& [ "$path" != "/" ] \
		&& [[ "$path" != *$'\n'* ]] \
		&& [[ "$path" != *$'\r'* ]]
}

validate_freepbx_metadata() {
	local module_root expected_module_dir canonical_module_dir
	local canonical_gpg_home canonical_user_home canonical_spool_home

	id "$FREEPBX_WEB_USER" >/dev/null 2>&1 || {
		log "Configured FreePBX web user does not exist: $FREEPBX_WEB_USER"
		return 1
	}
	[ -n "$FREEPBX_WEB_GROUP" ] || {
		log "Unable to resolve the primary group for FreePBX user $FREEPBX_WEB_USER."
		return 1
	}
	validate_absolute_path "$FREEPBX_WEB_ROOT" || {
		log "FreePBX returned an unsafe web root."
		return 1
	}
	validate_absolute_path "$FREEPBX_GPG_HOME" || {
		log "FreePBX returned an unsafe GPG home."
		return 1
	}
	validate_absolute_path "$FREEPBX_USER_HOME" || {
		log "FreePBX returned an unsafe web-user home."
		return 1
	}
	if [ -L "$FREEPBX_GPG_HOME" ]; then
		log "Refusing a symbolic-link FreePBX GPG home: $FREEPBX_GPG_HOME"
		return 1
	fi

	canonical_gpg_home="$(readlink -m -- "$FREEPBX_GPG_HOME")"
	canonical_user_home="$(readlink -m -- "${FREEPBX_USER_HOME}/.gnupg")"
	canonical_spool_home=""
	if validate_absolute_path "$FREEPBX_ASTSPOOLDIR"; then
		canonical_spool_home="$(readlink -m -- "${FREEPBX_ASTSPOOLDIR}/.gnupg")"
	fi
	if [ "$canonical_gpg_home" != "$canonical_user_home" ] \
		&& { [ -z "$canonical_spool_home" ] || [ "$canonical_gpg_home" != "$canonical_spool_home" ]; }; then
		log "FreePBX GPG home is outside the configured web-user home and Asterisk spool."
		return 1
	fi
	FREEPBX_GPG_HOME="$canonical_gpg_home"

	module_root="$(readlink -m -- "${FREEPBX_WEB_ROOT}/admin/modules")"
	expected_module_dir="${module_root}/${MODULE}"
	[ -d "$expected_module_dir" ] && [ ! -L "$expected_module_dir" ] || {
		log "Missing or unsafe module directory: $expected_module_dir"
		return 1
	}
	canonical_module_dir="$(readlink -m -- "$expected_module_dir")"
	[ "$canonical_module_dir" = "$expected_module_dir" ] || {
		log "Module directory escaped the configured FreePBX module root."
		return 1
	}
	MODULE_DIR="$canonical_module_dir"
	MODULE_SIG="${MODULE_DIR}/module.sig"
	if [ -L "$MODULE_SIG" ]; then
		log "Refusing a symbolic-link module signature: $MODULE_SIG"
		return 1
	fi
}

acquire_signing_lock() {
	local lock_parent

	validate_absolute_path "$LOCK_FILE" || {
		log "Unsafe signer lock path."
		return 1
	}
	[ ! -L "$LOCK_FILE" ] || {
		log "Refusing a symbolic-link signer lock: $LOCK_FILE"
		return 1
	}
	lock_parent="$(dirname "$LOCK_FILE")"
	install -d -m 0755 -o root -g root "$lock_parent"
	exec 9>"$LOCK_FILE"
	chown root:root "$LOCK_FILE"
	chmod 0600 "$LOCK_FILE"
	flock -w 120 9 || {
		log "Timed out waiting for another local-signing operation to finish."
		return 1
	}
}

prepare_signing_home() {
	local signing_home_symlink=""

	validate_absolute_path "$SIGN_HOME" || {
		log "Unsafe private signing-home path."
		return 1
	}
	[ ! -L "$SIGN_HOME" ] || {
		log "Refusing a symbolic-link private signing home: $SIGN_HOME"
		return 1
	}
	install -d -m 0700 -o root -g root "$SIGN_HOME" || {
		log "Unable to prepare the private signing home."
		return 1
	}
	if ! signing_home_symlink="$(find "$SIGN_HOME" -xdev -type l -print -quit)"; then
		log "Unable to inspect the private signing home."
		return 1
	fi
	if [ -n "$signing_home_symlink" ]; then
		log "Refusing a private signing home containing symbolic links."
		return 1
	fi
	chown -R -h root:root "$SIGN_HOME" || {
		log "Unable to repair private signing-home ownership."
		return 1
	}
	find "$SIGN_HOME" -xdev -type d -exec chmod 0700 {} + || {
		log "Unable to secure private signing-home directories."
		return 1
	}
	find "$SIGN_HOME" -xdev -type f -exec chmod 0600 {} + || {
		log "Unable to secure private signing-home files."
		return 1
	}
}

signing_home_gpg() {
	local timeout_seconds="$1"
	shift
	timeout "$timeout_seconds" env GNUPGHOME="$SIGN_HOME" \
		gpg --homedir "$SIGN_HOME" --batch --yes "$@"
}

secret_fingerprints() {
	signing_home_gpg 60 --with-colons --list-secret-keys 2>/dev/null \
		| awk -F: '
			$1 == "sec" { want_fingerprint = 1; next }
			want_fingerprint && $1 == "fpr" {
				print toupper($10)
				want_fingerprint = 0
			}
		'
}

fingerprint_can_sign() {
	local fingerprint="$1"
	local probe_signature="$WORKDIR/key-probe-${fingerprint}.asc"

	[[ "$fingerprint" =~ ^[A-F0-9]{40,64}$ ]] || return 1
	if signing_home_gpg 60 --pinentry-mode loopback --passphrase '' \
		--local-user "${fingerprint}!" --clearsign \
		--output "$probe_signature" "$WORKDIR/key-probe.txt" \
		>"$WORKDIR/key-probe.stdout" 2>"$WORKDIR/key-probe.stderr"; then
		rm -f -- "$probe_signature"
		return 0
	fi
	rm -f -- "$probe_signature"
	return 1
}

select_usable_signing_key() {
	local fingerprint_file="$SIGN_HOME/sls-signing-fingerprint"
	local fingerprints_file="$WORKDIR/secret-fingerprints"
	local candidate=""
	local -a fingerprints

	printf '%s\n' "SLS local signing key probe" >"$WORKDIR/key-probe.txt" || return 1
	if [ -f "$fingerprint_file" ] && [ ! -L "$fingerprint_file" ]; then
		candidate="$(tr -d '[:space:]' <"$fingerprint_file" | tr '[:lower:]' '[:upper:]')"
		if fingerprint_can_sign "$candidate"; then
			SIGNING_FINGERPRINT="$candidate"
		fi
	fi

	if [ -z "$SIGNING_FINGERPRINT" ]; then
		if ! secret_fingerprints >"$fingerprints_file"; then
			log "Unable to inventory the private local signing keys."
			return 1
		fi
		mapfile -t fingerprints <"$fingerprints_file"
		for candidate in "${fingerprints[@]}"; do
			if fingerprint_can_sign "$candidate"; then
				SIGNING_FINGERPRINT="$candidate"
				break
			fi
		done
	fi

	if [ -z "$SIGNING_FINGERPRINT" ]; then
		if ! cat >"$WORKDIR/keyparams" <<EOF
Key-Type: RSA
Key-Length: 3072
Name-Real: Southland Servers Mass Notifications Local Signing
Name-Email: root@${SLS_SIGNER_HOSTNAME}
Expire-Date: 0
%no-protection
%commit
EOF
		then
			log "Unable to build the local signing-key parameters."
			return 1
		fi
		log "Generating a PBX-local module signing key."
		if ! signing_home_gpg 180 --generate-key "$WORKDIR/keyparams"; then
			log "Local signing-key generation failed."
			return 1
		fi
		if ! secret_fingerprints >"$fingerprints_file"; then
			log "Unable to inventory the generated local signing key."
			return 1
		fi
		mapfile -t fingerprints <"$fingerprints_file"
		for candidate in "${fingerprints[@]}"; do
			if fingerprint_can_sign "$candidate"; then
				SIGNING_FINGERPRINT="$candidate"
				break
			fi
		done
	fi

	[ -n "$SIGNING_FINGERPRINT" ] || {
		log "No usable private local signing key is available in $SIGN_HOME."
		if [ -s "$WORKDIR/key-probe.stderr" ]; then
			tail -20 "$WORKDIR/key-probe.stderr" >&2 || true
		fi
		return 1
	}
	printf '%s\n' "$SIGNING_FINGERPRINT" >"$WORKDIR/signing-fingerprint" || return 1
	chmod 0600 "$WORKDIR/signing-fingerprint" || return 1
	mv -f -- "$WORKDIR/signing-fingerprint" "$fingerprint_file" || return 1
	chown root:root "$fingerprint_file" || return 1
	chmod 0600 "$fingerprint_file" || return 1
}

prepare_freepbx_gpg_home() {
	local freepbx_home_symlink=""

	install -d -m 0700 -o "$FREEPBX_WEB_USER" -g "$FREEPBX_WEB_GROUP" "$FREEPBX_GPG_HOME" || {
		log "Unable to prepare the exact FreePBX GPG home."
		return 1
	}
	if ! freepbx_home_symlink="$(find "$FREEPBX_GPG_HOME" -xdev -type l -print -quit)"; then
		log "Unable to inspect the FreePBX GPG home."
		return 1
	fi
	if [ -n "$freepbx_home_symlink" ]; then
		log "Refusing a FreePBX GPG home containing symbolic links."
		return 1
	fi
	# Restored/cloned PBXs commonly contain root-owned keybox or trust files.
	# Repair the exact FreePBX GPG home before invoking GPG as its web account.
	chown -R -h "$FREEPBX_WEB_USER:$FREEPBX_WEB_GROUP" "$FREEPBX_GPG_HOME" || {
		log "Unable to repair FreePBX GPG-home ownership."
		return 1
	}
	find "$FREEPBX_GPG_HOME" -xdev -type d -exec chmod 0700 {} + || {
		log "Unable to secure FreePBX GPG-home directories."
		return 1
	}
	find "$FREEPBX_GPG_HOME" -xdev -type f -exec chmod 0600 {} + || {
		log "Unable to secure FreePBX GPG-home files."
		return 1
	}
}

run_freepbx_gpg() {
	local stage="$1"
	local stdin_file="$2"
	local stdout_file="$3"
	shift 3
	local attempt

	for attempt in 1 2 3; do
		if timeout 45 runuser -u "$FREEPBX_WEB_USER" -- \
			env HOME="$(dirname "$FREEPBX_GPG_HOME")" PATH="$PATH" \
			gpg --homedir "$FREEPBX_GPG_HOME" --batch --yes --no-permission-warning "$@" \
			<"$stdin_file" >"$stdout_file"; then
			return 0
		fi
		log "$stage failed on attempt $attempt of 3."
		[ "$attempt" -eq 3 ] || sleep "$attempt"
	done
	return 1
}

trust_signing_key_in_freepbx() {
	local public_key="$WORKDIR/public.asc"
	local ownertrust="$WORKDIR/ownertrust.txt"
	local exported_trust="$WORKDIR/exported-ownertrust.txt"

	if ! signing_home_gpg 60 --armor --export "$SIGNING_FINGERPRINT" >"$public_key"; then
		log "Unable to export the PBX-local public signing key."
		return 1
	fi
	[ -s "$public_key" ] || {
		log "The PBX-local public signing-key export was empty."
		return 1
	}
	printf '%s:6:\n' "$SIGNING_FINGERPRINT" >"$ownertrust" || {
		log "Unable to build the FreePBX owner-trust import."
		return 1
	}

	prepare_freepbx_gpg_home || return 1
	run_freepbx_gpg "FreePBX public-key import" "$public_key" /dev/null --import || return 1
	run_freepbx_gpg "FreePBX owner-trust import" "$ownertrust" /dev/null --import-ownertrust || return 1
	run_freepbx_gpg "FreePBX public-key lookup" /dev/null /dev/null \
		--list-keys "$SIGNING_FINGERPRINT" || return 1
	run_freepbx_gpg "FreePBX owner-trust verification" /dev/null "$exported_trust" \
		--export-ownertrust || return 1
	grep -Fqx "${SIGNING_FINGERPRINT}:6:" "$exported_trust" || {
		log "FreePBX did not retain ultimate trust for the PBX-local signing key."
		return 1
	}
}

build_candidate_signature() {
	local keyid="${SIGNING_FINGERPRINT: -16}"
	local file_list="$WORKDIR/module-files.list"
	local relative_file file_hash timestamp

	if ! timestamp="$(php -r 'printf("%.4f", microtime(true));')"; then
		log "Unable to create the module signature timestamp."
		return 1
	fi
	if ! find "$MODULE_DIR" -type f \
			! -name 'module.sig' \
			! -name '.module.sig.sls-*' \
			! -name '*.pyc' \
			! -path '*/__pycache__/*' \
			-printf '%P\0' | LC_ALL=C sort -z >"$file_list"; then
		log "Unable to enumerate module files for signing."
		return 1
	fi

	if ! cat >"$WORKDIR/module.plain" <<EOF
;################################################
;#        FreePBX Module Signature File         #
;################################################
;# Do not alter the contents of this file!  If  #
;# this file is tampered with, the module will  #
;# fail validation and be marked as invalid!    #
;################################################
[config]
version=1
hash=sha256
signedwith=${keyid}
signedby='Southland Servers Mass Notifications Local Signing <root@${SLS_SIGNER_HOSTNAME}>'
repo=local
timestamp=${timestamp}
[hashes]
EOF
	then
		log "Unable to build the plain-text module signature manifest."
		return 1
	fi
	while IFS= read -r -d '' relative_file; do
		[[ "$relative_file" != *$'\n'* && "$relative_file" != *'='* ]] || {
			log "Module contains a filename that cannot be represented safely in module.sig."
			return 1
		}
		if ! file_hash="$(sha256sum "$MODULE_DIR/$relative_file" | awk '{print $1}')"; then
			log "Unable to hash module file: $relative_file"
			return 1
		fi
		[[ "$file_hash" =~ ^[a-f0-9]{64}$ ]] || {
			log "Invalid SHA-256 result for module file: $relative_file"
			return 1
		}
		printf '%s = %s\n' "$relative_file" "$file_hash" \
			>>"$WORKDIR/module.plain" || return 1
	done <"$file_list"

	if ! signing_home_gpg 90 --pinentry-mode loopback --passphrase '' \
		--local-user "${SIGNING_FINGERPRINT}!" --clearsign \
		--output "$WORKDIR/module.sig.candidate" "$WORKDIR/module.plain"; then
		log "GPG could not create the candidate module signature."
		return 1
	fi
}

verify_published_signature() {
	SIGN_MODULE="$MODULE" php -d pcre.jit=0 -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
$module = (string)getenv("SIGN_MODULE");
$gpg = \FreePBX::GPG();
$gpg->timeout = 30;
$result = $gpg->verifyModule($module);
echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
$valid = is_array($result)
	&& array_key_exists("status", $result)
	&& is_int($result["status"])
	&& $result["status"] === 129
	&& array_key_exists("details", $result)
	&& is_array($result["details"])
	&& count($result["details"]) === 0;
if (!$valid) {
	exit(1);
}
\FreePBX::Database()
	->prepare("UPDATE modules SET signature = ? WHERE modulename = ?")
	->execute([json_encode($result), $module]);
exit(0);
'
}

publish_candidate_transactionally() {
	local candidate_target="${MODULE_DIR}/.module.sig.sls-new.$$"

	PREVIOUS_SIG=""
	if [ -e "$MODULE_SIG" ]; then
		[ -f "$MODULE_SIG" ] && [ ! -L "$MODULE_SIG" ] || {
			log "Existing module signature is not a safe regular file."
			return 1
		}
		if ! cp -p -- "$MODULE_SIG" "$WORKDIR/module.sig.previous"; then
			log "Unable to preserve the previous module signature."
			return 1
		fi
		PREVIOUS_SIG="$WORKDIR/module.sig.previous"
	fi

	if ! install -m 0644 -o "$FREEPBX_WEB_USER" -g "$FREEPBX_WEB_GROUP" \
		"$WORKDIR/module.sig.candidate" "$candidate_target"; then
		log "Unable to stage the candidate module signature."
		rm -f -- "$candidate_target"
		return 1
	fi
	if ! mv -f -- "$candidate_target" "$MODULE_SIG"; then
		log "Unable to publish the candidate module signature."
		rm -f -- "$candidate_target"
		return 1
	fi
	SIGNATURE_PUBLISHED=1
	if ! prepare_freepbx_gpg_home; then
		log "Unable to leave the FreePBX GPG home in a web-user-readable state."
		restore_previous_signature
		return 1
	fi
	if verify_published_signature; then
		SIGNATURE_PUBLISHED=0
		return 0
	fi
	log "FreePBX rejected the candidate signature for $MODULE; restoring the previous signature."
	restore_previous_signature
	return 1
}

sign_module_transaction() {
	build_candidate_signature || return 1
	trust_signing_key_in_freepbx || return 1
	publish_candidate_transactionally
}

main() {
	local attempt

	[ "${EUID:-$(id -u)}" -eq 0 ] || {
		log "Run the local signer as root."
		exit 1
	}
	[[ "$MODULE" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] \
		&& [ "$MODULE" != "." ] \
		&& [ "$MODULE" != ".." ] || {
			log "Unsafe module name."
			exit 1
		}
	for command_name in php gpg base64 readlink runuser timeout flock sha256sum; do
		command -v "$command_name" >/dev/null 2>&1 || {
			log "Required signing command is unavailable: $command_name"
			exit 1
		}
	done
	close_inherited_maintenance_lock

	SLS_SIGNER_HOSTNAME="$(hostname -f 2>/dev/null || hostname || true)"
	SLS_SIGNER_HOSTNAME="$(printf '%s' "$SLS_SIGNER_HOSTNAME" | tr -cd 'A-Za-z0-9.-')"
	[ -n "$SLS_SIGNER_HOSTNAME" ] || SLS_SIGNER_HOSTNAME="localhost"

	acquire_signing_lock
	WORKDIR="$(mktemp -d "/tmp/${MODULE}-sls-local-sign.XXXXXX")"
	chmod 0700 "$WORKDIR"
	load_freepbx_metadata
	validate_freepbx_metadata
	prepare_signing_home
	select_usable_signing_key

	for attempt in 1 2; do
		if sign_module_transaction; then
			log "Verified $MODULE with FreePBX trusted status 129."
			return 0
		fi
		[ "$attempt" -eq 2 ] || {
			log "Retrying $MODULE after a transient signing or module-file race."
			sleep 2
		}
	done
	log "Unable to produce a trusted local signature for $MODULE."
	return 1
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
	main "$@"
fi
