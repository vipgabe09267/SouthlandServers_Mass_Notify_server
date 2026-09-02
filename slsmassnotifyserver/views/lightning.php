<?php
$settings = is_array($settings ?? null) ? $settings : [];
$activeSettings = is_array($active_settings ?? null) ? $active_settings : $settings;
$xweather = is_array($settings['xweather'] ?? null) ? $settings['xweather'] : [];
$activeXweather = is_array($activeSettings['xweather'] ?? null) ? $activeSettings['xweather'] : [];
$extensions = is_array($available_extensions ?? null) ? $available_extensions : [];
$desktopClients = array_values(array_filter((array)($available_desktop_clients ?? []), 'is_array'));
$tones = is_array($available_tones ?? null) ? $available_tones : [];
$systemSounds = is_array($available_system_sounds ?? null) ? $available_system_sounds : [];
$csrfToken = (string)($csrf_token ?? '');
$openingSelection = (string)($xweather['opening_tone'] ?? 'opening_Lightning_alert');
$closingSelection = (string)($xweather['closing_tone'] ?? '');
$cooldownRemaining = max(0, (int)($cooldown_remaining ?? 0));
$apiUsage = is_array($api_usage ?? null) ? $api_usage : [];
$adaptiveFreeTier = !array_key_exists('adaptive_free_tier', $xweather) || !empty($xweather['adaptive_free_tier']);
$weatherZones = is_array($settings['nws_zones'] ?? null) ? $settings['nws_zones'] : [];
$weatherZoneNames = [];
foreach ($weatherZones as $weatherZone) {
	if (!is_array($weatherZone)) continue;
	$zoneId = (string)($weatherZone['id'] ?? '');
	if ($zoneId === '') continue;
	$zoneName = trim((string)($weatherZone['name'] ?? '')) ?: (string)($weatherZone['zone'] ?? $zoneId);
	$zoneCode = trim((string)($weatherZone['zone'] ?? ''));
	$weatherZoneNames[$zoneId] = $zoneCode !== '' ? $zoneName . ' — ' . $zoneCode : $zoneName;
}
$desktopNames = [];
foreach ($desktopClients as $desktopClient) {
	$username = (string)($desktopClient['username'] ?? '');
	if ($username === '') continue;
	$name = trim((string)($desktopClient['name'] ?? ''));
	$clientId = trim((string)($desktopClient['client_id'] ?? ''));
	$identity = $name !== '' && $name !== $username ? $name . ' — ' . $username : $username;
	if ($clientId !== '') $identity .= ' · ' . _('client ID') . ' ' . $clientId;
	$desktopNames[$username] = $identity;
}
$extensionNames = [];
foreach ($extensions as $extension) {
	$number = (string)($extension['extension'] ?? '');
	if ($number !== '') $extensionNames[$number] = true;
}
$viewGroups = static function (array $config) {
	$groups = array_values(array_filter((array)($config['groups'] ?? []), 'is_array'));
	if ($groups) return array_slice($groups, 0, 5);
	$legacyLocation = trim((string)($config['location'] ?? ''));
	$legacyRecipients = (array)($config['recipients'] ?? []);
	$legacyZone = trim((string)($config['adaptive_nws_zone_id'] ?? ''));
	if ($legacyLocation === '' && !$legacyRecipients && $legacyZone === '') return [];
	return [[
		'id' => '',
		'name' => _('Primary Lightning Area'),
		'enabled' => '1',
		'adaptive_nws_zone_id' => $legacyZone,
		'location' => $legacyLocation,
		'radius_miles' => (int)($config['radius_miles'] ?? 25),
		'extensions' => $legacyRecipients,
		'desktop_clients' => [],
		'email_recipients' => [],
		'all_clear' => (string)($config['all_clear'] ?? 'none'),
	]];
};
$lightningGroups = $viewGroups($xweather);
$activeLightningGroups = $viewGroups($activeXweather);
$testGroups = array_values(array_filter($activeLightningGroups, static function ($group) {
	return !empty($group['enabled']) && trim((string)($group['id'] ?? '')) !== '';
}));
$defaultTestGroupId = (string)($testGroups[0]['id'] ?? '');
$usageLimit = max(0, (int)($apiUsage['limit'] ?? 0));
$usageUsed = max(0, (int)($apiUsage['used'] ?? 0));
$usageRemaining = max(0, (int)($apiUsage['remaining'] ?? 0));
$usagePercent = $usageLimit > 0 ? min(100, round(($usageUsed / $usageLimit) * 100, 1)) : 0;
$usagePeriodState = (string)($apiUsage['period_state'] ?? ($usageLimit > 0 ? 'unknown' : 'unavailable'));
$usageSnapshotCurrent = !empty($apiUsage['snapshot_current']) && $usagePeriodState === 'current';
$usageReset = trim((string)($apiUsage['reset_at_formatted'] ?? $apiUsage['reset_at'] ?? ''));
$usageObserved = trim((string)($apiUsage['observed_at_formatted'] ?? $apiUsage['observed_at'] ?? ''));
$emailText = static function ($value) {
	return is_array($value) ? implode("\n", $value) : (string)$value;
};
?>
<style>
.sls-lightning-page { max-width: 1180px; margin: 0 auto; }
.sls-page-header { display:flex; justify-content:space-between; gap:20px; align-items:flex-start; margin-bottom:18px; }
.sls-card { background:#fff; border:1px solid #dfe5ec; border-radius:8px; box-shadow:0 2px 8px rgba(15,23,42,.05); margin-bottom:18px; overflow:hidden; }
.sls-card-header { padding:15px 18px; border-bottom:1px solid #e8edf2; background:#f8fafc; }
.sls-card-header h3 { margin:0; font-size:18px; }
.sls-card-header-flex { display:flex; align-items:center; justify-content:space-between; gap:14px; }
.sls-card-header-flex .sls-card-heading-copy { min-width:0; }
.sls-card-header-flex .sls-card-heading-copy small { display:block; margin-top:4px; color:#64748b; font-weight:400; }
.sls-card-body { padding:18px; }
.sls-lightning-preview { border-left:5px solid #f59e0b; background:#fffbeb; border-radius:6px; padding:15px 18px; font-size:16px; line-height:1.55; }
.sls-recipient-grid { max-height:240px; overflow:auto; border:1px solid #e5e7eb; border-radius:6px; padding:10px 12px; background:#fbfcfe; }
.sls-recipient-grid .checkbox { margin:5px 0; }
.sls-form-actions { position:sticky; bottom:0; z-index:5; padding:13px 16px; background:rgba(255,255,255,.96); border:1px solid #dfe5ec; border-radius:8px; box-shadow:0 -2px 10px rgba(15,23,42,.06); }
.sls-required-note { color:#64748b; font-size:13px; }
.sls-toggle { position:relative; display:inline-block; width:48px; height:26px; vertical-align:middle; margin-right:8px; }
.sls-toggle input { opacity:0; width:0; height:0; }
.sls-toggle-slider { position:absolute; inset:0; cursor:pointer; background:#cbd5e1; border-radius:26px; transition:.2s; }
.sls-toggle-slider:before { content:""; position:absolute; width:20px; height:20px; left:3px; top:3px; background:#fff; border-radius:50%; box-shadow:0 1px 3px rgba(0,0,0,.25); transition:.2s; }
.sls-toggle input:checked + .sls-toggle-slider { background:#6d28d9; }
.sls-toggle input:checked + .sls-toggle-slider:before { transform:translateX(22px); }
.sls-adaptive-card { border:1px solid #86efac; border-radius:8px; padding:13px 15px; background:#ecfdf5; color:#166534; transition:background-color .2s,border-color .2s,color .2s; }
.sls-adaptive-card.is-disabled { border-color:#fca5a5; background:#fef2f2; color:#991b1b; }
.sls-adaptive-card .sls-toggle { margin-bottom:0; }
.sls-adaptive-card .sls-toggle input:checked + .sls-toggle-slider { background:#16a34a; }
.sls-adaptive-state { display:inline-flex; align-items:center; gap:7px; vertical-align:middle; }
.sls-adaptive-copy { display:block; margin:8px 0 0; color:inherit; }
.sls-adaptive-divider { margin:25px 0 22px; border-top-color:#dbe3ec; }
.sls-adaptive-guidance { margin:7px 0 0; line-height:1.5; }
.sls-usage-status { display:flex; align-items:flex-start; gap:12px; padding:13px 15px; margin-bottom:14px; border:1px solid #bfdbfe; border-radius:7px; background:#eff6ff; color:#1e3a8a; }
.sls-usage-status.is-current { border-color:#86efac; background:#f0fdf4; color:#166534; }
.sls-usage-status.is-expired, .sls-usage-status.is-unknown { border-color:#fcd34d; background:#fffbeb; color:#92400e; }
.sls-usage-status i { margin-top:2px; font-size:17px; }
.sls-usage-status strong { display:block; margin-bottom:2px; }
.sls-usage-grid { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:14px; }
.sls-usage-stat { flex:1 1 160px; min-width:0; padding:14px; border:1px solid #e2e8f0; border-radius:7px; background:#fff; }
.sls-usage-stat-label { color:#64748b; font-size:12px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
.sls-usage-stat-value { display:block; margin-top:4px; color:#0f172a; font-size:24px; line-height:1.1; font-weight:700; }
.sls-usage-stat-value small { color:#64748b; font-size:13px; font-weight:400; }
.sls-quota-progress { height:12px; margin:7px 0 5px; overflow:hidden; border-radius:999px; background:#e2e8f0; }
.sls-quota-progress-bar { height:100%; min-width:0; border-radius:999px; background:#2563eb; transition:width .2s ease; }
.sls-quota-progress-bar.is-warning { background:#d97706; }
.sls-quota-progress-bar.is-danger { background:#dc2626; }
.sls-area-quota-note { margin:0 0 14px; color:#64748b; }
.sls-group-empty { padding:28px 18px; text-align:center; color:#64748b; border:1px dashed #cbd5e1; border-radius:7px; background:#f8fafc; }
.sls-group-summary-table { margin-bottom:0; }
.sls-group-summary-table td { vertical-align:middle !important; }
.sls-group-summary-table tr.is-disabled { color:#64748b; background:#f8fafc; }
.sls-group-summary-location { display:block; max-width:330px; color:#64748b; font-size:12px; word-break:break-word; }
.sls-group-counts { white-space:nowrap; }
.sls-lightning-modal .modal-dialog { width:min(1040px, calc(100% - 30px)); }
.sls-lightning-modal .modal-body { max-height:72vh; overflow:auto; background:#f8fafc; }
.sls-lightning-editor { padding:15px; margin-bottom:14px; border:1px solid #d7e0ea; border-radius:8px; background:#fff; box-shadow:0 1px 3px rgba(15,23,42,.04); }
.sls-lightning-editor-header { display:flex; align-items:center; justify-content:space-between; gap:12px; padding-bottom:10px; margin-bottom:12px; border-bottom:1px solid #edf1f5; }
.sls-lightning-editor-title { min-width:0; font-size:16px; }
.sls-lightning-editor-actions { display:flex; align-items:center; gap:8px; }
.sls-lightning-editor-actions select { width:auto; min-width:105px; }
.sls-editor-section-label { display:block; margin:4px 0 6px; color:#475569; font-size:12px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
.sls-missing-targets { margin:12px 0 0; }
.sls-test-area-list { max-height:180px; overflow:auto; padding:8px 12px; border:1px solid #e2e8f0; border-radius:7px; background:#fff; }
.sls-test-area-list .checkbox { margin:7px 0; }
.sls-test-pending-note { margin-top:8px; }
.sls-operation-status { display:flex; align-items:flex-start; gap:12px; margin:14px 0; padding:12px 14px; border:1px solid #bfdbfe; border-radius:8px; background:#eff6ff; color:#1e3a8a; box-shadow:0 1px 3px rgba(15,23,42,.04); }
.sls-operation-status .sls-operation-icon { display:inline-flex; flex:0 0 30px; width:30px; height:30px; align-items:center; justify-content:center; border-radius:50%; background:#dbeafe; }
.sls-operation-status .sls-operation-copy { min-width:0; line-height:1.35; }
.sls-operation-status .sls-operation-copy strong { display:block; margin-bottom:2px; }
.sls-operation-status .sls-operation-copy span { display:block; color:inherit; opacity:.88; }
.sls-operation-status.is-success { border-color:#86efac; background:#f0fdf4; color:#166534; }
.sls-operation-status.is-success .sls-operation-icon { background:#dcfce7; }
.sls-operation-status.is-error { border-color:#fca5a5; background:#fef2f2; color:#991b1b; }
.sls-operation-status.is-error .sls-operation-icon { background:#fee2e2; }
@media (max-width:767px) {
	.sls-page-header, .sls-card-header-flex, .sls-lightning-editor-header { display:block; }
	.sls-page-header form, .sls-card-header-flex .btn, .sls-lightning-editor-actions { margin-top:10px; }
	.sls-lightning-editor-actions { display:flex; justify-content:space-between; }
	.sls-lightning-modal .modal-dialog { width:auto; margin:10px; }
	.sls-lightning-modal .modal-body { max-height:76vh; }
	.sls-usage-stat { flex-basis:calc(50% - 6px); }
	.sls-group-summary-table { min-width:760px; }
}
@media (max-width:480px) { .sls-usage-stat { flex-basis:100%; } }
</style>
<div class="container-fluid sls-lightning-page">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
	<div class="sls-page-header">
		<div>
			<h1><i class="fa fa-bolt text-warning" aria-hidden="true"></i> <?php echo _('Lightning Alerts'); ?></h1>
			<p class="text-muted"><?php echo _('Alert once when a lightning cluster enters the configured radius. Additional strikes from the same active cluster do not repeat the alert.'); ?></p>
		</div>
	</div>

	<?php foreach ([['value' => $save_result ?? null], ['value' => $apply_result ?? null], ['value' => $test_result ?? null], ['value' => $connection_result ?? null]] as $notice) { if (is_array($notice['value'])) { $result = $notice['value']; ?>
	<div class="alert alert-<?php echo !empty($result['success']) ? 'success' : 'warning'; ?>">
		<?php echo htmlspecialchars((string)($result['message'] ?? '')); ?>
		<?php if (!empty($result['errors'])) { ?><ul><?php foreach ((array)$result['errors'] as $error) { ?><li><?php echo htmlspecialchars($error); ?></li><?php } ?></ul><?php } ?>
	</div>
	<?php }} ?>

	<div class="sls-card">
		<div class="sls-card-header"><h3><i class="fa fa-volume-up text-warning"></i> <?php echo _('Lightning Alert Test'); ?></h3></div>
		<div class="sls-card-body">
			<div class="sls-lightning-preview" id="sls-lightning-preview">
				<strong><?php echo _('Simulated delivery only'); ?></strong><br>
				<span><?php echo _('Every selected area receives a clearly labeled test through its applied phone and desktop routes. No actual lightning event is reported.'); ?></span>
			</div>
			<p class="help-block"><?php echo _('Choose the applied area routes you intend to exercise. Tests never send area email, Discord, or generic webhooks. A 60-second cooldown prevents repeated sends.'); ?></p>
			<div id="sls-lightning-test-result" class="sls-operation-status" style="display:none" role="status" aria-live="polite">
				<span class="sls-operation-icon"><i data-lightning-status-icon class="fa fa-circle-o" aria-hidden="true"></i></span>
				<span class="sls-operation-copy"><strong data-lightning-status-title></strong><span data-lightning-status-message></span></span>
			</div>
			<form id="sls-lightning-test-form" method="post" action="config.php?display=slsmassnotifyserver_lightning">
				<input type="hidden" name="slsmassnotifyserver_action" value="test_lightning">
				<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
				<input type="hidden" name="ajax" value="1">
				<div class="form-group">
					<label><?php echo _('Applied trigger areas to test'); ?></label>
					<div class="sls-test-area-list" id="sls-lightning-test-areas">
					<?php if (!$testGroups) { ?>
						<div class="text-muted"><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo _('No enabled, applied Lightning trigger area is available. Save and apply at least one complete area before testing.'); ?></div>
					<?php } else { foreach ($testGroups as $testGroup) { $testGroupId = (string)($testGroup['id'] ?? ''); ?>
						<div class="checkbox"><label><input type="checkbox" name="lightning_group_ids[]" value="<?php echo htmlspecialchars($testGroupId); ?>" <?php echo $testGroupId === $defaultTestGroupId ? 'checked' : ''; ?>> <strong><?php echo htmlspecialchars((string)($testGroup['name'] ?? _('Lightning Area'))); ?></strong> <span class="text-muted">— <?php echo htmlspecialchars((string)($testGroup['location'] ?? '')); ?> · <?php echo (int)($testGroup['radius_miles'] ?? 25); ?> <?php echo _('mi'); ?></span></label></div>
					<?php }} ?>
					</div>
					<div class="text-danger sls-test-pending-note" id="sls-lightning-test-selection-error" style="display:none" role="alert"><?php echo _('Select at least one applied trigger area.'); ?></div>
					<?php if (!empty($has_pending_changes)) { ?><p class="help-block sls-test-pending-note"><i class="fa fa-clock-o" aria-hidden="true"></i> <?php echo _('A saved configuration is pending. Manual tests continue to use the currently applied area routes until changes are applied.'); ?></p><?php } ?>
				</div>
				<button class="btn btn-warning" id="sls-lightning-test-submit" type="submit" <?php echo ($cooldownRemaining > 0 || !$testGroups) ? 'disabled' : ''; ?>><i class="fa fa-bolt"></i> <?php echo _('Send Lightning Test'); ?></button>
				<span class="text-muted" id="sls-lightning-test-cooldown" data-remaining="<?php echo $cooldownRemaining; ?>" style="margin-left:10px"><?php echo $cooldownRemaining > 0 ? sprintf(_('Available again in %d seconds'), $cooldownRemaining) : ''; ?></span>
			</form>
		</div>
	</div>

	<div class="sls-card">
		<div class="sls-card-header sls-card-header-flex">
			<div class="sls-card-heading-copy"><h3><i class="fa fa-plug text-primary" aria-hidden="true"></i> <?php echo _('Xweather API Connection & Usage'); ?></h3><small><?php echo _('Provider usage is measured in cost tokens/hits, not simply HTTP request count.'); ?></small></div>
			<form method="post" action="config.php?display=slsmassnotifyserver_lightning">
				<input type="hidden" name="slsmassnotifyserver_action" value="verify_lightning_connection">
				<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
				<button class="btn btn-primary" type="submit"><i class="fa fa-check-circle" aria-hidden="true"></i> <?php echo _('Verify Applied Areas'); ?></button>
			</form>
		</div>
		<div class="sls-card-body">
			<p><?php echo _('Validate the applied client ID, protected client secret, and every enabled Lightning trigger area using its selected strike type, without sending an alert. Verification updates Dashboard health and refreshes the provider usage snapshot, but it also makes an additional query for each enabled area.'); ?></p>
			<?php if ($usageSnapshotCurrent) { ?>
				<div class="sls-usage-status is-current"><i class="fa fa-check-circle" aria-hidden="true"></i><div><strong><?php echo _('Current provider period · last API snapshot'); ?></strong><?php echo $usageReset !== '' ? htmlspecialchars(sprintf(_('Provider reports that this account period resets %s.'), $usageReset)) : _('The provider reports a current account period.'); ?><?php if ($usageObserved !== '') { ?> <span class="text-muted"><?php echo htmlspecialchars(sprintf(_('Observed %s.'), $usageObserved)); ?></span><?php } ?></div></div>
			<?php } elseif ($usagePeriodState === 'expired') { ?>
				<div class="sls-usage-status is-expired"><i class="fa fa-history" aria-hidden="true"></i><div><strong><?php echo _('Previous provider period · historical snapshot'); ?></strong><?php echo $usageReset !== '' ? htmlspecialchars(sprintf(_('This reported period ended %s.'), $usageReset)) . ' ' : ''; ?><?php echo _('The counters below are not presented as current. They refresh on the next successful storm query or when you verify the connection.'); ?><?php if ($usageObserved !== '') { ?> <span class="text-muted"><?php echo htmlspecialchars(sprintf(_('Last observed %s.'), $usageObserved)); ?></span><?php } ?></div></div>
			<?php } elseif ($usagePeriodState === 'unknown') { ?>
				<div class="sls-usage-status is-unknown"><i class="fa fa-question-circle" aria-hidden="true"></i><div><strong><?php echo _('Last API snapshot · period timing unavailable'); ?></strong><?php echo _('Xweather returned usage counters, but the reset time could not be verified. Use Verify Applied Areas to request a fresh snapshot.'); ?><?php if ($usageObserved !== '') { ?> <span class="text-muted"><?php echo htmlspecialchars(sprintf(_('Last observed %s.'), $usageObserved)); ?></span><?php } ?></div></div>
			<?php } else { ?>
				<div class="sls-usage-status"><i class="fa fa-info-circle" aria-hidden="true"></i><div><strong><?php echo _('No provider usage snapshot yet'); ?></strong><?php echo _('Run Verify Applied Areas or wait for the next storm query to populate token counters and period timing.'); ?></div></div>
			<?php } ?>

			<?php if ($usageLimit > 0) { ?>
			<div class="sls-usage-grid" aria-label="<?php echo htmlspecialchars(_('Xweather token usage snapshot')); ?>">
				<div class="sls-usage-stat"><span class="sls-usage-stat-label"><?php echo $usageSnapshotCurrent ? _('Current tokens used') : _('Historical tokens used'); ?></span><span class="sls-usage-stat-value"><?php echo number_format($usageUsed); ?></span></div>
				<div class="sls-usage-stat"><span class="sls-usage-stat-label"><?php echo $usageSnapshotCurrent ? _('Current tokens remaining') : _('Historical tokens remaining'); ?></span><span class="sls-usage-stat-value"><?php echo number_format($usageRemaining); ?></span></div>
				<div class="sls-usage-stat"><span class="sls-usage-stat-label"><?php echo $usageSnapshotCurrent ? _('Current period allowance') : _('Historical period allowance'); ?></span><span class="sls-usage-stat-value"><?php echo number_format($usageLimit); ?> <small><?php echo _('tokens'); ?></small></span></div>
				<div class="sls-usage-stat"><span class="sls-usage-stat-label"><?php echo _('Snapshot usage'); ?></span><div class="sls-quota-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $usagePercent; ?>"><div class="sls-quota-progress-bar <?php echo $usagePercent >= 90 ? 'is-danger' : ($usagePercent >= 70 ? 'is-warning' : ''); ?>" style="width:<?php echo $usagePercent; ?>%"></div></div><strong><?php echo $usagePercent; ?>%</strong> <span class="text-muted"><?php echo _('used'); ?></span></div>
			</div>
			<?php } ?>

		</div>
	</div>

	<form method="post" action="config.php?display=slsmassnotifyserver_lightning">
		<input type="hidden" name="slsmassnotifyserver_action" value="save_lightning_settings">
		<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
		<input type="hidden" name="xweather[groups_present]" value="1">

		<div class="sls-card">
			<div class="sls-card-header"><h3><i class="fa fa-cogs text-primary" aria-hidden="true"></i> <?php echo _('Service & Polling'); ?></h3></div>
			<div class="sls-card-body">
				<div class="row">
					<div class="col-md-3"><div class="form-group"><label><?php echo _('Lightning service'); ?></label><select class="form-control" name="xweather[enabled]"><option value="0" <?php echo empty($xweather['enabled']) ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option><option value="1" <?php echo !empty($xweather['enabled']) ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option></select><p class="help-block"><?php echo _('This master switch controls every trigger area.'); ?></p></div></div>
					<div class="col-md-3"><div class="form-group"><label for="sls-lightning-query-interval"><?php echo _('API query period'); ?></label><select class="form-control" id="sls-lightning-query-interval" name="xweather[query_interval_minutes]"><?php for ($minutes = 1; $minutes <= 10; $minutes++) { ?><option value="<?php echo $minutes; ?>" <?php echo (int)($xweather['query_interval_minutes'] ?? 5) === $minutes ? 'selected' : ''; ?>><?php echo $minutes <= 4 ? '&#9888; ' : ''; ?><?php echo sprintf(_('%d minute(s)'), $minutes); ?></option><?php } ?></select><p class="help-block text-danger" id="sls-lightning-fast-poll-warning" style="<?php echo (int)($xweather['query_interval_minutes'] ?? 5) <= 4 ? '' : 'display:none;'; ?>"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?php echo _('The standard allowance may not sustain this polling frequency.'); ?></p></div></div>
					<div class="col-md-3"><div class="form-group"><label for="sls-lightning-grace"><?php echo _('Storm-mode grace period'); ?></label><select class="form-control" id="sls-lightning-grace" name="xweather[adaptive_grace_minutes]"><?php foreach ([5, 10, 15, 30, 45, 60, 90, 120] as $grace) { ?><option value="<?php echo $grace; ?>" <?php echo (int)($xweather['adaptive_grace_minutes'] ?? 60) === $grace ? 'selected' : ''; ?>><?php echo sprintf(_('%d minutes'), $grace); ?></option><?php } ?></select><p class="help-block"><?php echo _('Default 60 minutes after each trigger zone clears.'); ?></p></div></div>
				</div>
				<p class="sls-required-note"><?php echo _('Each area can monitor cloud-to-ground strikes, cloud-to-cloud strikes, or both. A matching cluster creates one alert per area when it first appears inside that radius and must clear before another alert. Standard Xweather lightning data covers the past 5 minutes, so 5 minutes is the longest gap-free query period. Selecting 6–10 minutes can miss strikes unless your subscription includes extended lightning history.'); ?></p>
				<hr class="sls-adaptive-divider">
				<div class="row"><div class="col-md-7"><div class="form-group"><label for="sls-lightning-adaptive"><?php echo _('Adaptive protection'); ?></label><div id="sls-lightning-adaptive-card" class="sls-adaptive-card <?php echo $adaptiveFreeTier ? 'is-enabled' : 'is-disabled'; ?>"><input type="hidden" name="xweather[adaptive_free_tier]" value="0"><label class="sls-toggle" aria-label="<?php echo htmlspecialchars(_('Toggle adaptive protection')); ?>"><input id="sls-lightning-adaptive" type="checkbox" name="xweather[adaptive_free_tier]" value="1" <?php echo $adaptiveFreeTier ? 'checked' : ''; ?>><span class="sls-toggle-slider"></span></label><span class="sls-adaptive-state"><i id="sls-lightning-adaptive-shield" class="fa fa-shield" aria-hidden="true"></i><strong id="sls-lightning-adaptive-label"></strong></span><p class="sls-adaptive-copy"><?php echo _('Enabled polls each area only while that area’s selected Weather.gov trigger zone indicates storm activity, plus the grace period. Disabled polls every enabled Xweather area continuously.'); ?></p></div></div></div></div>
				<div class="alert alert-warning sls-adaptive-guidance"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <strong><?php echo _('Forecast-aware adaptive protection:'); ?></strong> <?php echo _('each area opens Xweather polling for a qualifying current Weather.gov alert or when thunder is indicated in the structured forecast period active at that time, then continues through the configured grace period. A later thunder period does not spend Xweather tokens before its start. This protects quota but can miss unexpected lightning. Multiple active areas can consume multiple queries in the same cycle, and the quota governor may pause polling when its protected budget is depleted.'); ?></div>
			</div>
		</div>

		<div class="sls-card">
			<div class="sls-card-header sls-card-header-flex">
				<div class="sls-card-heading-copy"><h3><i class="fa fa-map-marker text-danger" aria-hidden="true"></i> <?php echo _('Lightning Trigger Areas'); ?></h3><small><?php echo _('Up to five independently routed areas, each with its own Weather.gov trigger, Xweather radius, and recipients.'); ?></small></div>
				<button type="button" class="btn btn-primary" data-toggle="modal" data-target="#sls-lightning-group-manager"><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo _('Manage Trigger Areas'); ?></button>
			</div>
			<div class="sls-card-body">
				<p class="sls-area-quota-note"><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo _('Each enabled area uses its own Xweather query while active, so multiple active areas use tokens faster.'); ?></p>
				<?php if (!$lightningGroups) { ?>
					<div class="sls-group-empty" style="margin-top:14px"><i class="fa fa-map-o fa-2x" aria-hidden="true"></i><br><strong><?php echo _('No Lightning trigger areas configured'); ?></strong><br><?php echo _('Use Manage Trigger Areas to add the first location and its delivery routes.'); ?></div>
				<?php } else { ?>
					<div class="table-responsive" style="margin-top:14px"><table class="table table-striped sls-group-summary-table"><thead><tr><th><?php echo _('Area'); ?></th><th><?php echo _('Weather.gov trigger'); ?></th><th><?php echo _('Radius'); ?></th><th><?php echo _('Delivery routes'); ?></th><th><?php echo _('All clear'); ?></th></tr></thead><tbody>
					<?php foreach ($lightningGroups as $groupIndex => $group) {
						$groupEnabled = !empty($group['enabled']);
						$groupZoneId = (string)($group['adaptive_nws_zone_id'] ?? '');
						$groupExtensions = (array)($group['extensions'] ?? []);
						$groupDesktops = (array)($group['desktop_clients'] ?? []);
						$groupEmails = is_array($group['email_recipients'] ?? null) ? $group['email_recipients'] : preg_split('/[\s,;]+/', trim((string)($group['email_recipients'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
					?>
						<tr class="<?php echo $groupEnabled ? '' : 'is-disabled'; ?>">
							<td><span class="label label-<?php echo $groupEnabled ? 'success' : 'default'; ?>"><?php echo $groupEnabled ? _('Enabled') : _('Disabled'); ?></span> <strong><?php echo htmlspecialchars(trim((string)($group['name'] ?? '')) ?: sprintf(_('Lightning Area %d'), $groupIndex + 1)); ?></strong><span class="sls-group-summary-location"><?php echo htmlspecialchars((string)($group['location'] ?? '')); ?></span></td>
							<td><?php if ($groupZoneId !== '' && isset($weatherZoneNames[$groupZoneId])) { echo htmlspecialchars($weatherZoneNames[$groupZoneId]); } elseif ($groupZoneId !== '') { ?><span class="text-danger"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?php echo _('Saved zone is unavailable'); ?></span><?php } else { ?><span class="text-danger"><?php echo _('Not selected'); ?></span><?php } ?></td>
							<td><?php echo (int)($group['radius_miles'] ?? 25); ?> <?php echo _('mi'); ?></td>
							<td class="sls-group-counts"><i class="fa fa-phone" title="<?php echo htmlspecialchars(_('Phones')); ?>" aria-hidden="true"></i> <?php echo count($groupExtensions); ?> &nbsp; <i class="fa fa-desktop" title="<?php echo htmlspecialchars(_('Desktops')); ?>" aria-hidden="true"></i> <?php echo count($groupDesktops); ?> &nbsp; <i class="fa fa-envelope" title="<?php echo htmlspecialchars(_('Area email')); ?>" aria-hidden="true"></i> <?php echo count($groupEmails); ?></td>
							<td><?php echo ($group['all_clear'] ?? 'none') === 'send' ? _('Send') : _('None'); ?></td>
						</tr>
					<?php } ?>
					</tbody></table></div>
				<?php } ?>
			</div>
		</div>

		<div class="modal fade sls-lightning-modal" id="sls-lightning-group-manager" tabindex="-1" role="dialog" aria-labelledby="sls-lightning-group-manager-title" aria-hidden="true">
			<div class="modal-dialog"><div class="modal-content">
				<div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="<?php echo htmlspecialchars(_('Close')); ?>"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="sls-lightning-group-manager-title"><i class="fa fa-map-marker text-danger" aria-hidden="true"></i> <?php echo _('Manage Lightning Trigger Areas'); ?></h4></div>
				<div class="modal-body">
					<div class="alert alert-warning"><i class="fa fa-info-circle" aria-hidden="true"></i> <strong><?php echo _('Token usage'); ?></strong> <?php echo _('You can configure up to five areas. Multiple areas under active storm triggers can query Xweather concurrently and consume the account allowance faster.'); ?></div>
					<?php if (!$weatherZones) { ?><div class="alert alert-danger"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> <?php echo _('No Weather.gov zone groups are configured. Create the needed zone in Weather Alerts before enabling adaptive Lightning areas.'); ?> <a class="alert-link" href="config.php?display=slsmassnotifyserver_nws" target="_blank" rel="noopener noreferrer"><?php echo _('Open Weather Alerts'); ?> <i class="fa fa-external-link" aria-hidden="true"></i></a></div><?php } ?>
					<div id="sls-lightning-editor-list">
					<?php foreach ($lightningGroups as $groupIndex => $group) {
						$groupRecipients = array_fill_keys(array_map('strval', (array)($group['extensions'] ?? [])), true);
						$groupDesktopRecipients = array_fill_keys(array_map('strval', (array)($group['desktop_clients'] ?? [])), true);
						$unknownGroupRecipients = array_diff_key($groupRecipients, $extensionNames);
						$unknownGroupDesktops = array_diff_key($groupDesktopRecipients, $desktopNames);
						$groupZoneId = (string)($group['adaptive_nws_zone_id'] ?? '');
					?>
						<div class="sls-lightning-editor" data-lightning-editor>
							<div class="sls-lightning-editor-header"><strong class="sls-lightning-editor-title" data-lightning-title><?php echo htmlspecialchars(trim((string)($group['name'] ?? '')) ?: sprintf(_('Lightning Area %d'), $groupIndex + 1)); ?></strong><div class="sls-lightning-editor-actions"><select class="form-control input-sm" data-lightning-field="enabled" aria-label="<?php echo htmlspecialchars(_('Area status')); ?>"><option value="1" <?php echo !empty($group['enabled']) ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option><option value="0" <?php echo empty($group['enabled']) ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option></select><button type="button" class="btn btn-link btn-sm text-danger" data-lightning-remove><i class="fa fa-trash" aria-hidden="true"></i> <?php echo _('Remove'); ?></button></div></div>
							<input type="hidden" data-lightning-field="id" value="<?php echo htmlspecialchars((string)($group['id'] ?? '')); ?>">
							<div class="row">
								<div class="col-md-6"><div class="form-group"><label><?php echo _('Area Name'); ?></label><input class="form-control" data-lightning-field="name" maxlength="64" value="<?php echo htmlspecialchars((string)($group['name'] ?? '')); ?>" placeholder="<?php echo htmlspecialchars(_('North Campus')); ?>"></div></div>
								<div class="col-md-6"><div class="form-group"><label><?php echo _('Weather.gov Adaptive Trigger Zone'); ?></label><select class="form-control" data-lightning-field="adaptive_nws_zone_id"><option value=""><?php echo _('Select a configured Weather zone group'); ?></option><?php if ($groupZoneId !== '' && !isset($weatherZoneNames[$groupZoneId])) { ?><option value="<?php echo htmlspecialchars($groupZoneId); ?>" selected><?php echo htmlspecialchars(sprintf(_('Unavailable saved zone (%s) — reselect to repair'), $groupZoneId)); ?></option><?php } ?><?php foreach ($weatherZoneNames as $zoneId => $zoneLabel) { ?><option value="<?php echo htmlspecialchars($zoneId); ?>" <?php echo $groupZoneId === $zoneId ? 'selected' : ''; ?>><?php echo htmlspecialchars($zoneLabel); ?></option><?php } ?></select><p class="help-block"><?php echo _('This selects a configured Weather Alerts group, not a location guessed from the area name.'); ?> <a href="config.php?display=slsmassnotifyserver_nws" target="_blank" rel="noopener noreferrer"><?php echo _('Manage Weather zones'); ?> <i class="fa fa-external-link" aria-hidden="true"></i></a></p></div></div>
							</div>
							<div class="row">
								<div class="col-md-6"><div class="form-group"><label><?php echo _('Xweather Location'); ?></label><input class="form-control" data-lightning-field="location" maxlength="120" value="<?php echo htmlspecialchars((string)($group['location'] ?? '')); ?>" placeholder="30.5083,-97.6789 or Round Rock, TX"><p class="help-block"><?php echo _('Center point for this area’s Xweather lightning query. Coordinates are the most precise.'); ?></p></div></div>
								<div class="col-md-2"><div class="form-group"><label><?php echo _('Radius'); ?></label><div class="input-group"><input class="form-control" data-lightning-field="radius_miles" type="number" min="1" max="62" value="<?php echo (int)($group['radius_miles'] ?? 25); ?>"><span class="input-group-addon"><?php echo _('mi'); ?></span></div></div></div>
								<div class="col-md-2"><div class="form-group"><label><?php echo _('Strike type'); ?></label><select class="form-control" data-lightning-field="strike_type"><option value="cloud_to_ground" <?php echo ($group['strike_type']??'cloud_to_ground')==='cloud_to_ground'?'selected':''; ?>><?php echo _('Cloud to ground'); ?></option><option value="cloud_to_cloud" <?php echo ($group['strike_type']??'')==='cloud_to_cloud'?'selected':''; ?>><?php echo _('Cloud to cloud'); ?></option><option value="both" <?php echo ($group['strike_type']??'')==='both'?'selected':''; ?>><?php echo _('Both'); ?></option></select></div></div>
								<div class="col-md-2"><div class="form-group"><label><?php echo _('After clear'); ?></label><select class="form-control" data-lightning-field="all_clear"><option value="none" <?php echo ($group['all_clear'] ?? 'none') === 'none' ? 'selected' : ''; ?>><?php echo _('Do nothing'); ?></option><option value="send" <?php echo ($group['all_clear'] ?? 'none') === 'send' ? 'selected' : ''; ?>><?php echo _('Send all clear'); ?></option></select></div></div>
							</div>
							<div class="row"><div class="col-md-4"><div class="form-group"><label><?php echo _('Area quiet hours'); ?></label><select class="form-control" data-lightning-field="quiet_hours_enabled"><option value="0" <?php echo empty($group['quiet_hours_enabled'])?'selected':''; ?>><?php echo _('Disabled'); ?></option><option value="1" <?php echo !empty($group['quiet_hours_enabled'])?'selected':''; ?>><?php echo _('Enabled'); ?></option></select></div></div><div class="col-md-4"><div class="form-group"><label><?php echo _('Start'); ?></label><input class="form-control" type="time" data-lightning-field="quiet_hours_start" value="<?php echo htmlspecialchars((string)($group['quiet_hours_start']??'21:00')); ?>"></div></div><div class="col-md-4"><div class="form-group"><label><?php echo _('End'); ?></label><input class="form-control" type="time" data-lightning-field="quiet_hours_end" value="<?php echo htmlspecialchars((string)($group['quiet_hours_end']??'06:00')); ?>"></div></div></div>
							<span class="sls-editor-section-label"><?php echo _('Area delivery routes'); ?></span>
							<div class="row">
								<div class="col-md-6"><label><?php echo _('Phone Extensions'); ?></label><div class="sls-recipient-grid"><div class="row"><?php foreach ($extensions as $extension) { $number = (string)($extension['extension'] ?? ''); ?><div class="col-sm-6"><div class="checkbox"><label><input type="checkbox" data-lightning-extension value="<?php echo htmlspecialchars($number); ?>" <?php echo isset($groupRecipients[$number]) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($number . (trim((string)($extension['name'] ?? '')) !== '' ? ' - ' . (string)$extension['name'] : '')); ?> <span class="text-muted"><?php echo !empty($extension['registered']) ? _('online') : _('offline'); ?></span></label></div></div><?php } ?><?php if (!$extensions) { ?><div class="col-xs-12 text-muted"><?php echo _('No PJSIP extensions are configured.'); ?></div><?php } ?></div></div></div>
								<div class="col-md-6"><label><?php echo _('Desktop Clients'); ?></label><div class="sls-recipient-grid"><div class="row"><?php foreach ($desktopClients as $desktopClient) { $username = (string)($desktopClient['username'] ?? ''); $desktopEnabled = !empty($desktopClient['enabled']); $desktopSelected = isset($groupDesktopRecipients[$username]); ?><div class="col-sm-6"><div class="checkbox"><label><input type="checkbox" data-lightning-desktop value="<?php echo htmlspecialchars($username); ?>" <?php echo $desktopSelected ? 'checked' : ''; ?> <?php echo (!$desktopEnabled && !$desktopSelected) ? 'disabled' : ''; ?>> <?php echo htmlspecialchars($desktopNames[$username] ?? $username); ?> <span class="text-muted"><?php echo $desktopEnabled ? _('enabled') : ($desktopSelected ? _('disabled — uncheck to remove') : _('disabled')); ?></span></label></div></div><?php } ?><?php if (!$desktopClients) { ?><div class="col-xs-12 text-muted"><?php echo _('No desktop clients are configured in General Settings.'); ?></div><?php } ?></div></div></div>
							</div>
							<?php if ($unknownGroupRecipients || $unknownGroupDesktops) { ?><div class="alert alert-warning sls-missing-targets"><strong><i class="fa fa-unlink" aria-hidden="true"></i> <?php echo _('Unavailable saved assignments'); ?></strong><p><?php echo _('These missing targets stay visible and selected so saving cannot silently discard them. Uncheck an assignment to remove it intentionally.'); ?></p><?php foreach (array_keys($unknownGroupRecipients) as $unknownExtension) { ?><div class="checkbox"><label><input type="checkbox" data-lightning-extension value="<?php echo htmlspecialchars($unknownExtension); ?>" checked> <?php echo htmlspecialchars($unknownExtension); ?> <span class="text-muted"><?php echo _('missing phone — uncheck to remove'); ?></span></label></div><?php } ?><?php foreach (array_keys($unknownGroupDesktops) as $unknownDesktop) { ?><div class="checkbox"><label><input type="checkbox" data-lightning-desktop value="<?php echo htmlspecialchars($unknownDesktop); ?>" checked> <?php echo htmlspecialchars($unknownDesktop); ?> <span class="text-muted"><?php echo _('missing desktop — uncheck to remove'); ?></span></label></div><?php } ?></div><?php } ?>
							<div class="form-group" style="margin-top:12px"><label><?php echo _('Email Recipients for This Area'); ?></label><textarea class="form-control" data-lightning-email rows="2" maxlength="4096" placeholder="lightning-team@example.com"><?php echo htmlspecialchars($emailText($group['email_recipients'] ?? [])); ?></textarea><p class="help-block"><?php echo _('Optional. Only live alerts from this area use these addresses. Up to 50 unique addresses are allowed per area. Manual tests never send email.'); ?></p></div>
						</div>
					<?php } ?>
					</div>
					<button type="button" class="btn btn-default" id="sls-lightning-add"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add Trigger Area'); ?></button> <span class="text-muted" id="sls-lightning-count"></span>
				</div>
				<div class="modal-footer"><span class="pull-left text-muted" style="padding-top:7px"><?php echo _('Done closes this editor; Save Lightning Configuration persists the changes.'); ?></span><button type="button" class="btn btn-primary" data-dismiss="modal"><?php echo _('Done'); ?></button></div>
			</div></div>
		</div>

		<script type="text/template" id="sls-lightning-template">
			<div class="sls-lightning-editor" data-lightning-editor>
				<div class="sls-lightning-editor-header"><strong class="sls-lightning-editor-title" data-lightning-title><?php echo _('New Lightning Area'); ?></strong><div class="sls-lightning-editor-actions"><select class="form-control input-sm" data-lightning-field="enabled" aria-label="<?php echo htmlspecialchars(_('Area status')); ?>"><option value="1" selected><?php echo _('Enabled'); ?></option><option value="0"><?php echo _('Disabled'); ?></option></select><button type="button" class="btn btn-link btn-sm text-danger" data-lightning-remove><i class="fa fa-trash" aria-hidden="true"></i> <?php echo _('Remove'); ?></button></div></div>
				<input type="hidden" data-lightning-field="id" value="">
				<div class="row"><div class="col-md-6"><div class="form-group"><label><?php echo _('Area Name'); ?></label><input class="form-control" data-lightning-field="name" maxlength="64" placeholder="<?php echo htmlspecialchars(_('North Campus')); ?>"></div></div><div class="col-md-6"><div class="form-group"><label><?php echo _('Weather.gov Adaptive Trigger Zone'); ?></label><select class="form-control" data-lightning-field="adaptive_nws_zone_id"><option value=""><?php echo _('Select a configured Weather zone group'); ?></option><?php foreach ($weatherZoneNames as $zoneId => $zoneLabel) { ?><option value="<?php echo htmlspecialchars($zoneId); ?>"><?php echo htmlspecialchars($zoneLabel); ?></option><?php } ?></select><p class="help-block"><?php echo _('Select a verified group from Weather Alerts.'); ?> <a href="config.php?display=slsmassnotifyserver_nws" target="_blank" rel="noopener noreferrer"><?php echo _('Manage Weather zones'); ?> <i class="fa fa-external-link" aria-hidden="true"></i></a></p></div></div></div>
				<div class="row"><div class="col-md-5"><div class="form-group"><label><?php echo _('Xweather Location'); ?></label><input class="form-control" data-lightning-field="location" maxlength="120" placeholder="30.5083,-97.6789 or Round Rock, TX"></div></div><div class="col-md-2"><div class="form-group"><label><?php echo _('Radius'); ?></label><input class="form-control" data-lightning-field="radius_miles" type="number" min="1" max="62" value="25"></div></div><div class="col-md-3"><div class="form-group"><label><?php echo _('Strike type'); ?></label><select class="form-control" data-lightning-field="strike_type"><option value="cloud_to_ground" selected><?php echo _('Cloud to ground'); ?></option><option value="cloud_to_cloud"><?php echo _('Cloud to cloud'); ?></option><option value="both"><?php echo _('Both'); ?></option></select></div></div><div class="col-md-2"><div class="form-group"><label><?php echo _('After clear'); ?></label><select class="form-control" data-lightning-field="all_clear"><option value="none" selected><?php echo _('None'); ?></option><option value="send"><?php echo _('Send'); ?></option></select></div></div></div>
				<div class="row"><div class="col-md-4"><div class="form-group"><label><?php echo _('Area quiet hours'); ?></label><select class="form-control" data-lightning-field="quiet_hours_enabled"><option value="0" selected><?php echo _('Disabled'); ?></option><option value="1"><?php echo _('Enabled'); ?></option></select></div></div><div class="col-md-4"><div class="form-group"><label><?php echo _('Start'); ?></label><input class="form-control" type="time" data-lightning-field="quiet_hours_start" value="21:00"></div></div><div class="col-md-4"><div class="form-group"><label><?php echo _('End'); ?></label><input class="form-control" type="time" data-lightning-field="quiet_hours_end" value="06:00"></div></div></div>
				<span class="sls-editor-section-label"><?php echo _('Area delivery routes'); ?></span>
				<div class="row"><div class="col-md-6"><label><?php echo _('Phone Extensions'); ?></label><div class="sls-recipient-grid"><div class="row"><?php foreach ($extensions as $extension) { $number = (string)($extension['extension'] ?? ''); ?><div class="col-sm-6"><div class="checkbox"><label><input type="checkbox" data-lightning-extension value="<?php echo htmlspecialchars($number); ?>"> <?php echo htmlspecialchars($number . (trim((string)($extension['name'] ?? '')) !== '' ? ' - ' . (string)$extension['name'] : '')); ?> <span class="text-muted"><?php echo !empty($extension['registered']) ? _('online') : _('offline'); ?></span></label></div></div><?php } ?><?php if (!$extensions) { ?><div class="col-xs-12 text-muted"><?php echo _('No PJSIP extensions are configured.'); ?></div><?php } ?></div></div></div><div class="col-md-6"><label><?php echo _('Desktop Clients'); ?></label><div class="sls-recipient-grid"><div class="row"><?php foreach ($desktopClients as $desktopClient) { $username = (string)($desktopClient['username'] ?? ''); $desktopEnabled = !empty($desktopClient['enabled']); ?><div class="col-sm-6"><div class="checkbox"><label><input type="checkbox" data-lightning-desktop value="<?php echo htmlspecialchars($username); ?>" <?php echo !$desktopEnabled ? 'disabled' : ''; ?>> <?php echo htmlspecialchars($desktopNames[$username] ?? $username); ?> <span class="text-muted"><?php echo $desktopEnabled ? _('enabled') : _('disabled'); ?></span></label></div></div><?php } ?><?php if (!$desktopClients) { ?><div class="col-xs-12 text-muted"><?php echo _('No desktop clients are configured in General Settings.'); ?></div><?php } ?></div></div></div></div>
				<div class="form-group" style="margin-top:12px"><label><?php echo _('Email Recipients for This Area'); ?></label><textarea class="form-control" data-lightning-email rows="2" maxlength="4096" placeholder="lightning-team@example.com"></textarea><p class="help-block"><?php echo _('Optional. Only live alerts from this area use these addresses. Up to 50 unique addresses are allowed per area. Manual tests never send email.'); ?></p></div>
			</div>
		</script>

		<div class="sls-card">
			<div class="sls-card-header"><h3><i class="fa fa-key text-muted" aria-hidden="true"></i> <?php echo _('Xweather API Login'); ?></h3></div>
			<div class="sls-card-body"><div class="row">
				<div class="col-md-6"><div class="form-group"><label><?php echo _('Client ID'); ?></label><input class="form-control" name="xweather[client_id]" value="<?php echo htmlspecialchars($xweather['client_id'] ?? ''); ?>" autocomplete="off"></div></div>
				<div class="col-md-6"><div class="form-group"><label><?php echo _('Client Secret'); ?></label><div class="input-group"><input class="form-control" id="sls-xweather-client-secret" type="password" name="xweather[client_secret]" value="<?php echo htmlspecialchars($xweather['client_secret'] ?? ''); ?>" autocomplete="new-password"><span class="input-group-btn"><button type="button" class="btn btn-default" id="sls-xweather-secret-toggle" title="<?php echo htmlspecialchars(_('Show or hide client secret')); ?>" aria-label="<?php echo htmlspecialchars(_('Show or hide client secret')); ?>"><i class="fa fa-eye" aria-hidden="true"></i></button></span></div><p class="help-block"><?php echo _('The saved value stays masked until you select the eye button.'); ?></p></div></div>
			</div><p class="help-block"><?php echo _('Credentials stay in the protected central configuration and are redacted from diagnostics and the Control API.'); ?> <a href="https://www.xweather.com/docs/weather-api/getting-started" target="_blank" rel="noopener noreferrer"><i class="fa fa-external-link" aria-hidden="true"></i> <?php echo _('Create an Xweather account and API access keys'); ?></a>.</p></div>
		</div>

		<div class="sls-card">
			<div class="sls-card-header"><h3><i class="fa fa-volume-up text-success" aria-hidden="true"></i> <?php echo _('Alert Audio'); ?></h3></div>
			<div class="sls-card-body"><div class="row">
			<?php foreach (['opening' => _('Pre-tone'), 'closing' => _('Closing tone')] as $tonePrefix => $toneLabel) { $selected = $tonePrefix === 'opening' ? $openingSelection : $closingSelection; ?>
				<div class="col-md-6"><div class="form-group"><label><?php echo $toneLabel; ?></label><select class="form-control" name="xweather[<?php echo $tonePrefix; ?>_tone]">
					<?php if ($tonePrefix === 'opening') { ?><option value="opening_Lightning_alert" <?php echo $selected === 'opening_Lightning_alert' ? 'selected' : ''; ?>><?php echo _('Default — Lightning_alert.mp3'); ?></option><?php } ?>
					<?php if ($selected === 'use_default') { ?><option value="use_default" selected><?php echo _('Legacy weather default'); ?></option><?php } ?>
					<option value="" <?php echo $selected === '' ? 'selected' : ''; ?>><?php echo _('None'); ?></option>
					<optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>"><?php foreach ($tones as $tone) { ?><option value="<?php echo htmlspecialchars($tone); ?>" <?php echo $selected === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $tone)); ?></option><?php } ?></optgroup>
					<?php if ($systemSounds) { ?><optgroup label="<?php echo htmlspecialchars(_('FreePBX System Recordings')); ?>"><?php foreach ($systemSounds as $sound) { ?><option value="<?php echo htmlspecialchars($sound['value']); ?>"><?php echo htmlspecialchars($sound['label']); ?></option><?php } ?></optgroup><?php } ?>
				</select></div></div>
			<?php } ?>
			</div>
			<div class="row"><div class="col-md-4"><div class="form-group"><label for="sls-lightning-tts-volume"><?php echo _('Lightning TTS Volume'); ?></label><div class="input-group"><input class="form-control" id="sls-lightning-tts-volume" type="number" min="1" max="200" name="xweather[tts_volume]" value="<?php echo (int)($xweather['tts_volume'] ?? ($settings['nws_tts_volume'] ?? 25)); ?>"><span class="input-group-addon">%</span></div><p class="help-block"><?php echo _('Default 25%; range 1–200%.'); ?></p></div></div></div>
			<p class="help-block"><?php echo _('The warning text is spoken with the configured weather Piper voice between these tones. Coordinate locations are spoken as “this area”; named locations use the configured city. One second of leading silence is included before the pre-tone and speech. System Recordings are validated and imported when saved.'); ?></p></div>
		</div>

		<?php $primaryLightningGroup = $lightningGroups[0] ?? []; ?>
		<input type="hidden" name="xweather[quiet_hours_enabled]" value="<?php echo empty($primaryLightningGroup['quiet_hours_enabled']) ? '0' : '1'; ?>">
		<input type="hidden" name="xweather[quiet_hours_start]" value="<?php echo htmlspecialchars((string)($primaryLightningGroup['quiet_hours_start'] ?? '21:00')); ?>">
		<input type="hidden" name="xweather[quiet_hours_end]" value="<?php echo htmlspecialchars((string)($primaryLightningGroup['quiet_hours_end'] ?? '06:00')); ?>">

		<div class="sls-form-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> <?php echo _('Save Lightning Configuration'); ?></button> <span class="text-muted" style="margin-left:10px"><?php echo _('Lightning alert emails always use the branded Southland Servers HTML layout with a plain-text fallback.'); ?></span></div>
	</form>
</div>
<script>
(function(){
	var configForm=document.querySelector('input[name="slsmassnotifyserver_action"][value="save_lightning_settings"]');
	configForm=configForm?configForm.form:null;
	var editorList=document.getElementById('sls-lightning-editor-list');
	var editorTemplate=document.getElementById('sls-lightning-template');
	var addArea=document.getElementById('sls-lightning-add');
	var areaCount=document.getElementById('sls-lightning-count');
	function editors(){return editorList?Array.prototype.slice.call(editorList.querySelectorAll('[data-lightning-editor]')):[];}
	function setEditorNames(){
		editors().forEach(function(editor,index){
			editor.querySelectorAll('[data-lightning-field]').forEach(function(field){field.name='xweather[groups]['+index+']['+field.getAttribute('data-lightning-field')+']';});
			editor.querySelectorAll('[data-lightning-extension]').forEach(function(field){field.name='xweather[groups]['+index+'][extensions][]';});
			editor.querySelectorAll('[data-lightning-desktop]').forEach(function(field){field.name='xweather[groups]['+index+'][desktop_clients][]';});
			var email=editor.querySelector('[data-lightning-email]');if(email)email.name='xweather[groups]['+index+'][email_recipients]';
			var name=editor.querySelector('[data-lightning-field="name"]');var title=editor.querySelector('[data-lightning-title]');
			if(title)title.textContent=name&&name.value.trim()?name.value.trim():<?php echo json_encode(_('Lightning Area')); ?>+' '+(index+1);
		});
		var count=editors().length;if(areaCount)areaCount.textContent=count+' / 5';if(addArea)addArea.disabled=count>=5;
	}
	if(editorList){
		editorList.addEventListener('input',function(event){if(event.target&&event.target.getAttribute('data-lightning-field')==='name')setEditorNames();});
		editorList.addEventListener('click',function(event){var button=event.target.closest('[data-lightning-remove]');if(!button)return;event.preventDefault();var editor=button.closest('[data-lightning-editor]');if(editor&&window.confirm(<?php echo json_encode(_('Remove this Lightning trigger area? The change is not permanent until you save and apply.')); ?>)){editor.parentNode.removeChild(editor);setEditorNames();}});
	}
	if(addArea&&editorTemplate&&editorList){addArea.addEventListener('click',function(){if(editors().length>=5)return;var shell=document.createElement('div');shell.innerHTML=editorTemplate.innerHTML.trim();var editor=shell.firstElementChild;if(editor){editorList.appendChild(editor);setEditorNames();var first=editor.querySelector('input[data-lightning-field="name"]');if(first)first.focus();}});}
	if(configForm){configForm.addEventListener('submit',setEditorNames);}
	setEditorNames();

	var queryInterval=document.getElementById('sls-lightning-query-interval');var fastPollWarning=document.getElementById('sls-lightning-fast-poll-warning');
	var adaptive=document.getElementById('sls-lightning-adaptive');var adaptiveCard=document.getElementById('sls-lightning-adaptive-card');var adaptiveShield=document.getElementById('sls-lightning-adaptive-shield');var adaptiveLabel=document.getElementById('sls-lightning-adaptive-label');
	function updatePollWarning(){var adaptiveOn=!!(adaptive&&adaptive.checked);if(adaptiveOn&&queryInterval)queryInterval.value='5';if(fastPollWarning&&queryInterval)fastPollWarning.style.display=!adaptiveOn&&parseInt(queryInterval.value||'5',10)<=4?'':'none';if(adaptiveCard){adaptiveCard.classList.toggle('is-enabled',adaptiveOn);adaptiveCard.classList.toggle('is-disabled',!adaptiveOn);}if(adaptiveShield)adaptiveShield.className='fa fa-shield '+(adaptiveOn?'text-success':'text-danger');if(adaptiveLabel)adaptiveLabel.textContent=adaptiveOn?<?php echo json_encode(_('Adaptive protection enabled')); ?>:<?php echo json_encode(_('Adaptive protection disabled')); ?>;}
	if(queryInterval)queryInterval.addEventListener('change',updatePollWarning);if(adaptive)adaptive.addEventListener('change',updatePollWarning);updatePollWarning();
	var secret=document.getElementById('sls-xweather-client-secret');var secretToggle=document.getElementById('sls-xweather-secret-toggle');
	if(secret&&secretToggle){secretToggle.addEventListener('click',function(){var reveal=secret.type==='password';secret.type=reveal?'text':'password';var icon=secretToggle.querySelector('i');if(icon)icon.className=reveal?'fa fa-eye-slash':'fa fa-eye';});}

	var testForm=document.getElementById('sls-lightning-test-form');var testButton=document.getElementById('sls-lightning-test-submit');var testResult=document.getElementById('sls-lightning-test-result');var selectionError=document.getElementById('sls-lightning-test-selection-error');var cooldown=document.getElementById('sls-lightning-test-cooldown');var remaining=cooldown?parseInt(cooldown.getAttribute('data-remaining')||'0',10)||0:0;var hasTestGroups=<?php echo $testGroups ? 'true' : 'false'; ?>;var testRunning=false;var defaultTestButtonHtml=testButton?testButton.innerHTML:'';
	function selectedTests(){return testForm?testForm.querySelectorAll('input[name="lightning_group_ids[]"]:checked').length:0;}
	function renderTestStatus(state,title,message){if(!testResult)return;var icon=testResult.querySelector('[data-lightning-status-icon]');var titleNode=testResult.querySelector('[data-lightning-status-title]');var messageNode=testResult.querySelector('[data-lightning-status-message]');var icons={running:'fa fa-circle-o-notch fa-spin',success:'fa fa-check',error:'fa fa-exclamation-triangle'};testResult.style.display='flex';testResult.className='sls-operation-status is-'+state;if(icon)icon.className=icons[state]||icons.running;if(titleNode)titleNode.textContent=title;if(messageNode)messageNode.textContent=message;}
	function renderCooldown(){if(!testButton||!cooldown)return;if(testRunning){testButton.disabled=true;return;}testButton.innerHTML=defaultTestButtonHtml;testButton.disabled=remaining>0||!hasTestGroups;if(remaining>0)cooldown.textContent=<?php echo json_encode(_('Available again in')); ?>+' '+remaining+' '+<?php echo json_encode(_('seconds')); ?>;else cooldown.textContent='';}
	renderCooldown();window.setInterval(function(){if(remaining>0){remaining-=1;renderCooldown();}},1000);
	if(testForm&&testButton&&testResult){testForm.addEventListener('submit',function(event){event.preventDefault();if(remaining>0||testRunning)return;if(selectedTests()<1){if(selectionError)selectionError.style.display='block';return;}if(selectionError)selectionError.style.display='none';if(!window.confirm(<?php echo json_encode(_('Send a Lightning test to the selected applied trigger areas?')); ?>))return;testRunning=true;testButton.disabled=true;testButton.innerHTML='<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> '+<?php echo json_encode(_('Running Test…')); ?>;renderTestStatus('running',<?php echo json_encode(_('Lightning test in progress')); ?>,<?php echo json_encode(_('Submitting the selected phone, audio, SIP NOTIFY, and desktop checks.')); ?>);fetch(testForm.action,{method:'POST',credentials:'same-origin',body:new FormData(testForm)}).then(function(response){if(!response.ok)throw new Error('http_'+response.status);return response.json();}).then(function(data){var ok=!!(data&&data.success);var message=data&&data.message?String(data.message):<?php echo json_encode(_('Lightning test request finished.')); ?>;var errors=data&&Array.isArray(data.errors)?data.errors.filter(Boolean):[];renderTestStatus(ok?'success':'error',ok?<?php echo json_encode(_('Lightning test submitted')); ?>:<?php echo json_encode(_('Lightning test needs attention')); ?>,message+(errors.length?' '+errors.join(' '):''));if(data&&data.cooldown)remaining=parseInt(data.cooldown.remaining||'0',10)||0;if(!ok)window.alert(<?php echo json_encode(_('Lightning test error')); ?>+'\n\n'+message+(errors.length?'\n\n'+errors.join('\n'):''));testRunning=false;renderCooldown();}).catch(function(){renderTestStatus('error',<?php echo json_encode(_('Lightning test could not be confirmed')); ?>,<?php echo json_encode(_('The PBX interface did not return a valid result. Review Notification Logs before retrying.')); ?>);window.alert(<?php echo json_encode(_('Lightning test error')); ?>+'\n\n'+<?php echo json_encode(_('The PBX did not return a confirmed delivery result. Review Notification Logs.')); ?>);testRunning=false;renderCooldown();});});}
}());
</script>
