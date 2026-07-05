<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group
$settings = is_array($settings ?? null) ? $settings : [];
$saveResult = $save_result ?? null;
$applyResult = $apply_result ?? null;
$tokenResult = $token_result ?? null;
$importResult = $import_result ?? null;
$hasPendingChanges = !empty($has_pending_changes);
$voices = is_array($available_voices ?? null) ? $available_voices : [];
$tones = is_array($available_tones ?? null) ? $available_tones : [];
$control = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : [];
$updates = is_array($settings['updates'] ?? null) ? $settings['updates'] : [];
$desktopClients = is_array($desktop_clients ?? null) ? $desktop_clients : [];
$packageStatus = is_array($package_update_status ?? null) ? $package_update_status : ['state' => 'latest', 'label' => 'LATEST'];
$packageStatusClass = (($packageStatus['state'] ?? '') === 'update') ? 'label-warning' : 'label-success';
?>
<div class="container-fluid">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image ?? '']); ?>
	<div class="row">
		<div class="col-sm-12">
			<?php if ($hasPendingChanges) { ?>
				<form method="post" class="pull-right" style="margin-bottom: 10px;">
					<input type="hidden" name="slsmassnotifyserver_action" value="apply_settings">
					<button type="submit" class="btn btn-danger"><?php echo _('Apply Config'); ?></button>
				</form>
			<?php } ?>
			<h2><?php echo _('General Settings'); ?></h2>
			<p class="text-muted"><?php echo _('Manage audio, desktop app clients, remote API access, updates, retention, and config backup. Phone SIP NOTIFY delivery is pushed directly through Asterisk/PJSIP using selected extensions.'); ?></p>
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
				<input type="hidden" name="slsmassnotifyserver_action" value="save_other_settings">

				<h3><?php echo _('Audio and Announcement Settings'); ?></h3>
				<div class="row">
					<div class="col-md-4">
						<div class="form-group">
							<label><?php echo _('Opening Tone'); ?></label>
							<select class="form-control" name="opening_tone">
								<?php foreach ($tones as $tone) { ?>
									<option value="<?php echo htmlspecialchars($tone); ?>" <?php echo ($settings['opening_tone'] ?? '') === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars($tone); ?></option>
								<?php } ?>
							</select>
							<input class="form-control" style="margin-top: 6px;" name="opening_tone_upload" type="file" accept=".wav,audio/wav,audio/x-wav">
						</div>
					</div>
					<div class="col-md-4">
						<div class="form-group">
							<label><?php echo _('Closing Tone'); ?></label>
							<select class="form-control" name="closing_tone">
								<?php foreach ($tones as $tone) { ?>
									<option value="<?php echo htmlspecialchars($tone); ?>" <?php echo ($settings['closing_tone'] ?? '') === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars($tone); ?></option>
								<?php } ?>
							</select>
							<input class="form-control" style="margin-top: 6px;" name="closing_tone_upload" type="file" accept=".wav,audio/wav,audio/x-wav">
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
					<div class="col-md-4">
						<div class="form-group">
							<label><?php echo _('Announcement TTS Voice'); ?></label>
							<select class="form-control" name="announcement_piper_voice">
								<?php foreach ($voices as $voice) { ?>
									<option value="<?php echo htmlspecialchars($voice['path']); ?>" <?php echo ($settings['announcement_piper_voice'] ?? '') === $voice['path'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($voice['name']); ?></option>
								<?php } ?>
							</select>
						</div>
					</div>
					<div class="col-md-2">
						<div class="form-group">
							<label><?php echo _('Announcement Volume'); ?></label>
							<input class="form-control" name="announcement_tts_volume" type="number" min="1" max="200" value="<?php echo (int)($settings['announcement_tts_volume'] ?? 50); ?>">
						</div>
					</div>
					<div class="col-md-4">
						<div class="form-group">
							<label><?php echo _('NWS TTS Voice'); ?></label>
							<select class="form-control" name="nws_piper_voice">
								<?php foreach ($voices as $voice) { ?>
									<option value="<?php echo htmlspecialchars($voice['path']); ?>" <?php echo ($settings['nws_piper_voice'] ?? '') === $voice['path'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($voice['name']); ?></option>
								<?php } ?>
							</select>
						</div>
					</div>
					<div class="col-md-2">
						<div class="form-group">
							<label><?php echo _('NWS Volume'); ?></label>
							<input class="form-control" name="nws_tts_volume" type="number" min="1" max="200" value="<?php echo (int)($settings['nws_tts_volume'] ?? 85); ?>">
						</div>
					</div>
				</div>
				<div class="form-group">
					<label><?php echo _('TTS Max Seconds'); ?></label>
					<input class="form-control" style="max-width: 220px;" name="tts_max_seconds" type="number" min="1" max="600" value="<?php echo (int)($settings['tts_max_seconds'] ?? 30); ?>">
					<p class="help-block"><?php echo _('Caps generated Piper speech. Default is 30 seconds. Maximum is 600 seconds.'); ?></p>
				</div>

				<h3><?php echo _('Desktop App Clients'); ?></h3>
					<p class="help-block"><?php echo _('Each desktop app should use its own username and password against /api/sipnotify/desktop. The short Client ID is the persistent selector for Control API desktop targeting. Passwords are AES-encrypted in the central config file.'); ?></p>
				<div class="table-responsive">
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
										<td><input class="form-control input-sm" name="desktop_clients[<?php echo (int)$index; ?>][client_id]" value="<?php echo htmlspecialchars($client['client_id'] ?? ''); ?>"></td>
										<td><input class="form-control input-sm" name="desktop_clients[<?php echo (int)$index; ?>][username]" value="<?php echo htmlspecialchars($client['username'] ?? ''); ?>"></td>
										<td><input class="form-control input-sm" name="desktop_clients[<?php echo (int)$index; ?>][password]" value="<?php echo htmlspecialchars($client['password'] ?? ''); ?>"></td>
									<td><button type="button" class="btn btn-default btn-sm" data-remove-desktop-client><?php echo _('Delete'); ?></button></td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
				<button type="button" class="btn btn-default" id="add-desktop-client"><?php echo _('Add Desktop Client'); ?></button>
				<div class="well" style="margin-top: 12px;">
					<strong><?php echo _('Desktop Endpoint'); ?></strong>
					<div><code><?php echo htmlspecialchars(($settings['sipnotify']['base_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/sipnotify')) . '/desktop'); ?></code></div>
						<p class="help-block" style="margin-bottom: 0;"><?php echo _('Use HTTP Basic authentication with the desktop client username and password. Legacy Bearer token access is not accepted for desktop clients.'); ?></p>
				</div>

				<div class="panel panel-warning" style="border-width: 2px;">
					<div class="panel-heading"><strong><?php echo _('Control API'); ?></strong></div>
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
								<input class="form-control" id="control_api_key" name="control_api[api_key]" type="text" value="<?php echo htmlspecialchars($control['api_key'] ?? ''); ?>">
								<span class="input-group-btn">
									<button type="button" class="btn btn-default" id="copy_control_api_key"><?php echo _('Copy'); ?></button>
									<button type="submit" class="btn btn-warning" name="slsmassnotifyserver_action" value="regenerate_control_api_key" onclick="return confirm('<?php echo htmlspecialchars(_('Regenerate the Control API key? Existing API clients using the old key will stop working after you apply changes.')); ?>');"><?php echo _('Regenerate'); ?></button>
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
								<p class="help-block" style="margin-top: 25px;"><?php echo _('Control API use is audited by source IP/action and retained for 30 days. Secrets are not written to the audit log.'); ?></p>
							</div>
						</div>
					</div>
				</div>

				<h3><?php echo _('Updates and Retention'); ?></h3>
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
						<label><?php echo _('Update Channel'); ?></label>
						<input type="hidden" name="updates[channel]" value="beta">
						<p class="form-control-static"><span class="label label-warning"><?php echo _('Beta'); ?></span></p>
						<p class="help-block"><?php echo _('Public beta updates are the only available channel right now.'); ?></p>
					</div>
					<div class="col-md-3">
						<label><?php echo _('Installed Package Version'); ?></label>
						<p class="form-control-static"><code><?php echo htmlspecialchars($package_version ?? 'unknown'); ?></code> <span class="label <?php echo $packageStatusClass; ?>"><?php echo htmlspecialchars($packageStatus['label'] ?? 'LATEST'); ?></span></p>
					</div>
				</div>

				<div style="margin-top: 18px;">
					<button type="submit" class="btn btn-primary"><?php echo _('Save'); ?></button>
				</div>
			</form>

			<hr>
			<h3><?php echo _('Config Backup'); ?></h3>
			<form method="post" style="margin-bottom: 15px;">
				<button type="submit" class="btn btn-default" name="slsmassnotifyserver_action" value="export_config"><?php echo _('Download .config'); ?></button>
			</form>
			<div class="panel panel-danger">
				<div class="panel-heading"><?php echo _('Danger Zone'); ?></div>
				<div class="panel-body">
					<h4><?php echo _('Installer Health'); ?></h4>
					<p class="text-danger"><?php echo _('Repair Installation refreshes runtime files, permissions, Apache API routes, cron, dialplan, dashboard widget files, and local signatures. It does not replace your central .config, but it may reload FreePBX and Asterisk dialplan.'); ?></p>
					<form method="post" style="margin-bottom: 18px;" onsubmit="return confirm('<?php echo htmlspecialchars(_('Are you sure you want to repair/reinstall the Mass Notifications integration now? This may reload FreePBX and Asterisk dialplan. Your central .config will not be replaced.')); ?>');">
						<button type="submit" class="btn btn-warning" name="slsmassnotifyserver_action" value="repair_installation"><?php echo _('Repair Installation'); ?></button>
					</form>
					<hr>
					<p class="text-danger"><?php echo _('Replacing the config file wipes the current plugin data and overwrites API keys, desktop clients, voices, announcement groups, NWS settings, and retention settings.'); ?></p>
					<form method="post" enctype="multipart/form-data" onsubmit="return confirm('<?php echo htmlspecialchars(_('Replace the Mass Notifications config? This requires Apply Config to become live.')); ?>');">
						<div class="form-group">
							<label><?php echo _('Upload .config'); ?></label>
							<input type="file" name="config_upload" accept=".config,application/json">
						</div>
						<button type="submit" class="btn btn-danger" name="slsmassnotifyserver_action" value="import_config"><?php echo _('Replace Config'); ?></button>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
<script>
(function() {
	function rand(chars) {
		var out = '';
		var alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		for (var i = 0; i < chars; i++) {
			out += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
		}
		return out;
	}
	var copyButton = document.getElementById('copy_control_api_key');
	var controlKey = document.getElementById('control_api_key');
	if (copyButton && controlKey) {
		copyButton.addEventListener('click', function() {
			controlKey.focus();
			controlKey.select();
			document.execCommand('copy');
		});
	}
	var table = document.querySelector('#desktop-client-table tbody');
	var add = document.getElementById('add-desktop-client');
	if (!table || !add) {
		return;
	}
	function nextIndex() {
		return table.querySelectorAll('[data-desktop-client-row]').length;
	}
	table.addEventListener('click', function(event) {
		if (event.target.matches('[data-remove-desktop-client]')) {
			event.target.closest('tr').remove();
		}
	});
		add.addEventListener('click', function() {
			var index = nextIndex();
			var clientId = 'cli_' + rand(6).toLowerCase();
			var username = 'sls' + rand(6);
			var password = rand(24);
			var row = document.createElement('tr');
		row.setAttribute('data-desktop-client-row', '1');
		row.innerHTML =
			'<td><input type="hidden" name="desktop_clients[' + index + '][id]" value="">' +
				'<input type="hidden" name="desktop_clients[' + index + '][password_enc]" value="">' +
				'<input type="checkbox" name="desktop_clients[' + index + '][enabled]" value="1" checked></td>' +
				'<td><input class="form-control input-sm" name="desktop_clients[' + index + '][name]" value="Desktop App"></td>' +
				'<td><input class="form-control input-sm" name="desktop_clients[' + index + '][client_id]" value="' + clientId + '"></td>' +
				'<td><input class="form-control input-sm" name="desktop_clients[' + index + '][username]" value="' + username + '"></td>' +
				'<td><input class="form-control input-sm" name="desktop_clients[' + index + '][password]" value="' + password + '"></td>' +
			'<td><button type="button" class="btn btn-default btn-sm" data-remove-desktop-client>Delete</button></td>';
		table.appendChild(row);
	});
}());
</script>
