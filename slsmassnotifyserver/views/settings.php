<?php
// Southland Servers Mass Notification Plugin
$saveResult = $save_result ?? null;
$applyResult = $apply_result ?? null;
$hasPendingChanges = !empty($has_pending_changes);
$placeholderHelp = "{{event}}, {{severity}}, {{message_type}}, {{audio}}, {{page_group}}, {{alert_id}}, {{zone}}, {{time}}, {{source_name}}, {{trigger_source}}, {{trigger_extension}}, {{trigger_name}}, {{audio_sequence}}";
$hourOptions = [];
for ($hour = 0; $hour < 24; $hour++) {
	$hourOptions[] = sprintf('%02d:00', $hour);
}
?>
<div class="container-fluid">
	<div class="display full-border">
		<div class="fpbx-container">
			<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
			<div style="display: flex; justify-content: space-between; gap: 15px; align-items: flex-start;">
				<div>
					<h1><?php echo _('NWS Settings'); ?></h1>
					<p class="text-muted">
						<?php echo _('Save changes here, then apply them to push the updated configuration into the live scripts.'); ?>
					</p>
				</div>
				<?php if ($hasPendingChanges) { ?>
					<form method="post" action="config.php?display=slsmassnotifyserver_settings">
						<input type="hidden" name="slsmassnotifyserver_action" value="apply_settings">
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

			<form method="post" action="config.php?display=slsmassnotifyserver_settings" enctype="multipart/form-data">
				<input type="hidden" name="slsmassnotifyserver_action" value="save_settings">

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
							<div class="well" style="max-height: 220px; overflow: auto;">
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
							<p class="help-block"><?php echo _('Use one recipient per line or separate them with commas. Leave blank to disable notification emails.'); ?></p>
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

				<h3><?php echo _('Quiet Hours'); ?></h3>
				<p class="help-block"><?php echo _('During quiet hours, only selected critical live NWS alerts are delivered. Manual tests are not affected.'); ?></p>
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
							<div class="well" style="max-height: 260px; overflow: auto;">
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

				<h3><?php echo _('Email Templates'); ?></h3>
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

				<h3><?php echo _('NWS Audio and Piper TTS'); ?></h3>
				<p class="help-block">
					<?php echo _('Live weather audio now uses a short opening tone, a generated Piper TTS summary from the NWS alert payload, and a short closing tone. Generated speech defaults to 30 seconds and can be capped at up to 600 seconds.'); ?>
					<code>/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds</code>
				</p>
				<div class="row">
					<div class="col-md-5">
						<div class="form-group">
							<label for="opening_tone"><?php echo _('Opening Tone'); ?></label>
							<select class="form-control" id="opening_tone" name="opening_tone">
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
							</select>
							<p class="help-block"><?php echo _('WAV uploads are converted to 8 kHz mono for Asterisk playback.'); ?></p>
							<input class="form-control" id="opening_tone_upload" name="opening_tone_upload" type="file" accept=".wav,audio/wav,audio/x-wav">
						</div>
					</div>
					<div class="col-md-5">
						<div class="form-group">
							<label for="closing_tone"><?php echo _('Closing Tone'); ?></label>
							<select class="form-control" id="closing_tone" name="closing_tone">
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
							</select>
							<p class="help-block"><?php echo _('Use short tones so the complete alert stays concise.'); ?></p>
							<input class="form-control" id="closing_tone_upload" name="closing_tone_upload" type="file" accept=".wav,audio/wav,audio/x-wav">
						</div>
					</div>
				</div>
				<div class="well">
					<strong><?php echo _('Piper Voice'); ?></strong>
					<div style="margin-top: 8px;"><code><?php echo htmlspecialchars($settings['piper_voice'] ?? '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/en_US-lessac-low.onnx'); ?></code></div>
					<div class="text-muted"><?php echo sprintf(_('Maximum spoken summary: %s seconds'), (int)($settings['tts_max_seconds'] ?? 30)); ?></div>
				</div>

				<div style="margin-top: 20px;">
					<button type="submit" class="btn btn-primary"><?php echo _('Save Configuration'); ?></button>
				</div>
			</form>
		</div>
	</div>
</div>
