<?php
// Southland Servers Mass Notification Plugin
$saveResult = $save_result ?? null;
$applyResult = $apply_result ?? null;
$hasPendingChanges = !empty($has_pending_changes);
$settingsDisplay = $settings_display ?? 'slsmassnotifyserver_settings';
$showTestSection = !empty($show_test_section);
$testResult = $test_result ?? null;
$cooldownRemaining = (int)($cooldown_remaining ?? 0);
$csrfToken = (string)($csrf_token ?? '');
$systemSounds = is_array($available_system_sounds ?? null) ? $available_system_sounds : [];
$voices = is_array($available_voices ?? null) ? $available_voices : [];
$zoneGroups = array_values((array)($settings['nws_zones'] ?? []));
$xweather = is_array($settings['xweather'] ?? null) ? $settings['xweather'] : [];
$placeholderHelp = "{{event}}, {{severity}}, {{message_type}}, {{audio}}, {{page_group}}, {{alert_id}}, {{zone}}, {{time}}, {{source_name}}, {{trigger_source}}, {{trigger_extension}}, {{trigger_name}}, {{audio_sequence}}";
$hourOptions = [];
for ($hour = 0; $hour < 24; $hour++) {
	$hourOptions[] = sprintf('%02d:00', $hour);
}
?>
<style>
.sls-nws-heading {
	margin: 28px 0 14px;
	padding-bottom: 8px;
	border-bottom: 1px solid #d7dce2;
	font-size: 18px;
}
.sls-nws-section-note { margin-bottom: 16px; }
.sls-nws-scroll { max-height: 240px; overflow: auto; }
.sls-nws-page { max-width: 1180px; margin: 0 auto; }
.sls-settings-card { border: 1px solid #dfe5ec; border-radius: 8px; box-shadow: 0 2px 8px rgba(15,23,42,.05); margin-bottom: 18px; overflow: hidden; }
.sls-settings-card > .panel-heading { padding: 14px 18px; background: #f8fafc; border-bottom: 1px solid #e8edf2; }
.sls-settings-card > .panel-body { padding: 18px; }
.sls-zone-empty { padding: 24px; text-align: center; color: #64748b; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; }
.sls-zone-editor { border: 1px solid #dfe5ec; border-radius: 7px; padding: 14px; margin-bottom: 12px; background: #fff; }
.sls-zone-editor-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.sls-zone-summary-table { margin-bottom: 0; }
.sls-zone-summary-table td { vertical-align: middle !important; }
.sls-zone-modal .modal-dialog { width: min(960px, calc(100% - 30px)); }
.sls-zone-modal .modal-body { max-height: 70vh; overflow: auto; background: #f8fafc; }
.sls-recipient-grid { border: 1px solid #e5e7eb; border-radius: 6px; padding: 9px 12px; background: #fbfcfe; }
.sls-sticky-actions { position: sticky; bottom: 0; z-index: 4; background: rgba(255,255,255,.96); padding: 13px 16px; border: 1px solid #dfe5ec; border-radius: 8px; box-shadow: 0 -2px 10px rgba(15,23,42,.06); }
.sls-weather-title { margin:0 0 7px; font-size:30px; line-height:1.2; font-weight:700; }
</style>
<div class="container-fluid sls-nws-page">
	<div class="display full-border">
		<div class="fpbx-container">
			<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
			<div style="display: flex; justify-content: space-between; gap: 15px; align-items: flex-start;">
				<div>
					<h1 class="sls-weather-title"><i class="fa fa-cloud text-primary" aria-hidden="true"></i> <?php echo $showTestSection ? _('Weather Alerts') : _('Weather Alert Settings'); ?></h1>
					<p class="text-muted"><?php echo _('Test and configure weather-alert delivery.'); ?></p>
				</div>
			</div>
			<div class="alert alert-info" style="margin-bottom:18px"><i class="fa fa-info-circle"></i> <?php echo _('Weather Alerts supports United States locations and zones through the U.S. National Weather Service weather.gov API only.'); ?></div>

			<?php if (is_array($saveResult)) { ?>
				<div class="alert alert-<?php echo !empty($saveResult['success']) ? 'success' : 'warning'; ?>">
					<?php echo htmlspecialchars($saveResult['message']); ?>
					<?php if (!empty($saveResult['errors'])) { ?>
						<ul style="margin-top: 10px;">
							<?php foreach ($saveResult['errors'] as $error) { ?>
								<li><?php echo htmlspecialchars($error); ?></li>
							<?php } ?>
						</ul>
					<?php } ?>
				</div>
			<?php } ?>

			<?php if (is_array($applyResult)) { ?>
				<div class="alert alert-<?php echo !empty($applyResult['success']) ? 'success' : 'warning'; ?>">
					<?php echo htmlspecialchars($applyResult['message']); ?>
					<?php if (!empty($applyResult['errors'])) { ?>
						<ul style="margin-top: 10px;">
							<?php foreach ($applyResult['errors'] as $error) { ?>
								<li><?php echo htmlspecialchars($error); ?></li>
							<?php } ?>
						</ul>
					<?php } ?>
				</div>
			<?php } ?>

			<?php if ($showTestSection) { ?>
				<div class="panel panel-default sls-settings-card">
					<div class="panel-heading">
						<h3 class="panel-title"><i class="fa fa-play-circle text-warning" aria-hidden="true"></i> <?php echo _('Manual Weather Alert Test'); ?></h3>
					</div>
					<div class="panel-body">
						<p class="text-muted">
							<?php echo _('Trigger a manual Piper TTS alert using the configured opening and closing tones.'); ?>
						</p>

						<div id="sls-test-cooldown-alert" class="alert alert-warning" <?php echo empty($cooldownRemaining) ? 'style="display: none;"' : ''; ?>>
							<span id="sls-test-cooldown-text" data-remaining="<?php echo (int)$cooldownRemaining; ?>">
								<?php echo !empty($cooldownRemaining) ? sprintf(_('Manual testing is on cooldown. Wait %s seconds before triggering another test.'), (int)$cooldownRemaining) : ''; ?>
							</span>
						</div>

						<div id="sls-test-result" style="display: none;"></div>

						<?php if (is_array($testResult)) { ?>
							<div class="alert alert-<?php echo !empty($testResult['success']) ? 'success' : 'warning'; ?>">
								<?php echo htmlspecialchars($testResult['message']); ?>
							</div>
						<?php } ?>

							<form id="sls-test-form" method="post" action="config.php?display=<?php echo htmlspecialchars($settingsDisplay); ?>">
								<input type="hidden" name="slsmassnotifyserver_action" value="trigger_test">
								<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
							<input type="hidden" name="ajax" value="1">

							<div class="alert alert-danger">
								<?php echo _('Warning: this test sends audio and visuals to the selected weather-zone recipients.'); ?>
							</div>
							<div class="form-group"><label><?php echo _('Test Zones'); ?></label>
								<div class="radio"><label><input type="radio" name="test_zone_scope" value="all" checked> <?php echo _('All configured zones'); ?></label></div>
								<div class="radio"><label><input type="radio" name="test_zone_scope" value="selected"> <?php echo _('Only selected zones'); ?></label></div>
								<div class="well sls-nws-scroll"><?php foreach ($zoneGroups as $zoneGroup) { ?><div class="checkbox"><label><input type="checkbox" name="test_zone_ids[]" value="<?php echo htmlspecialchars($zoneGroup['id'] ?? ''); ?>"> <?php echo htmlspecialchars(($zoneGroup['name'] ?? '') . ' (' . ($zoneGroup['zone'] ?? '') . ')'); ?></label></div><?php } ?></div>
							</div>

							<button type="submit" id="sls-test-submit" class="btn btn-danger" <?php echo !empty($cooldownRemaining) ? 'disabled' : ''; ?>><?php echo _('Trigger Piper TTS Test'); ?></button>
						</form>
					</div>
				</div>

				<script>
				(function() {
					var form = document.getElementById('sls-test-form');
					var submit = document.getElementById('sls-test-submit');
					var cooldownAlert = document.getElementById('sls-test-cooldown-alert');
					var cooldownText = document.getElementById('sls-test-cooldown-text');
					var result = document.getElementById('sls-test-result');
					if (!form || !submit || !cooldownAlert || !cooldownText || !result) {
						return;
					}
					var remaining = parseInt(cooldownText.getAttribute('data-remaining') || '0', 10) || 0;
					function renderCooldown() {
						if (remaining > 0) {
							submit.disabled = true;
							cooldownAlert.style.display = 'block';
							cooldownText.textContent = 'Manual testing is on cooldown. Wait ' + remaining + ' seconds before triggering another test.';
							return;
						}
						submit.disabled = false;
						cooldownAlert.style.display = 'none';
						cooldownText.textContent = '';
					}
					setInterval(function() {
						if (remaining > 0) {
							remaining -= 1;
							renderCooldown();
						}
					}, 1000);
					setInterval(function() {
						fetch('config.php?display=<?php echo htmlspecialchars($settingsDisplay); ?>&slsmassnotifyserver_action=cooldowns', {credentials: 'same-origin'})
							.then(function(response) { return response.json(); })
							.then(function(data) {
								if (data && data.cooldowns && data.cooldowns.test) {
									remaining = parseInt(data.cooldowns.test.remaining || '0', 10) || 0;
									renderCooldown();
								}
							})
							.catch(function() {});
					}, 10000);
					form.addEventListener('submit', function(event) {
						event.preventDefault();
						if (remaining > 0 || !confirm('Are you sure you wish to trigger a test? This will trigger all configured NWS recipients.')) {
							return;
						}
						submit.disabled = true;
						var body = new FormData(form);
						fetch(form.action, {method: 'POST', credentials: 'same-origin', body: body})
							.then(function(response) { return response.json(); })
							.then(function(data) {
								result.style.display = 'block';
								result.className = 'alert alert-' + (data && data.success ? 'success' : 'warning');
								result.textContent = data && data.message ? data.message : 'Test request finished.';
								if (data && data.cooldowns && data.cooldowns.test) {
									remaining = parseInt(data.cooldowns.test.remaining || '0', 10) || 0;
								}
								renderCooldown();
							})
							.catch(function() {
								result.style.display = 'block';
								result.className = 'alert alert-danger';
								result.textContent = 'Test request failed.';
								renderCooldown();
							});
					});
					renderCooldown();
				}());
				</script>
			<?php } ?>

				<form method="post" action="config.php?display=<?php echo htmlspecialchars($settingsDisplay); ?>" enctype="multipart/form-data">
					<input type="hidden" name="slsmassnotifyserver_action" value="save_settings">
					<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

				<div class="row">
					<div class="col-md-4">
						<div class="form-group">
							<label for="enabled"><?php echo _('Enabled'); ?></label>
							<select class="form-control" id="enabled" name="enabled">
								<option value="1" <?php echo $settings['enabled'] === '1' ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option>
								<option value="0" <?php echo $settings['enabled'] === '0' ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option>
							</select>
							<p class="help-block"><?php echo _('When disabled, both live NWS alerts and manual test paging are skipped.'); ?></p>
						</div>
					</div>
					<div class="col-md-6">
						<div class="form-group">
							<label for="nws_api_base_url"><?php echo _('NWS API Base URL'); ?></label>
							<input class="form-control" id="nws_api_base_url" name="nws_api_base_url" type="url" value="<?php echo htmlspecialchars($settings['nws_api_base_url'] ?? 'https://api.weather.gov'); ?>">
							<p class="help-block"><?php echo _('Default: https://api.weather.gov. Each enabled group is polled once per minute.'); ?></p>
						</div>
					</div>
				</div>

				<div class="panel panel-default sls-settings-card">
					<div class="panel-heading clearfix">
						<div class="pull-left"><strong><i class="fa fa-map-marker text-danger" aria-hidden="true"></i> <?php echo _('NWS Zone Groups'); ?></strong><div class="text-muted"><small><?php echo _('Up to five zones, each with its own extension recipients.'); ?></small></div></div>
						<button type="button" class="btn btn-primary btn-sm pull-right" data-toggle="modal" data-target="#sls-zone-manager"><i class="fa fa-map-marker"></i> <?php echo _('Manage Zone Groups'); ?></button>
					</div>
					<div class="panel-body">
					<?php if (empty($zoneGroups)) { ?>
						<div class="sls-zone-empty"><i class="fa fa-map-o fa-2x"></i><br><?php echo _('No NWS zone groups are configured. Use Manage Zone Groups to add one.'); ?></div>
					<?php } else { ?>
						<div class="table-responsive"><table class="table table-striped sls-zone-summary-table"><thead><tr><th><?php echo _('Group'); ?></th><th><?php echo _('NWS Zone'); ?></th><th><?php echo _('Recipients'); ?></th></tr></thead><tbody>
						<?php foreach ($zoneGroups as $zoneGroup) { ?><tr><td><strong><?php echo htmlspecialchars($zoneGroup['name'] ?? ''); ?></strong></td><td><code><?php echo htmlspecialchars($zoneGroup['zone'] ?? ''); ?></code></td><td><?php echo htmlspecialchars(implode(', ', (array)($zoneGroup['extensions'] ?? []))); ?></td></tr><?php } ?>
						</tbody></table></div>
					<?php } ?>
					</div>
				</div>

				<div class="modal fade sls-zone-modal" id="sls-zone-manager" tabindex="-1" role="dialog" aria-hidden="true">
					<div class="modal-dialog"><div class="modal-content">
						<div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><?php echo _('Manage NWS Zone Groups'); ?></h4></div>
						<div class="modal-body"><div id="sls-zone-editor-list">
						<?php foreach ($zoneGroups as $zoneIndex => $zoneGroup) { $zoneRecipients = array_fill_keys((array)($zoneGroup['extensions'] ?? []), true); ?>
							<div class="sls-zone-editor" data-zone-editor>
								<div class="sls-zone-editor-header"><strong data-zone-title><?php echo htmlspecialchars($zoneGroup['name'] ?? sprintf(_('Weather Zone %d'), $zoneIndex + 1)); ?></strong><button type="button" class="btn btn-link btn-sm text-danger" data-zone-remove><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div>
								<input type="hidden" data-zone-field="id" name="nws_zones[<?php echo $zoneIndex; ?>][id]" value="<?php echo htmlspecialchars($zoneGroup['id'] ?? ''); ?>">
								<div class="row"><div class="col-md-7"><div class="form-group"><label><?php echo _('Group Name'); ?></label><input class="form-control" data-zone-field="name" name="nws_zones[<?php echo $zoneIndex; ?>][name]" maxlength="64" value="<?php echo htmlspecialchars($zoneGroup['name'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(_('Williamson County')); ?>"></div></div><div class="col-md-5"><div class="form-group"><label><?php echo _('NWS Zone'); ?></label><input class="form-control" data-zone-field="zone" name="nws_zones[<?php echo $zoneIndex; ?>][zone]" maxlength="6" value="<?php echo htmlspecialchars($zoneGroup['zone'] ?? ''); ?>" placeholder="TXC491"></div></div></div>
								<label><?php echo _('Recipient Extensions'); ?></label><div class="sls-recipient-grid sls-nws-scroll"><div class="row"><?php foreach ((array)($available_extensions ?? []) as $extension) { ?><div class="col-md-4"><div class="checkbox"><label><input type="checkbox" data-zone-extension name="nws_zones[<?php echo $zoneIndex; ?>][extensions][]" value="<?php echo htmlspecialchars($extension['extension']); ?>" <?php echo isset($zoneRecipients[$extension['extension']]) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($extension['extension'] . ($extension['name'] !== '' ? ' - ' . $extension['name'] : '')); ?> <span class="text-muted"><?php echo !empty($extension['registered']) ? _('online') : _('offline'); ?></span></label></div></div><?php } ?></div></div>
							</div>
						<?php } ?>
						</div><button type="button" class="btn btn-default" id="sls-zone-add"><i class="fa fa-plus"></i> <?php echo _('Add Zone Group'); ?></button> <span class="text-muted" id="sls-zone-count"></span></div>
						<div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal"><?php echo _('Done'); ?></button></div>
					</div></div>
				</div>
				<script type="text/template" id="sls-zone-template"><div class="sls-zone-editor" data-zone-editor><div class="sls-zone-editor-header"><strong data-zone-title><?php echo _('New Weather Zone'); ?></strong><button type="button" class="btn btn-link btn-sm text-danger" data-zone-remove><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div><input type="hidden" data-zone-field="id" value=""><div class="row"><div class="col-md-7"><div class="form-group"><label><?php echo _('Group Name'); ?></label><input class="form-control" data-zone-field="name" maxlength="64" placeholder="<?php echo htmlspecialchars(_('Williamson County')); ?>"></div></div><div class="col-md-5"><div class="form-group"><label><?php echo _('NWS Zone'); ?></label><input class="form-control" data-zone-field="zone" maxlength="6" placeholder="TXC491"></div></div></div><label><?php echo _('Recipient Extensions'); ?></label><div class="sls-recipient-grid sls-nws-scroll"><div class="row"><?php foreach ((array)($available_extensions ?? []) as $extension) { ?><div class="col-md-4"><div class="checkbox"><label><input type="checkbox" data-zone-extension value="<?php echo htmlspecialchars($extension['extension']); ?>"> <?php echo htmlspecialchars($extension['extension'] . ($extension['name'] !== '' ? ' - ' . $extension['name'] : '')); ?> <span class="text-muted"><?php echo !empty($extension['registered']) ? _('online') : _('offline'); ?></span></label></div></div><?php } ?></div></div></div></script>

				<h3 class="sls-nws-heading"><i class="fa fa-moon-o text-muted" aria-hidden="true"></i> <?php echo _('Quiet Hours'); ?></h3>
				<p class="help-block sls-nws-section-note"><?php echo _('During quiet hours, only selected critical live NWS alerts are delivered. Manual tests are not affected.'); ?></p>
				<div class="row">
					<div class="col-md-3">
						<div class="form-group">
							<label for="quiet_hours_enabled"><?php echo _('Quiet Hours'); ?></label>
							<select class="form-control" id="quiet_hours_enabled" name="quiet_hours_enabled">
								<option value="1" <?php echo ($settings['quiet_hours_enabled'] ?? '0') === '1' ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option>
								<option value="0" <?php echo ($settings['quiet_hours_enabled'] ?? '0') === '0' ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option>
							</select>
						</div>
					</div>
					<div class="col-md-3">
						<div class="form-group">
							<label for="quiet_hours_start"><?php echo _('Start Time'); ?></label>
							<select class="form-control" id="quiet_hours_start" name="quiet_hours_start">
								<?php foreach ($hourOptions as $hourOption) { ?>
									<option value="<?php echo htmlspecialchars($hourOption); ?>" <?php echo ($settings['quiet_hours_start'] ?? '21:00') === $hourOption ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars($hourOption); ?>
									</option>
								<?php } ?>
							</select>
						</div>
					</div>
					<div class="col-md-3">
						<div class="form-group">
							<label for="quiet_hours_end"><?php echo _('End Time'); ?></label>
							<select class="form-control" id="quiet_hours_end" name="quiet_hours_end">
								<?php foreach ($hourOptions as $hourOption) { ?>
									<option value="<?php echo htmlspecialchars($hourOption); ?>" <?php echo ($settings['quiet_hours_end'] ?? '06:00') === $hourOption ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars($hourOption); ?>
									</option>
								<?php } ?>
							</select>
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-md-8">
						<div class="form-group">
							<label><?php echo _('Critical Alerts During Quiet Hours'); ?></label>
							<div class="well sls-nws-scroll">
								<?php $criticalEvents = array_fill_keys((array)($settings['quiet_critical_events'] ?? []), true); ?>
								<?php foreach ($events_map as $eventName) { ?>
									<div class="checkbox">
										<label>
											<input type="checkbox" name="quiet_critical_events[]" value="<?php echo htmlspecialchars($eventName); ?>" <?php echo isset($criticalEvents[$eventName]) ? 'checked' : ''; ?>>
											<?php echo htmlspecialchars($eventName); ?>
										</label>
									</div>
								<?php } ?>
							</div>
						</div>
					</div>
				</div>

				<div class="panel panel-default sls-settings-card"><div class="panel-heading"><strong><i class="fa fa-volume-up text-success" aria-hidden="true"></i> <?php echo _('Weather Alert Audio'); ?></strong><div class="text-muted"><small><?php echo _('Choose the opening and closing sounds around the concise weather summary.'); ?></small></div></div><div class="panel-body">
				<div class="row">
					<div class="col-md-5">
						<div class="form-group">
							<label for="nws_opening_tone"><?php echo _('Opening Tone'); ?></label>
							<select class="form-control" id="nws_opening_tone" name="nws_opening_tone">
								<option value="" <?php echo ($settings['nws_opening_tone'] ?? '') === '' ? 'selected' : ''; ?>><?php echo _('None'); ?></option>
								<optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>">
								<?php foreach ((array)($available_tones ?? []) as $toneName) {
									if (strpos($toneName, 'opening_') !== 0) continue;
									$displayName = str_replace('_', ' ', substr($toneName, strlen('opening_')));
									if ($toneName === 'opening_NWS_alert') {
										$displayName = 'NWS alert (' . _('default; bundled as NWS_alert.wav') . ')';
									}
								?>
									<option value="<?php echo htmlspecialchars($toneName); ?>" <?php echo ($settings['nws_opening_tone'] ?? 'opening_NWS_alert') === $toneName ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars($displayName); ?>
									</option>
								<?php } ?>
								</optgroup>
								<?php if ($systemSounds) { ?><optgroup label="<?php echo htmlspecialchars(_('FreePBX System Recordings')); ?>">
								<?php foreach ($systemSounds as $sound) { ?><option value="<?php echo htmlspecialchars($sound['value']); ?>"><?php echo htmlspecialchars($sound['label']); ?></option><?php } ?>
								</optgroup><?php } ?>
							</select>
							<p class="help-block"><?php echo _('Upload additional choices in Admin > System Recordings. The selected recording is converted for Asterisk when saved.'); ?></p>
						</div>
					</div>
					<div class="col-md-5">
						<div class="form-group">
							<label for="nws_closing_tone"><?php echo _('Closing Tone'); ?></label>
							<select class="form-control" id="nws_closing_tone" name="nws_closing_tone">
								<option value="" <?php echo ($settings['nws_closing_tone'] ?? '') === '' ? 'selected' : ''; ?>><?php echo _('None'); ?></option>
								<optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>">
								<?php foreach ((array)($available_tones ?? []) as $toneName) {
									if (strpos($toneName, 'closing_') !== 0) continue;
									$displayName = str_replace('_', ' ', substr($toneName, strlen('closing_')));
								?>
									<option value="<?php echo htmlspecialchars($toneName); ?>" <?php echo ($settings['nws_closing_tone'] ?? '') === $toneName ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars($displayName); ?>
									</option>
								<?php } ?>
								</optgroup>
								<?php if ($systemSounds) { ?><optgroup label="<?php echo htmlspecialchars(_('FreePBX System Recordings')); ?>">
								<?php foreach ($systemSounds as $sound) { ?><option value="<?php echo htmlspecialchars($sound['value']); ?>"><?php echo htmlspecialchars($sound['label']); ?></option><?php } ?>
								</optgroup><?php } ?>
							</select>
							<p class="help-block"><?php echo _('Use a short System Recording so the complete alert stays concise.'); ?></p>
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-md-6"><div class="form-group"><label for="nws_piper_voice"><?php echo _('Weather TTS Voice'); ?></label><select class="form-control" id="nws_piper_voice" name="nws_piper_voice"><?php foreach ($voices as $voice) { ?><option value="<?php echo htmlspecialchars($voice['path']); ?>" <?php echo (($settings['nws_piper_voice'] ?? '') === $voice['path']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($voice['name'] . (basename($voice['path']) === 'en_US-amy-low.onnx' ? ' (' . _('default') . ')' : '')); ?></option><?php } ?></select><p class="help-block"><?php echo _('Amy is the default Weather Alert voice.'); ?></p></div></div>
					<div class="col-md-3"><div class="form-group"><label for="nws_tts_volume"><?php echo _('Weather Volume'); ?></label><div class="input-group"><input class="form-control" id="nws_tts_volume" name="nws_tts_volume" type="number" min="1" max="200" value="<?php echo (int)($settings['nws_tts_volume'] ?? 25); ?>"><span class="input-group-addon">%</span></div><p class="help-block"><?php echo _('Default 25%.'); ?></p></div></div>
					<div class="col-md-3"><div class="form-group"><label for="nws_tts_max_seconds"><?php echo _('Maximum Summary'); ?></label><div class="input-group"><input class="form-control" id="nws_tts_max_seconds" name="tts_max_seconds" type="number" min="1" max="600" value="<?php echo (int)($settings['tts_max_seconds'] ?? 30); ?>"><span class="input-group-addon"><?php echo _('sec'); ?></span></div></div></div>
				</div>
				</div></div>

				<div class="sls-sticky-actions">
					<button type="submit" class="btn btn-primary"><?php echo _('Save Configuration'); ?></button>
				</div>
			</form>
		</div>
	</div>
</div>
<script>
(function() {
	var list = document.getElementById('sls-zone-editor-list');
	var add = document.getElementById('sls-zone-add');
	var template = document.getElementById('sls-zone-template');
	var count = document.getElementById('sls-zone-count');
	if (!list || !add || !template) return;
	function editors() { return Array.prototype.slice.call(list.querySelectorAll('[data-zone-editor]')); }
	function reindex() {
		var rows = editors();
		rows.forEach(function(row, index) {
			['id','name','zone'].forEach(function(field) {
				var input = row.querySelector('[data-zone-field="' + field + '"]');
				if (input) input.name = 'nws_zones[' + index + '][' + field + ']';
			});
			Array.prototype.forEach.call(row.querySelectorAll('[data-zone-extension]'), function(input) { input.name = 'nws_zones[' + index + '][extensions][]'; });
			var title = row.querySelector('[data-zone-title]');
			var name = row.querySelector('[data-zone-field="name"]');
			if (title) title.textContent = name && name.value.trim() ? name.value.trim() : 'Weather Zone ' + (index + 1);
		});
		add.disabled = rows.length >= 5;
		if (count) count.textContent = rows.length + ' / 5 groups';
	}
	list.addEventListener('click', function(event) {
		var remove = event.target.closest ? event.target.closest('[data-zone-remove]') : null;
		if (!remove) return;
		var row = remove.closest('[data-zone-editor]');
		if (row) row.parentNode.removeChild(row);
		reindex();
	});
	list.addEventListener('input', function(event) { if (event.target.matches('[data-zone-field="name"]')) reindex(); });
	add.addEventListener('click', function() {
		if (editors().length >= 5) return;
		var shell = document.createElement('div');
		shell.innerHTML = template.textContent || template.innerHTML;
		var row = shell.firstElementChild;
		if (row) list.appendChild(row);
		reindex();
		var first = row && row.querySelector('[data-zone-field="name"]');
		if (first) first.focus();
	});
	reindex();
}());
</script>
