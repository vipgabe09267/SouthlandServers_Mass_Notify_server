<?php
$settings = is_array($settings ?? null) ? $settings : [];
$xweather = is_array($settings['xweather'] ?? null) ? $settings['xweather'] : [];
$extensions = is_array($available_extensions ?? null) ? $available_extensions : [];
$tones = is_array($available_tones ?? null) ? $available_tones : [];
$systemSounds = is_array($available_system_sounds ?? null) ? $available_system_sounds : [];
$recipients = array_fill_keys((array)($xweather['recipients'] ?? []), true);
$csrfToken = (string)($csrf_token ?? '');
$radius = (int)($xweather['radius_miles'] ?? 25);
$location = trim((string)($xweather['location'] ?? '')) ?: _('your configured location');
$openingSelection = (string)($xweather['opening_tone'] ?? 'opening_Lightning_alert');
$closingSelection = (string)($xweather['closing_tone'] ?? '');
$cooldownRemaining = max(0, (int)($cooldown_remaining ?? 0));
$apiUsage = is_array($api_usage ?? null) ? $api_usage : [];
$adaptiveFreeTier = !array_key_exists('adaptive_free_tier', $xweather) || !empty($xweather['adaptive_free_tier']);
$weatherZones = is_array($settings['nws_zones'] ?? null) ? $settings['nws_zones'] : [];
$selectedAdaptiveZone = (string)($xweather['adaptive_nws_zone_id'] ?? ($weatherZones[0]['id'] ?? ''));
$hourOptions = [];
for ($hour = 0; $hour < 24; $hour++) { $hourOptions[] = sprintf('%02d:00', $hour); }
?>
<style>
.sls-lightning-page { max-width: 1180px; margin: 0 auto; }
.sls-page-header { display:flex; justify-content:space-between; gap:20px; align-items:flex-start; margin-bottom:18px; }
.sls-lightning-labs-badge { display:inline-block; margin-left:8px; vertical-align:middle; font-size:12px; }
.sls-card { background:#fff; border:1px solid #dfe5ec; border-radius:8px; box-shadow:0 2px 8px rgba(15,23,42,.05); margin-bottom:18px; overflow:hidden; }
.sls-card-header { padding:15px 18px; border-bottom:1px solid #e8edf2; background:#f8fafc; }
.sls-card-header h3 { margin:0; font-size:18px; }
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
@media (max-width:767px) { .sls-page-header { display:block; } .sls-page-header form { margin-top:10px; } }
</style>
<div class="container-fluid sls-lightning-page">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
	<div class="sls-page-header">
		<div>
			<h1><i class="fa fa-bolt text-warning" aria-hidden="true"></i> <?php echo _('Lightning Alerts'); ?> <span class="label label-success sls-lightning-labs-badge"><i class="fa fa-flask" aria-hidden="true"></i> <?php echo _('Labs'); ?></span></h1>
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
				<strong><?php echo _('Spoken message preview'); ?></strong><br>
				<span id="sls-lightning-preview-text"><?php echo htmlspecialchars(sprintf(_('TEST ONLY. This is a simulated alert. Lightning has been detected within %d miles of %s. No actual lightning event is being reported.'), $radius, $location)); ?></span>
			</div>
			<p class="help-block"><?php echo _('The test is clearly labeled as simulated in phone, audio, desktop, email, and Discord delivery. A 60-second cooldown prevents repeated test sends.'); ?></p>
			<form method="post" action="config.php?display=slsmassnotifyserver_lightning" onsubmit="return confirm('<?php echo htmlspecialchars(_('Send a live lightning test to the configured recipients?'), ENT_QUOTES); ?>');">
				<input type="hidden" name="slsmassnotifyserver_action" value="test_lightning">
				<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
				<button class="btn btn-warning" id="sls-lightning-test-submit" type="submit" <?php echo $cooldownRemaining > 0 ? 'disabled' : ''; ?>><i class="fa fa-bolt"></i> <?php echo _('Send Lightning Test'); ?></button>
				<span class="text-muted" id="sls-lightning-test-cooldown" data-remaining="<?php echo $cooldownRemaining; ?>" style="margin-left:10px"><?php echo $cooldownRemaining > 0 ? sprintf(_('Available again in %d seconds'), $cooldownRemaining) : ''; ?></span>
			</form>
		</div>
	</div>

	<div class="sls-card">
		<div class="sls-card-header"><h3><i class="fa fa-plug text-primary" aria-hidden="true"></i> <?php echo _('Xweather API Connection'); ?></h3></div>
		<div class="sls-card-body"><div class="row"><div class="col-md-8"><p><?php echo _('Validate the currently applied client ID, protected client secret, location, and cloud-to-ground lightning query without sending an alert.'); ?></p><p class="text-muted"><?php echo _('Successful validation updates Dashboard health and confirms the live API response schema. Verification makes one additional query. Xweather measures account usage in cost tokens/hits, not simply HTTP request count.'); ?></p><?php if (!empty($apiUsage['limit'])) { ?><p style="margin-bottom:0"><strong><?php echo _('Current account-period usage:'); ?></strong> <?php echo (int)$apiUsage['used']; ?> / <?php echo (int)$apiUsage['limit']; ?> <?php echo _('tokens used'); ?>; <?php echo (int)$apiUsage['remaining']; ?> <?php echo _('remaining'); ?>. <span class="text-muted"><?php echo sprintf(_('During storm mode, this PBX can query every %d minutes (%d queries per continuously active day).'), (int)$apiUsage['interval_minutes'], (int)$apiUsage['max_queries_per_day']); ?><?php if (!empty($apiUsage['last_query_cost_tokens'])) { ?> <?php echo sprintf(_('The last optimized query cost %d tokens; continuous polling would cost about %d tokens/day and %d tokens per 30 days.'), (int)$apiUsage['last_query_cost_tokens'], (int)$apiUsage['estimated_tokens_per_day'], (int)$apiUsage['estimated_tokens_per_30_days']); ?><?php } ?><?php if (!empty($apiUsage['reset_at'])) { ?> <?php echo htmlspecialchars(sprintf(_('Account period resets %s.'), $apiUsage['reset_at'])); ?><?php } ?></span></p><?php if (!$adaptiveFreeTier && !empty($apiUsage['last_query_cost_tokens']) && empty($apiUsage['free_tier_month_sustainable'])) { ?><div class="alert alert-warning" style="margin:12px 0 0"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <strong><?php echo _('Quota warning:'); ?></strong> <?php echo _('At the observed query cost, this polling schedule cannot run for a 30-day month on a 15,000-token allowance. Increase the account allowance or expect Lightning Alerts to report a quota fault when the balance is exhausted.'); ?></div><?php } ?><?php } ?></div><div class="col-md-4 text-right"><form method="post" action="config.php?display=slsmassnotifyserver_lightning"><input type="hidden" name="slsmassnotifyserver_action" value="verify_lightning_connection"><input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>"><button class="btn btn-primary" type="submit"><i class="fa fa-check-circle" aria-hidden="true"></i> <?php echo _('Verify API Connection'); ?></button></form></div></div></div>
	</div>

	<form method="post" action="config.php?display=slsmassnotifyserver_lightning">
		<input type="hidden" name="slsmassnotifyserver_action" value="save_lightning_settings">
		<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

		<div class="sls-card">
			<div class="sls-card-header"><h3><i class="fa fa-crosshairs text-danger" aria-hidden="true"></i> <?php echo _('Detection Area'); ?></h3></div>
			<div class="sls-card-body">
				<div class="row">
					<div class="col-md-2"><div class="form-group"><label><?php echo _('Service'); ?></label><select class="form-control" name="xweather[enabled]"><option value="0" <?php echo empty($xweather['enabled']) ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option><option value="1" <?php echo !empty($xweather['enabled']) ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option></select></div></div>
					<div class="col-md-4"><div class="form-group"><label for="sls-lightning-location"><?php echo _('Location'); ?></label><input class="form-control" id="sls-lightning-location" name="xweather[location]" value="<?php echo htmlspecialchars($xweather['location'] ?? ''); ?>" placeholder="30.5083,-97.6789 or Round Rock, TX"><p class="help-block"><?php echo _('Center point used for the lightning radius.'); ?></p></div></div>
					<div class="col-md-2"><div class="form-group"><label for="sls-lightning-radius"><?php echo _('Radius (miles)'); ?></label><input class="form-control" id="sls-lightning-radius" type="number" min="1" max="62" name="xweather[radius_miles]" value="<?php echo $radius; ?>"></div></div>
					<div class="col-md-2"><div class="form-group"><label for="sls-lightning-query-interval"><?php echo _('API query period'); ?></label><select class="form-control" id="sls-lightning-query-interval" name="xweather[query_interval_minutes]"><?php for ($minutes = 1; $minutes <= 10; $minutes++) { ?><option value="<?php echo $minutes; ?>" <?php echo (int)($xweather['query_interval_minutes'] ?? 5) === $minutes ? 'selected' : ''; ?>><?php echo $minutes <= 4 ? '&#9888; ' : ''; ?><?php echo sprintf(_('%d minute(s)'), $minutes); ?></option><?php } ?></select><p class="help-block text-danger" id="sls-lightning-fast-poll-warning" style="<?php echo (int)($xweather['query_interval_minutes'] ?? 5) <= 4 ? '' : 'display:none;'; ?>"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?php echo _('The free-tier token allowance cannot sustain this polling frequency.'); ?></p></div></div>
					<div class="col-md-2"><div class="form-group"><label><?php echo _('After storm leaves'); ?></label><select class="form-control" name="xweather[all_clear]"><option value="none" <?php echo ($xweather['all_clear'] ?? 'none') === 'none' ? 'selected' : ''; ?>><?php echo _('Do nothing'); ?></option><option value="send" <?php echo ($xweather['all_clear'] ?? 'none') === 'send' ? 'selected' : ''; ?>><?php echo _('Send all clear'); ?></option></select></div></div>
				</div>
				<p class="sls-required-note"><?php echo _('Cloud-to-ground strikes are queried only. A cluster creates one alert when it first appears inside the radius and must clear before another alert. Standard/free Xweather lightning data covers the past 5 minutes, so 5 minutes is the longest gap-free query period. Selecting 6–10 minutes can miss strikes unless your subscription includes extended lightning history.'); ?></p>
				<hr class="sls-adaptive-divider">
				<div class="row"><div class="col-md-5"><div class="form-group"><label for="sls-lightning-adaptive"><?php echo _('Adaptive protection'); ?></label><div id="sls-lightning-adaptive-card" class="sls-adaptive-card <?php echo $adaptiveFreeTier ? 'is-enabled' : 'is-disabled'; ?>"><input type="hidden" name="xweather[adaptive_free_tier]" value="0"><label class="sls-toggle" aria-label="<?php echo htmlspecialchars(_('Toggle adaptive protection')); ?>"><input id="sls-lightning-adaptive" type="checkbox" name="xweather[adaptive_free_tier]" value="1" <?php echo $adaptiveFreeTier ? 'checked' : ''; ?>><span class="sls-toggle-slider"></span></label><span class="sls-adaptive-state"><i id="sls-lightning-adaptive-shield" class="fa fa-shield" aria-hidden="true"></i><strong id="sls-lightning-adaptive-label"></strong></span><p class="sls-adaptive-copy"><?php echo _('Enabled uses the selected Weather.gov zone to protect API quota. Disabled polls Xweather continuously at the configured period, regardless of NWS conditions.'); ?></p></div></div></div><div class="col-md-4"><div class="form-group"><label for="sls-lightning-zone"><?php echo _('Weather trigger zone'); ?></label><select class="form-control" id="sls-lightning-zone" name="xweather[adaptive_nws_zone_id]" <?php echo $adaptiveFreeTier ? 'required' : ''; ?>><option value=""><?php echo _('Select a configured weather zone'); ?></option><?php foreach ($weatherZones as $zoneGroup) { ?><option value="<?php echo htmlspecialchars((string)($zoneGroup['id'] ?? '')); ?>" <?php echo $selectedAdaptiveZone === (string)($zoneGroup['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($zoneGroup['name'] ?? $zoneGroup['zone'] ?? '') . ' — ' . (string)($zoneGroup['zone'] ?? '')); ?></option><?php } ?></select><p class="help-block"><?php echo _('Required only while adaptive protection is enabled.'); ?></p></div></div><div class="col-md-3"><div class="form-group"><label for="sls-lightning-grace"><?php echo _('Storm-mode grace period'); ?></label><select class="form-control" id="sls-lightning-grace" name="xweather[adaptive_grace_minutes]"><?php foreach ([5, 10, 15, 30, 45, 60, 90, 120] as $grace) { ?><option value="<?php echo $grace; ?>" <?php echo (int)($xweather['adaptive_grace_minutes'] ?? 60) === $grace ? 'selected' : ''; ?>><?php echo sprintf(_('%d minutes'), $grace); ?></option><?php } ?></select><p class="help-block"><?php echo _('Default 60 minutes.'); ?></p></div></div></div>
				<div class="alert alert-warning sls-adaptive-guidance"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?php echo _('Adaptive mode saves Xweather tokens by polling only while one of this PBX’s configured Weather.gov zones has an active thunderstorm event, plus the grace period. It can miss lightning that occurs without an NWS event. The quota governor may pause storm-mode polling if its protected token budget is depleted.'); ?></div>
			</div>
		</div>

		<div class="sls-card">
			<div class="sls-card-header"><h3><i class="fa fa-key text-muted" aria-hidden="true"></i> <?php echo _('Xweather API Login'); ?></h3></div>
			<div class="sls-card-body"><div class="row">
				<div class="col-md-6"><div class="form-group"><label><?php echo _('Client ID'); ?></label><input class="form-control" name="xweather[client_id]" value="<?php echo htmlspecialchars($xweather['client_id'] ?? ''); ?>" autocomplete="off"></div></div>
				<div class="col-md-6"><div class="form-group"><label><?php echo _('Client Secret'); ?></label><div class="input-group"><input class="form-control" id="sls-xweather-client-secret" type="password" name="xweather[client_secret]" value="<?php echo htmlspecialchars($xweather['client_secret'] ?? ''); ?>" autocomplete="new-password"><span class="input-group-btn"><button type="button" class="btn btn-default" id="sls-xweather-secret-toggle" title="<?php echo htmlspecialchars(_('Show or hide client secret')); ?>" aria-label="<?php echo htmlspecialchars(_('Show or hide client secret')); ?>"><i class="fa fa-eye" aria-hidden="true"></i></button></span></div><p class="help-block"><?php echo _('The saved value stays masked until you select the eye button.'); ?></p></div></div>
			</div><p class="help-block"><?php echo _('Credentials stay in the protected central configuration and are redacted from diagnostics and the Control API.'); ?> <a href="https://www.xweather.com/docs/weather-api/getting-started" target="_blank" rel="noopener noreferrer"><i class="fa fa-external-link" aria-hidden="true"></i> <?php echo _('Create an Xweather account and API access keys'); ?></a>.</p></div>
		</div>

		<div class="sls-card">
			<div class="sls-card-header"><h3><i class="fa fa-users text-primary" aria-hidden="true"></i> <?php echo _('Recipients'); ?></h3></div>
			<div class="sls-card-body"><div class="sls-recipient-grid"><div class="row">
			<?php foreach ($extensions as $extension) { ?><div class="col-md-4 col-sm-6"><div class="checkbox"><label><input type="checkbox" name="xweather[recipients][]" value="<?php echo htmlspecialchars($extension['extension']); ?>" <?php echo isset($recipients[$extension['extension']]) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($extension['extension'] . (($extension['name'] ?? '') !== '' ? ' - ' . $extension['name'] : '')); ?> <span class="text-muted"><?php echo !empty($extension['registered']) ? _('online') : _('offline'); ?></span></label></div></div><?php } ?>
			</div></div></div>
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

		<div class="sls-card">
			<div class="sls-card-header"><h3><i class="fa fa-moon-o text-muted" aria-hidden="true"></i> <?php echo _('Lightning Quiet Hours'); ?></h3></div>
			<div class="sls-card-body"><div class="row">
				<div class="col-md-4"><div class="form-group"><label style="display:block"><?php echo _('Quiet hours'); ?></label><label class="sls-toggle"><input type="checkbox" name="xweather[quiet_hours_enabled]" value="1" <?php echo !empty($xweather['quiet_hours_enabled']) ? 'checked' : ''; ?>><span class="sls-toggle-slider"></span></label><strong><?php echo !empty($xweather['quiet_hours_enabled']) ? _('Enabled') : _('Disabled'); ?></strong></div></div>
				<div class="col-md-4"><div class="form-group"><label><?php echo _('Start'); ?></label><select class="form-control" name="xweather[quiet_hours_start]"><?php foreach ($hourOptions as $hour) { ?><option value="<?php echo $hour; ?>" <?php echo ($xweather['quiet_hours_start'] ?? '21:00') === $hour ? 'selected' : ''; ?>><?php echo $hour; ?></option><?php } ?></select></div></div>
				<div class="col-md-4"><div class="form-group"><label><?php echo _('End'); ?></label><select class="form-control" name="xweather[quiet_hours_end]"><?php foreach ($hourOptions as $hour) { ?><option value="<?php echo $hour; ?>" <?php echo ($xweather['quiet_hours_end'] ?? '06:00') === $hour ? 'selected' : ''; ?>><?php echo $hour; ?></option><?php } ?></select></div></div>
			</div><p class="help-block"><?php echo _('When enabled, a storm first detected during lightning quiet hours waits and alerts only if it remains active after quiet hours end.'); ?></p></div>
		</div>

		<div class="sls-form-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> <?php echo _('Save Lightning Configuration'); ?></button> <span class="text-muted" style="margin-left:10px"><?php echo _('Lightning alert emails always use the branded Southland Servers HTML layout with a plain-text fallback.'); ?></span></div>
	</form>
</div>
<script>
(function(){
	var radius=document.getElementById('sls-lightning-radius');
	var location=document.getElementById('sls-lightning-location');
	var preview=document.getElementById('sls-lightning-preview-text');
	function update(){ if(!preview)return; var miles=(radius&&radius.value)||'25'; var place=(location&&location.value.trim())||'your configured location'; preview.textContent='TEST ONLY. This is a simulated alert. Lightning has been detected within '+miles+' miles of '+place+'. No actual lightning event is being reported.'; }
	if(radius)radius.addEventListener('input',update); if(location)location.addEventListener('input',update);
	var queryInterval=document.getElementById('sls-lightning-query-interval'); var fastPollWarning=document.getElementById('sls-lightning-fast-poll-warning');
	var adaptive=document.getElementById('sls-lightning-adaptive'); var triggerZone=document.getElementById('sls-lightning-zone'); var adaptiveCard=document.getElementById('sls-lightning-adaptive-card'); var adaptiveShield=document.getElementById('sls-lightning-adaptive-shield'); var adaptiveLabel=document.getElementById('sls-lightning-adaptive-label');
	function updatePollWarning(){if(!queryInterval||!fastPollWarning)return;var adaptiveOn=!!(adaptive&&adaptive.checked);if(adaptiveOn)queryInterval.value='5';queryInterval.disabled=adaptiveOn;fastPollWarning.style.display=!adaptiveOn&&parseInt(queryInterval.value||'5',10)<=4?'':'none';if(triggerZone)triggerZone.required=adaptiveOn;if(adaptiveCard){adaptiveCard.classList.toggle('is-enabled',adaptiveOn);adaptiveCard.classList.toggle('is-disabled',!adaptiveOn);}if(adaptiveShield)adaptiveShield.className='fa fa-shield '+(adaptiveOn?'text-success':'text-danger');if(adaptiveLabel)adaptiveLabel.textContent=adaptiveOn?<?php echo json_encode(_('Adaptive protection enabled')); ?>:<?php echo json_encode(_('Adaptive protection disabled')); ?>;}
	if(queryInterval){queryInterval.addEventListener('change',updatePollWarning);updatePollWarning();}
	if(adaptive)adaptive.addEventListener('change',updatePollWarning);
	var secret=document.getElementById('sls-xweather-client-secret'); var secretToggle=document.getElementById('sls-xweather-secret-toggle');
	if(secret&&secretToggle){secretToggle.addEventListener('click',function(){var reveal=secret.type==='password';secret.type=reveal?'text':'password';var icon=secretToggle.querySelector('i');if(icon)icon.className=reveal?'fa fa-eye-slash':'fa fa-eye';});}
	var testButton=document.getElementById('sls-lightning-test-submit'); var cooldown=document.getElementById('sls-lightning-test-cooldown'); var remaining=cooldown?parseInt(cooldown.getAttribute('data-remaining')||'0',10)||0:0;
	function renderCooldown(){if(!testButton||!cooldown)return;if(remaining>0){testButton.disabled=true;cooldown.textContent='Available again in '+remaining+' seconds';}else{testButton.disabled=false;cooldown.textContent='';}}
	renderCooldown(); setInterval(function(){if(remaining>0){remaining-=1;renderCooldown();}},1000);
}());
</script>
