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
</style>
<div class="container-fluid">
	<div class="display full-border">
		<div class="fpbx-container">
			<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
			<div style="display: flex; justify-content: space-between; gap: 15px; align-items: flex-start;">
				<div>
					<h1><?php echo $showTestSection ? _('NWS Alerts') : _('NWS Settings'); ?></h1>
					<p class="text-muted">
						<?php echo _('Test and configure weather-alert delivery, then apply saved changes to push the updated configuration into the live scripts.'); ?>
					</p>
				</div>
				<?php if ($hasPendingChanges) { ?>
						<form method="post" action="config.php?display=<?php echo htmlspecialchars($settingsDisplay); ?>">
							<input type="hidden" name="slsmassnotifyserver_action" value="apply_settings">
							<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
						<button type="submit" class="btn btn-danger"><?php echo _('Apply Changes'); ?></button>
					</form>
				<?php } ?>
			</div>

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

			<?php if ($hasPendingChanges) { ?>
				<div class="alert alert-info">
					<?php echo _('Saved configuration changes are waiting to be applied.'); ?>
				</div>
			<?php } ?>

			<?php if ($showTestSection) { ?>
				<div class="panel panel-default">
					<div class="panel-heading">
						<h3 class="panel-title"><?php echo _('Manual NWS Test'); ?></h3>
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
								<?php echo _('Warning: this test will trigger all configured NWS audio recipients.'); ?>
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
					<div class="col-md-4">
						<div class="form-group">
							<label><?php echo _('NWS Audio Recipients'); ?></label>
							<div class="well sls-nws-scroll">
								<?php $selectedRecipients = array_fill_keys((array)($settings['alert_recipients'] ?? []), true); ?>
								<?php foreach ((array)($available_extensions ?? []) as $extension) { ?>
									<div class="checkbox">
										<label>
											<input type="checkbox" name="alert_recipients[]" value="<?php echo htmlspecialchars($extension['extension']); ?>" <?php echo isset($selectedRecipients[$extension['extension']]) ? 'checked' : ''; ?>>
											<?php echo htmlspecialchars($extension['extension']); ?>
											<?php if ($extension['name'] !== '') { ?>
												<?php echo ' - ' . htmlspecialchars($extension['name']); ?>
											<?php } ?>
											<span class="text-muted">
												<?php echo !empty($extension['registered']) ? _('registered') : _('not registered'); ?>
											</span>
										</label>
									</div>
								<?php } ?>
							</div>
							<p class="help-block"><?php echo _('Live NWS audio uses direct intercom calls to these extensions from SLS Mass Notification System.'); ?></p>
						</div>
					</div>
				</div>

				<div class="row">
					<div class="col-md-4">
						<div class="form-group">
							<label for="nws_zone"><?php echo _('NWS Zone or County'); ?></label>
							<input class="form-control" id="nws_zone" name="nws_zone" type="text" value="<?php echo htmlspecialchars($settings['nws_zone'] ?? ''); ?>" maxlength="6">
							<p class="help-block"><?php echo _('Examples: TXC491 for Williamson County TX, or TXZ163 for a forecast zone. Find zones at weather.gov by opening your local forecast and checking the zone/county code in NWS alerts.'); ?></p>
						</div>
					</div>
					<div class="col-md-6">
						<div class="form-group">
							<label for="nws_api_base_url"><?php echo _('NWS API Base URL'); ?></label>
							<input class="form-control" id="nws_api_base_url" name="nws_api_base_url" type="url" value="<?php echo htmlspecialchars($settings['nws_api_base_url'] ?? 'https://api.weather.gov'); ?>">
							<p class="help-block"><?php echo _('Default: https://api.weather.gov. The alert script appends /alerts/active with the selected zone.'); ?></p>
						</div>
					</div>
				</div>

				<div class="row">
					<div class="col-md-7">
						<div class="form-group">
							<label for="mail_to"><?php echo _('Notification Emails'); ?></label>
							<textarea class="form-control" id="mail_to" name="mail_to" rows="3"><?php echo htmlspecialchars($settings['mail_to']); ?></textarea>
							<p class="help-block"><?php echo _('Use one recipient per line or separate them with commas. Leave blank to disable notification emails. Messages are sent through the PBX server mail transport/Postfix configuration.'); ?></p>
						</div>
					</div>
					<div class="col-md-4">
						<div class="well">
							<strong><?php echo _('Email From'); ?></strong>
								<div style="margin-top: 8px;"><?php echo htmlspecialchars($settings['mail_from_name'] ?? 'SLS Mass Notification System'); ?></div>
							<div class="text-muted"><?php echo htmlspecialchars($settings['mail_from_addr'] ?? ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost.localdomain'))); ?></div>
						</div>
						<div class="form-group">
							<label for="discord_webhook_url"><?php echo _('Discord Webhook'); ?></label>
							<input class="form-control" id="discord_webhook_url" name="discord_webhook_url" type="url" value="<?php echo htmlspecialchars($settings['discord_webhook_url'] ?? ''); ?>">
							<p class="help-block"><?php echo _('Optional. Alerts are also sent to this Discord webhook when configured.'); ?></p>
						</div>
					</div>
				</div>

				<h3 class="sls-nws-heading"><?php echo _('Quiet Hours'); ?></h3>
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

				<h3 class="sls-nws-heading"><?php echo _('Email Templates'); ?></h3>
				<p class="help-block"><?php echo sprintf(_('Placeholders supported: %s'), htmlspecialchars($placeholderHelp)); ?></p>
				<div class="row">
					<div class="col-md-6">
						<div class="form-group">
							<label for="alert_email_subject"><?php echo _('Live Alert Subject'); ?></label>
							<input class="form-control" id="alert_email_subject" name="alert_email_subject" type="text" value="<?php echo htmlspecialchars($settings['alert_email_subject']); ?>">
						</div>
						<div class="form-group">
							<label for="alert_email_body"><?php echo _('Live Alert Body'); ?></label>
							<textarea class="form-control" id="alert_email_body" name="alert_email_body" rows="10"><?php echo htmlspecialchars($settings['alert_email_body']); ?></textarea>
						</div>
					</div>
					<div class="col-md-6">
						<div class="form-group">
							<label for="test_email_subject"><?php echo _('Test Subject'); ?></label>
							<input class="form-control" id="test_email_subject" name="test_email_subject" type="text" value="<?php echo htmlspecialchars($settings['test_email_subject']); ?>">
						</div>
						<div class="form-group">
							<label for="test_email_body"><?php echo _('Test Body'); ?></label>
							<textarea class="form-control" id="test_email_body" name="test_email_body" rows="10"><?php echo htmlspecialchars($settings['test_email_body']); ?></textarea>
						</div>
					</div>
				</div>

				<h3 class="sls-nws-heading"><?php echo _('NWS Audio and Piper TTS'); ?></h3>
				<p class="help-block">
					<?php echo _('Live weather audio now uses a short opening tone, a generated Piper TTS summary from the NWS alert payload, and a short closing tone. Generated speech defaults to 30 seconds and can be capped at up to 600 seconds.'); ?>
					<code>/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds</code>
				</p>
				<div class="row">
					<div class="col-md-5">
						<div class="form-group">
							<label for="opening_tone"><?php echo _('Opening Tone'); ?></label>
							<select class="form-control" id="opening_tone" name="opening_tone">
								<optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>">
								<?php foreach ((array)($available_tones ?? []) as $toneName) {
									if (strpos($toneName, 'opening_') !== 0) continue;
									$displayName = str_replace('_', ' ', substr($toneName, strlen('opening_')));
									if ($toneName === 'opening_Paging_Tone_Opening') {
										$displayName .= ' *';
									}
								?>
									<option value="<?php echo htmlspecialchars($toneName); ?>" <?php echo ($settings['opening_tone'] ?? 'opening_Paging_Tone_Opening') === $toneName ? 'selected' : ''; ?>>
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
							<label for="closing_tone"><?php echo _('Closing Tone'); ?></label>
							<select class="form-control" id="closing_tone" name="closing_tone">
								<optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>">
								<?php foreach ((array)($available_tones ?? []) as $toneName) {
									if (strpos($toneName, 'closing_') !== 0) continue;
									$displayName = str_replace('_', ' ', substr($toneName, strlen('closing_')));
									if ($toneName === 'closing_Paging_Tone_Closing') {
										$displayName .= ' *';
									}
								?>
									<option value="<?php echo htmlspecialchars($toneName); ?>" <?php echo ($settings['closing_tone'] ?? 'closing_Paging_Tone_Closing') === $toneName ? 'selected' : ''; ?>>
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
				<div class="well">
					<strong><?php echo _('Piper Voice'); ?></strong>
					<div style="margin-top: 8px;"><code><?php echo htmlspecialchars($settings['piper_voice'] ?? '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/en_US-lessac-low.onnx'); ?></code></div>
					<div class="text-muted"><?php echo sprintf(_('Maximum spoken summary: %s seconds'), (int)($settings['tts_max_seconds'] ?? 30)); ?></div>
				</div>

				<h3 class="sls-nws-heading"><?php echo _('Preview'); ?></h3>
				<p class="help-block"><?php echo _('Preview shows representative generated output only. It does not send SIP NOTIFY, desktop notifications, email, Discord, or audio.'); ?></p>
				<div class="row">
					<div class="col-md-6">
						<label><?php echo _('Example TTS Summary'); ?></label>
						<pre><?php echo htmlspecialchars(sprintf('Weather alert for %s. Tornado Watch. Conditions are favorable for tornadoes. Monitor official sources and be ready to shelter if warnings are issued.', $settings['nws_zone'] ?: 'your configured zone')); ?></pre>
					</div>
					<div class="col-md-6">
						<label><?php echo _('Example Desktop Payload'); ?></label>
						<pre><?php echo htmlspecialchars(json_encode([
							'kind' => 'alert',
							'event' => 'Tornado Watch',
							'priority' => 'urgent',
							'zone' => $settings['nws_zone'] ?? '',
							'description' => 'Representative NWS alert summary.',
						], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
					</div>
				</div>
				<div class="row">
					<div class="col-md-6">
						<label><?php echo _('Example Phone XML'); ?></label>
						<pre><?php echo htmlspecialchars("<YealinkIPPhoneTextScreen Beep='yes' Timeout='0'>\n  <Title>NWS ALERT</Title>\n  <Text>Tornado Watch for " . ($settings['nws_zone'] ?: 'configured zone') . "</Text>\n</YealinkIPPhoneTextScreen>"); ?></pre>
					</div>
					<div class="col-md-6">
						<label><?php echo _('Example Email Subject'); ?></label>
						<pre><?php echo htmlspecialchars(str_replace('{{event}}', 'Tornado Watch', (string)($settings['alert_email_subject'] ?? 'NWS alert triggered - {{event}}'))); ?></pre>
						<p class="help-block"><?php echo _('Emails use the PBX server mail transport/Postfix configuration.'); ?></p>
					</div>
				</div>

				<div style="margin-top: 20px;">
					<button type="submit" class="btn btn-primary"><?php echo _('Save Configuration'); ?></button>
				</div>
			</form>
		</div>
	</div>
</div>
