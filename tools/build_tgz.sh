#!/usr/bin/env bash
set -euo pipefail

umask 027
export PYTHONDONTWRITEBYTECODE=1

UNSIGNED=0
if [[ "${1:-}" == --unsigned ]]; then UNSIGNED=1; shift; fi
if [[ "$#" -ne 0 ]]; then
  printf 'Usage: %s [--unsigned]\n' "$0" >&2
  exit 2
fi
if [[ -r /etc/freepbx.conf ]]; then
  [[ "${SLS_TEST_NAMESPACE:-}" == entered ]] || {
    printf 'Building on a PBX requires tools/run_isolated_tests.sh --build; direct builds cannot use production state as a fixture.\n' >&2
    exit 2
  }
  for kind in MOUNT NET PID; do
    variable="SLS_TEST_PARENT_${kind}_NS"
    case "$kind" in MOUNT) path=mnt ;; NET) path=net ;; PID) path=pid ;; esac
    if [[ -z "${!variable:-}" || "${!variable}" == "$(readlink "/proc/self/ns/$path")" ]]; then
      printf 'Build isolation verification failed for %s namespace; no tests were started.\n' "$kind" >&2
      exit 2
    fi
  done
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Registered suites get separate fixture namespaces even during archive builds.
# Non-test interpreter calls retain their ordinary behavior.
sls_build_fixture() {
  local interpreter="$1"; shift
  if [[ "$#" -eq 1 && "$1" == "$ROOT_DIR"/tools/test_* ]]; then
    command python3 -I "$ROOT_DIR/tools/isolated_dispatch.py" "tools/${1##*/}"
  else
    command "$interpreter" "$@"
  fi
}
python3() { sls_build_fixture python3 "$@"; }
php() { sls_build_fixture php "$@"; }
bash() { sls_build_fixture bash "$@"; }
node() { sls_build_fixture node "$@"; }

MODULE="slsmassnotifyserver"
VERSION="$(php -r '$x=simplexml_load_file($argv[1]); if (!$x) exit(1); echo (string)$x->version;' "${ROOT_DIR}/${MODULE}/module.xml")"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-beta)?$ ]] || {
  printf 'module.xml contains an invalid release version.\n' >&2
  exit 2
}
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

command python3 "${ROOT_DIR}/tools/verify_passkey_vendor.py"
PHP_LINT_VERSION_ID="$(php -n -r 'echo PHP_VERSION_ID;')"
while IFS= read -r -d '' file; do
  # Upstream loads these attributed constants only on PHP 8.5 and later.
  # Its exact bytes and the enclosing version guard were verified above.
  if [[ "$file" == "${ROOT_DIR}/${MODULE}/passkey-libs/vendor/symfony/polyfill-uuid/bootstrap85.php" && "$PHP_LINT_VERSION_ID" -lt 80500 ]]; then continue; fi
  php -l "$file" >/dev/null
done < <(find "${ROOT_DIR}/${MODULE}" -type f -name '*.php' -print0)

while IFS= read -r -d '' file; do
  bash -n "$file"
done < <(find "${ROOT_DIR}/${MODULE}" "${ROOT_DIR}/tools" -type f -name '*.sh' -print0)

while IFS= read -r -d '' file; do
  node --check "$file" >/dev/null
done < <(find "${ROOT_DIR}/${MODULE}" "${ROOT_DIR}/tools" -type f -name '*.js' -print0)

python3 - "${ROOT_DIR}/${MODULE}" <<'PY'
import ast
import pathlib
import sys

for path in pathlib.Path(sys.argv[1]).rglob("*.py"):
    ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
PY

python3 - "${ROOT_DIR}/${MODULE}" <<'PY'
import json
import pathlib
import sys

def unique(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate member: ' + key)
        result[key] = value
    return result

def constant(value):
    raise ValueError('non-standard number: ' + value)

for path in pathlib.Path(sys.argv[1]).rglob('*.json'):
    if path.is_symlink() or not path.is_file():
        raise ValueError('JSON source path is unsafe: ' + str(path))
    if path.stat().st_size > 8 * 1024 * 1024:
        raise ValueError('JSON source exceeds 8 MiB: ' + str(path))
    try:
        json.loads(path.read_text(encoding='utf-8'), object_pairs_hook=unique, parse_constant=constant)
    except (ValueError, UnicodeError) as error:
        raise ValueError('Invalid JSON source ' + str(path) + ': ' + str(error)) from error
PY

python3 - "${ROOT_DIR}" "${BASH_SOURCE[0]}" <<'PY'
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1])
source = pathlib.Path(sys.argv[2]).read_text(encoding="utf-8")
expected = {
    path.relative_to(root).as_posix()
    for path in (root / "tools").glob("test_*")
    if path.is_file() and path.suffix in {".js", ".php", ".py", ".sh"}
}
referenced = set(re.findall(r'\$\{ROOT_DIR\}/(tools/test_[^" ]+)', source))
missing = sorted(expected - referenced)
stale = sorted(referenced - expected)
if missing or stale:
    raise SystemExit(
        "release regression inventory mismatch; missing="
        + repr(missing)
        + "; stale="
        + repr(stale)
    )
PY

cmp -s "${ROOT_DIR}/slsmassnotifyserver/api/sls-mass-notify/config-crypto.php" "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify/sls_config_crypto.php" || { printf 'Shared PHP configuration encryption copies differ.\n' >&2; exit 1; }
python3 "${ROOT_DIR}/tools/test_config_crypto.py"
python3 "${ROOT_DIR}/tools/test_config_fail_closed.py"
python3 "${ROOT_DIR}/tools/test_live_paging_readers.py"
node "${ROOT_DIR}/tools/test_operation_progress.js"
node "${ROOT_DIR}/tools/test_operations_transport.js"
node "${ROOT_DIR}/tools/test_administrative_transport.js"
python3 "${ROOT_DIR}/tools/test_isolation_readonly.py"
python3 "${ROOT_DIR}/tools/test_webhook_content.py"
python3 "${ROOT_DIR}/tools/test_external_journal_safety.py"
python3 "${ROOT_DIR}/tools/test_installer_crypto_helper.py"
python3 "${ROOT_DIR}/tools/test_release_portability.py"
php "${ROOT_DIR}/tools/test_ami_loopback.php"
php "${ROOT_DIR}/tools/test_ami_ownership.php"
php "${ROOT_DIR}/tools/test_api_audience_projection.php"
php "${ROOT_DIR}/tools/test_operator_directory.php"
python3 "${ROOT_DIR}/tools/test_upgrade_trust.py"
bash "${ROOT_DIR}/tools/test_installer_asterisk_capabilities.sh"
bash "${ROOT_DIR}/tools/test_installer_timezone.sh"
bash "${ROOT_DIR}/tools/test_installer_config_safety.sh"
bash "${ROOT_DIR}/tools/test_local_signer.sh"
bash "${ROOT_DIR}/tools/test_uninstaller_signer_snapshot.sh"
php "${ROOT_DIR}/tools/test_scheduling_contract.php"
php "${ROOT_DIR}/tools/test_schedule_calendar.php"
php "${ROOT_DIR}/tools/test_schedule_programmes.php"
php "${ROOT_DIR}/tools/test_directory_mapping.php"
php "${ROOT_DIR}/tools/test_directory_cloud.php"
php "${ROOT_DIR}/tools/test_directory_ldap_records.php"
php "${ROOT_DIR}/tools/test_enterprise_continuity.php"
php "${ROOT_DIR}/tools/test_incident_languages.php"
php "${ROOT_DIR}/tools/test_incident_editor_directory.php"
php "${ROOT_DIR}/tools/test_operator_expiry.php"
node "${ROOT_DIR}/tools/test_subscriber_worker.js"
php "${ROOT_DIR}/tools/test_enterprise_provider_lifecycle.php"
php "${ROOT_DIR}/tools/test_enterprise_journal_maintenance.php"
php "${ROOT_DIR}/tools/test_enterprise_announcement_channels.php"
php "${ROOT_DIR}/tools/test_browser_push.php"
python3 "${ROOT_DIR}/tools/test_installation_doctor.py"
python3 "${ROOT_DIR}/tools/test_017_regressions.py"
python3 "${ROOT_DIR}/tools/test_017_lightning_windows.py"
python3 "${ROOT_DIR}/tools/test_017_storage_and_startup.py"
python3 "${ROOT_DIR}/tools/test_017_state_boundaries.py"
python3 "${ROOT_DIR}/tools/test_017_portal_csp.py"
python3 "${ROOT_DIR}/tools/test_first_install_documentation.py"
python3 "${ROOT_DIR}/tools/test_verified_bootstrap.py"
php "${ROOT_DIR}/tools/test_017_identity_revisions.php"
php "${ROOT_DIR}/tools/test_vendor_stream_bounds.php"
php "${ROOT_DIR}/tools/test_vendor_promise_diagnostics.php"
php "${ROOT_DIR}/tools/test_vendor_random_engine.php"
php "${ROOT_DIR}/tools/test_vendor_random_failure.php"
php "${ROOT_DIR}/tools/test_vendor_phpseclib_key_formats.php"
php "${ROOT_DIR}/tools/test_vendor_phpseclib_arithmetic.php"
php "${ROOT_DIR}/tools/test_vendor_asn1_bounds.php"
php "${ROOT_DIR}/tools/test_vendor_x509_bounds.php"
php "${ROOT_DIR}/tools/test_vendor_xmlsec_bounds.php"
php "${ROOT_DIR}/tools/test_vendor_ssh_bounds.php"
php "${ROOT_DIR}/tools/test_vendor_ansi_bounds.php"
php "${ROOT_DIR}/tools/test_module_private_atomic_write.php"
php "${ROOT_DIR}/tools/test_017_labs_audiences.php"
php "${ROOT_DIR}/tools/test_017_approval_alias.php"
php "${ROOT_DIR}/tools/test_017_environment_boundary.php"
python3 "${ROOT_DIR}/tools/test_runtime_account_environment.py"
python3 "${ROOT_DIR}/tools/test_installer_module_enabled.py"
python3 "${ROOT_DIR}/tools/test_installer_config_readers.py"
php "${ROOT_DIR}/tools/test_017_runtime_history.php"
php "${ROOT_DIR}/tools/test_phone_inventory.php"
python3 "${ROOT_DIR}/tools/test_runtime_initialization_races.py"
php "${ROOT_DIR}/tools/test_paging_readiness.php"
php "${ROOT_DIR}/tools/test_control_idempotency.php"
python3 "${ROOT_DIR}/tools/test_control_idempotency_http.py"
php "${ROOT_DIR}/tools/test_announcement_worker_health.php"
python3 "${ROOT_DIR}/tools/test_enterprise_browser_ui.py"
python3 "${ROOT_DIR}/tools/test_schedule_recording_browser_ui.py"
python3 "${ROOT_DIR}/tools/test_installer_interruptions.py"
python3 "${ROOT_DIR}/tools/test_legacy_runtime_permissions.py"
python3 "${ROOT_DIR}/tools/test_mail_admission.py"
php "${ROOT_DIR}/tools/test_operator_mail_admission.php"
php "${ROOT_DIR}/tools/test_beta_notice.php"
python3 "${ROOT_DIR}/tools/test_beta_notice_ui.py"
python3 "${ROOT_DIR}/tools/test_cron_runner.py"
python3 "${ROOT_DIR}/tools/test_speech_admission.py"
python3 "${ROOT_DIR}/tools/test_regional_voice_packs.py"
python3 "${ROOT_DIR}/tools/test_phone_inventory.py"
python3 "${ROOT_DIR}/tools/test_dependency_advisories.py"
php "${ROOT_DIR}/tools/test_announcement_delivery_contract.php"
php "${ROOT_DIR}/tools/test_announcement_snapshot.php"
php "${ROOT_DIR}/tools/test_speech_duration.php"
python3 "${ROOT_DIR}/tools/test_announcement_previews.py"
php "${ROOT_DIR}/tools/test_announcement_preview_protocol.php"
php "${ROOT_DIR}/tools/test_delivery_review.php"
php "${ROOT_DIR}/tools/test_delivery_rehearsal.php"
php "${ROOT_DIR}/tools/test_desktop_capacity.php"
php "${ROOT_DIR}/tools/test_sms_bulkvs.php"
php "${ROOT_DIR}/tools/test_general_settings_save.php"
php "${ROOT_DIR}/tools/test_desktop_fleet.php"
python3 "${ROOT_DIR}/tools/test_collaboration_webhooks.py"
python3 "${ROOT_DIR}/tools/test_outbound_voice_ui.py"
php "${ROOT_DIR}/tools/test_outbound_voice.php"
php "${ROOT_DIR}/tools/test_scheduled_voice.php"
python3 "${ROOT_DIR}/tools/test_announcement_email.py"
php "${ROOT_DIR}/tools/test_announcement_email_config.php"
php "${ROOT_DIR}/tools/test_announcement_email_editor.php"
php "${ROOT_DIR}/tools/test_announcement_email_controllers.php"
python3 "${ROOT_DIR}/tools/test_announcement_email_recipient_ui.py"
php "${ROOT_DIR}/tools/test_announcement_email_delivery.php"
php "${ROOT_DIR}/tools/test_announcement_email_transport.php"
php "${ROOT_DIR}/tools/test_announcement_email_facade.php"
php "${ROOT_DIR}/tools/test_api_email_permissions.php"
php "${ROOT_DIR}/tools/test_recipient_selection_email.php"
python3 "${ROOT_DIR}/tools/test_scheduled_recipient_envelope_ui.py"
php "${ROOT_DIR}/tools/test_incident_email.php"
php "${ROOT_DIR}/tools/test_phone_collector_service.php"
python3 "${ROOT_DIR}/tools/test_installer_phone_service.py"
python3 "${ROOT_DIR}/tools/test_phone_admission.py"
python3 "${ROOT_DIR}/tools/test_phone_voicemail_admission.py"
python3 "${ROOT_DIR}/tools/test_voicemail_runtime.py"
python3 "${ROOT_DIR}/tools/test_outbound_playback_asterisk.py"
python3 "${ROOT_DIR}/tools/test_phone_outcomes.py"
python3 "${ROOT_DIR}/tools/test_outbound_routes.py"
php "${ROOT_DIR}/tools/test_phone_producers.php"
python3 "${ROOT_DIR}/tools/test_resource_capacity.py"
python3 "${ROOT_DIR}/tools/test_phone_capacity_asterisk.py"
python3 "${ROOT_DIR}/tools/test_installer_preparation.py"
python3 "${ROOT_DIR}/tools/test_served_php_probe.py"
python3 "${ROOT_DIR}/tools/test_served_php_fpm_cache.py"
php "${ROOT_DIR}/tools/test_system_recordings.php"
python3 "${ROOT_DIR}/tools/test_weather_channels.py"
php "${ROOT_DIR}/tools/test_weather_channel_authorization.php"
python3 "${ROOT_DIR}/tools/test_private_channel_handoffs.py"
php "${ROOT_DIR}/tools/test_weather_health_destinations.php"
python3 "${ROOT_DIR}/tools/test_lightning_providers.py"
python3 "${ROOT_DIR}/tools/test_piper_dependencies.py"
python3 "${ROOT_DIR}/tools/test_pinned_piper_runtime.py"
python3 "${ROOT_DIR}/tools/test_piper_environment.py"
python3 "${ROOT_DIR}/tools/test_voice_download_bounds.py"
python3 "${ROOT_DIR}/tools/test_sbom.py"
python3 "${ROOT_DIR}/tools/test_release_source_scan.py"
python3 "${ROOT_DIR}/tools/test_update_policy.py"
php "${ROOT_DIR}/tools/test_update_policy.php"
php "${ROOT_DIR}/tools/test_setup_preferences.php"
php "${ROOT_DIR}/tools/test_control_api_bounds.php"
php "${ROOT_DIR}/tools/test_control_api_contract.php"
php "${ROOT_DIR}/tools/test_api_helper_loading.php"
php "${ROOT_DIR}/tools/test_monitoring.php"
php "${ROOT_DIR}/tools/test_radio_gateway.php"
php "${ROOT_DIR}/tools/test_audience_limits.php"
php "${ROOT_DIR}/tools/test_person_profiles.php"
python3 "${ROOT_DIR}/tools/test_desktop_app_compat.py"
python3 "${ROOT_DIR}/tools/test_support_snapshot.py"
python3 "${ROOT_DIR}/tools/test_nws_http.py"
python3 "${ROOT_DIR}/tools/test_nws_http_wire.py"
python3 "${ROOT_DIR}/tools/test_isolated_dispatch.py"
python3 "${ROOT_DIR}/tools/test_audit_ledger.py"
php "${ROOT_DIR}/tools/test_event_log_pages.php"
python3 "${ROOT_DIR}/tools/test_admin_output_buffers.py"
php "${ROOT_DIR}/tools/test_speech_rules.php"
php "${ROOT_DIR}/tools/test_sms_core.php"
php "${ROOT_DIR}/tools/test_mms.php"
php "${ROOT_DIR}/tools/test_sms_integration.php"
python3 "${ROOT_DIR}/tools/test_sms_callback.py"
python3 "${ROOT_DIR}/tools/test_control_audit_storage.py"
python3 "${ROOT_DIR}/tools/test_audit_separation.py"
php "${ROOT_DIR}/tools/test_security_audit.php"
python3 "${ROOT_DIR}/tools/test_api_state_storage.py"
php "${ROOT_DIR}/tools/test_group_identity.php"
python3 "${ROOT_DIR}/tools/test_weather_speech_duration.py"
php "${ROOT_DIR}/tools/test_protected_config_compatibility.php"
php "${ROOT_DIR}/tools/test_announcement_activity_lock.php"
python3 "${ROOT_DIR}/tools/test_announcement_worker_lifecycle.py"
python3 "${ROOT_DIR}/tools/test_sip_notify_submission_status.py"
python3 "${ROOT_DIR}/tools/test_ami_transport_bounds.py"
php "${ROOT_DIR}/tools/test_phone_media_url.php"
python3 "${ROOT_DIR}/tools/test_phone_media_url.py"
python3 "${ROOT_DIR}/tools/test_announcement_display_timeout.py"
python3 "${ROOT_DIR}/tools/test_desktop_payload.py"
python3 "${ROOT_DIR}/tools/test_desktop_streaming.py"
python3 "${ROOT_DIR}/tools/test_desktop_fpm_capacity.py"
python3 "${ROOT_DIR}/tools/test_desktop_fleet_load.py"
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
php "${ROOT_DIR}/tools/test_config_durability.php"
php "${ROOT_DIR}/tools/test_protected_file_helpers.php"
php "${ROOT_DIR}/tools/test_ui_performance_contract.php"
php "${ROOT_DIR}/tools/test_ui_log_bounds.php"
php "${ROOT_DIR}/tools/test_hero_view.php"
php "${ROOT_DIR}/tools/test_dashboard_section.php"
python3 "${ROOT_DIR}/tools/test_runtime_tree_safety.py"
php "${ROOT_DIR}/tools/test_native_cron_read.php"
python3 "${ROOT_DIR}/tools/test_schedule_journal_memory.py"
php "${ROOT_DIR}/tools/test_update_contract.php"
python3 "${ROOT_DIR}/tools/test_maintenance_failure_propagation.py"
python3 "${ROOT_DIR}/tools/test_installer_log_compatibility.py"
python3 "${ROOT_DIR}/tools/test_installer_python_bootstrap.py"
python3 "${ROOT_DIR}/tools/test_installer_probe_storage.py"
php "${ROOT_DIR}/tools/test_local_web_probe.php"
php "${ROOT_DIR}/tools/test_advertised_address.php"
php "${ROOT_DIR}/tools/test_encrypted_config.php"
php "${ROOT_DIR}/tools/test_sensitive_exports.php"
python3 "${ROOT_DIR}/tools/test_api_security.py"
php "${ROOT_DIR}/tools/test_admin_request_boundaries.php"
php "${ROOT_DIR}/tools/test_api_credential_management.php"
php "${ROOT_DIR}/tools/test_api_announcement_permissions.php"
python3 "${ROOT_DIR}/tools/test_api_delayed_permissions.py"
php "${ROOT_DIR}/tools/test_locations.php"
php "${ROOT_DIR}/tools/test_saved_audiences.php"
php "${ROOT_DIR}/tools/test_audience_picker.php"
php "${ROOT_DIR}/tools/test_geographic_targeting.php"
php "${ROOT_DIR}/tools/test_locations_http.php"
php "${ROOT_DIR}/tools/test_automations.php"
php "${ROOT_DIR}/tools/test_operator_access.php"
php "${ROOT_DIR}/tools/test_operator_auth.php"
php "${ROOT_DIR}/tools/test_operator_http.php"
php "${ROOT_DIR}/tools/test_operator_portal.php"
php "${ROOT_DIR}/tools/test_operator_login_rate.php"
php "${ROOT_DIR}/tools/test_operator_recovery.php"
python3 "${ROOT_DIR}/tools/test_operator_portal_http.py"
python3 "${ROOT_DIR}/tools/test_phone_visual_owner.py"
php "${ROOT_DIR}/tools/test_weather_delivery_receipts.php"
python3 "${ROOT_DIR}/tools/test_weather_receipt_readers.py"
php "${ROOT_DIR}/tools/test_weather_voicemail_receipts.php"
python3 "${ROOT_DIR}/tools/test_weather_email_details.py"
bash "${ROOT_DIR}/tools/test_installer_dependencies.sh"
python3 "${ROOT_DIR}/tools/test_automation_http.py"
php "${ROOT_DIR}/tools/test_automation_integration.php"
php "${ROOT_DIR}/tools/test_panic_phone.php"
python3 "${ROOT_DIR}/tools/test_trigger_actions.py"
python3 "${ROOT_DIR}/tools/test_emergency_observer.py"
php "${ROOT_DIR}/tools/test_incidents.php"
php "${ROOT_DIR}/tools/test_incident_replay_restore.php"
php "${ROOT_DIR}/tools/test_incident_escalation.php"
node --check "${ROOT_DIR}/slsmassnotifyserver/views/incident_escalation_editor.js"
node --check "${ROOT_DIR}/slsmassnotifyserver/views/schedule_programmes.js"
node --check "${ROOT_DIR}/slsmassnotifyserver/views/programme_visual_calendar.js"
node --check "${ROOT_DIR}/slsmassnotifyserver/portal/display.js"
node --check "${ROOT_DIR}/slsmassnotifyserver/portal/passkeys.js"
node --check "${ROOT_DIR}/slsmassnotifyserver/views/system_recording_browser.js"
php "${ROOT_DIR}/tools/test_incident_bounds.php"
php "${ROOT_DIR}/tools/test_incident_retirement.php"
python3 "${ROOT_DIR}/tools/test_external_monitor.py"
python3 "${ROOT_DIR}/tools/test_desktop_http_fleet.py"
php "${ROOT_DIR}/tools/test_incident_integration.php"
python3 "${ROOT_DIR}/tools/test_incident_integration.py"
python3 "${ROOT_DIR}/tools/test_module_trust.py"
python3 "${ROOT_DIR}/tools/test_privileged_signer_boundaries.py"
php "${ROOT_DIR}/tools/test_unprivileged_integration.php"
php "${ROOT_DIR}/tools/test_schedule_runner_lock.php"
php "${ROOT_DIR}/tools/test_schedule_lateness.php"
php "${ROOT_DIR}/tools/test_schedule_form_lateness.php"
php "${ROOT_DIR}/tools/test_scheduled_delivery.php"
php "${ROOT_DIR}/tools/test_scheduled_delivery_jobs.php"
php "${ROOT_DIR}/tools/test_announcement_admission.php"
python3 "${ROOT_DIR}/tools/test_scheduled_transport_deadlines.py"
python3 "${ROOT_DIR}/tools/test_installer_privilege_separation.py"
python3 "${ROOT_DIR}/tools/test_installer_database_recovery.py"
python3 "${ROOT_DIR}/tools/test_installer_stock_preflight.py"
python3 "${ROOT_DIR}/tools/test_installer_transaction.py"
php "${ROOT_DIR}/tools/test_installer_configuration_locks.php"
python3 "${ROOT_DIR}/tools/test_privileged_install.py"
python3 "${ROOT_DIR}/tools/test_privileged_maintenance.py"
python3 "${ROOT_DIR}/tools/test_installer_recovery.py"
python3 "${ROOT_DIR}/tools/test_install_idle.py"
python3 "${ROOT_DIR}/tools/test_install_guard.py"
php "${ROOT_DIR}/tools/test_announcement_visual_concurrency.php"
python3 "${ROOT_DIR}/tools/test_installer_download_safety.py"
python3 "${ROOT_DIR}/tools/test_installer_rollback.py"
python3 "${ROOT_DIR}/tools/test_uninstaller_safe_logs.py"
python3 "${ROOT_DIR}/tools/test_uninstaller_transaction.py"
python3 "${ROOT_DIR}/tools/test_external_delivery_retry.py"
python3 "${ROOT_DIR}/tools/test_system_notifications.py"
python3 "${ROOT_DIR}/tools/test_system_notification_storage.py"
python3 "${ROOT_DIR}/tools/test_system_health_notifications.py"
python3 "${ROOT_DIR}/tools/test_status_health.py"
python3 "${ROOT_DIR}/tools/test_maintenance_permission_safety.py"
python3 "${ROOT_DIR}/tools/test_sls_notify_journal.py"
python3 "${ROOT_DIR}/tools/test_desktop_feed_cache.py"
python3 "${ROOT_DIR}/tools/test_desktop_media_http.py"
python3 "${ROOT_DIR}/tools/test_desktop_history_retention.py"
python3 "${ROOT_DIR}/tools/test_xweather_runtime_safety.py"
python3 "${ROOT_DIR}/tools/test_xweather_manual_test_status.py"
python3 "${ROOT_DIR}/tools/test_xweather_usage_period.py"
python3 "${ROOT_DIR}/tools/test_lightning_audit_boundaries.py"
python3 "${ROOT_DIR}/tools/test_nws_worker_boundaries.py"
php "${ROOT_DIR}/tools/test_xweather_usage_period.php"
python3 "${ROOT_DIR}/tools/test_xweather_groups.py"
php "${ROOT_DIR}/tools/test_xweather_groups.php"
python3 "${ROOT_DIR}/tools/test_nws_alert_dedup.py"
python3 "${ROOT_DIR}/tools/test_nws_status_concurrency.py"
python3 "${ROOT_DIR}/tools/test_alert_worker_cli_safety.py"
python3 "${ROOT_DIR}/tools/test_weather_manual_test_contract.py"
python3 "${ROOT_DIR}/tools/test_manual_weather_reservations.py"
python3 "${ROOT_DIR}/tools/test_weather_audit_regular.py"
python3 "${ROOT_DIR}/tools/test_weather_audit_bounds.py"
php "${ROOT_DIR}/tools/test_freepbx_backup_restore.php"
php "${ROOT_DIR}/tools/test_native_restore_recovery.php"
python3 "${ROOT_DIR}/tools/test_operational_backup.py"
php "${ROOT_DIR}/tools/test_operational_backup.php"
php "${ROOT_DIR}/tools/test_help_ui_contract.php"
php "${ROOT_DIR}/tools/test_desktop_reliability.php"
php "${ROOT_DIR}/tools/test_desktop_receipts.php"
python3 "${ROOT_DIR}/tools/test_desktop_status_readers.py"
python3 "${ROOT_DIR}/tools/test_desktop_receipt_contract.py"
node "${ROOT_DIR}/tools/test_desktop_receipt_ui.js"
node "${ROOT_DIR}/tools/test_phone_contact_editor.js"
python3 "${ROOT_DIR}/tools/test_desktop_api_delivery.py"
python3 "${ROOT_DIR}/tools/test_desktop_editor.py"
php "${ROOT_DIR}/tools/test_live_paging.php"
php "${ROOT_DIR}/tools/test_voicemail_controls.php"
php "${ROOT_DIR}/tools/test_voicemail_settings.php"
python3 "${ROOT_DIR}/tools/test_voicemail_controls_ui.py"
python3 "${ROOT_DIR}/tools/test_weather_voicemail_producers.py"
php "${ROOT_DIR}/tools/test_paging_pending_dialplan.php"
python3 "${ROOT_DIR}/tools/test_live_paging_asterisk.py"
python3 "${ROOT_DIR}/tools/test_voicemail_asterisk.py"
python3 "${ROOT_DIR}/tools/test_lightning_voicemail_asterisk.py"
php "${ROOT_DIR}/tools/test_live_audio_durability.php"
php "${ROOT_DIR}/tools/test_live_paging_notify.php"
python3 "${ROOT_DIR}/tools/test_live_paging_visual.py"
php "${ROOT_DIR}/tools/test_live_paging_prompt_ownership.php"
php "${ROOT_DIR}/tools/test_live_paging_group_staging.php"
php "${ROOT_DIR}/tools/test_independent_channels.php"
python3 "${ROOT_DIR}/tools/test_delivery_storage_security.py"
python3 "${ROOT_DIR}/tools/test_announcement_retention.py"
python3 "${ROOT_DIR}/tools/test_generated_media_retention.py"
php "${ROOT_DIR}/tools/test_media_access.php"
python3 "${ROOT_DIR}/tools/test_media_access_http.py"
python3 "${ROOT_DIR}/tools/test_event_log_retention.py"
python3 "${ROOT_DIR}/tools/test_lightning_observations.py"
python3 "${ROOT_DIR}/tools/test_lightning_gate_policy.py"
php "${ROOT_DIR}/tools/test_lightning_gate_view.php"
php "${ROOT_DIR}/tools/test_lightning_outage_config.php"
python3 "${ROOT_DIR}/tools/test_weather_channel_isolation.py"
python3 "${ROOT_DIR}/tools/test_release_manifest.py"
python3 "${ROOT_DIR}/tools/test_release_signer_transaction.py"
python3 "${ROOT_DIR}/tools/test_release_trust.py"
python3 "${ROOT_DIR}/tools/test_device_overrides.py"
python3 "${ROOT_DIR}/tools/test_weather_queue.py"
python3 "${ROOT_DIR}/tools/test_installer_runtime_manifest.py"
php "${ROOT_DIR}/tools/test_runtime_file_install.php"
php "${ROOT_DIR}/tools/test_profiles.php"
php "${ROOT_DIR}/tools/test_delivery_reporting.php"
python3 "${ROOT_DIR}/tools/test_audio_priority.py"
python3 "${ROOT_DIR}/tools/test_audio_state.py"
python3 "${ROOT_DIR}/tools/test_audio_state_interop.py"
python3 "${ROOT_DIR}/tools/test_announcement_footer.py"
php "${ROOT_DIR}/tools/test_support_diagnostics.php"
php "${ROOT_DIR}/tools/test_deployment_readiness.php"
python3 "${ROOT_DIR}/tools/test_public_api_schemas.py"
python3 "${ROOT_DIR}/tools/check_release_docs.py"
php "${ROOT_DIR}/tools/test_device_acceptance.php"
php "${ROOT_DIR}/tools/test_immediate_settings.php"
php "${ROOT_DIR}/tools/test_enterprise_operations.php"
php "${ROOT_DIR}/tools/test_enterprise_security_review.php"
python3 "${ROOT_DIR}/tools/test_enterprise_controller_http.py"
php "${ROOT_DIR}/tools/test_enterprise_identity.php"
php "${ROOT_DIR}/tools/test_http_client_safeguards.php"
python3 "${ROOT_DIR}/tools/test_sensor_sdk_lock.py"
php "${ROOT_DIR}/tools/test_enterprise_identity_leases.php"
php "${ROOT_DIR}/tools/test_enterprise_provider_json.php"
php "${ROOT_DIR}/tools/test_enterprise_identity_management.php"
php "${ROOT_DIR}/tools/test_enterprise_operator_api.php"
python3 "${ROOT_DIR}/tools/test_enterprise_control_http.py"
python3 "${ROOT_DIR}/tools/test_enterprise_identity_http.py"
php "${ROOT_DIR}/tools/test_enterprise_cluster.php"
php "${ROOT_DIR}/tools/test_cluster_protocol_deadline.php"
php "${ROOT_DIR}/tools/test_enterprise_edge_receipts.php"
python3 "${ROOT_DIR}/tools/test_enterprise_cluster_tls.py"
python3 "${ROOT_DIR}/tools/test_enterprise_cluster_deployment.py"
node "${ROOT_DIR}/tools/test_enterprise_edge_view.js"
node "${ROOT_DIR}/tools/test_dashboard_assets.js"
php "${ROOT_DIR}/tools/test_enterprise_integrations.php"
python3 "${ROOT_DIR}/tools/test_scoped_tls.py"
python3 "${ROOT_DIR}/tools/test_browser_display.py"
php "${ROOT_DIR}/tools/test_browser_events.php"
php "${ROOT_DIR}/tools/test_operator_passkeys.php"
python3 "${ROOT_DIR}/tools/test_mail_evidence.py"
php "${ROOT_DIR}/tools/test_mail_evidence.php"
php "${ROOT_DIR}/tools/test_enterprise_sms_replies.php"
php "${ROOT_DIR}/tools/test_enterprise_integrated_facade.php"
php "${ROOT_DIR}/tools/test_enterprise_feature_manager.php"
python3 "${ROOT_DIR}/tools/test_enterprise_integration_protocols.py"
python3 "${ROOT_DIR}/tools/test_slsconsole.py"
php "${ROOT_DIR}/tools/test_webhook_reporting.php"
node --check "${ROOT_DIR}/slsmassnotifyserver/views/device_acceptance.js"

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
if find "${ROOT_DIR}/${MODULE}" -type d \
  ! -path "${ROOT_DIR}/${MODULE}/passkey-libs/vendor/psr/log" \( \
  -name '__pycache__' -o -name '.cache' -o -name cache -o -name caches \
  -o -name log -o -name logs -o -name backup -o -name backups \
  -o -name generated -o -name rendered -o -name tmp \
\) -print -quit | grep -q .; then
  printf 'Module tree contains a generated, cache, log, or backup directory.\n' >&2
  exit 1
fi
python3 "${ROOT_DIR}/tools/scan_release_source.py" "${ROOT_DIR}/${MODULE}" "${ROOT_DIR}/tools"

for required in \
  EnterpriseContinuity.php \
  docs/INSTALLER_OPERATIONS.md docs/ENTERPRISE_ACCEPTANCE.md docs/READINESS_REVIEW_0.1.6.md PhoneInventory.php ScheduleProgrammes.php ControlIdempotency.php BrowserPush.php BrowserEvents.php EnterpriseAnnouncementChannels.php EnterpriseDeliveryConnector.php ScopedTls.php IncidentLanguages.php \
  views/identity_labs.css views/schedule_programmes.php views/schedule_programmes.js views/programme_visual_calendar.js views/schedule_programmes.css views/installation_profile.php views/system_recording_browser.js portal/subscriber-worker.js \
  bin/sls_mass_notify/sls_speech_admission.py bin/sls_mass_notify/sls_piper_worker.py bin/sls_mass_notify/sls_desktop_history.py bin/sls_mass_notify/sls_desktop_feed_cache.py bin/sls_mass_notify/sls_php_security.py bin/sls_mass_notify/php-security-policy.json bin/sls_mass_notify/sls_phone_inventory.py bin/sls_mass_notify/sls_install_transaction.py bin/sls_mass_notify/sls_install_doctor.py bin/sls_mass_notify/sls_update_status.py bin/sls_mass_notify/sls_atomic_json.php bin/sls_mass_notify/sls_atomic_json.py \
  docs/OPERATORS.md docs/TRIGGERS.md docs/RELEASE_TRUST.md docs/PAGING.md \
  docs/ENTERPRISE_OPERATIONS.md docs/ENTERPRISE_LABS_PLAN.md docs/ENTERPRISE_CLUSTER.md docs/ENTERPRISE_IDENTITY.md docs/ENTERPRISE_INTEGRATIONS.md \
  LabsSafety.php BetaNotice.php BetaNoticeService.php views/beta_notice.php views/beta_notice.css views/beta_notice.js EnterpriseAdministration.php EnterpriseOperations.php EnterpriseOperationsConfig.php EnterpriseOperationsStore.php EnterpriseApprovalService.php EnterpriseFloorplanStore.php EnterpriseFloorplanAudit.php EnterpriseFloorplanReferences.php page.slsmassnotifyserver_enterprise.php views/enterprise.php views/enterprise.css views/save_state.js views/enterprise.js \
  EnterpriseCluster.php EnterpriseClusterConfig.php EnterpriseClusterIntegration.php EnterpriseClusterRuntime.php EnterpriseClusterProtocol.php EnterpriseClusterStore.php EnterpriseClusterEdge.php EnterpriseClusterPbxContract.php EnterpriseClusterIncidentStore.php api/sls-mass-notify/peer.php api/sls-mass-notify/edge.php api/sls-mass-notify/edge-view.php bin/sls_mass_notify_cluster_worker.php bin/sls_mass_notify_cluster_effect.php bin/sls_mass_notify/sls_cluster_guard.py \
  EnterpriseIdentity.php EnterpriseIdentityConfig.php EnterpriseIdentityHttp.php EnterpriseIdentityManagement.php EnterpriseIdentityOidc.php EnterpriseIdentitySaml.php EnterpriseIdentityStore.php DirectoryConfig.php DirectoryCloud.php DirectorySync.php SubscriberConfig.php SubscriberService.php identity-libs/.htaccess identity-libs/composer.json identity-libs/composer.lock identity-libs/SLS_VENDOR_BASELINE.json identity-libs/SLS_VENDOR_PATCHES.json identity-libs/vendor/autoload.php portal/sso.php portal/subscriber.php portal/display.php portal/display.js portal/passkeys.js OperatorPasskeys.php PasskeyConfig.php OperatorPasskeyView.php passkey-libs/.htaccess passkey-libs/composer.json passkey-libs/composer.lock passkey-libs/verify.php passkey-libs/vendor/autoload.php \
  EnterpriseIntegrations.php EnterpriseIntegrationsConfig.php EnterpriseIntegrationsProvider.php EnterpriseIntegrationsService.php EnterpriseIntegrationsStore.php EnterprisePublicWarning.php views/integrations.php views/integrations.css views/integrations.js bin/sls_mass_notify/sls_mass_notify_incident_response.php \
  views/detected_phones.php bin/sls_mass_notify/sls_upgrade_trust.py bin/sls_mass_notify/freepbx-mirror-signing.pub \
  AudienceLimits.php DeliveryRehearsal.php \
  RadioGateway.php \
  Monitoring.php MailEvidence.php bin/sls_mass_notify/sls_mail_evidence.py bin/sls_mass_notify/sls_support_snapshot.py bin/sls_mass_notify/sls_nws_http.py bin/sls_mass_notify/sls_weather_payload.py \
  module.xml Slsmassnotifyserver.class.php ImmediateSettings.php InitializationJournal.php StatusHealth.php LocalWebProbe.php AdvertisedAddress.php EncryptedConfig.php AdminAudit.php OperationalBackup.php AnnouncementDelivery.php AnnouncementLimits.php AnnouncementPreview.php DeliveryReview.php SpeechRules.php RegionalVoices.php DesktopFleet.php AnnouncementAdmission.php ScheduledDelivery.php SchedulePresentation.php ScheduleCalendar.php views/schedule_calendar.php UpdatePolicy.php DesktopCapacity.php PhoneEventService.php OutboundVoice.php AnnouncementEmail.php AnnouncementSms.php RecipientSelection.php TestProfiles.php SupportDiagnostics.php DeploymentReadiness.php DeploymentAcceptance.php DeviceAcceptance.php views/deployment_readiness.php views/device_acceptance.php views/device_acceptance.js Backup.php Restore.php install.php uninstall.php \
  views/outbound_voice.php views/recipient_selection.php views/announcement_email.php views/announcement_email_selector.php views/announcement_sms.php views/announcement_sms_selector.php \
  ApiCredentialManagement.php ApiPermissionGuards.php views/api_credentials.php api/sls-mass-notify/index.php api/sls-mass-notify/security.php api/sls-mass-notify/event-log.php api/sls-mass-notify/sms-callback.php api/sls-mass-notify/sms/Config.php api/sls-mass-notify/sms/Store.php api/sls-mass-notify/sms/Continuity.php api/sls-mass-notify/sms/Provider.php api/sls-mass-notify/sms/Service.php api/sls-mass-notify/sms/.htaccess api/sls-mass-notify/sms/vendor/twilio/RequestValidator.php api/sls-mass-notify/sms/vendor/twilio/Values.php api/sls-mass-notify/sms/vendor/twilio/LICENSE api/sls-mass-notify/sms/vendor/twilio/UPSTREAM.json \
  PasswordBlocklist.php resources/common-passwords.sha256 resources/common-passwords.source.json resources/common-passwords.LICENSE \
  OperatorAuth.php OperatorPortal.php OperatorRecovery.php OperatorRecoveryService.php OperatorLoginRate.php SecurityAudit.php portal/index.php portal/.htaccess views/portal.css OperatorAccess.php Operators.php DeliveryAuthorization.php WeatherDeliveryReceipts.php page.slsmassnotifyserver_operations.php page.slsmassnotifyserver_operators.php views/operations.php views/operations.js views/operators.php views/operators.js views/operators.css \
  AutomationConfig.php AutomationStore.php AutomationService.php AutomationInputs.php AutomationPhone.php Automations.php page.slsmassnotifyserver_automations.php views/automations.php views/automations.js views/automations.css api/sls-mass-notify/trigger.php \
  IncidentConfig.php IncidentStore.php IncidentArchive.php IncidentService.php Incidents.php page.slsmassnotifyserver_incidents.php views/incidents.php views/incident_retention.php views/incident_escalation_editor.js \
  page.slsmassnotifyserver_scheduling.php views/scheduling.php \
  AudiencePicker.php views/audience_source_picker.php views/audience_source_picker.js views/audiences.php views/audiences.js views/audience_webhook_selector.php views/labs.php views/settings_navigation.php views/device_capacity.js LocationDirectory.php GeographicTargeting.php Locations.php page.slsmassnotifyserver_locations.php views/locations.php views/locations.css views/locations.js views/location_map.php views/geographic.js assets/location-basemap.svg \
  Paging.php VoicemailConfig.php functions.inc.php page.slsmassnotifyserver_paging.php views/paging.php \
  page.slsmassnotifyserver_lightning.php views/lightning.php \
  api/sipnotify/index.php \
  api/sls-mass-notify/media.php api/sls-mass-notify/media-policy.php api/sls-mass-notify/person-profiles.php api/sls-mass-notify/desktop-feed-cache.php \
  dashboard/sections/SlsMassNotifyAnnouncement.class.php \
  dashboard/views/sections/sls-mass-notify-announcement.php \
  bin/sls_mass_notify/sls_notify.py bin/sls_mass_notify/sls_config.py \
  bin/sls_mass_notify/sls_branded_email.py bin/sls_mass_notify/sls_announcement_email.py \
  bin/sls_mass_notify/sls_branded_discord.py assets/webhook-builder.png \
  bin/sls_mass_notify/sls_notification_destinations.py \
  bin/sls_mass_notify/sls_release_verify.py bin/sls_mass_notify/sls_release_trust.py bin/sls_mass_notify/release-signing.pub \
  bin/sls_mass_notify/sls_module_trust.py bin/sls_mass_notify_delivery_authorization.php bin/sls_mass_notify/sls_trigger_actions.py bin/sls_mass_notify/sls_emergency_observer.py bin/sls_mass_notify_panic.php bin/sls_mass_notify_automation_worker.php \
  bin/sls_mass_notify/sls_privileged_install.py \
  bin/slsconsole bin/sls_mass_notify/sls_console.py bin/sls_mass_notify/sls_runtime_state.php \
  bin/sls_mass_notify/sls_installer_recovery.py \
  bin/sls_mass_notify/sls_install_idle.py \
  bin/sls_mass_notify/sls_install_guard.py \
  bin/sls_mass_notify/sls_legacy_permissions.py \
  bin/sls_mass_notify/sls_mail_admission.py bin/sls_mass_notify/sls_cron_runner.py \
  bin/sls_mass_notify/piper-requirements.txt bin/sls_mass_notify/piper-requirements.lock \
  bin/sls_mass_notify/piper-packaging.lock bin/sls_mass_notify/piper-artifacts.json bin/sls_mass_notify/piper-optional-voices.json \
  bin/sls_mass_notify/sls_audio_queue.py bin/sls_mass_notify/sls_audio_state.py bin/sls_mass_notify/sls_storage_maintenance.py bin/sls_mass_notify/sls_audit_separation.py bin/sls_mass_notify/sls_operational_backup.py \
  bin/sls_mass_notify/sls_resource_capacity.py bin/sls_mass_notify/sls_bounded_stream.py bin/sls_mass_notify/sls_piper_dependencies.py bin/sls_mass_notify/sls_piper_environment.py bin/sls_mass_notify/sls_update_policy.py \
  bin/sls_mass_notify/sls_phone_admission.py bin/sls_mass_notify/sls_voicemail.py bin/sls_mass_notify/sls_phone_events.py bin/sls_mass_notify/sls_live_paging_visual.py \
  bin/sls_mass_notify/sls_phone_outcomes.py bin/sls_mass_notify/sls_outbound_routes.py bin/sls_mass_notify_phone_agi.py \
  bin/sls_mass_notify/external-acknowledgement.wav \
  bin/sls_mass_notify/sls_weather_queue.py \
  bin/sls_mass_notify/sls_announcement_jobs.php \
  bin/sls_mass_notify/sls_api_test_guard.php \
  bin/sls_mass_notify/sls_live_paging.php bin/sls_mass_notify_live_paging.php \
  bin/sls_mass_notify_announcement_worker.php \
  bin/sls_mass_notify/sls_system_notifications.py bin/sls_mass_notify/sls_external_monitor.py \
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

sls_archive_members() {
  command python3 -I - "${ROOT_DIR}" "${MODULE}" <<'PY'
import os
from pathlib import Path
import stat
import sys

root = Path(sys.argv[1])
module = root / sys.argv[2]

def members(path):
    if path.name in {'module.sig', '__pycache__'} or path.name.endswith('.pyc'):
        return []
    info = path.lstat()
    if info.st_mode & 0o6000:
        raise SystemExit(f'Unsafe archive source permissions: {path}')
    if stat.S_ISREG(info.st_mode) and info.st_nlink == 1:
        return [path.relative_to(root)]
    if not stat.S_ISDIR(info.st_mode):
        raise SystemExit(f'Unsafe archive source entry: {path}')
    descendants = []
    for child in sorted(path.iterdir(), key=lambda value: os.fsencode(value.name)):
        descendants.extend(members(child))
    return [path.relative_to(root), *descendants] if descendants else []

paths = members(module)
if not paths:
    raise SystemExit('Module source has no archive files.')
sys.stdout.buffer.write(b'\0'.join(os.fsencode(path) for path in paths) + b'\0')
PY
}

sls_archive_members | tar --no-recursion --null --verbatim-files-from \
  --mtime='@0' --owner=0 --group=0 --numeric-owner --mode='a=rX,u+w' \
  --exclude='module.sig' --exclude='__pycache__' --exclude='*.pyc' \
  -C "${ROOT_DIR}" -cf - --files-from=- | gzip -n -9 > "${PACKAGE}"
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
    if not members or len(members) > 5000:
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
if [[ "$UNSIGNED" == 0 ]]; then python3 "${ROOT_DIR}/tools/sign_release.py"; fi
printf '%s\n' "${PACKAGE}"
