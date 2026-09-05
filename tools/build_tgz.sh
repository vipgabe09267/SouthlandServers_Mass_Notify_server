#!/usr/bin/env bash
set -euo pipefail

umask 027
export PYTHONDONTWRITEBYTECODE=1

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULE="slsmassnotifyserver"
VERSION="$(php -r '$x=simplexml_load_file($argv[1]); if (!$x) exit(1); echo (string)$x->version;' "${ROOT_DIR}/${MODULE}/module.xml")"
DIST_DIR="${ROOT_DIR}/dist"
PACKAGE="${DIST_DIR}/${MODULE}-${VERSION}.tgz"

mkdir -p "${DIST_DIR}"
rm -f "${PACKAGE}"

for document in README.md INSTALL.md CHANGELOG.md SECURITY.md PHONE_FORMATS.md LICENSE; do
  cmp -s "${ROOT_DIR}/${document}" "${ROOT_DIR}/${MODULE}/${document}" || {
    printf 'Root and module copies differ: %s\n' "$document" >&2
    exit 1
  }
done

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find "${ROOT_DIR}/${MODULE}" -type f -name '*.php' -print0)

while IFS= read -r -d '' file; do
  bash -n "$file"
done < <(find "${ROOT_DIR}/${MODULE}" "${ROOT_DIR}/tools" -type f -name '*.sh' -print0)

python3 - "${ROOT_DIR}/${MODULE}" <<'PY'
import ast
import pathlib
import sys

for path in pathlib.Path(sys.argv[1]).rglob("*.py"):
    ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
PY

python3 "${ROOT_DIR}/tools/test_release_portability.py"
bash "${ROOT_DIR}/tools/test_installer_asterisk_capabilities.sh"
bash "${ROOT_DIR}/tools/test_installer_timezone.sh"
bash "${ROOT_DIR}/tools/test_installer_config_safety.sh"
bash "${ROOT_DIR}/tools/test_local_signer.sh"
bash "${ROOT_DIR}/tools/test_uninstaller_signer_snapshot.sh"
php "${ROOT_DIR}/tools/test_scheduling_contract.php"
php "${ROOT_DIR}/tools/test_announcement_delivery_contract.php"
python3 "${ROOT_DIR}/tools/test_announcement_display_timeout.py"
php "${ROOT_DIR}/tools/test_desktop_announcement_expiry.php"
php "${ROOT_DIR}/tools/test_email_sender_domain.php"
python3 "${ROOT_DIR}/tools/test_email_sender_domain.py"
php "${ROOT_DIR}/tools/test_notification_destinations.php"
python3 "${ROOT_DIR}/tools/test_notification_destinations.py"
php "${ROOT_DIR}/tools/test_notification_log_taxonomy.php"
php "${ROOT_DIR}/tools/test_nws_zone_destinations.php"
python3 "${ROOT_DIR}/tools/test_nws_zone_destinations.py"
python3 "${ROOT_DIR}/tools/test_nws_cross_zone_claims.py"
php "${ROOT_DIR}/tools/test_configuration_security_contract.php"
php "${ROOT_DIR}/tools/test_ui_performance_contract.php"
php "${ROOT_DIR}/tools/test_update_contract.php"
python3 "${ROOT_DIR}/tools/test_external_delivery_retry.py"
python3 "${ROOT_DIR}/tools/test_system_notifications.py"
python3 "${ROOT_DIR}/tools/test_sls_notify_journal.py"
python3 "${ROOT_DIR}/tools/test_xweather_runtime_safety.py"
python3 "${ROOT_DIR}/tools/test_xweather_manual_test_status.py"
python3 "${ROOT_DIR}/tools/test_xweather_usage_period.py"
php "${ROOT_DIR}/tools/test_xweather_usage_period.php"
python3 "${ROOT_DIR}/tools/test_xweather_groups.py"
php "${ROOT_DIR}/tools/test_xweather_groups.php"
python3 "${ROOT_DIR}/tools/test_nws_alert_dedup.py"
python3 "${ROOT_DIR}/tools/test_nws_status_concurrency.py"
python3 "${ROOT_DIR}/tools/test_alert_worker_cli_safety.py"
python3 "${ROOT_DIR}/tools/test_weather_manual_test_contract.py"
php "${ROOT_DIR}/tools/test_freepbx_backup_restore.php"
php "${ROOT_DIR}/tools/test_help_ui_contract.php"
php "${ROOT_DIR}/tools/test_desktop_reliability.php"
php "${ROOT_DIR}/tools/test_independent_channels.php"
python3 "${ROOT_DIR}/tools/test_delivery_storage_security.py"
python3 "${ROOT_DIR}/tools/test_lightning_observations.py"
python3 "${ROOT_DIR}/tools/test_weather_channel_isolation.py"
python3 "${ROOT_DIR}/tools/test_release_manifest.py"
python3 "${ROOT_DIR}/tools/test_device_overrides.py"
python3 "${ROOT_DIR}/tools/test_weather_queue.py"
python3 "${ROOT_DIR}/tools/test_installer_runtime_manifest.py"
php "${ROOT_DIR}/tools/test_profiles.php"
python3 "${ROOT_DIR}/tools/test_audio_priority.py"
python3 "${ROOT_DIR}/tools/test_announcement_footer.py"
php "${ROOT_DIR}/tools/test_support_diagnostics.php"

cmp -s "${ROOT_DIR}/tools/uninstall_release.sh" "${ROOT_DIR}/${MODULE}/bin/sls_mass_notify_uninstall.sh" || {
  printf 'Standalone and packaged uninstallers differ.\n' >&2
  exit 1
}

python3 - "${ROOT_DIR}/tools/uninstall_release.sh" "${ROOT_DIR}/${MODULE}/bin/sls_mass_notify_uninstall.sh" <<'PY'
import pathlib
import re
import subprocess
import sys

for filename in sys.argv[1:]:
    source = pathlib.Path(filename).read_text(encoding="utf-8")
    blocks = re.findall(r"<<'PHP'\n(.*?)\nPHP\n", source, flags=re.DOTALL)
    if not blocks:
        raise SystemExit(f"no embedded PHP blocks found in {filename}")
    for index, block in enumerate(blocks, start=1):
        result = subprocess.run(
            ["php", "-l"],
            input=block,
            text=True,
            capture_output=True,
            check=False,
        )
        if result.returncode != 0:
            raise SystemExit(
                f"invalid embedded PHP block {index} in {filename}: "
                + (result.stderr or result.stdout).strip()
            )
PY

php -r '$xml = simplexml_load_file($argv[1]); if (!$xml || trim((string)$xml->rawname) !== $argv[2] || trim((string)$xml->version) !== $argv[3]) exit(1);' \
  "${ROOT_DIR}/${MODULE}/module.xml" "$MODULE" "$VERSION"

if find "${ROOT_DIR}/${MODULE}" -type f \( \
  -name 'module.sig*' -o -name '*.pyc' -o -name '*.pyo' -o -name '*.bak*' \
  -o -name '*.backup*' -o -name '*.old' -o -name '*.orig' -o -name '*.rej' \
  -o -name '*.tmp' -o -name '*.swp' -o -name '*.swo' -o -name '*~' \
  -o -name '.DS_Store' -o -name '.env' -o -name '.env.*' -o -name '.htpasswd' \
  -o -name '*.log' -o -name '*.key' -o -name '*.pem' -o -name '*.p12' \
  -o -name '*.pfx' -o -name '*.config' -o -name '*.pending.json' \
  -o -name '*.onnx' -o -name '*.onnx.json' -o -name '*.ckpt' -o -name '*.model' \
  -o -name '*.tgz' -o -name '*.tar' -o -name '*.tar.gz' -o -name '*.zip' \
\) -print -quit | grep -q .; then
  printf 'Module tree contains a generated, private, cache, or backup artifact.\n' >&2
  exit 1
fi
if find "${ROOT_DIR}/${MODULE}" -type d \( \
  -name '__pycache__' -o -name '.cache' -o -name cache -o -name caches \
  -o -name log -o -name logs -o -name backup -o -name backups \
  -o -name generated -o -name rendered -o -name tmp \
\) -print -quit | grep -q .; then
  printf 'Module tree contains a generated, cache, log, or backup directory.\n' >&2
  exit 1
fi
if grep -RIlE 'ghp_[A-Za-z0-9]+|github_pat_[A-Za-z0-9_]+|-----BEGIN ([A-Z0-9 ]+ )?PRIVATE KEY-----' "${ROOT_DIR}/${MODULE}" "${ROOT_DIR}/tools" | grep -q .; then
  printf 'A credential or private-key pattern was found in release source.\n' >&2
  exit 1
fi

for required in \
  module.xml Slsmassnotifyserver.class.php AnnouncementDelivery.php TestProfiles.php SupportDiagnostics.php Backup.php Restore.php install.php uninstall.php \
  page.slsmassnotifyserver_scheduling.php views/scheduling.php \
  page.slsmassnotifyserver_lightning.php views/lightning.php \
  api/sipnotify/index.php \
  dashboard/sections/SlsMassNotifyAnnouncement.class.php \
  dashboard/views/sections/sls-mass-notify-announcement.php \
  bin/sls_mass_notify/sls_notify.py bin/sls_mass_notify/sls_config.py \
  bin/sls_mass_notify/sls_branded_email.py \
  bin/sls_mass_notify/sls_branded_discord.py \
  bin/sls_mass_notify/sls_notification_destinations.py \
  bin/sls_mass_notify/sls_release_verify.py bin/sls_mass_notify/release-signing.pub \
  bin/sls_mass_notify/piper-requirements.txt \
  bin/sls_mass_notify/sls_audio_queue.py bin/sls_mass_notify/sls_storage_maintenance.py \
  bin/sls_mass_notify/sls_weather_queue.py \
  bin/sls_mass_notify_announcement_worker.php \
  bin/sls_mass_notify/sls_system_notifications.py \
  bin/sls_mass_notify/sls_nws_status.py \
  bin/sls_mass_notify/sls_nws_delivery_claims.py \
  bin/sls_mass_notify_nws_poll.sh bin/sls_mass_notify_test.sh \
  bin/sls_mass_notify_weather_poll.sh \
  bin/sls_mass_notify_schedule_worker.php \
  bin/sls_mass_notify/sls_mass_notify_xweather_poll.py \
  bin/sls_mass_notify_update.sh bin/sls_mass_notify_maintenance.sh \
  bin/sls_mass_notify_uninstall.sh \
  bin/sls_mass_notify_install_piper_voices.sh \
  assets/SLS_Mass_Notif_Email.png \
  sounds/tones/opening_Paging_Tone_Opening.wav \
  sounds/tones/closing_Paging_Tone_Closing.wav \
  sounds/system-recordings/NWS_alert.wav \
  sounds/system-recordings/Lightning_alert.wav \
  sounds/system-recordings/Lightning_alert.mp3; do
  [ -f "${ROOT_DIR}/${MODULE}/${required}" ] || {
    printf 'Required module file is missing: %s\n' "$required" >&2
    exit 1
  }
done

tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner --mode='go-w' \
  --exclude='module.sig' --exclude='__pycache__' --exclude='*.pyc' \
  -C "${ROOT_DIR}" -cf - "${MODULE}" | gzip -n -9 > "${PACKAGE}"
chmod 0640 "${PACKAGE}"

TGZ_PATH="${PACKAGE}" MODULE_NAME="${MODULE}" MODULE_VERSION="${VERSION}" python3 - <<'PY'
import os
import pathlib
import tarfile
import xml.etree.ElementTree as ET

archive = os.environ["TGZ_PATH"]
module = os.environ["MODULE_NAME"]
version = os.environ["MODULE_VERSION"]
total = 0
with tarfile.open(archive, "r:gz") as handle:
    members = handle.getmembers()
    if not members or len(members) > 2000:
        raise SystemExit("invalid archive member count")
    names = {member.name for member in members}
    for member in members:
        path = pathlib.PurePosixPath(member.name)
        if path.is_absolute() or ".." in path.parts or not path.parts or path.parts[0] != module:
            raise SystemExit(f"unsafe archive path: {member.name}")
        if member.issym() or member.islnk() or member.isdev() or member.isfifo() or member.mode & 0o6000:
            raise SystemExit(f"unsafe archive member: {member.name}")
        if member.isfile():
            total += member.size
    if total > 50 * 1024 * 1024:
        raise SystemExit("archive expands beyond 50 MB")
    module_xml = handle.extractfile(f"{module}/module.xml")
    if module_xml is None:
        raise SystemExit("module.xml missing")
    root = ET.fromstring(module_xml.read())
    if (root.findtext("rawname") or "").strip() != module:
        raise SystemExit("module rawname mismatch")
    if (root.findtext("version") or "").strip() != version:
        raise SystemExit("module version mismatch")
PY

EXPECTED_INSTALLER_HASH="$(sed -n 's/^EXPECTED_TGZ_SHA256="\([0-9a-f]\{64\}\)"$/\1/p' "${ROOT_DIR}/tools/install_release.sh")"
ACTUAL_PACKAGE_HASH="$(sha256sum "${PACKAGE}" | awk '{print $1}')"
if [ -z "${EXPECTED_INSTALLER_HASH}" ] || [ "${ACTUAL_PACKAGE_HASH}" != "${EXPECTED_INSTALLER_HASH}" ]; then
  printf 'Installer/package SHA-256 mismatch. Expected %s but built %s.\n' \
    "${EXPECTED_INSTALLER_HASH:-missing}" "${ACTUAL_PACKAGE_HASH}" >&2
  exit 1
fi

sha256sum "${PACKAGE}"
python3 "${ROOT_DIR}/tools/sign_release.py"
printf '%s\n' "${PACKAGE}"
