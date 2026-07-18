<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group
$settings = is_array($settings ?? null) ? $settings : [];
$saveResult = $save_result ?? null;
$extensions = is_array($available_extensions ?? null) ? $available_extensions : [];
$voices = is_array($available_voices ?? null) ? $available_voices : [];
$setup = is_array($settings['setup'] ?? null) ? $settings['setup'] : [];
$criticalEvents = is_array($settings['quiet_critical_events'] ?? null) ? $settings['quiet_critical_events'] : [];
$selectedRecipients = array_fill_keys((array)($settings['alert_recipients'] ?? []), true);
$xweather = is_array($settings['xweather'] ?? null) ? $settings['xweather'] : [];
$setupWeatherZones = is_array($settings['nws_zones'] ?? null) ? $settings['nws_zones'] : [];
$setupAdaptiveZone = (string)($xweather['adaptive_nws_zone_id'] ?? ($setupWeatherZones[0]['id'] ?? ''));
$lightningRecipients = array_fill_keys((array)($xweather['recipients'] ?? []), true);
$setupTones = is_array($available_tones ?? null) ? $available_tones : [];
$setupSystemSounds = is_array($available_system_sounds ?? null) ? $available_system_sounds : [];
$dismissible = !empty($dismissible);
$weatherSetupEnabled = (($settings['enabled'] ?? '0') === '1');
$lightningSetupEnabled = (($xweather['enabled'] ?? '0') === '1');
?>
<style>
	.sls-setup-backdrop {
		position: fixed;
		z-index: 1040;
		top: 0;
		right: 0;
		bottom: 0;
		left: 0;
		background: rgba(17, 24, 39, 0.72);
	}
	.sls-setup-modal-shell {
		position: fixed;
		z-index: 1050;
		top: 20px;
		right: 20px;
		bottom: 20px;
		left: 20px;
		overflow: auto;
	}
	.sls-setup-modal-dialog {
		max-width: 1100px;
		margin: 0 auto;
		background: #fff;
		border-radius: 6px;
		box-shadow: 0 18px 48px rgba(0, 0, 0, 0.35);
	}
	.sls-setup-modal-content {
		padding: 20px;
	}
	.sls-setup-section { margin:22px 0; padding:18px; border:1px solid #d9e2ec; border-radius:10px; background:#f8fafc; }
	.sls-setup-section h3 { margin:0 0 6px; }
	.sls-setup-enable-row { max-width:360px; margin-top:14px; }
	.sls-setup-option-details { margin-top:14px; padding-top:16px; border-top:1px solid #d9e2ec; }
	.sls-setup-option-details[hidden], [data-sls-setup-integration][hidden] { display:none !important; }
	.sls-setup-modal-content .checkbox > label,
	.sls-setup-modal-content label.checkbox-inline { display:inline-flex; align-items:flex-start; gap:8px; padding-left:0; line-height:1.35; }
	.sls-setup-modal-content .checkbox input[type="checkbox"],
	.sls-setup-modal-content label.checkbox-inline input[type="checkbox"] { position:static; flex:0 0 auto; margin:2px 0 0 !important; }
	.sls-setup-modal-content label.checkbox-inline { margin:0 14px 8px 0; }
	.sls-setup-toggle { position:relative; display:inline-block; width:48px; height:26px; vertical-align:middle; margin-right:8px; }
	.sls-setup-toggle input { opacity:0; width:0; height:0; }
	.sls-setup-toggle span { position:absolute; inset:0; border-radius:26px; background:#cbd5e1; cursor:pointer; transition:.2s; }
	.sls-setup-toggle span:before { content:""; position:absolute; width:20px; height:20px; left:3px; top:3px; border-radius:50%; background:#fff; transition:.2s; }
	.sls-setup-toggle input:checked + span { background:#6d28d9; }
	.sls-setup-toggle input:checked + span:before { transform:translateX(22px); }
	.sls-setup-adaptive-card { border:1px solid #86efac; border-radius:8px; padding:12px 14px; background:#ecfdf5; color:#166534; transition:background-color .2s,border-color .2s,color .2s; }
	.sls-setup-adaptive-card.is-disabled { border-color:#fca5a5; background:#fef2f2; color:#991b1b; }
	.sls-setup-adaptive-card .sls-setup-toggle { margin-bottom:0; }
	.sls-setup-adaptive-card .sls-setup-toggle input:checked + span { background:#16a34a; }
	.sls-setup-adaptive-state { display:inline-flex; align-items:center; gap:7px; vertical-align:middle; }
	.sls-setup-adaptive-copy { display:block; margin:8px 0 0; color:inherit; }
	@media (max-width: 767px) {
		.sls-setup-modal-shell {
			top: 8px;
			right: 8px;
			bottom: 8px;
			left: 8px;
		}
		.sls-setup-modal-content {
			padding: 12px;
		}
	}
</style>
<div class="sls-setup-backdrop"></div>
<div class="sls-setup-modal-shell" role="dialog" aria-modal="true">
<div class="sls-setup-modal-dialog">
<div class="container-fluid sls-setup-modal-content">
	<?php if ($dismissible) { ?>
		<button type="button" class="close sls-setup-dismiss" aria-label="<?php echo htmlspecialchars(_('Close setup wizard')); ?>" style="font-size: 30px; opacity: .75;">
			<span aria-hidden="true">&times;</span>
		</button>
	<?php } ?>
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image ?? '']); ?>
	<div class="row">
		<div class="col-sm-12">
			<h2><i class="fa fa-magic text-primary" aria-hidden="true"></i> <?php echo _('Setup Wizard'); ?></h2>
			<p class="text-muted">
				<?php echo _('Thank you for installing the Southland Servers Mass Notification System. Complete this wizard before using the Mass Notifications module.'); ?>
			</p>
			<p>
				<a href="<?php echo htmlspecialchars($project_url ?? 'https://southlandservers.xyz/projects'); ?>" target="_blank" rel="noopener"><?php echo _('Project'); ?></a>
				|
				<a href="<?php echo htmlspecialchars($discord_url ?? 'https://southlandservers.xyz/discord'); ?>" target="_blank" rel="noopener"><?php echo _('Discord'); ?></a>
				|
				<a href="<?php echo htmlspecialchars($github_url ?? 'https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server'); ?>" target="_blank" rel="noopener"><?php echo _('GitHub'); ?></a>
			</p>

			<?php if (is_array($saveResult)) { ?>
				<div class="alert alert-<?php echo !empty($saveResult['success']) ? 'success' : 'warning'; ?>">
					<?php echo htmlspecialchars($saveResult['message']); ?>
					<?php if (!empty($saveResult['errors'])) { ?>
						<ul>
							<?php foreach ($saveResult['errors'] as $error) { ?>
								<li><?php echo htmlspecialchars($error); ?></li>
							<?php } ?>
						</ul>
					<?php } ?>
				</div>
			<?php } ?>

			<?php if (!empty($setup['completed'])) { ?>
				<div class="alert alert-success"><?php echo _('Setup is already complete. You can rerun this wizard to update first-run choices.'); ?></div>
			<?php } else { ?>
				<div class="alert alert-danger">
					<strong><?php echo _('Warning'); ?>:</strong>
					<?php echo _('This project is still beta software and is not production ready. Use it at your own risk.'); ?>
				</div>
			<?php } ?>

				<form method="post" action="config.php?display=slsmassnotifyserver">
					<input type="hidden" name="slsmassnotifyserver_action" value="save_setup_wizard">
					<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars((string)($csrf_token ?? '')); ?>">

				<h3><i class="fa fa-check-square-o text-success" aria-hidden="true"></i> <?php echo _('Required Acknowledgements'); ?></h3>
				<div class="checkbox">
					<label>
						<input type="checkbox" name="beta_agree" value="1" <?php echo !empty($setup['beta_accepted']) ? 'checked' : ''; ?>>
						<?php echo _('I understand this beta is non-production-ready and I use it at my own risk.'); ?>
					</label>
				</div>
				<div class="panel panel-default">
					<div class="panel-heading"><?php echo _('AGPL-3.0 License Notice'); ?></div>
					<div class="panel-body" style="max-height: 180px; overflow: auto;">
						<p><?php echo _('Southland Servers Mass Notifications Server is intended to be licensed under the GNU Affero General Public License version 3. Network users must be able to receive the corresponding source code under the AGPL terms.'); ?></p>
						<p><a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener"><?php echo _('Read the AGPL-3.0 license'); ?></a></p>
					</div>
				</div>
				<div class="checkbox">
					<label>
						<input type="checkbox" name="agpl_agree" value="1" <?php echo !empty($setup['agpl_accepted']) ? 'checked' : ''; ?>>
						<?php echo _('I accept the AGPL-3.0 license notice.'); ?>
					</label>
				</div>
				<div class="panel panel-default">
					<div class="panel-heading"><?php echo _('EULA'); ?></div>
					<pre style="max-height: 220px; overflow: auto; white-space: pre-wrap;"><?php echo htmlspecialchars((string)($eula_text ?? '')); ?></pre>
				</div>
				<div class="checkbox">
					<label>
						<input type="checkbox" name="eula_agree" value="1" <?php echo !empty($setup['eula_accepted']) ? 'checked' : ''; ?>>
						<?php echo _('I have read and accept the EULA.'); ?>
					</label>
				</div>

				<section class="sls-setup-section" aria-labelledby="sls-setup-weather-title">
				<h3 id="sls-setup-weather-title"><i class="fa fa-cloud text-primary" aria-hidden="true"></i> <?php echo _('Weather Alerts'); ?></h3>
				<p class="help-block"><?php echo _('Optional. Supports United States locations and zones through the U.S. National Weather Service weather.gov API only.'); ?></p>
				<div class="sls-setup-enable-row form-group">
					<label for="sls-setup-weather-enabled"><?php echo _('Set up Weather Alerts now?'); ?></label>
					<select class="form-control" id="sls-setup-weather-enabled" name="enabled">
						<option value="0" <?php echo !$weatherSetupEnabled ? 'selected' : ''; ?>><?php echo _('No'); ?></option>
						<option value="1" <?php echo $weatherSetupEnabled ? 'selected' : ''; ?>><?php echo _('Yes'); ?></option>
					</select>
				</div>
				<div id="sls-setup-weather-details" class="sls-setup-option-details" <?php echo !$weatherSetupEnabled ? 'hidden' : ''; ?>>
				<div class="row">
					<div class="col-md-3">
						<div class="form-group">
							<label><?php echo _('NWS Zone/County'); ?></label>
							<input class="form-control" name="nws_zone" value="<?php echo htmlspecialchars($settings['nws_zone'] ?? ''); ?>" placeholder="TXC491">
							<p class="help-block"><a href="https://www.weather.gov/gis/ZoneCounty" target="_blank" rel="noopener"><?php echo _('Find your NWS zone'); ?></a></p>
						</div>
					</div>
					<div class="col-md-6">
						<div class="form-group">
							<label><?php echo _('NWS API Base URL'); ?></label>
							<input class="form-control" name="nws_api_base_url" value="<?php echo htmlspecialchars($settings['nws_api_base_url'] ?? 'https://api.weather.gov'); ?>">
						</div>
					</div>
				</div>
				<div class="form-group">
					<label><?php echo _('NWS Recipient Extensions'); ?></label>
					<div class="row">
						<?php foreach ($extensions as $target) { ?>
							<div class="col-sm-3">
								<label class="checkbox-inline">
									<input type="checkbox" name="alert_recipients[]" value="<?php echo htmlspecialchars($target['extension']); ?>" <?php echo isset($selectedRecipients[$target['extension']]) ? 'checked' : ''; ?>>
									<?php echo htmlspecialchars($target['extension']); ?>
									<?php if (($target['name'] ?? '') !== '') { echo ' - ' . htmlspecialchars($target['name']); } ?>
								</label>
							</div>
						<?php } ?>
					</div>
				</div>
				<div class="row">
					<div class="col-md-3">
						<label><?php echo _('Quiet Hours'); ?></label>
						<select class="form-control" name="quiet_hours_enabled">
							<option value="0" <?php echo empty($settings['quiet_hours_enabled']) ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option>
							<option value="1" <?php echo !empty($settings['quiet_hours_enabled']) ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option>
						</select>
					</div>
					<div class="col-md-3">
						<label><?php echo _('Start'); ?></label>
						<input class="form-control" name="quiet_hours_start" value="<?php echo htmlspecialchars($settings['quiet_hours_start'] ?? '21:00'); ?>">
					</div>
					<div class="col-md-3">
						<label><?php echo _('End'); ?></label>
						<input class="form-control" name="quiet_hours_end" value="<?php echo htmlspecialchars($settings['quiet_hours_end'] ?? '06:00'); ?>">
					</div>
					<div class="col-md-3">
						<label><?php echo _('Critical Bypass Events'); ?></label>
						<input class="form-control" name="quiet_critical_events[]" value="<?php echo htmlspecialchars(implode(', ', $criticalEvents)); ?>">
						<p class="help-block"><?php echo _('Comma-separated event names are accepted.'); ?></p>
					</div>
				</div>
				</div>
				</section>

				<section class="sls-setup-section" aria-labelledby="sls-setup-lightning-title">
				<h3 id="sls-setup-lightning-title"><i class="fa fa-bolt text-warning" aria-hidden="true"></i> <?php echo _('Lightning Alerts'); ?></h3>
				<p class="help-block"><?php echo _('Optional Xweather lightning monitoring is separate from NWS. It alerts once when a storm enters the radius and can optionally announce an all clear after the storm leaves.'); ?></p>
				<div class="sls-setup-enable-row form-group">
					<label for="sls-setup-lightning-enabled"><?php echo _('Set up Lightning Alerts now?'); ?></label>
					<select class="form-control" id="sls-setup-lightning-enabled" name="xweather[enabled]"><option value="0" <?php echo !$lightningSetupEnabled ? 'selected' : ''; ?>><?php echo _('No'); ?></option><option value="1" <?php echo $lightningSetupEnabled ? 'selected' : ''; ?>><?php echo _('Yes'); ?></option></select>
				</div>
				<div id="sls-setup-lightning-details" class="sls-setup-option-details" <?php echo !$lightningSetupEnabled ? 'hidden' : ''; ?>>
				<div class="row">
					<div class="col-md-3"><div class="form-group"><label><?php echo _('Location'); ?></label><input class="form-control" name="xweather[location]" value="<?php echo htmlspecialchars($xweather['location'] ?? ''); ?>" placeholder="Round Rock, TX or 30.5083,-97.6789"></div></div>
					<div class="col-md-2"><div class="form-group"><label><?php echo _('Radius (miles)'); ?></label><input class="form-control" type="number" min="1" max="62" name="xweather[radius_miles]" value="<?php echo (int)($xweather['radius_miles'] ?? 25); ?>"></div></div>
					<div class="col-md-2"><div class="form-group"><label><?php echo _('API period'); ?></label><select class="form-control" name="xweather[query_interval_minutes]"><?php for ($minutes = 1; $minutes <= 10; $minutes++) { ?><option value="<?php echo $minutes; ?>" <?php echo (int)($xweather['query_interval_minutes'] ?? 5) === $minutes ? 'selected' : ''; ?>><?php echo $minutes <= 4 ? '&#9888; ' : ''; ?><?php echo sprintf(_('%d min'), $minutes); ?></option><?php } ?></select><p class="help-block text-danger"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?php echo _('1–4 minute choices cannot be sustained by the free-tier allowance.'); ?></p></div></div>
					<div class="col-md-3"><div class="form-group"><label><?php echo _('After storm leaves'); ?></label><select class="form-control" name="xweather[all_clear]"><option value="none" <?php echo ($xweather['all_clear'] ?? 'none') === 'none' ? 'selected' : ''; ?>><?php echo _('Do nothing'); ?></option><option value="send" <?php echo ($xweather['all_clear'] ?? 'none') === 'send' ? 'selected' : ''; ?>><?php echo _('Send all-clear message'); ?></option></select></div></div>
				</div>
				<p class="help-block"><?php echo _('Cloud-to-ground strikes are queried. Standard/free Xweather lightning data covers the past 5 minutes, so the 5-minute default is the longest gap-free period. Values from 6–10 minutes may miss strikes without extended-history access.'); ?></p>
				<div class="row"><div class="col-md-5"><div class="form-group"><label><?php echo _('Adaptive protection'); ?></label><div id="sls-setup-adaptive-card" class="sls-setup-adaptive-card <?php echo (!array_key_exists('adaptive_free_tier', $xweather) || !empty($xweather['adaptive_free_tier'])) ? 'is-enabled' : 'is-disabled'; ?>"><input type="hidden" name="xweather[adaptive_free_tier]" value="0"><label class="sls-setup-toggle" aria-label="<?php echo htmlspecialchars(_('Toggle adaptive protection')); ?>"><input id="sls-setup-adaptive" type="checkbox" name="xweather[adaptive_free_tier]" value="1" <?php echo !array_key_exists('adaptive_free_tier', $xweather) || !empty($xweather['adaptive_free_tier']) ? 'checked' : ''; ?>><span></span></label><span class="sls-setup-adaptive-state"><i id="sls-setup-adaptive-shield" class="fa fa-shield" aria-hidden="true"></i><strong id="sls-setup-adaptive-label"></strong></span><p class="sls-setup-adaptive-copy"><?php echo _('Enabled polls Xweather only when the selected Weather.gov zone indicates storm activity. Disabled polls continuously at the configured API period.'); ?></p></div></div></div><div class="col-md-4"><div class="form-group"><label><?php echo _('Weather trigger zone'); ?></label><select class="form-control" id="sls-setup-adaptive-zone" name="xweather[adaptive_nws_zone_id]"><option value=""><?php echo _('Select a configured weather zone'); ?></option><?php foreach ($setupWeatherZones as $zoneGroup) { ?><option value="<?php echo htmlspecialchars((string)($zoneGroup['id'] ?? '')); ?>" <?php echo $setupAdaptiveZone === (string)($zoneGroup['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($zoneGroup['name'] ?? $zoneGroup['zone'] ?? '') . ' — ' . (string)($zoneGroup['zone'] ?? '')); ?></option><?php } ?></select></div></div><div class="col-md-3"><div class="form-group"><label><?php echo _('Storm-mode grace'); ?></label><select class="form-control" name="xweather[adaptive_grace_minutes]"><?php foreach ([5, 10, 15, 30, 45, 60, 90, 120] as $grace) { ?><option value="<?php echo $grace; ?>" <?php echo (int)($xweather['adaptive_grace_minutes'] ?? 60) === $grace ? 'selected' : ''; ?>><?php echo sprintf(_('%d minutes'), $grace); ?></option><?php } ?></select><p class="help-block"><?php echo _('Default 60 minutes.'); ?></p></div></div></div>
				<div class="alert alert-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?php echo _('Adaptive mode is recommended for the 15,000-token free allowance. It requires Weather Alerts and at least one weather zone. Xweather polls every five minutes only during a relevant NWS thunderstorm event and the grace period; lightning outside an NWS event can be missed.'); ?></div>
				<div class="row"><div class="col-md-6"><div class="form-group"><label><?php echo _('Xweather Client ID'); ?></label><input class="form-control" name="xweather[client_id]" value="<?php echo htmlspecialchars($xweather['client_id'] ?? ''); ?>" autocomplete="off"></div></div><div class="col-md-6"><div class="form-group"><label><?php echo _('Xweather Client Secret'); ?></label><input class="form-control" type="password" name="xweather[client_secret]" value="" placeholder="<?php echo !empty($xweather['client_secret']) ? htmlspecialchars(_('Stored; leave blank to keep')) : ''; ?>" autocomplete="new-password"></div></div></div><p class="help-block"><a href="https://www.xweather.com/docs/weather-api/getting-started" target="_blank" rel="noopener noreferrer"><i class="fa fa-external-link" aria-hidden="true"></i> <?php echo _('Create an Xweather account and API access keys'); ?></a></p>
				<div class="form-group"><label><?php echo _('Lightning Recipients'); ?></label><div class="row"><?php foreach ($extensions as $target) { ?><div class="col-sm-3"><label class="checkbox-inline"><input type="checkbox" name="xweather[recipients][]" value="<?php echo htmlspecialchars($target['extension']); ?>" <?php echo isset($lightningRecipients[$target['extension']]) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($target['extension'] . (($target['name'] ?? '') !== '' ? ' - ' . $target['name'] : '')); ?></label></div><?php } ?></div></div>
				<div class="row">
				<?php foreach (['opening' => _('Lightning Pre-tone'), 'closing' => _('Lightning Closing Tone')] as $prefix => $label) { $selectedTone = (string)($xweather[$prefix . '_tone'] ?? ($prefix === 'opening' ? 'opening_Lightning_alert' : '')); ?>
					<div class="col-md-6"><div class="form-group"><label><?php echo $label; ?></label><select class="form-control" name="xweather[<?php echo $prefix; ?>_tone]"><?php if ($prefix === 'opening') { ?><option value="opening_Lightning_alert" <?php echo $selectedTone === 'opening_Lightning_alert' ? 'selected' : ''; ?>><?php echo _('Default — Lightning_alert.mp3'); ?></option><?php } ?><?php if ($selectedTone === 'use_default') { ?><option value="use_default" selected><?php echo _('Legacy weather default'); ?></option><?php } ?><option value="" <?php echo $selectedTone === '' ? 'selected' : ''; ?>><?php echo _('None'); ?></option><optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>"><?php foreach ($setupTones as $tone) { ?><option value="<?php echo htmlspecialchars($tone); ?>" <?php echo $selectedTone === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $tone)); ?></option><?php } ?></optgroup><?php if ($setupSystemSounds) { ?><optgroup label="<?php echo htmlspecialchars(_('FreePBX System Recordings')); ?>"><?php foreach ($setupSystemSounds as $sound) { ?><option value="<?php echo htmlspecialchars($sound['value']); ?>"><?php echo htmlspecialchars($sound['label']); ?></option><?php } ?></optgroup><?php } ?></select></div></div>
				<?php } ?>
				</div>
				<div class="row"><div class="col-md-4"><div class="form-group"><label><?php echo _('Lightning TTS Volume'); ?></label><div class="input-group"><input class="form-control" name="xweather[tts_volume]" type="number" min="1" max="200" value="<?php echo (int)($xweather['tts_volume'] ?? ($settings['nws_tts_volume'] ?? 25)); ?>"><span class="input-group-addon">%</span></div><p class="help-block"><?php echo _('Default 25%. Coordinate locations are spoken as “this area.”'); ?></p></div></div></div>
				<div class="row"><div class="col-md-4"><div class="form-group"><label style="display:block"><?php echo _('Lightning Quiet Hours'); ?></label><label class="sls-setup-toggle"><input type="checkbox" name="xweather[quiet_hours_enabled]" value="1" <?php echo !empty($xweather['quiet_hours_enabled']) ? 'checked' : ''; ?>><span></span></label><strong><?php echo !empty($xweather['quiet_hours_enabled']) ? _('Enabled') : _('Disabled'); ?></strong></div></div><div class="col-md-4"><div class="form-group"><label><?php echo _('Start'); ?></label><input class="form-control" name="xweather[quiet_hours_start]" value="<?php echo htmlspecialchars($xweather['quiet_hours_start'] ?? '21:00'); ?>"></div></div><div class="col-md-4"><div class="form-group"><label><?php echo _('End'); ?></label><input class="form-control" name="xweather[quiet_hours_end]" value="<?php echo htmlspecialchars($xweather['quiet_hours_end'] ?? '06:00'); ?>"></div></div></div>
				</div>
				</section>

				<h3><i class="fa fa-key text-muted" aria-hidden="true"></i> <?php echo _('Remote API'); ?></h3>
				<div class="form-group">
					<label><i class="fa fa-lock text-muted" aria-hidden="true"></i> <?php echo _('Public PBX Hostname'); ?></label>
					<input class="form-control" value="<?php echo htmlspecialchars($settings['public_pbx_host'] ?? ($settings['sipnotify']['pbx_host'] ?? '')); ?>" readonly aria-readonly="true">
					<p class="help-block"><?php echo _('Automatically detected by the PBX and displayed for reference. It is used for desktop API links and phone image URLs.'); ?></p>
				</div>
				<div class="form-group">
					<label><?php echo _('Phone Image Transport'); ?></label>
					<select class="form-control" name="sipnotify_media_scheme">
						<option value="http" <?php echo (($settings['sipnotify']['media_scheme'] ?? 'http') === 'http') ? 'selected' : ''; ?>><?php echo _('HTTP - Legacy phone compatibility'); ?></option>
						<option value="https" <?php echo (($settings['sipnotify']['media_scheme'] ?? 'http') === 'https') ? 'selected' : ''; ?>><?php echo _('HTTPS - Requires phone-compatible certificate and TLS'); ?></option>
					</select>
					<p class="help-block"><?php echo _('This affects generated phone image files only. Control and desktop API authentication remain HTTPS.'); ?></p>
				</div>
				<div class="form-group">
					<label><?php echo _('Enable Control API'); ?></label>
					<select class="form-control" name="control_api_enabled">
						<option value="0" <?php echo empty($settings['control_api']['enabled']) ? 'selected' : ''; ?>><?php echo _('No'); ?></option>
						<option value="1" <?php echo !empty($settings['control_api']['enabled']) ? 'selected' : ''; ?>><?php echo _('Yes'); ?></option>
					</select>
					<p class="help-block"><?php echo htmlspecialchars($settings['control_api']['base_url'] ?? ''); ?></p>
				</div>

				<h3><i class="fa fa-phone text-primary" aria-hidden="true"></i> <?php echo _('SIP NOTIFY Phones'); ?></h3>
				<p class="help-block"><?php echo _('Phone notifications are sent directly through Asterisk/PJSIP to registered extensions. The sender detects common phone vendors from registered contacts. Manual format overrides can be added later in General Settings if an endpoint is unknown or needs a forced format.'); ?></p>

				<h3><i class="fa fa-volume-up text-success" aria-hidden="true"></i> <?php echo _('Audio Profiles and TTS'); ?></h3>
				<div class="alert alert-info"><i class="fa fa-random" aria-hidden="true"></i> <?php echo _('Regular paging, Weather Alerts, and Lightning Alerts use independent tones and volume settings. The installer includes the recommended default sounds for all three profiles.'); ?></div>
				<?php
				$missingVoices = array_filter($voices, static function ($voice) {
					return empty($voice['available']);
				});
				if (!empty($missingVoices)) { ?>
					<div class="alert alert-warning">
						<?php echo _('One or more Piper voice files are not present yet. The installer attempts to download them during install; if TTS fails, check internet access and rerun module install or install Piper voices manually.'); ?>
					</div>
				<?php } ?>
				<?php $audioProfiles = [
					[
						'title' => _('Regular Paging'), 'icon' => 'fa-bullhorn text-primary',
						'integration' => 'general',
						'opening_field' => 'opening_tone', 'closing_field' => 'closing_tone',
						'opening_default' => 'opening_Paging_Tone_Opening', 'closing_default' => 'closing_Paging_Tone_Closing',
						'volume_field' => 'announcement_tts_volume', 'volume_default' => 25,
						'voice_field' => 'announcement_piper_voice', 'voice_label' => _('Announcement Voice'), 'voice_default' => 'en_US-lessac-low.onnx',
						'help' => _('Bundled paging opening and closing tones; volume applies to tones and spoken announcements.'),
					],
					[
						'title' => _('Weather Alerts'), 'icon' => 'fa-cloud text-info',
						'integration' => 'weather',
						'opening_field' => 'nws_opening_tone', 'closing_field' => 'nws_closing_tone',
						'opening_default' => 'opening_NWS_alert', 'closing_default' => '',
						'volume_field' => 'nws_tts_volume', 'volume_default' => 25,
						'voice_field' => 'nws_piper_voice', 'voice_label' => _('Weather Voice'), 'voice_default' => 'en_US-amy-low.onnx',
						'help' => _('Bundled NWS_alert.wav opening tone with no closing tone by default.'),
					],
				]; ?>
				<div class="row">
				<?php foreach ($audioProfiles as $profile) { ?>
					<div class="col-md-6" data-sls-setup-integration="<?php echo htmlspecialchars($profile['integration']); ?>" <?php echo $profile['integration'] === 'weather' && !$weatherSetupEnabled ? 'hidden' : ''; ?>><div class="panel panel-default"><div class="panel-heading"><strong><i class="fa <?php echo $profile['icon']; ?>" aria-hidden="true"></i> <?php echo $profile['title']; ?></strong></div><div class="panel-body">
						<p class="text-muted"><?php echo $profile['help']; ?></p>
						<div class="row">
						<?php foreach (['opening' => _('Opening Tone'), 'closing' => _('Closing Tone')] as $side => $label) { $field = $profile[$side . '_field']; $defaultTone = $profile[$side . '_default']; $selectedTone = (string)($settings[$field] ?? $defaultTone); ?>
							<div class="col-sm-6"><div class="form-group"><label><?php echo $label; ?></label><select class="form-control" name="<?php echo htmlspecialchars($field); ?>"><option value="" <?php echo $selectedTone === '' ? 'selected' : ''; ?>><?php echo _('None'); ?></option><optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>"><?php foreach ($setupTones as $tone) { ?><option value="<?php echo htmlspecialchars($tone); ?>" <?php echo $selectedTone === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $tone) . ($tone === $defaultTone ? ' (' . _('default') . ')' : '')); ?></option><?php } ?></optgroup><?php if ($setupSystemSounds) { ?><optgroup label="<?php echo htmlspecialchars(_('FreePBX System Recordings')); ?>"><?php foreach ($setupSystemSounds as $sound) { ?><option value="<?php echo htmlspecialchars($sound['value']); ?>"><?php echo htmlspecialchars($sound['label']); ?></option><?php } ?></optgroup><?php } ?></select></div></div>
						<?php } ?>
						</div>
						<div class="row"><div class="col-sm-7"><div class="form-group"><label><?php echo $profile['voice_label']; ?></label><select class="form-control" name="<?php echo htmlspecialchars($profile['voice_field']); ?>"><?php foreach ($voices as $voice) { ?><option value="<?php echo htmlspecialchars($voice['path']); ?>" <?php echo (($settings[$profile['voice_field']] ?? '') === $voice['path']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($voice['name'] . (basename($voice['path']) === $profile['voice_default'] ? ' (' . _('default') . ')' : '')); ?></option><?php } ?></select></div></div><div class="col-sm-5"><div class="form-group"><label><?php echo _('Volume'); ?></label><div class="input-group"><input class="form-control" name="<?php echo htmlspecialchars($profile['volume_field']); ?>" type="number" min="1" max="200" value="<?php echo (int)($settings[$profile['volume_field']] ?? $profile['volume_default']); ?>"><span class="input-group-addon">%</span></div></div></div></div>
					</div></div></div>
				<?php } ?>
				</div>
				<div class="row">
					<div class="col-md-6">
						<label><?php echo _('TTS Max Seconds'); ?></label>
						<input class="form-control" name="tts_max_seconds" type="number" min="1" max="600" value="<?php echo (int)($settings['tts_max_seconds'] ?? 30); ?>">
					</div>
					<div class="col-md-6">
						<label><?php echo _('Notification Log Retention Days'); ?></label>
						<input class="form-control" name="log_retention_days" type="number" min="1" max="365" value="<?php echo (int)($settings['log_retention_days'] ?? 90); ?>">
					</div>
				</div>
				<hr>
				<button type="submit" class="btn btn-primary"><?php echo _('Complete Setup'); ?></button>
			</form>
		</div>
	</div>
</div>
</div>
</div>
<script>
(function() {
	var weatherSelect = document.getElementById('sls-setup-weather-enabled');
	var weatherDetails = document.getElementById('sls-setup-weather-details');
	var lightningSelect = document.getElementById('sls-setup-lightning-enabled');
	var lightningDetails = document.getElementById('sls-setup-lightning-details');
	var adaptive = document.getElementById('sls-setup-adaptive');
	var card = document.getElementById('sls-setup-adaptive-card');
	var shield = document.getElementById('sls-setup-adaptive-shield');
	var label = document.getElementById('sls-setup-adaptive-label');
	var zone = document.getElementById('sls-setup-adaptive-zone');
	function setFieldsEnabled(root, enabled) {
		if (!root) { return; }
		Array.prototype.forEach.call(root.querySelectorAll('input, select, textarea, button'), function(field) {
			field.disabled = !enabled;
		});
	}
	function renderOptionalSection(select, details) {
		if (!select || !details) { return false; }
		var enabled = select.value === '1';
		details.hidden = !enabled;
		select.setAttribute('aria-expanded', enabled ? 'true' : 'false');
		setFieldsEnabled(details, enabled);
		return enabled;
	}
	function renderWeatherState() {
		var enabled = renderOptionalSection(weatherSelect, weatherDetails);
		Array.prototype.forEach.call(document.querySelectorAll('[data-sls-setup-integration="weather"]'), function(profile) {
			profile.hidden = !enabled;
			setFieldsEnabled(profile, enabled);
		});
	}
	function renderAdaptiveState() {
		if (!adaptive || !card) { return; }
		var enabled = !!(lightningSelect && lightningSelect.value === '1' && adaptive.checked);
		card.classList.toggle('is-enabled', enabled);
		card.classList.toggle('is-disabled', !enabled);
		if (shield) { shield.className = 'fa fa-shield ' + (enabled ? 'text-success' : 'text-danger'); }
		if (label) { label.textContent = enabled ? <?php echo json_encode(_('Adaptive protection enabled')); ?> : <?php echo json_encode(_('Adaptive protection disabled')); ?>; }
		// The server selects the first newly configured Weather zone when this is blank.
		if (zone) { zone.required = false; }
	}
	function renderLightningState() {
		renderOptionalSection(lightningSelect, lightningDetails);
		renderAdaptiveState();
	}
	if (weatherSelect) { weatherSelect.addEventListener('change', renderWeatherState); }
	if (lightningSelect) { lightningSelect.addEventListener('change', renderLightningState); }
	if (adaptive) {
		adaptive.addEventListener('change', renderAdaptiveState);
	}
	renderWeatherState();
	renderLightningState();
}());
</script>
<?php if ($dismissible) { ?>
<script>
(function() {
	var dismiss = document.querySelector('.sls-setup-dismiss');
	if (!dismiss) {
		return;
	}
	dismiss.addEventListener('click', function() {
		var backdrop = document.querySelector('.sls-setup-backdrop');
		var shell = document.querySelector('.sls-setup-modal-shell');
		if (backdrop) {
			backdrop.style.display = 'none';
		}
		if (shell) {
			shell.style.display = 'none';
		}
	});
}());
</script>
<?php } ?>
