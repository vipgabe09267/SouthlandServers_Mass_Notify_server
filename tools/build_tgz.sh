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

cmp -s "${ROOT_DIR}/slsmassnotifyserver/api/sls-mass-notify/config-crypto.php" "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify/sls_config_crypto.php" || { printf 'Shared PHP configuration encryption copies differ.\n' >&2; exit 1; }
python3 "${ROOT_DIR}/tools/test_config_crypto.py"
python3 "${ROOT_DIR}/tools/test_config_fail_closed.py"
python3 "${ROOT_DIR}/tools/test_installer_crypto_helper.py"
python3 "${ROOT_DIR}/tools/test_release_portability.py"
php "${ROOT_DIR}/tools/test_ami_loopback.php"
bash "${ROOT_DIR}/tools/test_installer_asterisk_capabilities.sh"
bash "${ROOT_DIR}/tools/test_installer_timezone.sh"
bash "${ROOT_DIR}/tools/test_installer_config_safety.sh"
bash "${ROOT_DIR}/tools/test_local_signer.sh"
bash "${ROOT_DIR}/tools/test_uninstaller_signer_snapshot.sh"
php "${ROOT_DIR}/tools/test_scheduling_contract.php"
php "${ROOT_DIR}/tools/test_schedule_calendar.php"
php "${ROOT_DIR}/tools/test_announcement_delivery_contract.php"
php "${ROOT_DIR}/tools/test_announcement_snapshot.php"
php "${ROOT_DIR}/tools/test_speech_duration.php"
python3 "${ROOT_DIR}/tools/test_announcement_previews.py"
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
php "${ROOT_DIR}/tools/test_incident_email.php"
php "${ROOT_DIR}/tools/test_phone_collector_service.php"
python3 "${ROOT_DIR}/tools/test_installer_phone_service.py"
python3 "${ROOT_DIR}/tools/test_phone_admission.py"
python3 "${ROOT_DIR}/tools/test_outbound_playback_asterisk.py"
python3 "${ROOT_DIR}/tools/test_phone_outcomes.py"
python3 "${ROOT_DIR}/tools/test_outbound_routes.py"
php "${ROOT_DIR}/tools/test_phone_producers.php"
python3 "${ROOT_DIR}/tools/test_resource_capacity.py"
php "${ROOT_DIR}/tools/test_system_recordings.php"
python3 "${ROOT_DIR}/tools/test_weather_channels.py"
python3 "${ROOT_DIR}/tools/test_lightning_providers.py"
python3 "${ROOT_DIR}/tools/test_piper_dependencies.py"
python3 "${ROOT_DIR}/tools/test_piper_environment.py"
python3 "${ROOT_DIR}/tools/test_sbom.py"
python3 "${ROOT_DIR}/tools/test_release_source_scan.py"
python3 "${ROOT_DIR}/tools/test_update_policy.py"
php "${ROOT_DIR}/tools/test_update_policy.php"
php "${ROOT_DIR}/tools/test_setup_preferences.php"
php "${ROOT_DIR}/tools/test_control_api_bounds.php"
php "${ROOT_DIR}/tools/test_control_api_contract.php"
php "${ROOT_DIR}/tools/test_event_log_pages.php"
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
php "${ROOT_DIR}/tools/test_phone_media_url.php"
python3 "${ROOT_DIR}/tools/test_phone_media_url.py"
python3 "${ROOT_DIR}/tools/test_announcement_display_timeout.py"
python3 "${ROOT_DIR}/tools/test_desktop_payload.py"
python3 "${ROOT_DIR}/tools/test_desktop_streaming.py"
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
python3 "${ROOT_DIR}/tools/test_weather_email_details.py"
bash "${ROOT_DIR}/tools/test_installer_dependencies.sh"
python3 "${ROOT_DIR}/tools/test_automation_http.py"
php "${ROOT_DIR}/tools/test_automation_integration.php"
php "${ROOT_DIR}/tools/test_panic_phone.php"
python3 "${ROOT_DIR}/tools/test_trigger_actions.py"
python3 "${ROOT_DIR}/tools/test_emergency_observer.py"
php "${ROOT_DIR}/tools/test_incidents.php"
php "${ROOT_DIR}/tools/test_incident_escalation.php"
node --check "${ROOT_DIR}/slsmassnotifyserver/views/incident_escalation_editor.js"
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
python3 "${ROOT_DIR}/tools/test_external_delivery_retry.py"
python3 "${ROOT_DIR}/tools/test_system_notifications.py"
python3 "${ROOT_DIR}/tools/test_system_health_notifications.py"
python3 "${ROOT_DIR}/tools/test_status_health.py"
python3 "${ROOT_DIR}/tools/test_maintenance_permission_safety.py"
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
python3 "${ROOT_DIR}/tools/test_manual_weather_reservations.py"
php "${ROOT_DIR}/tools/test_freepbx_backup_restore.php"
php "${ROOT_DIR}/tools/test_native_restore_recovery.php"
python3 "${ROOT_DIR}/tools/test_operational_backup.py"
php "${ROOT_DIR}/tools/test_operational_backup.php"
php "${ROOT_DIR}/tools/test_help_ui_contract.php"
php "${ROOT_DIR}/tools/test_desktop_reliability.php"
php "${ROOT_DIR}/tools/test_desktop_receipts.php"
python3 "${ROOT_DIR}/tools/test_desktop_receipt_contract.py"
node "${ROOT_DIR}/tools/test_desktop_receipt_ui.js"
node "${ROOT_DIR}/tools/test_phone_contact_editor.js"
python3 "${ROOT_DIR}/tools/test_desktop_api_delivery.py"
python3 "${ROOT_DIR}/tools/test_desktop_editor.py"
php "${ROOT_DIR}/tools/test_live_paging.php"
php "${ROOT_DIR}/tools/test_paging_pending_dialplan.php"
python3 "${ROOT_DIR}/tools/test_live_paging_asterisk.py"
php "${ROOT_DIR}/tools/test_live_audio_durability.php"
php "${ROOT_DIR}/tools/test_live_paging_notify.php"
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
php "${ROOT_DIR}/tools/test_device_acceptance.php"
php "${ROOT_DIR}/tools/test_enterprise_operations.php"
php "${ROOT_DIR}/tools/test_enterprise_security_review.php"
python3 "${ROOT_DIR}/tools/test_enterprise_controller_http.py"
php "${ROOT_DIR}/tools/test_enterprise_identity.php"
php "${ROOT_DIR}/tools/test_enterprise_identity_management.php"
php "${ROOT_DIR}/tools/test_enterprise_operator_api.php"
python3 "${ROOT_DIR}/tools/test_enterprise_control_http.py"
python3 "${ROOT_DIR}/tools/test_enterprise_identity_http.py"
php "${ROOT_DIR}/tools/test_enterprise_cluster.php"
python3 "${ROOT_DIR}/tools/test_enterprise_cluster_tls.py"
python3 "${ROOT_DIR}/tools/test_enterprise_cluster_deployment.py"
node "${ROOT_DIR}/tools/test_enterprise_edge_view.js"
node "${ROOT_DIR}/tools/test_dashboard_assets.js"
php "${ROOT_DIR}/tools/test_enterprise_integrations.php"
php "${ROOT_DIR}/tools/test_enterprise_sms_replies.php"
php "${ROOT_DIR}/tools/test_enterprise_integrated_facade.php"
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
if find "${ROOT_DIR}/${MODULE}" -type d \( \
  -name '__pycache__' -o -name '.cache' -o -name cache -o -name caches \
  -o -name log -o -name logs -o -name backup -o -name backups \
  -o -name generated -o -name rendered -o -name tmp \
\) -print -quit | grep -q .; then
  printf 'Module tree contains a generated, cache, log, or backup directory.\n' >&2
  exit 1
fi
python3 "${ROOT_DIR}/tools/scan_release_source.py" "${ROOT_DIR}/${MODULE}" "${ROOT_DIR}/tools"

for required in \
  docs/OPERATORS.md docs/TRIGGERS.md docs/RELEASE_TRUST.md docs/PAGING.md \
  docs/ENTERPRISE_OPERATIONS.md docs/ENTERPRISE_LABS_PLAN.md docs/ENTERPRISE_CLUSTER.md docs/ENTERPRISE_IDENTITY.md docs/ENTERPRISE_INTEGRATIONS.md \
  LabsSafety.php EnterpriseAdministration.php EnterpriseOperations.php EnterpriseOperationsConfig.php EnterpriseOperationsStore.php EnterpriseApprovalService.php EnterpriseFloorplanStore.php page.slsmassnotifyserver_enterprise.php views/enterprise.php views/enterprise.css views/enterprise.js \
  EnterpriseCluster.php EnterpriseClusterConfig.php EnterpriseClusterIntegration.php EnterpriseClusterRuntime.php EnterpriseClusterProtocol.php EnterpriseClusterStore.php EnterpriseClusterEdge.php EnterpriseClusterPbxContract.php EnterpriseClusterIncidentStore.php api/sls-mass-notify/peer.php api/sls-mass-notify/edge.php api/sls-mass-notify/edge-view.php bin/sls_mass_notify_cluster_worker.php bin/sls_mass_notify_cluster_effect.php bin/sls_mass_notify/sls_cluster_guard.py \
  EnterpriseIdentity.php EnterpriseIdentityConfig.php EnterpriseIdentityHttp.php EnterpriseIdentityManagement.php EnterpriseIdentityOidc.php EnterpriseIdentitySaml.php EnterpriseIdentityStore.php DirectoryConfig.php DirectorySync.php SubscriberConfig.php SubscriberService.php identity-libs/.htaccess identity-libs/composer.json identity-libs/composer.lock identity-libs/vendor/autoload.php portal/sso.php portal/subscriber.php \
  EnterpriseIntegrations.php EnterpriseIntegrationsConfig.php EnterpriseIntegrationsProvider.php EnterpriseIntegrationsService.php EnterpriseIntegrationsStore.php EnterprisePublicWarning.php views/integrations.php views/integrations.css views/integrations.js bin/sls_mass_notify/sls_mass_notify_incident_response.php \
  module.xml Slsmassnotifyserver.class.php StatusHealth.php LocalWebProbe.php AdvertisedAddress.php EncryptedConfig.php AdminAudit.php OperationalBackup.php AnnouncementDelivery.php AnnouncementPreview.php SpeechRules.php DesktopFleet.php AnnouncementAdmission.php ScheduledDelivery.php SchedulePresentation.php ScheduleCalendar.php views/schedule_calendar.php UpdatePolicy.php DesktopCapacity.php PhoneEventService.php OutboundVoice.php AnnouncementEmail.php AnnouncementSms.php RecipientSelection.php TestProfiles.php SupportDiagnostics.php DeploymentReadiness.php DeploymentAcceptance.php DeviceAcceptance.php views/deployment_readiness.php views/device_acceptance.php views/device_acceptance.js Backup.php Restore.php install.php uninstall.php \
  views/outbound_voice.php views/recipient_selection.php views/announcement_email.php views/announcement_email_selector.php views/announcement_sms.php views/announcement_sms_selector.php \
  ApiCredentialManagement.php ApiPermissionGuards.php views/api_credentials.php api/sls-mass-notify/index.php api/sls-mass-notify/security.php api/sls-mass-notify/event-log.php api/sls-mass-notify/sms-callback.php api/sls-mass-notify/sms/Config.php api/sls-mass-notify/sms/Store.php api/sls-mass-notify/sms/Continuity.php api/sls-mass-notify/sms/Provider.php api/sls-mass-notify/sms/Service.php api/sls-mass-notify/sms/.htaccess api/sls-mass-notify/sms/vendor/twilio/RequestValidator.php api/sls-mass-notify/sms/vendor/twilio/Values.php api/sls-mass-notify/sms/vendor/twilio/LICENSE api/sls-mass-notify/sms/vendor/twilio/UPSTREAM.json \
  OperatorAuth.php OperatorPortal.php OperatorRecovery.php OperatorRecoveryService.php OperatorLoginRate.php SecurityAudit.php portal/index.php portal/.htaccess views/portal.css OperatorAccess.php Operators.php DeliveryAuthorization.php WeatherDeliveryReceipts.php page.slsmassnotifyserver_operations.php page.slsmassnotifyserver_operators.php views/operations.php views/operations.js views/operators.php views/operators.js views/operators.css \
  AutomationConfig.php AutomationStore.php AutomationService.php AutomationInputs.php AutomationPhone.php Automations.php page.slsmassnotifyserver_automations.php views/automations.php views/automations.js views/automations.css api/sls-mass-notify/trigger.php \
  IncidentConfig.php IncidentStore.php IncidentArchive.php IncidentService.php Incidents.php page.slsmassnotifyserver_incidents.php views/incidents.php views/incident_retention.php views/incident_escalation_editor.js \
  page.slsmassnotifyserver_scheduling.php views/scheduling.php \
  AudiencePicker.php views/audience_source_picker.php views/audience_source_picker.js views/audiences.php views/audiences.js views/audience_webhook_selector.php views/labs.php views/settings_navigation.php views/device_capacity.js LocationDirectory.php GeographicTargeting.php Locations.php page.slsmassnotifyserver_locations.php views/locations.php views/locations.css views/locations.js views/location_map.php views/geographic.js assets/location-basemap.svg \
  Paging.php functions.inc.php page.slsmassnotifyserver_paging.php views/paging.php \
  page.slsmassnotifyserver_lightning.php views/lightning.php \
  api/sipnotify/index.php \
  api/sls-mass-notify/media.php api/sls-mass-notify/media-policy.php \
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
  bin/sls_mass_notify/piper-requirements.txt bin/sls_mass_notify/piper-requirements.lock \
  bin/sls_mass_notify/piper-packaging.lock bin/sls_mass_notify/piper-artifacts.json \
  bin/sls_mass_notify/sls_audio_queue.py bin/sls_mass_notify/sls_audio_state.py bin/sls_mass_notify/sls_storage_maintenance.py bin/sls_mass_notify/sls_audit_separation.py bin/sls_mass_notify/sls_operational_backup.py \
  bin/sls_mass_notify/sls_resource_capacity.py bin/sls_mass_notify/sls_piper_dependencies.py bin/sls_mass_notify/sls_piper_environment.py bin/sls_mass_notify/sls_update_policy.py \
  bin/sls_mass_notify/sls_phone_admission.py bin/sls_mass_notify/sls_phone_events.py \
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
