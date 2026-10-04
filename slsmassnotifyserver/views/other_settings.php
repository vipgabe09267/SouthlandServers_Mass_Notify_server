<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group
$settings = is_array($settings ?? null) ? $settings : [];
require_once dirname(__DIR__) . '/AdvertisedAddress.php';
$addressFields = \FreePBX\modules\SlsAdvertisedAddress::fields($settings);
$saveResult = $save_result ?? null;
$applyResult = $apply_result ?? null;
$tokenResult = $token_result ?? null;
$importResult = $import_result ?? null;
$hasPendingChanges = !empty($has_pending_changes);
$voices = is_array($available_voices ?? null) ? $available_voices : [];
$tones = is_array($available_tones ?? null) ? $available_tones : [];
$systemSounds = is_array($available_system_sounds ?? null) ? $available_system_sounds : [];
$control = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : [];
$updates = is_array($settings['updates'] ?? null) ? $settings['updates'] : [];
$desktopClients = is_array($desktop_clients ?? null) ? $desktop_clients : [];
$testProfilePhones = [];
foreach (($available_extensions ?? []) as $extension) {
	$testProfilePhones[] = ['value' => (string)$extension['extension'], 'label' => (string)$extension['extension'] . ' · ' . (string)($extension['name'] ?? '')];
}
$testProfileDesktops = [];
foreach ($desktopClients as $client) {
	$testProfileDesktops[] = ['value' => (string)($client['username'] ?? ''), 'label' => (string)($client['name'] ?? $client['username'] ?? '')];
}
$packageStatus = is_array($package_update_status ?? null) ? $package_update_status : ['state' => 'latest', 'label' => 'LATEST'];
$hasPackageUpdate = (($packageStatus['state'] ?? '') === 'update');
$updateProgress = is_array($update_progress ?? null) ? $update_progress : ['state' => 'idle', 'message' => ''];
$updateProgressState = in_array(($updateProgress['state'] ?? 'idle'), ['idle', 'queued', 'checking', 'installing', 'complete', 'failed'], true) ? (string)$updateProgress['state'] : 'idle';
$updateMonitorActive = !empty($update_monitor_active) || in_array($updateProgressState, ['queued', 'checking', 'installing'], true);
$updateProgressBusy = in_array($updateProgressState, ['queued', 'checking', 'installing'], true);
$maintenanceProgress = is_array($maintenance_progress ?? null) ? $maintenance_progress : ['action' => '', 'state' => 'idle', 'message' => ''];
$maintenanceProgressState = in_array(($maintenanceProgress['state'] ?? 'idle'), ['idle', 'queued', 'running', 'complete', 'failed'], true) ? (string)$maintenanceProgress['state'] : 'idle';
$maintenanceMonitorAction = in_array(($maintenance_monitor_action ?? ''), ['repair', 'uninstall', 'config'], true) ? (string)$maintenance_monitor_action : '';
$maintenanceMonitorActive = $maintenanceMonitorAction !== '';
$maintenanceProgressBusy = in_array($maintenanceProgressState, ['queued', 'running'], true);
$packageStatusClass = (($packageStatus['state'] ?? '') === 'error') ? 'label-danger'
    : ((($packageStatus['state'] ?? '') === 'update') ? 'label-warning' : 'label-success');
$formatOverrides = [];
$formatLabels = [
	'yealink' => _('Yealink - Color'), 'yealink_text' => _('Yealink - Text Only'),
	'yealink_image' => _('Yealink - Always Image (color phones)'),
	'cisco' => _('Cisco'), 'poly' => _('Poly / Polycom'), 'grandstream' => _('Grandstream'),
	'fanvil' => _('Fanvil'), 'snom' => _('Snom'), 'aastra' => _('Aastra / Mitel'),
	'sangoma' => _('Sangoma'), 'avaya' => _('Avaya'), 'vtech' => _('VTech'),
	'ale' => _('Alcatel-Lucent Enterprise'), 'panasonic' => _('Panasonic KX Series'),
];
$systemNotificationEmails = preg_split(
	'/[\s,;]+/',
	trim((string)($settings['system_notification_emails'] ?? $settings['mail_to'] ?? '')),
	-1,
	PREG_SPLIT_NO_EMPTY
) ?: [];
$discordWebhooks = is_array($settings['discord_webhooks'] ?? null) ? $settings['discord_webhooks'] : [];
if (empty($discordWebhooks) && !empty($settings['discord_webhook_url'])) {
	$discordWebhooks[] = [
		'id' => 'discord_' . substr(hash('sha256', (string)$settings['discord_webhook_url']), 0, 16),
		'name' => _('Primary Discord'),
		'url' => (string)$settings['discord_webhook_url'],
		'enabled' => '1',
	];
}
$genericWebhooks = is_array($settings['generic_webhooks'] ?? null) ? $settings['generic_webhooks'] : [];
$announcementWebhooks = is_array($settings['announcement_webhooks'] ?? null) ? $settings['announcement_webhooks'] : [];
$renderWebhookFormat = static function ($type, $name, $selected = 'native') {
	$options = ['native' => $type === 'announcement' ? _('Discord-compatible') : _('SLS event JSON'), 'slack' => _('Slack incoming webhook'), 'teams_workflow' => _('Microsoft Teams Workflows')];
	echo '<label>' . _('Integration') . '<select class="form-control" data-field="payload_format" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">';
	foreach ($options as $value => $label) {
		echo '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
	}
	echo '</select></label>';
};
$enabledDiscordWebhooks = array_filter($discordWebhooks, static function ($destination) {
	return is_array($destination) && !empty($destination['enabled']);
});
$enabledGenericWebhooks = array_filter($genericWebhooks, static function ($destination) {
	return is_array($destination) && !empty($destination['enabled']);
});
$enabledAnnouncementWebhooks = array_filter($announcementWebhooks, static function ($destination) {
	return is_array($destination) && !empty($destination['enabled']);
});
$mailFromLocalPart = strtolower(trim((string)($settings['mail_from_local_part'] ?? 'no-reply')));
if ($mailFromLocalPart === '') {
	$mailFromLocalPart = 'no-reply';
}
$mailFromDomain = strtolower(trim((string)($settings['mail_from_domain'] ?? '')));
$mailFromAddress = strtolower(trim((string)($settings['mail_from_addr'] ?? '')));
if ($mailFromDomain === '' && strpos($mailFromAddress, '@') !== false) {
	$mailFromDomain = substr($mailFromAddress, strrpos($mailFromAddress, '@') + 1);
}
if ($mailFromDomain === '') {
	$mailFromDomain = 'localhost.localdomain';
}
$mailFromAddress = $mailFromLocalPart . '@' . $mailFromDomain;
$csrfToken = (string)($csrf_token ?? '');
foreach ((array)($settings['sipnotify']['format_overrides'] ?? []) as $extension => $format) {
	$extension = preg_replace('/[^0-9]/', '', (string)$extension);
	$format = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$format));
	if ($extension !== '' && $format !== '') {
		$formatOverrides[] = ['extension' => $extension, 'format' => $format];
	}
}
?>
<style>
#sls-other-settings-form .input-group > .form-control { min-width:0; width:1%; flex:1 1 0; }
#sls-other-settings-form .input-group > .input-group-addon { flex:0 0 auto; width:auto; white-space:nowrap; }
.sls-update-policy .form-group { display:block; }
#sls-address-editor .sls-address-ports {display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:8px 0 12px;}
#sls-address-editor .sls-address-ports > div {min-width:0;}
@media(max-width:600px){#sls-address-editor .sls-address-ports{grid-template-columns:1fr;}}
.sls-labs-badge {
	display: inline-block;
	margin-left: 6px;
	vertical-align: middle;
}
.sls-format-help {
	position: relative;
	display: inline-block;
	padding: 0;
	border: 0;
	background: transparent;
	color: #337ab7;
	cursor: help;
	font-size: 16px;
	line-height: 1;
	outline: none;
}
.sls-format-help-text {
	display: none;
	position: absolute;
	z-index: 1000;
	top: 22px;
	right: 0;
	width: 360px;
	max-width: 80vw;
	padding: 10px 12px;
	border: 1px solid #9ca3af;
	background: #fff;
	color: #1f2937;
	box-shadow: 0 3px 10px rgba(0, 0, 0, 0.18);
	font-size: 12px;
	font-weight: 400;
	line-height: 1.45;
	text-align: left;
	white-space: normal;
}
.sls-format-help:hover .sls-format-help-text,
.sls-format-help:focus .sls-format-help-text {
	display: block;
}
.sls-settings-heading {
	margin: 28px 0 16px;
	padding: 0 0 8px;
	border-bottom: 1px solid #d7dce2;
	font-size: 18px;
}
.sls-settings-intro {
	margin-bottom: 18px;
}
.sls-general-title { margin:0 0 7px; font-size:30px; line-height:1.2; font-weight:700; }
.sls-compact-table {
	max-height: 330px;
	overflow: auto;
}
.sls-desktop-client-scroll {
	max-height: 300px;
	overflow: auto;
	border: 1px solid #d7dce2;
	border-radius: 6px;
	background: #fff;
}
.sls-desktop-client-scroll table { margin-bottom: 0; min-width: 860px; }
.sls-desktop-client-scroll thead th {
	position: sticky;
	top: 0;
	z-index: 2;
	background: #f3f6f9;
	box-shadow: inset 0 -1px 0 #d7dce2;
}
.sls-manager-card { border:1px solid #dfe5ec; border-radius:8px; background:#f8fafc; padding:14px 16px; min-height:92px; }
.sls-manager-card h4 { margin:0 0 6px; font-size:16px; }
.sls-manager-summary { color:#64748b; margin-bottom:10px; }
.sls-manager-modal .modal-dialog { width:min(900px, calc(100% - 30px)); }
.sls-manager-modal .modal-body { max-height:68vh; overflow:auto; background:#f8fafc; }
.sls-editor-row { display:flex; gap:10px; align-items:center; padding:10px; margin-bottom:8px; background:#fff; border:1px solid #e5e7eb; border-radius:6px; }
.sls-editor-row .sls-editor-grow { flex:1 1 auto; }
.sls-editor-row .sls-editor-format { flex:0 1 330px; }
#sls-format-editor-list .sls-editor-row { display:grid; grid-template-columns:minmax(150px,1fr) minmax(220px,330px) auto; align-items:end; }
#sls-format-editor-list .sls-editor-row [data-remove-format] { margin:0 0 1px !important; padding-left:10px; padding-right:10px; white-space:nowrap; }
.sls-summary-table { margin-bottom:8px; background:#fff; }
.sls-destination-tabs { margin-bottom:16px; }
.sls-destination-pane { padding:16px; border:1px solid #dfe5ec; border-top:0; background:#fff; }
.sls-destination-list { max-height:320px; overflow:auto; margin-bottom:12px; }
.sls-webhook-row { display:grid; grid-template-columns:auto minmax(150px,1fr) minmax(260px,2fr) auto; gap:10px; align-items:end; }
.sls-webhook-enabled { align-self:center; padding-top:18px; }
.sls-destination-limit { display:inline-block; margin-left:8px; vertical-align:middle; }
.sls-stored-secret { color:#4b5563; font-size:12px; margin-top:5px; }
.sls-empty-state { padding:22px 16px; border:1px dashed #cbd5e1; border-radius:6px; color:#64748b; text-align:center; background:#f8fafc; }
.sls-destination-note { margin:12px 0 0; }
.sls-save-actions { margin:26px 0 34px; padding:16px; border:1px solid #dfe5ec; border-radius:8px; background:#f8fafc; }
.sls-update-controls { display:flex; align-items:center; flex-wrap:wrap; gap:10px; }
.sls-operation-status {
	display:grid;
	grid-template-columns:34px minmax(0,1fr);
	gap:10px;
	align-items:center;
	width:100%;
	margin:12px 0 0;
	padding:10px 12px;
	border:1px solid #bfdbfe;
	border-left:4px solid #3b82f6;
	border-radius:7px;
	background:#f8fbff;
	color:#334155;
}
.sls-operation-status[hidden] { display:none; }
.sls-operation-status__icon {
	display:flex;
	align-items:center;
	justify-content:center;
	width:32px;
	height:32px;
	border-radius:50%;
	background:#e8f1ff;
	color:#2563eb;
	font-size:15px;
}
.sls-operation-status__body { min-width:0; }
.sls-operation-status__title { display:block; margin-bottom:1px; color:#172033; font-size:13px; font-weight:700; }
.sls-operation-status__message { display:block; font-size:12px; line-height:1.4; overflow-wrap:anywhere; }
.sls-operation-status__track { height:2px; margin-top:7px; overflow:hidden; border-radius:99px; background:#dbeafe; }
.sls-operation-status__bar { display:block; width:38%; height:100%; border-radius:99px; background:#3b82f6; animation:sls-operation-progress 1.35s ease-in-out infinite; }
.sls-operation-status[data-state="complete"] { border-color:#bbdfc7; border-left-color:#2f855a; background:#f5fbf7; }
.sls-operation-status[data-state="complete"] .sls-operation-status__icon { background:#e1f3e7; color:#247347; }
.sls-operation-status[data-state="failed"] { border-color:#efc2c2; border-left-color:#c53030; background:#fff8f8; }
.sls-operation-status[data-state="failed"] .sls-operation-status__icon { background:#fbe7e7; color:#b42318; }
.sls-operation-status[data-state="complete"] .sls-operation-status__track,
.sls-operation-status[data-state="failed"] .sls-operation-status__track { display:none; }
@keyframes sls-operation-progress {
	0% { transform:translateX(-115%); }
	50% { transform:translateX(80%); }
	100% { transform:translateX(265%); }
}
@media (prefers-reduced-motion:reduce) { .sls-operation-status__bar { animation:none; width:100%; opacity:.55; } }
.sls-config-backup { margin:0 0 14px; padding-top:24px; border-top:1px solid #d7dce2; }
.sls-danger-panel { margin-top:24px; border-width:2px; }
.sls-danger-panel .panel-heading { padding:13px 16px; font-size:16px; font-weight:700; }
.sls-danger-panel .panel-body { padding:18px; }
.sls-danger-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; }
.sls-danger-action { display:flex; flex-direction:column; min-width:0; padding:16px; border:1px solid #f4c7c3; border-radius:8px; background:#fffafa; }
.sls-danger-action h4 { margin:0 0 9px; font-size:16px; }
.sls-danger-action p { margin:0 0 15px; line-height:1.5; }
.sls-danger-action form { margin-top:auto; }
.sls-danger-action .form-group { margin-bottom:12px; }
.sls-danger-action--critical { border-color:#f0a8a1; background:#fff5f5; }
.sls-maintenance-progress { margin:0 0 16px; }
.sls-maintenance-form .btn[disabled] { cursor:wait; }
@media (max-width:991px) { .sls-danger-grid { grid-template-columns:1fr; } }
@media(max-width:767px){.sls-editor-row,#sls-format-editor-list .sls-editor-row,.sls-webhook-row{display:block}.sls-editor-row>*,.sls-webhook-row>*{margin-bottom:8px}.sls-manager-modal .modal-dialog{width:auto}.sls-manager-card .text-right{text-align:left;margin-top:10px}#sls-format-editor-list .sls-editor-row [data-remove-format]{margin-top:4px !important}}
</style>
<div class="container-fluid">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image ?? '']); ?>
	<div class="row">
		<div class="col-sm-12">
			<h1 class="sls-general-title"><i class="fa fa-cogs text-primary" aria-hidden="true"></i> <?php echo _('General Settings'); ?></h1>
			<p class="text-muted sls-settings-intro"><?php echo _('Manage phone delivery, audio, desktop clients, remote access, updates, and recovery settings.'); ?></p>
			<div class="clearfix"></div>

			<?php foreach ([$saveResult, $applyResult, $tokenResult, $importResult] as $result) { ?>
				<?php if (is_array($result)) { ?>
					<div class="alert alert-<?php echo !empty($result['success']) ? 'success' : 'warning'; ?>">
						<?php echo htmlspecialchars($result['message']); ?>
						<?php if (!empty($result['errors'])) { ?>
							<ul><?php foreach ($result['errors'] as $error) { ?><li><?php echo htmlspecialchars($error); ?></li><?php } ?></ul>
						<?php } ?>
					</div>
				<?php } ?>
			<?php } ?>

			<form method="post" id="sls-other-settings-form" enctype="multipart/form-data">
				<input type="hidden" name="sls_general_form_present" value="1">
				<input type="hidden" name="slsmassnotifyserver_action" value="save_other_settings">
				<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

<?php include __DIR__ . '/settings_navigation.php'; ?>
<section class="sls-settings-section" id="sls-settings-phones" data-settings-section="phones" aria-labelledby="sls-settings-tab-phones">
				<h3 class="sls-settings-heading"><i class="fa fa-phone text-primary" aria-hidden="true"></i> <?php echo _('Phone Delivery'); ?></h3>
                <div class="form-group" style="max-width:620px">
                    <label for="sls-phone-limit"><?php echo _('Simultaneous Phone Capacity'); ?></label>
                    <input class="form-control" id="sls-phone-limit" name="phone_device_limit" type="number" min="1" max="1000" value="<?php echo (int)($settings['phone_device_limit'] ?? 25); ?>">
                    <p class="help-block"><?php echo _('Default: 25 registered device contacts. An extension registered on several phones uses one slot per contact. Increases require enough CPU, memory, and SLS free disk space for both the phone and desktop capacities. An audio audience larger than this limit is rejected before calls are queued. Existing calls retain their slots until they end.'); ?></p>
                </div>
                <div class="sls-capacity-preview" id="sls-capacity-preview" aria-live="polite" aria-busy="false">
                    <strong><i class="fa fa-tachometer text-primary" aria-hidden="true"></i> <?php echo _('Resources for the selected capacities'); ?></strong>
                    <p class="help-block" data-capacity-status><?php echo _('Checking combined phone and desktop requirements…'); ?></p>
                    <dl class="sls-capacity-metrics" hidden>
                        <div><dt><?php echo _('CPU required'); ?></dt><dd data-capacity-cpu></dd></div>
                        <div><dt><?php echo _('RAM required'); ?></dt><dd data-capacity-memory></dd></div>
                        <div><dt><?php echo _('Additional SLS free space'); ?></dt><dd data-capacity-storage></dd></div>
                        <div><dt><?php echo _('Temporary workspace'); ?></dt><dd data-capacity-temporary></dd></div>
                    </dl>
                    <p class="help-block" data-capacity-hardware></p><ul data-capacity-errors hidden></ul>
                    <p class="help-block"><?php echo _('Updates when phone or desktop capacity changes. Each check shows whether this PBX has sufficient resources. Saving an unsupported increase is rejected. Shared filesystems must have space for both SLS storage and temporary workspace. Existing calls, recordings and workload need additional headroom.'); ?></p>
                </div>
				<div class="row">
					<div class="col-md-6">
						<div class="form-group">
							<label><?php echo _('Public PBX Hostname'); ?></label>
							<div class="input-group"><span class="input-group-addon"><i class="fa fa-globe" aria-hidden="true"></i></span><input id="sls-advertised-host" name="advertised_pbx_host" class="form-control" maxlength="253" value="<?php echo htmlspecialchars($addressFields['host'], ENT_QUOTES, 'UTF-8'); ?>" disabled></div>
							<p class="help-block"><?php echo _('Saved by SLS for API links and hosted images. Changing DNS or the operating-system hostname does not update this address.'); ?></p>
							<label><input type="checkbox" id="sls-address-change" name="sls_address_change" value="1"> <?php echo _('Change advertised address'); ?></label>
							<div id="sls-address-editor" hidden>
								<div class="sls-address-ports">
									<div><label for="sls-advertised-api-port"><?php echo _('Desktop HTTPS port'); ?></label><input id="sls-advertised-api-port" class="form-control" type="number" name="advertised_api_port" min="1" max="65535" value="<?php echo (int)$addressFields['api_port']; ?>" disabled></div>
									<div><label for="sls-advertised-control-port"><?php echo _('Control API HTTPS port'); ?></label><input id="sls-advertised-control-port" class="form-control" type="number" name="advertised_control_port" min="1" max="65535" value="<?php echo (int)$addressFields['control_port']; ?>" disabled></div>
									<div><label for="sls-advertised-media-port"><?php echo _('Phone image port'); ?></label><input id="sls-advertised-media-port" class="form-control" type="number" name="advertised_media_port" min="1" max="65535" value="<?php echo (int)$addressFields['media_port']; ?>" disabled></div>
								</div>
								<p class="help-block"><?php echo _('Use the ports your clients connect to, including any router or proxy mapping. Submit and Apply Config save these addresses in the .config file. DNS, certificates, firewall rules, desktop connection settings, and the email sender domain are managed separately.'); ?></p>
								<div id="sls-address-preview" class="well well-sm" style="overflow-wrap:anywhere;"></div>
								<button type="button" id="sls-address-check" class="btn btn-default btn-sm"><i class="fa fa-stethoscope" aria-hidden="true"></i> <?php echo _('Check local HTTPS'); ?></button>
								<p id="sls-address-check-result" role="status" aria-live="polite" class="help-block"></p>
							</div>
						</div>
					</div>
					<div class="col-md-3">
						<div class="form-group">
							<label><?php echo _('Phone Image Transport'); ?></label>
							<select class="form-control" name="sipnotify_media_scheme">
								<option value="http" <?php echo (($settings['sipnotify']['media_scheme'] ?? 'https') === 'http') ? 'selected' : ''; ?>>HTTP</option>
								<option value="https" <?php echo (($settings['sipnotify']['media_scheme'] ?? 'https') === 'https') ? 'selected' : ''; ?>>HTTPS</option>
							</select>
							<p class="help-block"><?php echo _('If HTTPS does not work try HTTP for legacy phones.'); ?></p>
							<p class="help-block" style="overflow-wrap:anywhere;"><?php echo _('Phone image address:'); ?> <span><?php echo htmlspecialchars((string)($settings['sipnotify']['media_base_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></p>
						</div>
					</div>
					<div class="col-md-6">
						<div class="sls-manager-card">
							<h4><i class="fa fa-phone"></i> <?php echo _('Phone Format Overrides'); ?></h4>
							<div class="sls-manager-summary"><?php echo empty($formatOverrides) ? _('Automatic vendor detection is used for every extension.') : sprintf(_('%d extension override(s) configured.'), count($formatOverrides)); ?></div>
							<button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#sls-format-manager"><i class="fa fa-pencil"></i> <?php echo _('Manage Overrides'); ?></button>
						</div>
					</div>
				</div>

				<div class="modal fade sls-manager-modal" id="sls-format-manager" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
					<div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><?php echo _('Manage Phone Format Overrides'); ?></h4></div>
					<div class="modal-body"><input type="hidden" name="sipnotify_format_overrides_present" value="1"><p class="text-muted"><?php echo _('Enter the extension and select its phone family. Leave extensions without an override on automatic detection. Use Yealink text fallback only when a model cannot display generated image alerts.'); ?></p><div id="sls-format-editor-list">
					<?php foreach ($formatOverrides as $index => $override) { ?><div class="sls-editor-row" data-format-row><div class="sls-editor-grow"><label><?php echo _('Extension'); ?></label><input class="form-control" inputmode="numeric" pattern="[0-9]+" name="sipnotify_format_overrides[<?php echo (int)$index; ?>][extension]" value="<?php echo htmlspecialchars($override['extension']); ?>"></div><div class="sls-editor-format"><label><?php echo _('Phone family'); ?></label><select class="form-control" name="sipnotify_format_overrides[<?php echo (int)$index; ?>][format]"><?php foreach ($formatLabels as $formatValue => $formatLabel) { ?><option value="<?php echo htmlspecialchars($formatValue); ?>" <?php echo $override['format'] === $formatValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($formatLabel); ?></option><?php } ?></select></div><button type="button" class="btn btn-link text-danger" data-remove-format style="margin-top:20px"><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div><?php } ?>
					</div><button type="button" class="btn btn-default" id="sls-add-format"><i class="fa fa-plus"></i> <?php echo _('Add Override'); ?></button>
					<details style="margin-top:18px;"><summary><?php echo _('Individual registered devices'); ?></summary>
					<p class="text-muted" style="margin-top:10px;"><?php echo _('A device override takes precedence over the extension override. It is tied to the current registration address; if that changes, automatic detection or the extension override applies. Up to 100 bindings.'); ?></p>
					<input type="hidden" name="sipnotify_device_overrides_present" value="1">
					<div id="sls-device-override-list" style="max-height:280px;overflow:auto;"></div>
					<button type="button" class="btn btn-default btn-sm" id="sls-device-refresh"><?php echo _('Load registered devices'); ?></button>
					<select class="form-control" id="sls-device-picker" aria-label="<?php echo htmlspecialchars(_('Registered device')); ?>" style="margin:10px 0;"></select>
					<button type="button" class="btn btn-default btn-sm" id="sls-device-add"><?php echo _('Add device override'); ?></button>
					<small id="sls-device-status" role="status" style="display:block;margin-top:8px;"></small>
					</details></div>
					<div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal"><?php echo _('Done'); ?></button></div>
				</div></div></div>
				<script type="text/template" id="sls-format-row-template"><div class="sls-editor-row" data-format-row><div class="sls-editor-grow"><label><?php echo _('Extension'); ?></label><input class="form-control" inputmode="numeric" pattern="[0-9]+"></div><div class="sls-editor-format"><label><?php echo _('Phone family'); ?></label><select class="form-control"><?php foreach ($formatLabels as $formatValue => $formatLabel) { ?><option value="<?php echo htmlspecialchars($formatValue); ?>"><?php echo htmlspecialchars($formatLabel); ?></option><?php } ?></select></div><button type="button" class="btn btn-link text-danger" data-remove-format style="margin-top:20px"><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div></script>

</section>
<section class="sls-settings-section" id="sls-settings-channels" data-settings-section="channels" aria-labelledby="sls-settings-tab-channels">
				<?php $slsRecipientDirectoryManaged = true; ?>
<input type="hidden" name="recipient_directory_managed" value="1">
<?php include __DIR__ . '/outbound_voice.php'; ?>
                <?php include __DIR__ . '/announcement_sms.php'; ?>

				<h3 class="sls-settings-heading"><i class="fa fa-envelope text-warning" aria-hidden="true"></i> <?php echo _('Email and Webhooks'); ?> <?php include __DIR__ . '/labs.php'; ?></h3>
				<div class="sls-manager-card">
					<div class="row"><div class="col-md-8"><h4><i class="fa fa-paper-plane"></i> <?php echo _('Outbound Delivery'); ?></h4><div class="sls-manager-summary"><?php echo sprintf(_('Email sender %s; %d system/error recipient(s); %d enabled Discord alert destination(s); %d enabled Weather integration(s); %d enabled Dashboard announcement webhook(s).'), htmlspecialchars($mailFromAddress), count($systemNotificationEmails), count($enabledDiscordWebhooks), count($enabledGenericWebhooks), count($enabledAnnouncementWebhooks)); ?> <?php echo _('Weather and Lightning email recipients are selected within each zone or trigger area.'); ?></div></div><div class="col-md-4 text-right"><button type="button" class="btn btn-default" data-toggle="modal" data-target="#sls-notification-manager"><i class="fa fa-pencil"></i> <?php echo _('Manage Delivery'); ?></button></div></div>
				</div>
				<div class="modal fade sls-manager-modal" id="sls-notification-manager" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
					<div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="<?php echo htmlspecialchars(_('Close')); ?>"><span aria-hidden="true">&times;</span></button><h4 class="modal-title"><?php echo _('Email and Webhooks'); ?></h4></div>
					<div class="modal-body">
						<input type="hidden" name="system_notification_recipients_present" value="1">
						<input type="hidden" name="discord_webhooks_present" value="1">
						<input type="hidden" name="generic_webhooks_present" value="1">
						<input type="hidden" name="announcement_webhooks_present" value="1">
						<ul class="nav nav-tabs sls-destination-tabs" role="tablist">
							<li class="active" role="presentation"><a href="#sls-destination-email" data-toggle="tab" role="tab"><i class="fa fa-envelope"></i> <?php echo _('Email Setup'); ?> <span class="badge"><?php echo count($systemNotificationEmails); ?></span></a></li>
							<li role="presentation"><a href="#sls-destination-discord" data-toggle="tab" role="tab"><i class="fa fa-comments"></i> <?php echo _('Discord'); ?> <span class="badge"><?php echo count($discordWebhooks); ?></span></a></li>
							<li role="presentation"><a href="#sls-destination-generic" data-toggle="tab" role="tab"><i class="fa fa-exchange"></i> <?php echo _('Weather Integrations'); ?> <span class="badge"><?php echo count($genericWebhooks); ?></span></a></li>
							<li role="presentation"><a href="#sls-destination-announcement" data-toggle="tab" role="tab"><i class="fa fa-bullhorn"></i> <?php echo _('Dashboard Webhooks'); ?> <span class="badge"><?php echo count($announcementWebhooks); ?></span></a></li>
						</ul>
						<div class="tab-content">
							<section class="tab-pane active sls-destination-pane" id="sls-destination-email" role="tabpanel">
								<h4><?php echo _('Postfix Email Configuration'); ?></h4>
								<?php include __DIR__.'/postfix_help.php'; ?>
								<p class="text-muted"><?php echo _('The module hands branded HTML email with a plain-text fallback to the PBX local mail service. Final delivery depends on Postfix, DNS, and any relay configured outside this module.'); ?></p>
							<div class="row"><div class="col-sm-5"><div class="form-group"><label for="sls-mail-from-local-part"><?php echo _('Sender Local Part'); ?></label><input class="form-control" id="sls-mail-from-local-part" name="mail_from_local_part" type="text" value="<?php echo htmlspecialchars($mailFromLocalPart); ?>" maxlength="64" pattern="[A-Za-z0-9](?:[A-Za-z0-9._+\-]{0,62}[A-Za-z0-9])?" autocomplete="off" spellcheck="false" placeholder="no-reply"><p class="help-block"><?php echo _('The part before the @ sign.'); ?></p></div></div><div class="col-sm-7"><div class="form-group"><label for="sls-mail-from-domain"><?php echo _('Sender Domain'); ?></label><div class="input-group"><span class="input-group-addon">@</span><input class="form-control" id="sls-mail-from-domain" name="mail_from_domain" type="text" value="<?php echo htmlspecialchars($mailFromDomain); ?>" maxlength="253" autocomplete="off" spellcheck="false" placeholder="pbx.example.com"></div></div></div></div>
								<p class="help-block"><?php echo _('Fresh installations use the local Postfix identity when it is valid. Editing this address does not configure a relay, SPF, DKIM, DMARC, or reverse DNS.'); ?></p>
								<div class="well"><div><strong><?php echo _('Email From'); ?>:</strong> <?php echo htmlspecialchars($settings['mail_from_name'] ?? 'SLS Mass Notification System'); ?> &lt;<span id="sls-mail-from-preview"><?php echo htmlspecialchars($mailFromAddress); ?></span>&gt;</div><div><strong><?php echo _('Delivery Method'); ?>:</strong> <?php echo _('Local Postfix sendmail'); ?> <code>/usr/sbin/sendmail</code></div></div>
								<?php include __DIR__.'/announcement_email.php'; ?>
								<h4><?php echo _('System and Error Notifications'); ?></h4>
								<p class="text-muted"><?php echo _('These addresses receive module health, configuration, maintenance, and delivery-fault notices. Weather and Lightning alert recipients are configured separately on their zone or trigger area.'); ?></p>
							<div class="sls-destination-list" id="sls-email-editor-list">
								<?php if (empty($systemNotificationEmails)) { ?><div class="sls-empty-state" data-destination-empty><?php echo _('No system or error notification recipients are configured.'); ?></div><?php } ?>
								<?php foreach ($systemNotificationEmails as $email) { ?><div class="sls-editor-row" data-email-row><div class="sls-editor-grow"><input class="form-control" type="email" name="system_notification_recipients[]" value="<?php echo htmlspecialchars($email); ?>" maxlength="254" placeholder="pbx-operations@example.com"></div><button type="button" class="btn btn-link text-danger" data-remove-email><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div><?php } ?>
								</div>
								<button type="button" class="btn btn-default btn-sm" id="sls-add-email"><i class="fa fa-plus"></i> <?php echo _('Add Recipient'); ?></button>
							</section>
							<section class="tab-pane sls-destination-pane" id="sls-destination-discord" role="tabpanel">
								<h4><?php echo _('Discord Webhooks'); ?></h4><p class="text-muted"><?php echo _('Add up to 10 branded Discord destinations. Stored webhook tokens are never placed back into this page; leave the URL blank to keep the stored value.'); ?></p>
							<div class="sls-destination-list" id="sls-discord-editor-list">
							<?php if (empty($discordWebhooks)) { ?><div class="sls-empty-state" data-destination-empty><?php echo _('No Discord destinations are configured.'); ?></div><?php } ?>
								<?php foreach ($discordWebhooks as $index => $destination) { ?><div class="sls-editor-row sls-webhook-row" data-webhook-row data-webhook-type="discord"><div class="sls-webhook-enabled"><input type="hidden" name="discord_webhooks[<?php echo (int)$index; ?>][enabled]" value="0"><label><input type="checkbox" name="discord_webhooks[<?php echo (int)$index; ?>][enabled]" value="1" <?php echo !empty($destination['enabled']) ? 'checked' : ''; ?>> <?php echo _('Enabled'); ?></label><input type="hidden" name="discord_webhooks[<?php echo (int)$index; ?>][id]" value="<?php echo htmlspecialchars($destination['id'] ?? ''); ?>"></div><div><label><?php echo _('Name'); ?></label><input class="form-control" name="discord_webhooks[<?php echo (int)$index; ?>][name]" value="<?php echo htmlspecialchars($destination['name'] ?? ''); ?>" maxlength="80"></div><div><label><?php echo _('Webhook URL'); ?></label><input class="form-control" type="password" name="discord_webhooks[<?php echo (int)$index; ?>][url]" value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(_('Stored; enter a new URL to replace')); ?>"><div class="sls-stored-secret"><i class="fa fa-lock"></i> <?php echo _('Stored in the protected central configuration'); ?></div></div><button type="button" class="btn btn-link text-danger" data-remove-webhook><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div><?php } ?>
									</div><button type="button" class="btn btn-default btn-sm" data-add-webhook="discord"><i class="fa fa-plus"></i> <?php echo _('Add Discord Destination'); ?></button><span class="text-muted sls-destination-limit" data-webhook-limit="discord" aria-live="polite" hidden><?php echo _('10-destination limit reached.'); ?></span>
							</section>
							<section class="tab-pane sls-destination-pane" id="sls-destination-generic" role="tabpanel">
								<h4><?php echo _('Weather and Lightning Integrations'); ?></h4><p class="text-muted"><?php echo _('Add up to 10 destinations for Weather and Lightning alerts, then select them within each zone or trigger area. Choose SLS event JSON, Slack incoming webhook, or Microsoft Teams Workflows. HTTPS on port 443 is required; private addresses, redirects, and invalid TLS certificates are blocked.'); ?></p>
							<div class="sls-destination-list" id="sls-generic-editor-list">
							<?php if (empty($genericWebhooks)) { ?><div class="sls-empty-state" data-destination-empty><?php echo _('No Weather or Lightning integrations are configured.'); ?></div><?php } ?>
								<?php foreach ($genericWebhooks as $index => $destination) { ?><div class="sls-editor-row sls-webhook-row" data-webhook-row data-webhook-type="generic"><div class="sls-webhook-enabled"><input type="hidden" name="generic_webhooks[<?php echo (int)$index; ?>][enabled]" value="0"><label><input type="checkbox" name="generic_webhooks[<?php echo (int)$index; ?>][enabled]" value="1" <?php echo !empty($destination['enabled']) ? 'checked' : ''; ?>> <?php echo _('Enabled'); ?></label><input type="hidden" name="generic_webhooks[<?php echo (int)$index; ?>][id]" value="<?php echo htmlspecialchars($destination['id'] ?? ''); ?>"></div><div><label><?php echo _('Name'); ?></label><input class="form-control" name="generic_webhooks[<?php echo (int)$index; ?>][name]" value="<?php echo htmlspecialchars($destination['name'] ?? ''); ?>" maxlength="80"><?php $renderWebhookFormat('generic', 'generic_webhooks[' . (int)$index . '][payload_format]', $destination['payload_format'] ?? 'native'); ?></div><div><label><?php echo _('HTTPS URL'); ?></label><input class="form-control" type="password" name="generic_webhooks[<?php echo (int)$index; ?>][url]" value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(_('Stored; enter a new URL to replace')); ?>"><div class="sls-stored-secret"><i class="fa fa-lock"></i> <?php echo _('Stored in the protected central configuration'); ?></div></div><button type="button" class="btn btn-link text-danger" data-remove-webhook><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div><?php } ?>
									</div><button type="button" class="btn btn-default btn-sm" data-add-webhook="generic"><i class="fa fa-plus"></i> <?php echo _('Add Integration'); ?></button><span class="text-muted sls-destination-limit" data-webhook-limit="generic" aria-live="polite" hidden><?php echo _('10-destination limit reached.'); ?></span>
							</section>
							<section class="tab-pane sls-destination-pane" id="sls-destination-announcement" role="tabpanel">
								<h4><?php echo _('Dashboard Announcement Webhooks'); ?></h4>
								<p class="text-muted"><?php echo _('Add up to 10 named Discord-compatible, Slack incoming webhook, or Microsoft Teams Workflows destinations. Select them as individual targets in the Dashboard announcement panel.'); ?></p>
								<div class="sls-destination-list" id="sls-announcement-editor-list">
								<?php if (empty($announcementWebhooks)) { ?><div class="sls-empty-state" data-destination-empty><?php echo _('No Dashboard announcement webhooks are configured.'); ?></div><?php } ?>
								<?php foreach ($announcementWebhooks as $index => $destination) { ?><div class="sls-editor-row sls-webhook-row" data-webhook-row data-webhook-type="announcement"><div class="sls-webhook-enabled"><input type="hidden" name="announcement_webhooks[<?php echo (int)$index; ?>][enabled]" value="0"><label><input type="checkbox" name="announcement_webhooks[<?php echo (int)$index; ?>][enabled]" value="1" <?php echo !empty($destination['enabled']) ? 'checked' : ''; ?>> <?php echo _('Enabled'); ?></label><input type="hidden" name="announcement_webhooks[<?php echo (int)$index; ?>][id]" value="<?php echo htmlspecialchars($destination['id'] ?? ''); ?>"></div><div><label><?php echo _('Name'); ?></label><input class="form-control" name="announcement_webhooks[<?php echo (int)$index; ?>][name]" value="<?php echo htmlspecialchars($destination['name'] ?? ''); ?>" maxlength="80"><?php $renderWebhookFormat('announcement', 'announcement_webhooks[' . (int)$index . '][payload_format]', $destination['payload_format'] ?? 'native'); ?></div><div><label><?php echo _('HTTPS Webhook URL'); ?></label><div class="input-group"><input class="form-control" type="password" name="announcement_webhooks[<?php echo (int)$index; ?>][url]" value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(_('Stored; enter a new URL to replace')); ?>"><span class="input-group-btn"><button type="button" class="btn btn-default" data-toggle-secret title="<?php echo htmlspecialchars(_('Show or hide the URL being entered')); ?>" aria-label="<?php echo htmlspecialchars(_('Show or hide the URL being entered')); ?>"><i class="fa fa-eye" aria-hidden="true"></i></button></span></div><div class="sls-stored-secret"><i class="fa fa-lock"></i> <?php echo _('Stored in the protected central configuration; the saved token is never returned to the page.'); ?></div></div><button type="button" class="btn btn-link text-danger" data-remove-webhook><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div><?php } ?>
								</div><button type="button" class="btn btn-default btn-sm" data-add-webhook="announcement"><i class="fa fa-plus"></i> <?php echo _('Add Dashboard Webhook'); ?></button><span class="text-muted sls-destination-limit" data-webhook-limit="announcement" aria-live="polite" hidden><?php echo _('10-destination limit reached.'); ?></span>
							</section>
						</div>
						<details class="sls-integration-help"><summary><?php echo _('Slack and Teams setup'); ?> <?php include __DIR__ . '/labs.php'; ?></summary>
							<p><?php echo _('Slack: create an incoming webhook for the desired channel. Teams: create a Workflows webhook that posts Adaptive Cards to the desired channel or chat, with the trigger authentication set to Anyone. Paste the generated secret URL and choose the matching integration above. Tenant-authenticated Teams triggers and retired Office 365 connector URLs are not supported.'); ?></p>
							<p><?php echo _('The provider or workflow chooses the channel. Keep the URL secret and assign a workflow co-owner so delivery does not depend on one user account. Accepted means the webhook accepted the request; it does not confirm that people received or read the message. Messages that exceed the provider size limit fail without shortening the alert.'); ?></p>
							<p><?php echo _('Incident messages include an Open incident in SLS link when the configured HTTPS Control API address matches the advertised PBX hostname. The link preserves the configured port and opens the incident in FreePBX; sign-in and module access are required. Opening it does not acknowledge an alert. Ordinary announcements and weather messages do not acquire an incident link from their wording.'); ?></p>
							<p><a href="https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/" target="_blank" rel="noopener noreferrer"><?php echo _('Slack setup guide'); ?></a> · <a href="https://support.microsoft.com/en-us/workflows/send-messages-in-teams-using-incoming-webhooks" target="_blank" rel="noopener noreferrer"><?php echo _('Teams Workflows setup guide'); ?></a></p>
						</details>
						<p class="text-muted sls-destination-note"><i class="fa fa-info-circle"></i> <?php echo _('Close this window, then use Save General Settings to stage the changes.'); ?></p>
					</div>
					<div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal"><?php echo _('Done Editing'); ?></button></div>
				</div></div></div>
				<script type="text/template" id="sls-email-row-template"><div class="sls-editor-row" data-email-row><div class="sls-editor-grow"><input class="form-control" type="email" placeholder="pbx-operations@example.com"></div><button type="button" class="btn btn-link text-danger" data-remove-email><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div></script>
				<script type="text/template" id="sls-discord-row-template"><div class="sls-editor-row sls-webhook-row" data-webhook-row data-webhook-type="discord"><div class="sls-webhook-enabled"><input type="hidden" data-field="enabled-hidden" value="0"><label><input type="checkbox" data-field="enabled" value="1" checked> <?php echo _('Enabled'); ?></label><input type="hidden" data-field="id" value=""></div><div><label><?php echo _('Name'); ?></label><input class="form-control" data-field="name" maxlength="80" placeholder="Operations"></div><div><label><?php echo _('Webhook URL'); ?></label><input class="form-control" type="password" data-field="url" autocomplete="new-password" placeholder="https://discord.com/api/webhooks/..."></div><button type="button" class="btn btn-link text-danger" data-remove-webhook><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div></script>
				<script type="text/template" id="sls-generic-row-template"><div class="sls-editor-row sls-webhook-row" data-webhook-row data-webhook-type="generic"><div class="sls-webhook-enabled"><input type="hidden" data-field="enabled-hidden" value="0"><label><input type="checkbox" data-field="enabled" value="1" checked> <?php echo _('Enabled'); ?></label><input type="hidden" data-field="id" value=""></div><div><label><?php echo _('Name'); ?></label><input class="form-control" data-field="name" maxlength="80" placeholder="Incident Platform"><?php $renderWebhookFormat('generic', ''); ?></div><div><label><?php echo _('HTTPS URL'); ?></label><input class="form-control" type="password" data-field="url" autocomplete="new-password" placeholder="https://alerts.example.com/hooks/sls"></div><button type="button" class="btn btn-link text-danger" data-remove-webhook><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div></script>
				<script type="text/template" id="sls-announcement-row-template"><div class="sls-editor-row sls-webhook-row" data-webhook-row data-webhook-type="announcement"><div class="sls-webhook-enabled"><input type="hidden" data-field="enabled-hidden" value="0"><label><input type="checkbox" data-field="enabled" value="1" checked> <?php echo _('Enabled'); ?></label><input type="hidden" data-field="id" value=""></div><div><label><?php echo _('Name'); ?></label><input class="form-control" data-field="name" maxlength="80" placeholder="Announcements"><?php $renderWebhookFormat('announcement', ''); ?></div><div><label><?php echo _('HTTPS Webhook URL'); ?></label><div class="input-group"><input class="form-control" type="password" data-field="url" autocomplete="new-password" placeholder="https://discord.com/api/webhooks/..."><span class="input-group-btn"><button type="button" class="btn btn-default" data-toggle-secret title="<?php echo htmlspecialchars(_('Show or hide the URL being entered')); ?>" aria-label="<?php echo htmlspecialchars(_('Show or hide the URL being entered')); ?>"><i class="fa fa-eye" aria-hidden="true"></i></button></span></div></div><button type="button" class="btn btn-link text-danger" data-remove-webhook><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div></script>

</section>
<section class="sls-settings-section" id="sls-settings-audio" data-settings-section="audio" aria-labelledby="sls-settings-tab-audio">
				<h3 class="sls-settings-heading"><i class="fa fa-volume-up text-success" aria-hidden="true"></i> <?php echo _('Regular Paging Audio'); ?></h3>
				<div class="alert alert-info"><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo _('These defaults apply only to dashboard and API announcements. Weather Alerts and Lightning Alerts keep their own independent sounds and volume settings.'); ?></div>
				<div class="row">
					<div class="col-md-4">
						<div class="form-group">
							<label><?php echo _('Paging Opening Tone'); ?></label>
							<select class="form-control" name="opening_tone">
								<option value="" <?php echo ($settings['opening_tone'] ?? '') === '' ? 'selected' : ''; ?>><?php echo _('None'); ?></option>
								<optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>">
								<?php foreach ($tones as $tone) { ?>
									<option value="<?php echo htmlspecialchars($tone); ?>" <?php echo ($settings['opening_tone'] ?? 'opening_Paging_Tone_Opening') === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars($tone === 'opening_Paging_Tone_Opening' ? _('Paging Tone Opening (bundled default)') : str_replace('_', ' ', $tone)); ?></option>
								<?php } ?>
								</optgroup>
								<?php if ($systemSounds) { ?><optgroup label="<?php echo htmlspecialchars(_('FreePBX System Recordings')); ?>">
								<?php foreach ($systemSounds as $sound) { ?><option value="<?php echo htmlspecialchars($sound['value']); ?>"><?php echo htmlspecialchars($sound['label']); ?></option><?php } ?>
								</optgroup><?php } ?>
							</select>
							<p class="help-block"><?php echo _('Upload additional choices in Admin > System Recordings.'); ?></p>
						</div>
					</div>
					<div class="col-md-4">
						<div class="form-group">
							<label><?php echo _('Paging Closing Tone'); ?></label>
							<select class="form-control" name="closing_tone">
								<option value="" <?php echo ($settings['closing_tone'] ?? '') === '' ? 'selected' : ''; ?>><?php echo _('None'); ?></option>
								<optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>">
								<?php foreach ($tones as $tone) { ?>
									<option value="<?php echo htmlspecialchars($tone); ?>" <?php echo ($settings['closing_tone'] ?? 'closing_Paging_Tone_Closing') === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars($tone === 'closing_Paging_Tone_Closing' ? _('Paging Tone Closing (bundled default)') : str_replace('_', ' ', $tone)); ?></option>
								<?php } ?>
								</optgroup>
								<?php if ($systemSounds) { ?><optgroup label="<?php echo htmlspecialchars(_('FreePBX System Recordings')); ?>">
								<?php foreach ($systemSounds as $sound) { ?><option value="<?php echo htmlspecialchars($sound['value']); ?>"><?php echo htmlspecialchars($sound['label']); ?></option><?php } ?>
								</optgroup><?php } ?>
							</select>
							<p class="help-block"><?php echo _('System recordings are converted into a managed Asterisk tone when saved.'); ?></p>
						</div>
					</div>
					<div class="col-md-4">
						<div class="form-group">
							<label><?php echo _('Announcement Cooldown Seconds'); ?></label>
							<input class="form-control" name="announcement_cooldown_seconds" type="number" min="5" max="600" value="<?php echo (int)($settings['announcement_cooldown_seconds'] ?? 60); ?>">
							<p class="help-block"><?php echo _('Default is 60 seconds. Allowed range is 5 to 600 seconds.'); ?></p>
						</div>
					</div>
				</div>

				<div class="row">
					<div class="col-md-6">
						<div class="form-group">
							<label><?php echo _('Announcement TTS Voice'); ?></label>
							<select class="form-control" name="announcement_piper_voice">
								<?php foreach ($voices as $voice) { ?>
									<option value="<?php echo htmlspecialchars($voice['path']); ?>" <?php echo ($settings['announcement_piper_voice'] ?? '') === $voice['path'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($voice['name'] . (basename($voice['path']) === 'en_US-lessac-low.onnx' ? ' (' . _('default') . ')' : '')); ?></option>
								<?php } ?>
							</select>
							<p class="help-block"><?php echo _('Lessac is the default regular-announcement voice. Weather and Lightning use the separate Weather voice, which defaults to Amy.'); ?></p>
						</div>
					</div>
					<div class="col-md-3">
						<div class="form-group">
							<label><?php echo _('General Paging Volume'); ?></label>
							<input class="form-control" name="announcement_tts_volume" type="number" min="1" max="200" value="<?php echo (int)($settings['announcement_tts_volume'] ?? 25); ?>">
							<p class="help-block"><?php echo _('Default 25%. Applies to the regular paging tones and generated announcement speech.'); ?></p>
						</div>
					</div>
					<div class="col-md-3">
						<div class="form-group">
							<label><?php echo _('Maximum Spoken Length'); ?></label>
							<div class="input-group"><input class="form-control" name="tts_max_seconds" type="number" min="1" max="600" value="<?php echo (int)($settings['tts_max_seconds'] ?? 30); ?>"><span class="input-group-addon"><?php echo _('sec'); ?></span></div>
							<p class="help-block"><?php echo _('Default 30; maximum 600 seconds.'); ?></p>
						</div>
					</div>
				</div>
                <div class="form-group">
                    <label for="sls-pronunciation"><i class="fa fa-commenting-o text-primary" aria-hidden="true"></i> <?php echo _('Announcement pronunciation'); ?></label>
                    <textarea class="form-control" id="sls-pronunciation" name="announcement_pronunciation_text" rows="4" maxlength="40000" aria-describedby="sls-pronunciation-help" placeholder="<?php echo htmlspecialchars(_('Example: PBX = P B X')); ?>"><?php echo htmlspecialchars(implode("\n", array_map(static function ($row) { return $row['phrase'].' = '.$row['spoken']; }, $settings['announcement_pronunciation'] ?? [])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                    <p id="sls-pronunciation-help" class="help-block"><?php echo _('Optional: one phrase = spoken replacement per line, up to 50. Matches whole phrases without regard to capitalization; the longest match wins. Replacements affect announcement speech only. Save, then use Listen on the dashboard to review the exact spoken text and audio.'); ?></p>
                </div>
				<div class="row">
					<div class="col-md-6">
						<div class="form-group">
							<label for="sls-announcement-timeout-mode"><i class="fa fa-hourglass-half text-primary" aria-hidden="true"></i> <?php echo _('Announcement Timeout'); ?></label>
							<select class="form-control" id="sls-announcement-timeout-mode" name="announcement_timeout_mode">
								<option value="none" <?php echo ($settings['announcement_timeout_mode'] ?? 'none') === 'none' ? 'selected' : ''; ?>><?php echo _('No expiry (default)'); ?></option>
								<option value="audio" <?php echo ($settings['announcement_timeout_mode'] ?? 'none') === 'audio' ? 'selected' : ''; ?>><?php echo _('Duration of Alert'); ?></option>
								<option value="custom" <?php echo ($settings['announcement_timeout_mode'] ?? 'none') === 'custom' ? 'selected' : ''; ?>><?php echo _('Specified timeout'); ?></option>
							</select>
							<p class="help-block"><?php echo _('Controls how long regular dashboard, API, and scheduled announcement screens remain visible. Duration of Alert follows the generated page audio; visual-only announcements remain until dismissed. Support outside Yealink and the desktop app depends on the receiving device.'); ?></p>
						</div>
					</div>
					<div class="col-md-3" id="sls-announcement-timeout-custom">
						<div class="form-group">
							<label for="sls-announcement-timeout-seconds"><?php echo _('Timeout Length'); ?></label>
							<div class="input-group"><input class="form-control" id="sls-announcement-timeout-seconds" name="announcement_timeout_seconds" type="number" min="1" max="86400" value="<?php echo (int)($settings['announcement_timeout_seconds'] ?? 300); ?>"><span class="input-group-addon"><?php echo _('sec'); ?></span></div>
							<p class="help-block"><?php echo _('Used only for a specified timeout. Allowed range: 1 second to 24 hours.'); ?></p>
						</div>
					</div>
				</div>

</section>
<section class="sls-settings-section" id="sls-settings-desktops" data-settings-section="desktops" aria-labelledby="sls-settings-tab-desktops">
				<h3 class="sls-settings-heading"><i class="fa fa-desktop text-info" aria-hidden="true"></i> <?php echo _('Desktop Clients'); ?> <?php include __DIR__ . '/labs.php'; ?></h3>
				<div class="form-group" style="max-width:420px">
					<label for="sls-desktop-limit"><?php echo _('Desktop Capacity'); ?></label>
					<input class="form-control" id="sls-desktop-limit" name="desktop_client_limit" type="number" min="1" max="1000" value="<?php echo (int)($settings['desktop_client_limit'] ?? 25); ?>">
					<p class="help-block"><?php echo _('Default: 25. Increasing this limit requires a successful CPU, memory, and disk check. Capacity above 32 simultaneous streams requires desktop apps that support cursor-based JSON fallback. Verify delivery under your expected load before deployment.'); ?></p>
				</div>
				<input type="hidden" name="desktop_clients_present" value="1">
				<div class="form-group" style="max-width:420px">
					<label for="sls-desktop-minimum-version"><?php echo _('Minimum desktop app version'); ?></label>
					<input class="form-control" id="sls-desktop-minimum-version" name="desktop_minimum_version" type="text" maxlength="40" placeholder="1.10.0" value="<?php echo htmlspecialchars($settings['desktop_minimum_version'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
					<p class="help-block"><?php echo _('Optional. Help & Diagnostics flags reporting apps below this version. This advisory policy does not block delivery. Older apps that do not report their version remain marked Not reported.'); ?></p>
				</div>
				<input type="hidden" name="desktop_clients_json" id="sls-desktop-clients-json" value="">
					<p class="help-block"><?php echo _('Each desktop app should use its own username and password against /api/sipnotify/desktop. Client IDs are generated automatically and cannot be edited. Passwords are AES-encrypted in the central config file.'); ?></p>
				<div class="table-responsive sls-desktop-client-scroll" id="desktop-client-scroll" aria-label="<?php echo htmlspecialchars(_('Desktop client list')); ?>">
					<table class="table table-striped table-bordered" id="desktop-client-table">
						<thead>
								<tr>
									<th><?php echo _('Enabled'); ?></th>
									<th><?php echo _('Desktop Name'); ?></th>
									<th><?php echo _('Client ID'); ?></th>
									<th><?php echo _('Username'); ?></th>
									<th><?php echo _('Password'); ?></th>
									<th><?php echo _('Action'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($desktopClients as $index => $client) { ?>
								<tr data-desktop-client-row>
									<td>
										<input type="hidden" name="desktop_clients[<?php echo (int)$index; ?>][id]" value="<?php echo htmlspecialchars($client['id'] ?? ''); ?>">
										<input type="hidden" name="desktop_clients[<?php echo (int)$index; ?>][password_enc]" value="<?php echo htmlspecialchars($client['password_enc'] ?? ''); ?>">
										<input type="checkbox" name="desktop_clients[<?php echo (int)$index; ?>][enabled]" value="1" <?php echo !empty($client['enabled']) ? 'checked' : ''; ?>>
										</td>
										<td><input class="form-control input-sm" name="desktop_clients[<?php echo (int)$index; ?>][name]" value="<?php echo htmlspecialchars($client['name'] ?? 'Desktop App'); ?>"></td>
										<td>
											<input type="hidden" name="desktop_clients[<?php echo (int)$index; ?>][client_id]" value="<?php echo htmlspecialchars($client['client_id'] ?? ''); ?>">
											<code><?php echo htmlspecialchars($client['client_id'] ?? _('Generated on save')); ?></code>
										</td>
										<td><input class="form-control input-sm" name="desktop_clients[<?php echo (int)$index; ?>][username]" value="<?php echo htmlspecialchars($client['username'] ?? ''); ?>"></td>
										<td><div class="input-group input-group-sm"><input class="form-control" name="desktop_clients[<?php echo (int)$index; ?>][password]" type="password" value="<?php echo htmlspecialchars($client['password'] ?? ''); ?>" autocomplete="new-password"><span class="input-group-btn"><button type="button" class="btn btn-default" data-toggle-secret title="<?php echo htmlspecialchars(_('Show or hide password')); ?>" aria-label="<?php echo htmlspecialchars(_('Show or hide password')); ?>"><i class="fa fa-eye" aria-hidden="true"></i></button></span></div></td>
									<td><button type="button" class="btn btn-default btn-sm" data-remove-desktop-client><?php echo _('Delete'); ?></button></td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
				<button type="button" class="btn btn-default" id="add-desktop-client"><?php echo _('Add Desktop Client'); ?></button>
				<input type="hidden" name="desktop_clients_complete" value="1">
				<p id="sls-desktop-capacity-error" class="text-danger" role="status" aria-live="polite"></p>
				<div class="well" style="margin-top: 12px;">
					<strong><?php echo _('Desktop Endpoint'); ?></strong>
					<div><code><?php echo htmlspecialchars(($settings['sipnotify']['base_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/sipnotify')) . '/desktop'); ?></code></div>
						<p class="help-block" style="margin-bottom: 0;"><?php echo _('Use HTTP Basic authentication with the desktop client username and password. Legacy Bearer token access is not accepted for desktop clients.'); ?></p>
				</div>

</section>
<section class="sls-settings-section" id="sls-settings-security" data-settings-section="security" aria-labelledby="sls-settings-tab-security">
				<div class="panel panel-warning" style="border-width: 2px;">
					<div class="panel-heading"><strong><i class="fa fa-code text-warning" aria-hidden="true"></i> <?php echo _('Control API'); ?></strong><?php include __DIR__ . '/labs.php'; ?></div>
					<div class="panel-body">
						<p class="text-warning"><?php echo _('Remote management can send announcements, trigger NWS tests, read status/logs, and update normalized Mass Notifications config. Keep this disabled unless a trusted remote controller needs it.'); ?></p>
						<div class="row">
							<div class="col-md-3">
								<div class="form-group">
									<label><?php echo _('Enabled'); ?></label>
									<select class="form-control" name="control_api[enabled]">
										<option value="0" <?php echo empty($control['enabled']) ? 'selected' : ''; ?>><?php echo _('No'); ?></option>
										<option value="1" <?php echo !empty($control['enabled']) ? 'selected' : ''; ?>><?php echo _('Yes'); ?></option>
									</select>
								</div>
							</div>
							<div class="col-md-9">
								<div class="form-group">
									<label><?php echo _('Endpoint'); ?></label>
									<input class="form-control" type="text" readonly value="<?php echo htmlspecialchars($control_api_url ?? ''); ?>">
								</div>
							</div>
						</div>
						<div class="form-group">
							<label><?php echo _('API Key'); ?></label>
							<div class="input-group">
								<input class="form-control" id="control_api_key" name="control_api[api_key]" type="password" value="<?php echo htmlspecialchars($control['api_key'] ?? ''); ?>" autocomplete="off">
								<span class="input-group-btn">
									<button type="button" class="btn btn-default" data-toggle-secret title="<?php echo htmlspecialchars(_('Show or hide API key')); ?>" aria-label="<?php echo htmlspecialchars(_('Show or hide API key')); ?>"><i class="fa fa-eye" aria-hidden="true"></i></button>
									<button type="button" class="btn btn-default" id="copy_control_api_key"><?php echo _('Copy'); ?></button>
									<button type="submit" class="btn btn-warning" name="slsmassnotifyserver_action" value="regenerate_control_api_key" onclick="return confirm(<?php echo htmlspecialchars(json_encode(_('Regenerate the Control API key? Existing API clients using the old key will stop working after you apply changes.')), ENT_QUOTES, 'UTF-8'); ?>);"><?php echo _('Regenerate'); ?></button>
								</span>
							</div>
						</div>
						<div class="row">
							<div class="col-md-3">
								<div class="form-group">
									<label><?php echo _('IP Allowlist'); ?></label>
									<select class="form-control" name="control_api[ip_allowlist_enabled]">
										<option value="0" <?php echo empty($control['ip_allowlist_enabled']) ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option>
										<option value="1" <?php echo !empty($control['ip_allowlist_enabled']) ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option>
									</select>
								</div>
							</div>
							<div class="col-md-9">
								<div class="form-group">
									<label><?php echo _('Allowed IPs/CIDRs'); ?></label>
									<textarea class="form-control" name="control_api[ip_allowlist]" rows="3" placeholder="198.51.100.10&#10;203.0.113.0/24"><?php echo htmlspecialchars($control['ip_allowlist'] ?? ''); ?></textarea>
									<p class="help-block"><?php echo _('Optional. One IPv4/IPv6 address or IPv4 CIDR per line. Leave disabled to allow any source with the API key.'); ?></p>
								</div>
							</div>
						</div>
						<div class="row">
							<div class="col-md-3">
								<div class="form-group">
									<label><?php echo _('Rate Limit'); ?></label>
									<select class="form-control" name="control_api[rate_limit_enabled]">
										<option value="0" <?php echo empty($control['rate_limit_enabled']) ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option>
										<option value="1" <?php echo !empty($control['rate_limit_enabled']) ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option>
									</select>
								</div>
							</div>
							<div class="col-md-3">
								<div class="form-group">
									<label><?php echo _('Requests Per Minute'); ?></label>
									<input class="form-control" name="control_api[rate_limit_per_minute]" type="number" min="1" max="600" value="<?php echo (int)($control['rate_limit_per_minute'] ?? 60); ?>">
								</div>
							</div>
							<div class="col-md-6">
								<p class="help-block" style="margin-top: 25px;"><?php echo _('Control API use is audited by source IP, action and credential identity. Local retention keeps up to 10,000 records for up to 30 days. Secrets and message contents are excluded.'); ?></p>
							</div>
						</div>
					</div>
				</div>

				<div class="panel panel-default">
					<div class="panel-heading"><strong><i class="fa fa-history" aria-hidden="true"></i> <?php echo _('Audit forwarding'); ?></strong></div>
					<div class="panel-body">
						<div class="row">
							<div class="col-md-4"><div class="form-group">
								<label for="sls-audit-syslog"><?php echo _('Send API audit events to the system logger'); ?></label>
								<select id="sls-audit-syslog" class="form-control" name="control_api[audit_syslog]" aria-describedby="sls-audit-syslog-help">
									<option value="0" <?php echo ($control['audit_syslog'] ?? '0') !== '1' ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option>
									<option value="1" <?php echo ($control['audit_syslog'] ?? '0') === '1' ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option>
								</select>
							</div></div>
							<div class="col-md-8"><p id="sls-audit-syslog-help" class="help-block"><?php echo _('Use an existing system logging agent to forward these events to a separate collector over authenticated TLS. SLS sends bounded local events tagged sls-mass-notify (local5.info), with unique event IDs. Source IPs and credential identities are included. Local acceptance does not confirm remote receipt; verify the collector before relying on it.'); ?></p></div>
						</div>
						<p class="help-block"><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo _('This setting does not install or reconfigure a logging service. Apply Config to activate it. Deployment readiness reports local forwarding failures; local audit history is retained independently.'); ?></p>
					</div>
				</div>
				<?php include __DIR__ . '/api_credentials.php'; ?>
				<div class="panel panel-default" style="margin-top:20px">
					<div class="panel-heading"><strong><i class="fa fa-shield" aria-hidden="true"></i> <?php echo _('API reverse proxies'); ?></strong></div>
					<div class="panel-body">
						<label for="sls-trusted-proxies"><?php echo _('Trusted proxy networks'); ?></label>
						<textarea id="sls-trusted-proxies" class="form-control" name="trusted_proxy_cidrs" rows="3" placeholder="192.0.2.10/32&#10;2001:db8::10/128" aria-describedby="sls-trusted-proxy-help"><?php echo htmlspecialchars(implode("\n", $settings['api_network']['trusted_proxy_cidrs'] ?? []), ENT_QUOTES, 'UTF-8'); ?></textarea>
						<p id="sls-trusted-proxy-help" class="help-block"><?php echo _('Leave empty for a direct connection. Add only the addresses of proxies you administer, one IPv4 or IPv6 CIDR per line. Use individual hosts or narrow networks. Each trusted proxy must overwrite X-Forwarded-For and X-Forwarded-Proto. Missing or invalid forwarded information is rejected; forwarded hostnames are never trusted. Submit and Apply Config to activate changes.'); ?></p>
					</div>
				</div>
</section>
<section class="sls-settings-section" id="sls-settings-maintenance" data-settings-section="maintenance" aria-labelledby="sls-settings-tab-maintenance">
				<h3 class="sls-settings-heading"><i class="fa fa-history text-muted" aria-hidden="true"></i> <?php echo _('Updates and Retention'); ?></h3>
				<div class="panel panel-default">
					<div class="panel-heading"><strong><i class="fa fa-shield" aria-hidden="true"></i> <?php echo _('Generated image and phone XML access'); ?></strong></div>
					<div class="panel-body">
						<?php $mediaAccess = array_replace(['network_restricted'=>false, 'allowed_cidrs'=>[], 'max_age_minutes'=>0], $settings['media_access'] ?? []); ?>
						<p class="help-block"><?php echo _('Images and phone XML use unguessable URLs without a desktop password or browser session. Optional restrictions below apply to every new download, including existing URLs. They do not retract content already downloaded. Defaults preserve existing device access.'); ?></p>
						<input type="hidden" name="media_access[network_restricted]" value="0">
						<div class="checkbox"><label><input type="checkbox" name="media_access[network_restricted]" value="1" <?php echo $mediaAccess['network_restricted'] ? 'checked' : ''; ?>> <?php echo _('Allow generated media only from listed networks'); ?></label></div>
						<div class="row">
							<div class="col-sm-7"><label for="sls-media-networks"><?php echo _('Allowed device networks'); ?></label>
								<textarea id="sls-media-networks" class="form-control" name="media_access[allowed_cidrs]" rows="3" maxlength="4096" placeholder="192.0.2.0/24&#10;2001:db8:1234::/48" aria-describedby="sls-media-networks-help"><?php echo htmlspecialchars(implode("\n", $mediaAccess['allowed_cidrs']), ENT_QUOTES, 'UTF-8'); ?></textarea>
								<p id="sls-media-networks-help" class="help-block"><?php echo _('Up to 32 IPv4/IPv6 addresses or CIDRs. Include every phone network and the public NAT address used by remote desktops. Forwarded addresses are accepted only from the trusted proxies configured above. A /0 network is not allowed.'); ?></p>
							</div>
							<div class="col-sm-5"><label for="sls-media-expiry"><?php echo _('Download expiry after file creation'); ?></label>
								<div class="input-group"><input id="sls-media-expiry" class="form-control" type="number" name="media_access[max_age_minutes]" min="0" max="1440" step="1" required value="<?php echo (int)$mediaAccess['max_age_minutes']; ?>" aria-describedby="sls-media-expiry-help"><span class="input-group-addon"><?php echo _('minutes'); ?></span></div>
								<p id="sls-media-expiry-help" class="help-block"><?php echo _('0 disables expiry. Otherwise choose 10–1440 minutes. Repeated downloads never extend this age. An older active weather image can expire too; allow enough time for your phone displays. Delivery history is retained.'); ?></p>
							</div>
						</div>
						<p class="text-muted"><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo _('Save and Apply Config activates this policy without a firewall change. Desktop Details retains message text when an image is unavailable. Some phone formats need the image or XML download to display the alert.'); ?></p>
					</div>
				</div>
				<div class="panel panel-default">
					<div class="panel-heading"><strong><i class="fa fa-hdd-o" aria-hidden="true"></i> <?php echo _('Generated media storage'); ?></strong></div>
					<div class="panel-body"><div class="row">
						<div class="col-sm-4"><label for="sls-media-cache"><?php echo _('Cache target'); ?></label>
							<div class="input-group"><input id="sls-media-cache" class="form-control" name="generated_media_cache_mib" type="number" min="64" max="4096" step="1" required aria-describedby="sls-media-cache-help" value="<?php echo (int)($settings['generated_media_cache_mib'] ?? 512); ?>"><span class="input-group-addon">MiB</span></div>
						</div>
						<div class="col-sm-8"><p id="sls-media-cache-help" class="help-block"><?php echo _('Default: 512 MiB for generated speech, images and phone XML combined. Maintenance removes the oldest unused files when the target is exceeded. Active alerts, saved references and files created within 15 minutes remain protected. This target is not a disk reservation or a limit on active announcements; excess protected usage produces a storage warning.'); ?></p></div>
					</div></div>
				</div>
				<?php if (($packageStatus['state'] ?? '') === 'update') { ?>
					<div class="alert alert-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <strong><?php echo htmlspecialchars($packageStatus['label'] ?? _('Update available')); ?></strong><?php if (!empty($packageStatus['message'])) { ?> <?php echo htmlspecialchars($packageStatus['message']); ?><?php } ?></div>
				<?php } ?>
				<div class="row">
					<div class="col-md-3">
						<label><?php echo _('Notification Log Retention Days'); ?></label>
						<input class="form-control" name="log_retention_days" type="number" min="1" max="365" value="<?php echo (int)($settings['log_retention_days'] ?? 90); ?>">
					</div>
					<div class="col-md-3">
						<label><?php echo _('Automatic GitHub Updates'); ?></label>
						<select class="form-control" name="updates[github_enabled]">
							<option value="0" <?php echo empty($updates['github_enabled']) ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option>
							<option value="1" <?php echo !empty($updates['github_enabled']) ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option>
						</select>
					</div>
					<div class="col-md-3">
						<label for="sls-update-channel"><?php echo _('Update Channel'); ?></label>
						<select id="sls-update-channel" class="form-control" name="updates[channel]">
							<option value="beta" <?php echo ($updates['channel'] ?? 'beta') === 'beta' ? 'selected' : ''; ?>><?php echo _('Beta and stable releases'); ?></option>
							<option value="stable" <?php echo ($updates['channel'] ?? 'beta') === 'stable' ? 'selected' : ''; ?>><?php echo _('Stable releases only'); ?></option>
						</select>
						<p class="help-block"><?php echo _('Every installation requires a valid publisher signature. Stable waits until a stable release is published.'); ?></p>
					</div>
					<div class="col-md-3">
						<label><?php echo _('Installed Package Version'); ?></label>
						<p class="form-control-static"><code><?php echo htmlspecialchars($package_version ?? 'unknown'); ?></code> <span class="label <?php echo $packageStatusClass; ?>"><?php echo htmlspecialchars($packageStatus['label'] ?? 'LATEST'); ?></span></p>
						<div class="sls-update-controls">
							<?php if ($hasPackageUpdate) { ?>
								<button type="submit" class="btn btn-warning btn-sm" name="slsmassnotifyserver_action" value="manual_update"><i class="fa fa-refresh" aria-hidden="true"></i> <?php echo _('Install Selected Release'); ?></button>
							<?php } ?>
						</div>
					</div>
				</div>
				<div class="panel panel-default sls-update-policy" style="margin-top:16px">
					<div class="panel-heading"><strong><i class="fa fa-calendar-check-o" aria-hidden="true"></i> <?php echo _('Update policy'); ?></strong></div>
					<div class="panel-body">
						<div class="row">
							<div class="col-sm-6 col-md-3 form-group">
								<label for="sls-update-pin"><?php echo _('Pinned version'); ?></label>
								<input id="sls-update-pin" class="form-control" name="updates[pinned_version]" maxlength="19" placeholder="0.1.5-beta" value="<?php echo htmlspecialchars($updates['pinned_version'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="sls-update-pin-help">
								<p id="sls-update-pin-help" class="help-block"><?php echo _('Optional exact version. Leave blank for the newest eligible release. An older pin holds the current installation; it does not downgrade it.'); ?></p>
							</div>
							<div class="col-sm-6 col-md-3 form-group">
								<label for="sls-update-delay"><?php echo _('Wait after publication'); ?></label>
								<div class="input-group"><input id="sls-update-delay" class="form-control" name="updates[rollout_delay_hours]" type="number" min="0" max="168" step="1" value="<?php echo (int)($updates['rollout_delay_hours'] ?? 0); ?>"><span class="input-group-addon"><?php echo _('hours'); ?></span></div>
								<p class="help-block"><?php echo _('Delay automatic installation by up to seven days. Use different delays on different PBXs for a staged rollout.'); ?></p>
							</div>
							<div class="col-sm-6 col-md-3 form-group">
								<label for="sls-update-window-start"><?php echo _('Automatic window starts'); ?></label>
								<input id="sls-update-window-start" class="form-control" name="updates[window_start]" type="time" value="<?php echo htmlspecialchars($updates['window_start'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="sls-update-window-help">
							</div>
							<div class="col-sm-6 col-md-3 form-group">
								<label for="sls-update-window-end"><?php echo _('Automatic window ends'); ?></label>
								<input id="sls-update-window-end" class="form-control" name="updates[window_end]" type="time" value="<?php echo htmlspecialchars($updates['window_end'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="sls-update-window-help">
							</div>
						</div>
						<p id="sls-update-window-help" class="help-block"><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo _('Times use the PBX operating-system timezone. Allow at least one hour, including overnight windows, or leave both times blank. Checks run hourly at minute 17; a window controls when installation starts. Manual installation bypasses the window and delay, while retaining the saved channel and version pin. Save and Apply Config before requesting an update.'); ?></p>
					</div>
				</div>
				<div id="sls-update-progress"
					class="sls-operation-status"
					role="status"
					aria-live="polite"
					aria-atomic="true"
					aria-busy="<?php echo $updateProgressBusy ? 'true' : 'false'; ?>"
					data-active="<?php echo $updateMonitorActive ? '1' : '0'; ?>"
					data-state="<?php echo htmlspecialchars($updateProgressState); ?>"
					data-status-url="config.php?display=slsmassnotifyserver_other&amp;sls_update_status=1"
					<?php echo $updateMonitorActive ? '' : 'hidden'; ?>>
					<span class="sls-operation-status__icon" aria-hidden="true"><i class="fa <?php echo $updateProgressState === 'complete' ? 'fa-check' : ($updateProgressState === 'failed' ? 'fa-exclamation' : 'fa-cloud-download'); ?>"></i></span>
					<span class="sls-operation-status__body">
						<strong class="sls-operation-status__title"><?php echo _('Module update'); ?></strong>
						<span class="sls-operation-status__message"><?php echo htmlspecialchars((string)($updateProgress['message'] ?? _('Preparing update status...'))); ?></span>
						<span class="sls-operation-status__track" aria-hidden="true"><span class="sls-operation-status__bar"></span></span>
					</span>
				</div>

				<details style="margin:20px 0;padding:14px;border:1px solid #dce2e8;border-radius:8px">
					<summary><i class="fa fa-check-square-o text-primary" aria-hidden="true"></i> <?php echo _('Saved Channel Checks (optional)'); ?> <?php include __DIR__ . '/labs.php'; ?> <span tabindex="0" class="fa fa-question-circle text-muted" role="img" title="<?php echo htmlspecialchars(_('Optional shortcuts for testing selected phones and desktops with audio, visuals, or both. Not required for normal announcements or weather alerts.')); ?>" aria-label="<?php echo htmlspecialchars(_('Optional shortcuts for testing selected phones and desktops with audio, visuals, or both. Not required for normal announcements or weather alerts.')); ?>"></span></summary>
					<p class="help-block"><?php echo _('Save up to ten scoped phone/desktop test profiles. Checks use the regular announcement audio settings and send no email or webhooks. They do not test Weather.gov or Xweather. Save and apply new profiles before running them.'); ?></p>
					<input type="hidden" name="test_profiles_present" value="1">
					<div id="sls-test-profile-list"></div>
					<button type="button" class="btn btn-default" id="sls-add-test-profile"><?php echo _('Add profile'); ?></button>
					<p id="sls-test-profile-status" role="status" aria-live="polite" style="margin-top:12px;white-space:pre-wrap"></p>
				</details>
				<div class="form-group" style="max-width:340px;margin:20px 0">
					<label for="sls-paging-answer-timeout"><?php echo _('Paging Answer Window'); ?></label>
					<div class="input-group"><input id="sls-paging-answer-timeout" name="paging_answer_timeout" type="number" min="1" max="5" class="form-control" value="<?php echo (int)($settings['paging_answer_timeout'] ?? 5); ?>"><span class="input-group-addon"><?php echo _('seconds'); ?></span></div>
					<p class="help-block"><?php echo _('Default 5 seconds; may be shortened to 1–4. Paging requires automatic answering. This does not change the visual alert expiry or extend playback.'); ?></p>
				</div>
</section>
				<div class="sls-save-actions">
					<button type="submit" class="btn btn-primary btn-lg"><i class="fa fa-save" aria-hidden="true"></i> <?php echo _('Save General Settings'); ?></button>
				</div>
				<input type="hidden" name="sls_general_form_complete" value="1">
			</form>

			<div data-settings-related="maintenance" aria-labelledby="sls-settings-tab-maintenance">
			<h3 class="sls-config-backup"><i class="fa fa-download text-primary" aria-hidden="true"></i> <?php echo _('Config Backup'); ?></h3>
			<?php if (!empty($configuration_backup_reminder['due'])): ?>
			<div class="alert alert-info" role="note">
				<i class="fa fa-shield" aria-hidden="true"></i>
				<strong><?php echo _('SLS backup reminder'); ?></strong>
				<?php echo _('Export an encrypted configuration backup every 90 days and before major changes. Save it outside the PBX and keep its passphrase separately. Configure scheduled backups in FreePBX Backup & Restore.'); ?>
			</div>
			<?php endif; ?>
			<form method="post" class="sls-manager-card" style="max-width:760px;margin-bottom:15px;">
				<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
				<input type="hidden" name="slsmassnotifyserver_action" value="export_encrypted_config">
				<h4><i class="fa fa-lock text-primary" aria-hidden="true"></i> <?php echo _('Encrypted configuration backup'); ?></h4>
				<p class="help-block"><?php echo _('Protect the configuration and its credentials with a separate backup passphrase. SLS does not store this passphrase and cannot recover a forgotten one. This export includes configuration; it does not include recordings or delivery history. Exports are audited before download; an audit storage or forwarding error prevents the download.'); ?></p>
				<div class="form-group"><label for="sls-backup-passphrase"><?php echo _('Backup passphrase'); ?></label><input class="form-control" id="sls-backup-passphrase" name="backup_passphrase" type="password" minlength="12" maxlength="1024" autocomplete="new-password" required><p class="help-block"><?php echo _('Use at least 12 characters, preferably several unrelated words. Store it separately from the downloaded file.'); ?></p></div>
				<div class="form-group"><label for="sls-backup-passphrase-confirm"><?php echo _('Confirm passphrase'); ?></label><input class="form-control" id="sls-backup-passphrase-confirm" name="backup_passphrase_confirm" type="password" minlength="12" maxlength="1024" autocomplete="new-password" required></div>
				<button type="submit" class="btn btn-primary"><i class="fa fa-download" aria-hidden="true"></i> <?php echo _('Download encrypted .config'); ?></button>
			</form>
			<details style="max-width:760px;margin-bottom:20px;"><summary><?php echo _('Unencrypted export for compatibility'); ?></summary>
			<p class="help-block"><?php echo _('An unencrypted export contains reusable credentials. Keep it in protected storage.'); ?></p>
			<form method="post">
				<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
				<button type="submit" class="btn btn-default" name="slsmassnotifyserver_action" value="export_config"><?php echo _('Download .config'); ?></button>
			</form>
			</details>
			<?php include __DIR__ . '/deployment_readiness.php'; ?>
			<form method="post" action="config.php?display=slsmassnotifyserver" style="margin:24px 0">
				<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
				<button type="submit" class="btn btn-default" name="slsmassnotifyserver_action" value="diagnostic_download"><i class="fa fa-stethoscope" aria-hidden="true"></i> <?php echo _('Download diagnostics'); ?></button>
				<p class="help-block"><?php echo _('Redacted support report: versions, health checks, permissions, and anonymized device counts. No credentials, configuration, message contents, or addresses.'); ?></p>
			</form>
			<div class="panel panel-danger sls-danger-panel">
				<div class="panel-heading"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?php echo _('Danger Zone'); ?></div>
				<div class="panel-body">
					<div id="sls-maintenance-progress"
						class="sls-operation-status sls-maintenance-progress"
						role="status"
						aria-live="polite"
						aria-atomic="true"
						aria-busy="<?php echo $maintenanceProgressBusy ? 'true' : 'false'; ?>"
						data-active="<?php echo $maintenanceMonitorActive ? '1' : '0'; ?>"
						data-action="<?php echo htmlspecialchars($maintenanceMonitorAction); ?>"
						data-state="<?php echo htmlspecialchars($maintenanceProgressState); ?>"
						data-status-url="config.php?display=slsmassnotifyserver_other&amp;sls_maintenance_status=1"
						<?php echo $maintenanceMonitorActive ? '' : 'hidden'; ?>>
						<span class="sls-operation-status__icon" aria-hidden="true"><i class="fa <?php echo $maintenanceProgressState === 'complete' ? 'fa-check' : ($maintenanceProgressState === 'failed' ? 'fa-exclamation' : 'fa-wrench'); ?>"></i></span>
						<span class="sls-operation-status__body">
							<strong class="sls-operation-status__title"><?php echo _('Maintenance'); ?></strong>
							<span class="sls-operation-status__message"><?php echo htmlspecialchars((string)($maintenanceProgress['message'] ?? _('Preparing maintenance status...'))); ?></span>
							<span class="sls-operation-status__track" aria-hidden="true"><span class="sls-operation-status__bar"></span></span>
						</span>
					</div>
					<div class="sls-danger-grid">
						<section class="sls-danger-action">
							<h4><i class="fa fa-wrench text-warning" aria-hidden="true"></i> <?php echo _('Installer Health'); ?></h4>
							<p><?php echo _('Repair Installation refreshes runtime files, permissions, Apache API routes, cron, dialplan, dashboard widget files, and local signatures. It does not replace your central .config, but it may reload FreePBX and Asterisk dialplan.'); ?></p>
							<form method="post" class="sls-maintenance-form" data-maintenance-action="repair" data-confirm="<?php echo htmlspecialchars(_('Are you sure you want to repair/reinstall the Mass Notifications integration now? This may reload FreePBX and Asterisk dialplan. Your central .config will not be replaced.'), ENT_QUOTES, 'UTF-8'); ?>">
								<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
								<input type="hidden" name="slsmassnotifyserver_action" value="repair_installation">
								<button type="submit" class="btn btn-warning"><?php echo _('Repair Installation'); ?></button>
							</form>
						</section>
						<section class="sls-danger-action sls-danger-action--critical">
							<h4><i class="fa fa-trash text-danger" aria-hidden="true"></i> <?php echo _('Completely Uninstall'); ?></h4>
							<p><strong><?php echo _('Warning:'); ?></strong> <?php echo _('This removes the module, runtime services, APIs, logs, desktop clients, credentials, tones, backups, and central configuration. This cannot be undone.'); ?></p>
							<form method="post" class="sls-maintenance-form" data-maintenance-action="uninstall" data-confirm="<?php echo htmlspecialchars(_('Are you sure you want to completely uninstall this module? All Mass Notifications configuration and data will be permanently deleted.'), ENT_QUOTES, 'UTF-8'); ?>">
								<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
								<input type="hidden" name="slsmassnotifyserver_action" value="complete_uninstall">
								<button type="submit" class="btn btn-danger"><i class="fa fa-trash" aria-hidden="true"></i> <?php echo _('Completely Uninstall'); ?></button>
							</form>
						</section>
						<section class="sls-danger-action sls-danger-action--critical">
							<h4><i class="fa fa-upload text-danger" aria-hidden="true"></i> <?php echo _('Replace Configuration'); ?></h4>
							<p><?php echo _('Replacing the config file wipes the current module data and overwrites API keys, desktop clients, voices, announcement groups, NWS settings, schedules, and retention settings.'); ?></p>
							<form method="post" enctype="multipart/form-data" class="sls-maintenance-form" data-maintenance-action="config" data-confirm="<?php echo htmlspecialchars(_('Replace the Mass Notifications config? This requires Apply Config to become live.'), ENT_QUOTES, 'UTF-8'); ?>">
								<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
								<input type="hidden" name="slsmassnotifyserver_action" value="import_config">
								<div class="form-group"><label><?php echo _('Upload .config'); ?></label><input type="file" name="config_upload" accept=".config,application/json" required></div>
								<div class="form-group"><label for="sls-import-passphrase"><?php echo _('Backup passphrase'); ?></label><input class="form-control" id="sls-import-passphrase" type="password" name="import_passphrase" maxlength="1024" autocomplete="off"><p class="help-block"><?php echo _('Required for an encrypted backup. Leave empty for an older unencrypted export. The file is authenticated and validated before changes are staged.'); ?></p></div>
								<button type="submit" class="btn btn-danger"><?php echo _('Replace Config'); ?></button>
							</form>
						</section>
					</div>
				</div>
			</div>
			</div>
		</div>
	</div>
</div>
<script>
<?php readfile(__DIR__.'/device_capacity.js'); ?>
(function () {
    'use strict';
    var toggle = document.getElementById('sls-address-change');
    if (!toggle) { return; }
    var panel = document.getElementById('sls-address-editor');
    var host = document.getElementById('sls-advertised-host');
    var api = document.getElementById('sls-advertised-api-port');
    var media = document.getElementById('sls-advertised-media-port');
    var control = document.getElementById('sls-advertised-control-port');
    var scheme = document.querySelector('[name="sipnotify_media_scheme"]');
    var preview = document.getElementById('sls-address-preview');
    var button = document.getElementById('sls-address-check');
    var status = document.getElementById('sls-address-check-result');
    var inputs = [host, api, control, media], original = inputs.map(function (input) { return input.value; });
    var request = null, generation = 0;
    function refresh(reset) {
        generation++;
        if (request) { request.abort(); request = null; }
        button.disabled = false; status.textContent = '';
        panel.hidden = !toggle.checked;
        inputs.forEach(function (input, index) {
            input.disabled = !toggle.checked; input.required = toggle.checked;
            if (reset && !toggle.checked) { input.value = original[index]; }
        });
        preview.textContent = '';
        var name = host.value.trim().toLowerCase();
        var apiBase = 'https://' + name + (Number(api.value) === 443 ? '' : ':' + api.value);
        var controlBase = 'https://' + name + (Number(control.value) === 443 ? '' : ':' + control.value);
        var mediaBase = scheme.value + '://' + name + (Number(media.value) === (scheme.value === 'https' ? 443 : 80) ? '' : ':' + media.value);
        [['Desktop', apiBase + '/api/sipnotify/desktop'], ['Control API', controlBase + '/api/sls-mass-notify'], ['Phone images', mediaBase + '/sls_mass_notify']].forEach(function (row) {
            var line = document.createElement('div'); line.textContent = row[0] + ': ' + row[1]; preview.appendChild(line);
        });
    }
    toggle.addEventListener('change', function () { refresh(true); if (toggle.checked) { host.focus(); } });
    inputs.concat([scheme]).forEach(function (input) { input.addEventListener('input', function () { refresh(false); }); });
    button.addEventListener('click', function () {
        if (!toggle.checked || !inputs.every(function (input) { return input.reportValidity(); })) { return; }
        var current = ++generation, body = new FormData();
        body.set('slsmassnotifyserver_action', 'check_advertised_address');
        body.set('slsmassnotifyserver_csrf', toggle.form.querySelector('[name="slsmassnotifyserver_csrf"]').value);
        inputs.forEach(function (input) { body.set(input.name, input.value); });
        body.set('sipnotify_media_scheme', scheme.value);
        button.disabled = true; status.textContent = 'Checking the local HTTPS origin…';
        var xhr = request = new XMLHttpRequest(); xhr.open('POST', 'config.php?display=slsmassnotifyserver_other'); xhr.timeout = 15000;
        xhr.onload = function () {
            if (current !== generation) { return; }
            button.disabled = false; request = null;
            var result = null;
            try { result = JSON.parse(xhr.responseText); } catch (ignored) {}
            status.textContent = result && typeof result.message === 'string' ? result.message
                : (result && result.connection && typeof result.connection.message === 'string' ? result.connection.message
                : 'The HTTPS check returned an unexpected response (HTTP ' + xhr.status + '). Reload the page if your administrator session expired.');
        };
        xhr.onerror = xhr.ontimeout = function () {
            if (current !== generation) { return; }
            button.disabled = false; request = null;
            status.textContent = 'The HTTPS check could not finish within 15 seconds. Check the PBX connection and try again.';
        };
        xhr.send(body);
    });
    refresh(false);
}());
(function() {
	var updateProgress = document.getElementById('sls-update-progress');
	if (updateProgress && updateProgress.getAttribute('data-active') === '1') {
		var updatePolls = 0;
		var updateStatusUrl = updateProgress.getAttribute('data-status-url');
		var updateIcon = updateProgress.querySelector('.sls-operation-status__icon i');
		var updateTitle = updateProgress.querySelector('.sls-operation-status__title');
		var updateText = updateProgress.querySelector('.sls-operation-status__message');
		function renderUpdateDisplay(state, message) {
			var terminal = state === 'complete' || state === 'failed';
			updateProgress.hidden = false;
			updateProgress.setAttribute('data-state', state);
			updateProgress.setAttribute('aria-busy', terminal ? 'false' : 'true');
			updateIcon.className = state === 'complete' ? 'fa fa-check' : (state === 'failed' ? 'fa fa-exclamation' : 'fa fa-cloud-download');
			updateTitle.textContent = state === 'complete' ? 'Update complete' : (state === 'failed' ? 'Update needs attention' : 'Module update');
			updateText.textContent = message || (state === 'complete' ? 'Update completed.' : (state === 'failed' ? 'Update failed.' : 'Checking update status...'));
		}
		function finishUpdateDisplay(state, message) {
			renderUpdateDisplay(state, message);
			if (state === 'complete') {
				window.setTimeout(function() {
					var cleanUrl = new URL(window.location.href);
					cleanUrl.searchParams.delete('sls_update_queued');
					window.location.replace(cleanUrl.toString());
				}, 1800);
			}
		}
		function pollUpdateStatus() {
			updatePolls += 1;
			fetch(updateStatusUrl, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
				.then(function(response) { if (!response.ok) throw new Error('status request failed'); return response.json(); })
				.then(function(data) {
					var state = String(data.state || 'checking');
					var message = String(data.message || 'Checking for update status...');
					if (state === 'complete' || state === 'failed') {
						finishUpdateDisplay(state, message);
						return;
					}
					renderUpdateDisplay(state, message);
					window.setTimeout(pollUpdateStatus, 2000);
				})
				.catch(function() {
					if (updatePolls >= 300) {
						finishUpdateDisplay('failed', 'Update status could not be confirmed. Check Notification Logs and try again.');
						return;
					}
					renderUpdateDisplay('checking', 'Update is running; waiting for the PBX interface to respond...');
					window.setTimeout(pollUpdateStatus, 3000);
				});
		}
		window.setTimeout(pollUpdateStatus, 500);
	}
	var maintenanceProgress = document.getElementById('sls-maintenance-progress');
	var maintenanceForms = document.querySelectorAll('.sls-maintenance-form');
	Array.prototype.forEach.call(maintenanceForms, function(form) {
		form.addEventListener('submit', function(event) {
			if (form.getAttribute('data-submitting') === '1') {
				return;
			}
			var promptText = form.getAttribute('data-confirm') || '';
			if (promptText && !window.confirm(promptText)) {
				event.preventDefault();
				return;
			}
			form.setAttribute('data-submitting', '1');
			Array.prototype.forEach.call(form.querySelectorAll('button'), function(control) {
				control.disabled = true;
			});
			if (maintenanceProgress) {
				var action = form.getAttribute('data-maintenance-action') || '';
				var labels = {
					repair: 'Submitting installation repair request...',
					uninstall: 'Submitting complete uninstall request...',
					config: 'Uploading and validating replacement configuration...'
				};
				var titles = {
					repair: 'Installation repair',
					uninstall: 'Complete uninstall',
					config: 'Configuration replacement'
				};
				maintenanceProgress.hidden = false;
				maintenanceProgress.setAttribute('data-action', action);
				maintenanceProgress.setAttribute('data-state', 'running');
				maintenanceProgress.setAttribute('aria-busy', 'true');
				var icon = maintenanceProgress.querySelector('.sls-operation-status__icon i');
				var title = maintenanceProgress.querySelector('.sls-operation-status__title');
				var text = maintenanceProgress.querySelector('.sls-operation-status__message');
				if (icon) icon.className = action === 'config' ? 'fa fa-upload' : (action === 'uninstall' ? 'fa fa-trash' : 'fa fa-wrench');
				if (title) title.textContent = titles[action] || 'Maintenance';
				if (text) text.textContent = labels[action] || 'Submitting maintenance request...';
			}
		});
	});
	if (maintenanceProgress && maintenanceProgress.getAttribute('data-active') === '1') {
		var maintenancePolls = 0;
		var maintenanceFailures = 0;
		var maintenanceStatusUrl = maintenanceProgress.getAttribute('data-status-url');
		var expectedAction = maintenanceProgress.getAttribute('data-action');
		var maintenanceIcon = maintenanceProgress.querySelector('.sls-operation-status__icon i');
		var maintenanceTitle = maintenanceProgress.querySelector('.sls-operation-status__title');
		var maintenanceText = maintenanceProgress.querySelector('.sls-operation-status__message');
		var maintenanceTitles = {
			repair: 'Installation repair',
			uninstall: 'Complete uninstall',
			config: 'Configuration replacement'
		};
		function cleanMaintenanceUrl() {
			var cleanUrl = new URL(window.location.href);
			cleanUrl.searchParams.delete('sls_maintenance_action');
			window.history.replaceState({}, document.title, cleanUrl.toString());
		}
		function finishMaintenanceDisplay(state, message) {
			maintenanceProgress.hidden = false;
			maintenanceProgress.setAttribute('data-state', state);
			maintenanceProgress.setAttribute('aria-busy', 'false');
			maintenanceIcon.className = state === 'complete' ? 'fa fa-check' : 'fa fa-exclamation';
			maintenanceTitle.textContent = state === 'complete' ? 'Maintenance complete' : 'Maintenance needs attention';
			maintenanceText.textContent = message || (state === 'complete' ? 'Maintenance completed.' : 'Maintenance failed.');
			cleanMaintenanceUrl();
			if (state === 'complete' && expectedAction === 'repair') {
				window.setTimeout(function() { window.location.reload(); }, 1800);
			}
		}
		function pollMaintenanceStatus() {
			maintenancePolls += 1;
			fetch(maintenanceStatusUrl, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
				.then(function(response) { if (!response.ok) throw new Error('status request failed'); return response.json(); })
				.then(function(data) {
					maintenanceFailures = 0;
					var action = String(data.action || expectedAction || '');
					var state = String(data.state || 'running');
					var message = String(data.message || 'Maintenance is running...');
					if (expectedAction && action && expectedAction !== action) {
						window.setTimeout(pollMaintenanceStatus, 1500);
						return;
					}
					if (state === 'complete' || state === 'failed') {
						finishMaintenanceDisplay(state, message);
						return;
					}
					maintenanceProgress.hidden = false;
					maintenanceProgress.setAttribute('data-state', state);
					maintenanceProgress.setAttribute('aria-busy', 'true');
					maintenanceIcon.className = action === 'config' ? 'fa fa-upload' : (action === 'uninstall' ? 'fa fa-trash' : 'fa fa-wrench');
					maintenanceTitle.textContent = maintenanceTitles[action] || 'Maintenance';
					maintenanceText.textContent = message;
					window.setTimeout(pollMaintenanceStatus, 1500);
				})
				.catch(function() {
					maintenanceFailures += 1;
					if (expectedAction === 'uninstall' && maintenanceFailures >= 3) {
						finishMaintenanceDisplay('complete', 'The module interface is no longer available. Complete uninstall has likely finished; verify in Module Admin.');
						return;
					}
					if (maintenancePolls >= 400) {
						finishMaintenanceDisplay('failed', 'Maintenance status could not be confirmed. Review Notification Logs before retrying.');
						return;
					}
					maintenanceProgress.setAttribute('data-state', 'running');
					maintenanceProgress.setAttribute('aria-busy', 'true');
					maintenanceText.textContent = 'Maintenance is running; waiting for the PBX interface to respond...';
					window.setTimeout(pollMaintenanceStatus, 2500);
				});
		}
		window.setTimeout(pollMaintenanceStatus, expectedAction === 'config' ? 200 : 500);
	}
	document.addEventListener('click', function(event) {
		var button = event.target.closest('[data-toggle-secret]');
		if (!button) {
			return;
		}
		var group = button.closest('.input-group');
		var input = group ? group.querySelector('input') : null;
		if (!input) {
			return;
		}
		var reveal = input.type === 'password';
		input.type = reveal ? 'text' : 'password';
		var icon = button.querySelector('i');
		if (icon) {
			icon.className = reveal ? 'fa fa-eye-slash' : 'fa fa-eye';
		}
	});
	var formatList = document.getElementById('sls-format-editor-list');
	var addFormat = document.getElementById('sls-add-format');
	var formatTemplate = document.getElementById('sls-format-row-template');
	function reindexFormats() {
		if (!formatList) return;
		Array.prototype.forEach.call(formatList.querySelectorAll('[data-format-row]'), function(row, index) {
			var extension = row.querySelector('input'); var format = row.querySelector('select');
			if (extension) extension.name = 'sipnotify_format_overrides[' + index + '][extension]';
			if (format) format.name = 'sipnotify_format_overrides[' + index + '][format]';
		});
	}
	if (formatList) {
		formatList.addEventListener('click', function(event) { var remove = event.target.closest('[data-remove-format]'); if (remove) { remove.closest('[data-format-row]').remove(); reindexFormats(); } });
	}
	if (addFormat && formatList && formatTemplate) {
		addFormat.addEventListener('click', function() { var shell=document.createElement('div'); shell.innerHTML=formatTemplate.innerHTML.trim(); formatList.appendChild(shell.firstElementChild); reindexFormats(); });
	}
	reindexFormats();
	var deviceList = document.getElementById('sls-device-override-list');
	var devicePicker = document.getElementById('sls-device-picker');
	var deviceStatus = document.getElementById('sls-device-status');
	var deviceInventory = {};
	var deviceFormats = <?php echo json_encode($formatLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	var savedDevices = <?php echo json_encode((object)($settings['sipnotify']['device_format_overrides'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	function addDeviceBinding(key, device) {
		if (!deviceList || !/^[a-f0-9]{32}$/.test(key) || deviceList.querySelector('[data-device-key="' + key + '"]')) return;
		if (deviceList.children.length >= 100) { deviceStatus.textContent = 'Remove a binding before adding another.'; return; }
		var row = document.createElement('div'); row.className = 'sls-editor-row'; row.setAttribute('data-device-key',key);
		var label = device.label || device.user_agent || 'Registered device';
		var text = document.createElement('span'); text.className = 'sls-editor-grow'; text.textContent = device.extension + ' · ' + label; row.appendChild(text);
		[['extension',device.extension],['label',label]].forEach(function(pair) {
			var input = document.createElement('input'); input.type = 'hidden'; input.name = 'sipnotify_device_overrides[' + key + '][' + pair[0] + ']'; input.value = pair[1]; row.appendChild(input);
		});
		var select = document.createElement('select'); select.className = 'form-control sls-editor-format'; select.name = 'sipnotify_device_overrides[' + key + '][format]'; select.setAttribute('aria-label','Device phone family');
		var automatic = document.createElement('option'); automatic.value = ''; automatic.textContent = 'No device override'; select.appendChild(automatic);
		Object.keys(deviceFormats).forEach(function(format) { var option = document.createElement('option'); option.value = format; option.textContent = deviceFormats[format]; select.appendChild(option); });
		select.value = device.format in deviceFormats ? device.format : ''; row.appendChild(select);
		var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-link text-danger'; remove.textContent = 'Remove'; remove.addEventListener('click',function(){row.remove();}); row.appendChild(remove); deviceList.appendChild(row);
	}
	Object.keys(savedDevices).forEach(function(key) { addDeviceBinding(key,savedDevices[key]); });
	var refreshDevices = document.getElementById('sls-device-refresh');
	if (refreshDevices) refreshDevices.addEventListener('click',function() {
		refreshDevices.disabled = true; deviceStatus.textContent = 'Reading current registrations…';
		fetch('config.php?display=slsmassnotifyserver&slsmassnotifyserver_action=device_inventory',{credentials:'same-origin',cache:'no-store'})
		.then(function(response) { if (!response.ok) throw new Error(); return response.json(); }).then(function(data) {
			devicePicker.innerHTML = ''; deviceInventory = {};
			if (!data.success) { deviceStatus.textContent = data.message || 'Device discovery is unavailable.'; return; }
			(data.devices || []).forEach(function(device) {
				if (!/^[a-f0-9]{32}$/.test(device.key || '')) return;
				deviceInventory[device.key] = device;
				var option = document.createElement('option'); option.value = device.key;
				option.textContent = device.extension + ' · ' + (device.user_agent || 'Unknown device') + ' · ' + device.transport.toUpperCase(); devicePicker.appendChild(option);
			}); deviceStatus.textContent = devicePicker.options.length + ' registrations. Templates do not confirm handset support.';
		}).catch(function() { deviceStatus.textContent = 'Unable to read registrations. No settings were changed.'; }).then(function(){refreshDevices.disabled=false;});
	});
	var addDevice = document.getElementById('sls-device-add');
	if (addDevice) addDevice.addEventListener('click',function() { var device = deviceInventory[devicePicker.value]; if (device) addDeviceBinding(devicePicker.value,device); });
	var emailList = document.getElementById('sls-email-editor-list');
	var addEmail = document.getElementById('sls-add-email');
	var emailTemplate = document.getElementById('sls-email-row-template');
	function updateDestinationEmptyState(list, rowSelector) {
		if (!list) return;
		var empty = list.querySelector('[data-destination-empty]');
		if (empty) empty.hidden = list.querySelectorAll(rowSelector).length !== 0;
	}
	function nameEmails() { if (!emailList) return; Array.prototype.forEach.call(emailList.querySelectorAll('[data-email-row] input'), function(input) { input.name='system_notification_recipients[]'; }); updateDestinationEmptyState(emailList, '[data-email-row]'); }
	if (emailList) {
		emailList.addEventListener('click', function(event) { var remove=event.target.closest('[data-remove-email]'); if(remove){remove.closest('[data-email-row]').remove();nameEmails();} });
	}
	if (addEmail && emailList && emailTemplate) {
		addEmail.addEventListener('click', function(){var shell=document.createElement('div');shell.innerHTML=emailTemplate.innerHTML.trim();emailList.appendChild(shell.firstElementChild);nameEmails();});
	}
	nameEmails();
	function reindexWebhooks(type) {
		var list = document.getElementById('sls-' + type + '-editor-list');
		if (!list) return;
		Array.prototype.forEach.call(list.querySelectorAll('[data-webhook-row]'), function(row, index) {
			var prefix = type + '_webhooks[' + index + ']';
			var enabledHidden = row.querySelector('[data-field="enabled-hidden"]') || row.querySelector('input[type="hidden"][name$="[enabled]"]');
			var enabled = row.querySelector('[data-field="enabled"]') || row.querySelector('input[type="checkbox"]');
			var id = row.querySelector('[data-field="id"]') || row.querySelector('input[type="hidden"][name$="[id]"]');
			var name = row.querySelector('[data-field="name"]') || row.querySelector('input[name$="[name]"]');
			var url = row.querySelector('[data-field="url"]') || row.querySelector('input[name$="[url]"]');
			if (enabledHidden) enabledHidden.name = prefix + '[enabled]';
			if (enabled) enabled.name = prefix + '[enabled]';
			if (id) id.name = prefix + '[id]';
			if (name) name.name = prefix + '[name]';
			if (url) url.name = prefix + '[url]';
			var format = row.querySelector('[data-field="payload_format"]');
			if (format) format.name = prefix + '[payload_format]';
			if (type !== 'discord') {
				var auth = row.querySelector('[data-webhook-auth]');
				if (!auth) {
					auth = document.createElement('details');
					auth.setAttribute('data-webhook-auth', '');
					auth.style.gridColumn = '1 / -1';
					var summary = document.createElement('summary');
					summary.textContent = 'Receiver authentication (optional)';
					auth.appendChild(summary);
					['bearer_token', 'signing_secret'].forEach(function(field) {
						var label = document.createElement('label');
						label.style.display = 'block'; label.style.marginTop = '10px';
						label.textContent = field === 'bearer_token' ? 'Bearer token' : 'HMAC-SHA256 signing secret';
						var input = document.createElement('input');
						input.type = 'password'; input.className = 'form-control'; input.maxLength = 512;
						input.autocomplete = 'new-password'; input.placeholder = 'Leave blank to keep the stored value';
						input.setAttribute('data-auth-field', field); label.appendChild(input); auth.appendChild(label);
						var clearLabel = document.createElement('label'); clearLabel.style.marginRight = '16px';
						var clear = document.createElement('input'); clear.type = 'checkbox'; clear.value = '1';
						clear.setAttribute('data-auth-field', 'clear_' + field);
						clearLabel.appendChild(clear); clearLabel.appendChild(document.createTextNode(' Remove stored ' + (field === 'bearer_token' ? 'token' : 'signing secret')));
						auth.appendChild(clearLabel);
					});
					var hint = document.createElement('p'); hint.className = 'help-block';
					hint.textContent = 'For native HTTPS receivers only. Slack and Teams Workflows use the secret in their URL; these optional headers are not sent to them. Secrets stay in the protected central configuration.';
					auth.appendChild(hint); row.appendChild(auth);
				}
				auth.hidden = format && format.value !== 'native';
				auth.querySelectorAll('[data-auth-field]').forEach(function(input) {
					input.name = prefix + '[' + input.getAttribute('data-auth-field') + ']';
				});
			}
		});
		updateDestinationEmptyState(list, '[data-webhook-row]');
		var addButton = document.querySelector('[data-add-webhook="' + type + '"]');
		var limitMessage = document.querySelector('[data-webhook-limit="' + type + '"]');
		var limitReached = list.querySelectorAll('[data-webhook-row]').length >= 10;
		if (addButton) {
			addButton.disabled = limitReached;
			addButton.setAttribute('aria-disabled', limitReached ? 'true' : 'false');
		}
		if (limitMessage) limitMessage.hidden = !limitReached;
	}
	['discord', 'generic', 'announcement'].forEach(function(type) {
		var list = document.getElementById('sls-' + type + '-editor-list');
		var template = document.getElementById('sls-' + type + '-row-template');
		var addButton = document.querySelector('[data-add-webhook="' + type + '"]');
		if (list) {
			list.addEventListener('change', function(event) {
				if (event.target.matches('[data-field="payload_format"]')) reindexWebhooks(type);
			});
			list.addEventListener('click', function(event) {
				var remove = event.target.closest('[data-remove-webhook]');
				if (remove) {
					remove.closest('[data-webhook-row]').remove();
					reindexWebhooks(type);
				}
			});
		}
		if (addButton && list && template) {
			addButton.addEventListener('click', function() {
				if (list.querySelectorAll('[data-webhook-row]').length >= 10) return;
				var shell = document.createElement('div');
				shell.innerHTML = template.innerHTML.trim();
				list.appendChild(shell.firstElementChild);
				reindexWebhooks(type);
				list.scrollTop = list.scrollHeight;
			});
		}
		reindexWebhooks(type);
	});
	var mailFromLocalPart = document.getElementById('sls-mail-from-local-part');
	var mailFromDomain = document.getElementById('sls-mail-from-domain');
	var mailFromPreview = document.getElementById('sls-mail-from-preview');
	function renderMailFromPreview() {
		if (!mailFromDomain || !mailFromPreview) return;
		var localPart = String(mailFromLocalPart ? mailFromLocalPart.value : 'no-reply').trim().toLowerCase();
		var domain = String(mailFromDomain.value || '').trim().replace(/^@/, '').replace(/\.$/, '').toLowerCase();
		mailFromPreview.textContent = (localPart || 'no-reply') + '@' + (domain || 'example.com');
	}
	if (mailFromLocalPart) {
		mailFromLocalPart.addEventListener('input', renderMailFromPreview);
	}
	if (mailFromDomain) {
		mailFromDomain.addEventListener('input', renderMailFromPreview);
	}
	renderMailFromPreview();
	var copyButton = document.getElementById('copy_control_api_key');
	var controlKey = document.getElementById('control_api_key');
	if (copyButton && controlKey) {
		copyButton.addEventListener('click', function() {
			controlKey.focus();
			controlKey.select();
			document.execCommand('copy');
		});
	}
	var timeoutMode = document.getElementById('sls-announcement-timeout-mode');
	var timeoutCustom = document.getElementById('sls-announcement-timeout-custom');
	var timeoutSeconds = document.getElementById('sls-announcement-timeout-seconds');
	function renderAnnouncementTimeout() {
		var custom = timeoutMode && timeoutMode.value === 'custom';
		if (timeoutCustom) {
			timeoutCustom.style.display = custom ? '' : 'none';
		}
		if (timeoutSeconds) {
			timeoutSeconds.disabled = !custom;
		}
	}
	if (timeoutMode) {
		timeoutMode.addEventListener('change', renderAnnouncementTimeout);
	}
	renderAnnouncementTimeout();
	var table = document.querySelector('#desktop-client-table tbody');
	var desktopScroller = document.getElementById('desktop-client-scroll');
	var add = document.getElementById('add-desktop-client');
	if (!table || !add) {
		return;
	}
	var capacity = document.getElementById('sls-desktop-limit');
	var capacityError = document.getElementById('sls-desktop-capacity-error');
	var clientJson = document.getElementById('sls-desktop-clients-json');
	// A single JSON field avoids PHP's max_input_vars truncating large fleets.
	// Serialize only this manager; all other settings keep their usual form fields.
	if (clientJson && clientJson.form) clientJson.form.addEventListener('submit', function () {
		var clients = [];
		table.querySelectorAll('[data-desktop-client-row]').forEach(function (row) {
			var client = {enabled: '0'};
			row.querySelectorAll('[name]').forEach(function (input) {
				var match = /^desktop_clients\[\d+\]\[([a-z_]+)\]$/.exec(input.name);
				if (match && (input.type !== 'checkbox' || input.checked)) client[match[1]] = input.value;
			});
			clients.push(client);
		});
		clientJson.value = JSON.stringify(clients);
		var inputs = table.querySelectorAll('[name]');
		inputs.forEach(function (input) { input.disabled = true; });
		// Re-enable if another handler stops submission, retaining the edited form.
		window.setTimeout(function () { inputs.forEach(function (input) { input.disabled = false; }); }, 0);
	}, true);
	function nextIndex() {
		var highest = -1;
		table.querySelectorAll('[name]').forEach(function (input) {
			var match = /^desktop_clients\[(\d+)\]/.exec(input.name);
			if (match) highest = Math.max(highest, Number(match[1]));
		});
		return highest + 1;
	}
	table.addEventListener('click', function(event) {
		if (event.target.matches('[data-remove-desktop-client]')) {
			event.target.closest('tr').remove();
		}
	});
		add.addEventListener('click', function() {
			if (table.querySelectorAll('[data-desktop-client-row]').length >= Number(capacity.value || 25)) {
				capacityError.textContent = 'Desktop capacity reached. Increase Desktop Capacity before adding another client.';
				return;
			}
			capacityError.textContent = '';
			var index = nextIndex();
				var row = document.createElement('tr');
		row.setAttribute('data-desktop-client-row', '1');
		row.innerHTML =
			'<td><input type="hidden" name="desktop_clients[' + index + '][id]" value="">' +
				'<input type="hidden" name="desktop_clients[' + index + '][password_enc]" value="">' +
				'<input type="checkbox" name="desktop_clients[' + index + '][enabled]" value="1" checked></td>' +
				'<td><input class="form-control input-sm" name="desktop_clients[' + index + '][name]" value="Desktop App"></td>' +
					'<td><input type="hidden" name="desktop_clients[' + index + '][client_id]" value=""><code>Generated on save</code></td>' +
					'<td><input class="form-control input-sm" name="desktop_clients[' + index + '][username]" value="" placeholder="Generated on save"></td>' +
					'<td><div class="input-group input-group-sm"><input class="form-control" name="desktop_clients[' + index + '][password]" type="password" value="" placeholder="Generated on save" autocomplete="new-password"><span class="input-group-btn"><button type="button" class="btn btn-default" data-toggle-secret title="Show or hide password" aria-label="Show or hide password"><i class="fa fa-eye" aria-hidden="true"></i></button></span></div></td>' +
			'<td><button type="button" class="btn btn-default btn-sm" data-remove-desktop-client>Delete</button></td>';
			table.appendChild(row);
			if (desktopScroller) {
				desktopScroller.scrollTop = desktopScroller.scrollHeight;
			}
	});
}());
</script>
<script>
(function () {
 'use strict';
 var list = document.getElementById('sls-test-profile-list');
 var add = document.getElementById('sls-add-test-profile');
 var status = document.getElementById('sls-test-profile-status');
 if (!list || !add || !status) return;
 var phones = <?php echo json_encode($testProfilePhones, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
 var desktops = <?php echo json_encode($testProfileDesktops, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
 var profiles = <?php echo json_encode(array_values($settings['test_profiles'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
 var index = 0, busy = false, pollTimer = null;
 function field(parent, title, name, values, selected, multiple) {
  var label = document.createElement('label'); label.textContent = title; parent.appendChild(label);
  var select = document.createElement('select'); select.className = 'form-control'; select.name = name;
  select.multiple = !!multiple; if (multiple) select.size = 3;
  values.forEach(function (item) { var option = document.createElement('option'); option.value = item.value;
   option.textContent = item.label; option.selected = selected.indexOf(item.value) !== -1; select.appendChild(option); });
  parent.appendChild(select); return select;
 }
 function finish(data) {
  status.textContent = (data.message || 'Check finished.') + (data.receipts || []).map(function(r) {
   return '\n' + r.channel + ' · ' + r.target + ' · ' + r.state;
  }).join('');
  busy = false; list.querySelectorAll('[data-profile-run]').forEach(function(button) { button.disabled = false; });
 }
 function poll(id, started) {
  if (!document.body.contains(list)) return;
  if (Date.now() - started > 900000) { finish({message:'Check is taking longer than expected. Review Dashboard delivery status before retrying.'}); return; }
  fetch('config.php?display=slsmassnotifyserver&slsmassnotifyserver_action=announcement_job&job_id=' + encodeURIComponent(id), {credentials:'same-origin', cache:'no-store'})
   .then(function(r) { if (!r.ok) throw new Error(); return r.json(); }).then(function(data) {
    status.textContent = data.message || 'Checking delivery…';
    if (data.state === 'queued' || data.state === 'running') pollTimer = window.setTimeout(function() { poll(id, started); },2000);
    else finish(data);
   }).catch(function() { status.textContent = 'Waiting for delivery status. Do not resend yet.'; pollTimer = window.setTimeout(function() { poll(id, started); },3000); });
 }
 function addProfile(profile) {
  if (list.children.length >= 10) return;
  var key = index++, prefix = 'test_profiles[' + key + ']';
  var row = document.createElement('div'); row.style.cssText = 'padding:12px 0;border-bottom:1px solid #e5e7eb;margin-bottom:12px';
  var id = document.createElement('input'); id.type = 'hidden'; id.name = prefix + '[id]';
  id.value = profile.id || 'test_' + Date.now().toString(36) + '_' + key; row.appendChild(id);
  var name = document.createElement('input'); name.className = 'form-control'; name.name = prefix + '[name]';
  name.value = profile.name || ''; name.maxLength = 64; name.placeholder = 'Profile name'; name.setAttribute('aria-label','Profile name'); row.appendChild(name);
  var grid = document.createElement('div'); grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:10px 0'; row.appendChild(grid);
  [['Phones','extensions',phones,profile.extensions || []],['Desktops','desktop_clients',desktops,profile.desktop_clients || []]].forEach(function(value) {
   var cell = document.createElement('div'); grid.appendChild(cell); field(cell,value[0],prefix+'['+value[1]+'][]',value[2],value[3].map(String),true);
  });
  var cell = document.createElement('div'); grid.appendChild(cell);
  field(cell,'Channels',prefix+'[channels]',[{value:'all',label:'Audio and visuals'},{value:'audio',label:'Phone audio only'},{value:'visual',label:'Phone/desktop visuals only'}],[profile.channels || 'all'],false);
  var run = document.createElement('button'); run.type='button'; run.className='btn btn-primary btn-sm'; run.textContent='Run saved check'; run.setAttribute('data-profile-run',''); row.appendChild(run);
  run.addEventListener('click',function() {
   if (busy) return; busy=true;
   list.querySelectorAll('[data-profile-run]').forEach(function(button) { button.disabled=true; }); status.textContent='Queuing channel check…';
   var body=new FormData(); body.set('slsmassnotifyserver_action','run_test_profile'); body.set('profile_id',id.value);
   body.set('slsmassnotifyserver_csrf',document.querySelector('#sls-other-settings-form [name="slsmassnotifyserver_csrf"]').value);
   fetch('config.php?display=slsmassnotifyserver',{method:'POST',credentials:'same-origin',body:body}).then(function(r) { if(!r.ok)throw new Error();return r.json(); })
    .then(function(data) { if(data.queued && data.job_id) poll(data.job_id,Date.now()); else finish(data); })
    .catch(function() { finish({message:'Request could not be confirmed. Check Dashboard status before trying again.'}); });
  });
  var remove=document.createElement('button'); remove.type='button'; remove.className='btn btn-default btn-sm'; remove.style.marginLeft='8px'; remove.textContent='Remove';
  remove.addEventListener('click',function() { if(!busy) { row.remove();add.disabled=false; } }); row.appendChild(remove); list.appendChild(row); add.disabled=list.children.length>=10;
 }
 profiles.forEach(addProfile); add.addEventListener('click',function() { addProfile({}); });
 window.addEventListener('pagehide',function() { if(pollTimer)window.clearTimeout(pollTimer); });
}());
</script>
