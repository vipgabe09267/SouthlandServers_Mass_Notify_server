<?php
// Southland Servers Mass Notification Module
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
$discordDestinations = array_values(array_filter((array)($settings['discord_webhooks'] ?? []), static function ($row) { return is_array($row) && !empty($row['enabled']); }));
$genericDestinations = array_values(array_filter((array)($settings['generic_webhooks'] ?? []), static function ($row) { return is_array($row) && !empty($row['enabled']); }));
$desktopClients = array_values(array_filter((array)($available_desktop_clients ?? []), 'is_array'));
$desktopNames = [];
foreach ($desktopClients as $desktopClient) {
	$username = (string)($desktopClient['username'] ?? '');
	$name = trim((string)($desktopClient['name'] ?? ''));
	$clientId = trim((string)($desktopClient['client_id'] ?? ''));
	$identity = $name !== '' && $name !== $username ? $name . ' — ' . $username : $username;
	if ($clientId !== '') {
		$identity .= ' · ' . _('client ID') . ' ' . $clientId;
	}
	$desktopNames[$username] = $identity;
}
$xweather = is_array($settings['xweather'] ?? null) ? $settings['xweather'] : [];
$placeholderHelp = "{{event}}, {{severity}}, {{message_type}}, {{audio}}, {{page_group}}, {{alert_id}}, {{zone}}, {{time}}, {{source_name}}, {{trigger_source}}, {{trigger_extension}}, {{trigger_name}}, {{audio_sequence}}";
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
.sls-nws-scroll.is-disabled { opacity: .48; background: #f1f5f9; transition: opacity .15s ease; }
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
.sls-operation-status { display:flex; align-items:flex-start; gap:12px; margin:0 0 16px; padding:12px 14px; border:1px solid #bfdbfe; border-radius:8px; background:#eff6ff; color:#1e3a8a; box-shadow:0 1px 3px rgba(15,23,42,.04); }
.sls-operation-status .sls-operation-icon { display:inline-flex; flex:0 0 30px; width:30px; height:30px; align-items:center; justify-content:center; border-radius:50%; background:#dbeafe; }
.sls-operation-status .sls-operation-copy { min-width:0; line-height:1.35; }
.sls-operation-status .sls-operation-copy strong { display:block; margin-bottom:2px; }
.sls-operation-status .sls-operation-copy span { display:block; color:inherit; opacity:.88; }
.sls-operation-status.is-success { border-color:#86efac; background:#f0fdf4; color:#166534; }
.sls-operation-status.is-success .sls-operation-icon { background:#dcfce7; }
.sls-operation-status.is-error { border-color:#fca5a5; background:#fef2f2; color:#991b1b; }
.sls-operation-status.is-error .sls-operation-icon { background:#fee2e2; }
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
							<?php echo _('Exercise the channels assigned to the selected zone groups. Phone targets receive the configured tones, Piper TTS page, and SIP NOTIFY; desktop-only groups receive a targeted live event without creating a phone page.'); ?>
						</p>

						<div id="sls-test-cooldown-alert" class="alert alert-warning" <?php echo empty($cooldownRemaining) ? 'style="display: none;"' : ''; ?>>
							<span id="sls-test-cooldown-text" data-remaining="<?php echo (int)$cooldownRemaining; ?>">
								<?php echo !empty($cooldownRemaining) ? sprintf(_('Manual testing is on cooldown. Wait %s seconds before triggering another test.'), (int)$cooldownRemaining) : ''; ?>
							</span>
						</div>

						<div id="sls-test-result" class="sls-operation-status" style="display:none" role="status" aria-live="polite">
							<span class="sls-operation-icon"><i data-test-status-icon class="fa fa-circle-o" aria-hidden="true"></i></span>
							<span class="sls-operation-copy"><strong data-test-status-title></strong><span data-test-status-message></span></span>
						</div>

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
								<?php echo _('Warning: this submits phone audio/SIP NOTIFY and targeted desktop events wherever those channels are configured. Zone email, Discord, and generic webhooks are intentionally skipped.'); ?>
							</div>
							<div class="form-group"><label><?php echo _('Test Zones'); ?></label>
								<div class="radio"><label><input type="radio" name="test_zone_scope" value="all" checked> <?php echo _('All configured zones'); ?></label></div>
								<div class="radio"><label><input type="radio" name="test_zone_scope" value="selected"> <?php echo _('Only selected zones'); ?></label></div>
								<div class="well sls-nws-scroll"><?php foreach ($zoneGroups as $zoneGroup) { ?><div class="checkbox"><label><input type="checkbox" name="test_zone_ids[]" value="<?php echo htmlspecialchars($zoneGroup['id'] ?? ''); ?>"> <?php echo htmlspecialchars(($zoneGroup['name'] ?? '') . ' (' . ($zoneGroup['zone'] ?? '') . ')'); ?></label></div><?php } ?></div>
							</div>

							<button type="submit" id="sls-test-submit" class="btn btn-danger" <?php echo !empty($cooldownRemaining) ? 'disabled' : ''; ?>><?php echo _('Run Weather Delivery Test'); ?></button>
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
					var resultIcon = result ? result.querySelector('[data-test-status-icon]') : null;
					var resultTitle = result ? result.querySelector('[data-test-status-title]') : null;
					var resultMessage = result ? result.querySelector('[data-test-status-message]') : null;
					var scopeInputs = form ? form.querySelectorAll('input[name="test_zone_scope"]') : [];
					var zoneInputs = form ? form.querySelectorAll('input[name="test_zone_ids[]"]') : [];
					if (!form || !submit || !cooldownAlert || !cooldownText || !result) {
						return;
					}
					var remaining = parseInt(cooldownText.getAttribute('data-remaining') || '0', 10) || 0;
					var requestRunning = false;
					var defaultSubmitHtml = submit.innerHTML;
					function renderTestStatus(state, title, message) {
						var iconClasses = {running: 'fa fa-circle-o-notch fa-spin', success: 'fa fa-check', error: 'fa fa-exclamation-triangle'};
						result.style.display = 'flex';
						result.className = 'sls-operation-status is-' + state;
						if (resultIcon) { resultIcon.className = iconClasses[state] || iconClasses.running; }
						if (resultTitle) { resultTitle.textContent = title; }
						if (resultMessage) { resultMessage.textContent = message; }
					}
					function renderZoneScope() {
						var selected = form.querySelector('input[name="test_zone_scope"]:checked');
						var disabled = !selected || selected.value !== 'selected';
						Array.prototype.forEach.call(zoneInputs, function(input) { input.disabled = disabled; });
						if (zoneInputs.length && zoneInputs[0].closest) {
							var list = zoneInputs[0].closest('.sls-nws-scroll');
							if (list) { list.classList.toggle('is-disabled', disabled); list.setAttribute('aria-disabled', disabled ? 'true' : 'false'); }
						}
					}
					Array.prototype.forEach.call(scopeInputs, function(input) { input.addEventListener('change', renderZoneScope); });
					function renderCooldown() {
						if (requestRunning) {
							submit.disabled = true;
							return;
						}
						submit.innerHTML = defaultSubmitHtml;
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
						if (remaining > 0 || !confirm('Run a test through the phone and desktop channels assigned to the selected Weather zone scope?')) {
							return;
						}
						submit.disabled = true;
						requestRunning = true;
						submit.innerHTML = '<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> ' + <?php echo json_encode(_('Running Test…')); ?>;
						renderTestStatus('running', <?php echo json_encode(_('Weather test in progress')); ?>, <?php echo json_encode(_('Submitting the selected phone, audio, SIP NOTIFY, and desktop checks.')); ?>);
						var body = new FormData(form);
						fetch(form.action, {method: 'POST', credentials: 'same-origin', body: body})
							.then(function(response) { return response.json(); })
							.then(function(data) {
								var message = data && data.message ? data.message : 'Test request finished.';
								var errors = data && Array.isArray(data.errors) ? data.errors.filter(Boolean) : [];
								var successful = !!(data && data.success);
								renderTestStatus(successful ? 'success' : 'error', successful ? <?php echo json_encode(_('Weather test submitted')); ?> : <?php echo json_encode(_('Weather test needs attention')); ?>, message + (errors.length ? ' ' + errors.join(' ') : ''));
								if (!data || !data.success) {
									window.alert('Weather test error\n\n' + message + (errors.length ? '\n\n' + errors.join('\n') : ''));
								}
								if (data && data.cooldowns && data.cooldowns.test) {
									remaining = parseInt(data.cooldowns.test.remaining || '0', 10) || 0;
								}
								requestRunning = false;
								renderCooldown();
							})
							.catch(function() {
								renderTestStatus('error', <?php echo json_encode(_('Weather test could not be confirmed')); ?>, <?php echo json_encode(_('The PBX interface did not return a valid result. Review Notification Logs before retrying.')); ?>);
								requestRunning = false;
								renderCooldown();
							});
					});
					renderCooldown();
					renderZoneScope();
				}());
				</script>
			<?php } ?>

				<form method="post" action="config.php?display=<?php echo htmlspecialchars($settingsDisplay); ?>" enctype="multipart/form-data">
					<input type="hidden" name="slsmassnotifyserver_action" value="save_settings">
					<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
					<input type="hidden" name="nws_zones_present" value="1">

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
							<input class="form-control" id="nws_api_base_url" name="nws_api_base_url" type="url" value="https://api.weather.gov" readonly aria-readonly="true">
							<p class="help-block"><?php echo _('Weather Alerts uses only the official U.S. weather.gov API endpoint.'); ?></p>
							<p class="help-block"><?php echo _('Default: https://api.weather.gov. Each enabled group is polled once per minute.'); ?></p>
						</div>
					</div>
				</div>

				<div class="panel panel-default sls-settings-card">
					<div class="panel-heading clearfix">
						<div class="pull-left"><strong><i class="fa fa-map-marker text-danger" aria-hidden="true"></i> <?php echo _('NWS Zone Groups'); ?></strong><div class="text-muted"><small><?php echo _('Up to five zones, each with its own phone, desktop, email, Discord/webhook routes, and quiet-hours policy. Webhook secrets remain centralized in General Settings.'); ?></small></div></div>
						<button type="button" class="btn btn-primary btn-sm pull-right" data-toggle="modal" data-target="#sls-zone-manager"><i class="fa fa-map-marker"></i> <?php echo _('Manage Zone Groups'); ?></button>
					</div>
					<div class="panel-body">
					<?php if (empty($zoneGroups)) { ?>
						<div class="sls-zone-empty"><i class="fa fa-map-o fa-2x"></i><br><?php echo _('No NWS zone groups are configured. Use Manage Zone Groups to add one.'); ?></div>
					<?php } else { ?>
						<div class="table-responsive"><table class="table table-striped sls-zone-summary-table"><thead><tr><th><?php echo _('Group'); ?></th><th><?php echo _('NWS Zone'); ?></th><th><?php echo _('Phones'); ?></th><th><?php echo _('Desktops'); ?></th><th><?php echo _('Zone Email'); ?></th></tr></thead><tbody>
						<?php foreach ($zoneGroups as $zoneGroup) { $zoneDesktopLabels = []; foreach ((array)($zoneGroup['desktop_clients'] ?? []) as $username) { $zoneDesktopLabels[] = $desktopNames[$username] ?? $username; } ?><tr><td><strong><?php echo htmlspecialchars($zoneGroup['name'] ?? ''); ?></strong></td><td><code><?php echo htmlspecialchars($zoneGroup['zone'] ?? ''); ?></code></td><td><?php echo htmlspecialchars(implode(', ', (array)($zoneGroup['extensions'] ?? [])) ?: _('None')); ?></td><td><?php echo htmlspecialchars(implode(', ', $zoneDesktopLabels) ?: _('None')); ?></td><td><?php echo htmlspecialchars(implode(', ', (array)($zoneGroup['email_recipients'] ?? [])) ?: _('None')); ?></td></tr><?php } ?>
						</tbody></table></div>
					<?php } ?>
					</div>
				</div>

				<div class="modal fade sls-zone-modal" id="sls-zone-manager" tabindex="-1" role="dialog" aria-hidden="true">
					<div class="modal-dialog"><div class="modal-content">
						<div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><?php echo _('Manage NWS Zone Groups'); ?></h4></div>
						<div class="modal-body">
							<div class="alert alert-info"><i class="fa fa-map-signs" aria-hidden="true"></i> <strong><?php echo _('Find your Weather.gov zone'); ?></strong><p style="margin:7px 0"><?php echo _('Open the official zone maps, choose your state, and find the three-digit number covering your location. Enter your two-letter state abbreviation, the letter Z, and that number. Example: Texas zone 163 is TXZ163.'); ?></p><a class="alert-link" href="https://www.weather.gov/pimar/PubZone" target="_blank" rel="noopener noreferrer"><?php echo _('Open official NWS zone maps'); ?> <i class="fa fa-external-link" aria-hidden="true"></i></a></div>
							<div id="sls-zone-editor-list">
						<?php foreach ($zoneGroups as $zoneIndex => $zoneGroup) { $zoneRecipients = array_fill_keys((array)($zoneGroup['extensions'] ?? []), true); $zoneDesktopRecipients = array_fill_keys((array)($zoneGroup['desktop_clients'] ?? []), true); $zoneUnknownDesktopRecipients = array_diff_key($zoneDesktopRecipients, $desktopNames); $zoneDiscord = array_fill_keys((array)($zoneGroup['discord_webhook_ids'] ?? []), true); $zoneGeneric = array_fill_keys((array)($zoneGroup['generic_webhook_ids'] ?? []), true); ?>
							<div class="sls-zone-editor" data-zone-editor>
								<div class="sls-zone-editor-header"><strong data-zone-title><?php echo htmlspecialchars($zoneGroup['name'] ?? sprintf(_('Weather Zone %d'), $zoneIndex + 1)); ?></strong><button type="button" class="btn btn-link btn-sm text-danger" data-zone-remove><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div>
								<input type="hidden" data-zone-field="id" name="nws_zones[<?php echo $zoneIndex; ?>][id]" value="<?php echo htmlspecialchars($zoneGroup['id'] ?? ''); ?>">
								<div class="row"><div class="col-md-7"><div class="form-group"><label><?php echo _('Group Name'); ?></label><input class="form-control" data-zone-field="name" name="nws_zones[<?php echo $zoneIndex; ?>][name]" maxlength="64" value="<?php echo htmlspecialchars($zoneGroup['name'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(_('Williamson County')); ?>"></div></div><div class="col-md-5"><div class="form-group"><label><?php echo _('NWS Zone'); ?></label><input class="form-control" data-zone-field="zone" name="nws_zones[<?php echo $zoneIndex; ?>][zone]" maxlength="6" value="<?php echo htmlspecialchars($zoneGroup['zone'] ?? ''); ?>" placeholder="TXZ163"></div></div></div>
								<div class="row"><div class="col-md-6"><label><?php echo _('Recipient Extensions'); ?></label><div class="sls-recipient-grid sls-nws-scroll"><div class="row"><?php foreach ((array)($available_extensions ?? []) as $extension) { ?><div class="col-md-6"><div class="checkbox"><label><input type="checkbox" data-zone-extension name="nws_zones[<?php echo $zoneIndex; ?>][extensions][]" value="<?php echo htmlspecialchars($extension['extension']); ?>" <?php echo isset($zoneRecipients[$extension['extension']]) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($extension['extension'] . ($extension['name'] !== '' ? ' - ' . $extension['name'] : '')); ?> <span class="text-muted"><?php echo !empty($extension['registered']) ? _('online') : _('offline'); ?></span></label></div></div><?php } ?></div></div></div><div class="col-md-6"><label><?php echo _('Desktop Clients'); ?></label><div class="sls-recipient-grid sls-nws-scroll"><div class="row"><?php foreach ($desktopClients as $desktopClient) { $username = (string)($desktopClient['username'] ?? ''); $desktopEnabled = !empty($desktopClient['enabled']); $desktopSelected = isset($zoneDesktopRecipients[$username]); ?><div class="col-md-6"><div class="checkbox"><label><input type="checkbox" data-zone-desktop name="nws_zones[<?php echo $zoneIndex; ?>][desktop_clients][]" value="<?php echo htmlspecialchars($username); ?>" <?php echo $desktopSelected ? 'checked' : ''; ?> <?php echo (!$desktopEnabled && !$desktopSelected) ? 'disabled' : ''; ?>> <?php echo htmlspecialchars($desktopNames[$username] ?? $username); ?> <span class="text-muted"><?php echo $desktopEnabled ? _('enabled') : ($desktopSelected ? _('disabled — uncheck to remove this assignment') : _('disabled')); ?></span></label></div></div><?php } ?><?php if (empty($desktopClients)) { ?><div class="col-xs-12 text-muted"><?php echo _('No desktop clients are configured in General Settings.'); ?></div><?php } ?></div></div></div></div>
								<?php if (!empty($zoneUnknownDesktopRecipients)) { ?><div class="alert alert-warning" style="margin-top:12px"><strong><?php echo _('Unavailable desktop assignments:'); ?></strong> <?php echo _('Uncheck each missing client to remove its assignment before saving.'); ?><?php foreach (array_keys($zoneUnknownDesktopRecipients) as $unknownUsername) { ?><div class="checkbox"><label><input type="checkbox" data-zone-desktop name="nws_zones[<?php echo $zoneIndex; ?>][desktop_clients][]" value="<?php echo htmlspecialchars($unknownUsername); ?>" checked> <?php echo htmlspecialchars($unknownUsername); ?> <span class="text-muted"><?php echo _('missing — uncheck to remove'); ?></span></label></div><?php } ?></div><?php } ?>
								<div class="form-group" style="margin-top:12px"><label><?php echo _('Email Recipients for This Zone'); ?></label><textarea class="form-control" data-zone-email name="nws_zones[<?php echo $zoneIndex; ?>][email_recipients]" rows="2" maxlength="4096" placeholder="weather-team@example.com"><?php echo htmlspecialchars(implode("\n", (array)($zoneGroup['email_recipients'] ?? []))); ?></textarea><p class="help-block"><?php echo _('Optional. Only live alerts from this zone use these addresses. Up to 50 unique addresses are allowed per zone. Manual tests never send email.'); ?></p></div>
								<div class="row"><div class="col-md-6"><label><?php echo _('Discord Destinations'); ?></label><?php foreach ($discordDestinations as $destination) { $id=(string)($destination['id']??''); ?><div class="checkbox"><label><input type="checkbox" data-zone-discord name="nws_zones[<?php echo $zoneIndex; ?>][discord_webhook_ids][]" value="<?php echo htmlspecialchars($id); ?>" <?php echo isset($zoneDiscord[$id])?'checked':''; ?>> <?php echo htmlspecialchars((string)($destination['name']??$id)); ?></label></div><?php } ?><?php if (!$discordDestinations) { ?><p class="text-muted"><?php echo _('No enabled Discord destinations.'); ?></p><?php } ?></div><div class="col-md-6"><label><?php echo _('Generic Webhook Destinations'); ?></label><?php foreach ($genericDestinations as $destination) { $id=(string)($destination['id']??''); ?><div class="checkbox"><label><input type="checkbox" data-zone-generic name="nws_zones[<?php echo $zoneIndex; ?>][generic_webhook_ids][]" value="<?php echo htmlspecialchars($id); ?>" <?php echo isset($zoneGeneric[$id])?'checked':''; ?>> <?php echo htmlspecialchars((string)($destination['name']??$id)); ?></label></div><?php } ?><?php if (!$genericDestinations) { ?><p class="text-muted"><?php echo _('No enabled generic webhook destinations.'); ?></p><?php } ?></div></div>
								<div class="row"><div class="col-md-3"><div class="form-group"><label><?php echo _('Zone Quiet Hours'); ?></label><select class="form-control" data-zone-field="quiet_hours_enabled" name="nws_zones[<?php echo $zoneIndex; ?>][quiet_hours_enabled]"><option value="0" <?php echo empty($zoneGroup['quiet_hours_enabled'])?'selected':''; ?>><?php echo _('Disabled'); ?></option><option value="1" <?php echo !empty($zoneGroup['quiet_hours_enabled'])?'selected':''; ?>><?php echo _('Enabled'); ?></option></select></div></div><div class="col-md-3"><div class="form-group"><label><?php echo _('Start'); ?></label><input class="form-control" type="time" data-zone-field="quiet_hours_start" name="nws_zones[<?php echo $zoneIndex; ?>][quiet_hours_start]" value="<?php echo htmlspecialchars((string)($zoneGroup['quiet_hours_start']??'21:00')); ?>"></div></div><div class="col-md-3"><div class="form-group"><label><?php echo _('End'); ?></label><input class="form-control" type="time" data-zone-field="quiet_hours_end" name="nws_zones[<?php echo $zoneIndex; ?>][quiet_hours_end]" value="<?php echo htmlspecialchars((string)($zoneGroup['quiet_hours_end']??'06:00')); ?>"></div></div><div class="col-md-3"><div class="form-group"><label><?php echo _('Critical bypass events'); ?></label><input class="form-control" data-zone-field="quiet_critical_events" name="nws_zones[<?php echo $zoneIndex; ?>][quiet_critical_events][]" value="<?php echo htmlspecialchars(implode(', ', (array)($zoneGroup['quiet_critical_events']??[]))); ?>"></div></div></div>
							</div>
						<?php } ?>
						</div><button type="button" class="btn btn-default" id="sls-zone-add"><i class="fa fa-plus"></i> <?php echo _('Add Zone Group'); ?></button> <span class="text-muted" id="sls-zone-count"></span></div>
						<div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal"><?php echo _('Done'); ?></button></div>
					</div></div>
				</div>
				<script type="text/template" id="sls-zone-template"><div class="sls-zone-editor" data-zone-editor><div class="sls-zone-editor-header"><strong data-zone-title><?php echo _('New Weather Zone'); ?></strong><button type="button" class="btn btn-link btn-sm text-danger" data-zone-remove><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div><input type="hidden" data-zone-field="id" value=""><div class="row"><div class="col-md-7"><div class="form-group"><label><?php echo _('Group Name'); ?></label><input class="form-control" data-zone-field="name" maxlength="64" placeholder="<?php echo htmlspecialchars(_('Williamson County')); ?>"></div></div><div class="col-md-5"><div class="form-group"><label><?php echo _('NWS Zone'); ?></label><input class="form-control" data-zone-field="zone" maxlength="6" placeholder="TXZ163"></div></div></div><div class="row"><div class="col-md-6"><label><?php echo _('Recipient Extensions'); ?></label><div class="sls-recipient-grid sls-nws-scroll"><div class="row"><?php foreach ((array)($available_extensions ?? []) as $extension) { ?><div class="col-md-6"><div class="checkbox"><label><input type="checkbox" data-zone-extension value="<?php echo htmlspecialchars($extension['extension']); ?>"> <?php echo htmlspecialchars($extension['extension'] . ($extension['name'] !== '' ? ' - ' . $extension['name'] : '')); ?> <span class="text-muted"><?php echo !empty($extension['registered']) ? _('online') : _('offline'); ?></span></label></div></div><?php } ?></div></div></div><div class="col-md-6"><label><?php echo _('Desktop Clients'); ?></label><div class="sls-recipient-grid sls-nws-scroll"><div class="row"><?php foreach ($desktopClients as $desktopClient) { $username = (string)($desktopClient['username'] ?? ''); $desktopEnabled = !empty($desktopClient['enabled']); ?><div class="col-md-6"><div class="checkbox"><label><input type="checkbox" data-zone-desktop value="<?php echo htmlspecialchars($username); ?>" <?php echo !$desktopEnabled ? 'disabled' : ''; ?>> <?php echo htmlspecialchars($desktopNames[$username] ?? $username); ?> <span class="text-muted"><?php echo $desktopEnabled ? _('enabled') : _('disabled'); ?></span></label></div></div><?php } ?><?php if (empty($desktopClients)) { ?><div class="col-xs-12 text-muted"><?php echo _('No desktop clients are configured in General Settings.'); ?></div><?php } ?></div></div></div></div><div class="form-group" style="margin-top:12px"><label><?php echo _('Email Recipients for This Zone'); ?></label><textarea class="form-control" data-zone-email rows="2" maxlength="4096" placeholder="weather-team@example.com"></textarea><p class="help-block"><?php echo _('Optional. Only live alerts from this zone use these addresses. Up to 50 unique addresses are allowed per zone. Manual tests never send email.'); ?></p></div></div></script>
				<script type="text/template" id="sls-zone-advanced-template">
					<div data-zone-advanced><div class="row"><div class="col-md-3"><div class="form-group"><label><?php echo _('Zone Quiet Hours'); ?></label><select class="form-control" data-zone-field="quiet_hours_enabled"><option value="0"><?php echo _('Disabled'); ?></option><option value="1"><?php echo _('Enabled'); ?></option></select></div></div><div class="col-md-3"><div class="form-group"><label><?php echo _('Start'); ?></label><input class="form-control" type="time" data-zone-field="quiet_hours_start" value="21:00"></div></div><div class="col-md-3"><div class="form-group"><label><?php echo _('End'); ?></label><input class="form-control" type="time" data-zone-field="quiet_hours_end" value="06:00"></div></div><div class="col-md-3"><div class="form-group"><label><?php echo _('Critical bypass events'); ?></label><input class="form-control" data-zone-field="quiet_critical_events" value="<?php echo htmlspecialchars(implode(', ', (array)($settings['quiet_critical_events'] ?? []))); ?>"></div></div></div>
					<div class="row"><div class="col-md-6"><label><?php echo _('Discord Destinations'); ?></label><?php foreach ($discordDestinations as $destination) { ?><div class="checkbox"><label><input type="checkbox" data-zone-discord value="<?php echo htmlspecialchars((string)$destination['id']); ?>"> <?php echo htmlspecialchars((string)$destination['name']); ?></label></div><?php } ?></div><div class="col-md-6"><label><?php echo _('Generic Webhook Destinations'); ?></label><?php foreach ($genericDestinations as $destination) { ?><div class="checkbox"><label><input type="checkbox" data-zone-generic value="<?php echo htmlspecialchars((string)$destination['id']); ?>"> <?php echo htmlspecialchars((string)$destination['name']); ?></label></div><?php } ?></div></div></div>
				</script>

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
	var advancedTemplate = document.getElementById('sls-zone-advanced-template');
	var count = document.getElementById('sls-zone-count');
	if (!list || !add || !template) return;
	function editors() { return Array.prototype.slice.call(list.querySelectorAll('[data-zone-editor]')); }
	function reindex() {
		var rows = editors();
		rows.forEach(function(row, index) {
			['discord_webhook_ids','generic_webhook_ids'].forEach(function(field) {
				if (!row.querySelector('[data-zone-empty="' + field + '"]')) {
					var empty = document.createElement('input'); empty.type = 'hidden'; empty.value = ''; empty.setAttribute('data-zone-empty', field); row.appendChild(empty);
				}
				row.querySelector('[data-zone-empty="' + field + '"]').name = 'nws_zones[' + index + '][' + field + '][]';
			});
			['id','name','zone','quiet_hours_enabled','quiet_hours_start','quiet_hours_end','quiet_critical_events'].forEach(function(field) {
				var input = row.querySelector('[data-zone-field="' + field + '"]');
				if (input) input.name = 'nws_zones[' + index + '][' + field + ']';
			});
			Array.prototype.forEach.call(row.querySelectorAll('[data-zone-extension]'), function(input) { input.name = 'nws_zones[' + index + '][extensions][]'; });
			Array.prototype.forEach.call(row.querySelectorAll('[data-zone-desktop]'), function(input) { input.name = 'nws_zones[' + index + '][desktop_clients][]'; });
			Array.prototype.forEach.call(row.querySelectorAll('[data-zone-discord]'), function(input) { input.name = 'nws_zones[' + index + '][discord_webhook_ids][]'; });
			Array.prototype.forEach.call(row.querySelectorAll('[data-zone-generic]'), function(input) { input.name = 'nws_zones[' + index + '][generic_webhook_ids][]'; });
			var emailInput = row.querySelector('[data-zone-email]');
			if (emailInput) emailInput.name = 'nws_zones[' + index + '][email_recipients]';
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
		if (row) {
			if (advancedTemplate && !row.querySelector('[data-zone-advanced]')) {
				var advancedShell = document.createElement('div'); advancedShell.innerHTML = advancedTemplate.innerHTML.trim();
				if (advancedShell.firstElementChild) row.appendChild(advancedShell.firstElementChild);
			}
			list.appendChild(row);
		}
		reindex();
		var first = row && row.querySelector('[data-zone-field="name"]');
		if (first) first.focus();
	});
	reindex();
}());
</script>
