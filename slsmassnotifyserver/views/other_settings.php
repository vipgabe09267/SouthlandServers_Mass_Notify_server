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
$systemSounds = is_array($available_system_sounds ?? null) ? $available_system_sounds : [];
$control = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : [];
$updates = is_array($settings['updates'] ?? null) ? $settings['updates'] : [];
$desktopClients = is_array($desktop_clients ?? null) ? $desktop_clients : [];
$packageStatus = is_array($package_update_status ?? null) ? $package_update_status : ['state' => 'latest', 'label' => 'LATEST'];
$packageStatusClass = (($packageStatus['state'] ?? '') === 'update') ? 'label-warning' : 'label-success';
$formatOverrides = [];
$csrfToken = (string)($csrf_token ?? '');
foreach ((array)($settings['sipnotify']['format_overrides'] ?? []) as $extension => $format) {
	$extension = preg_replace('/[^0-9]/', '', (string)$extension);
	$format = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$format));
	if ($extension !== '' && $format !== '') {
		$formatOverrides[] = $extension . '=' . $format;
	}
}
?>
<style>
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
.sls-compact-table {
	max-height: 330px;
	overflow: auto;
}
</style>
<div class="container-fluid">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image ?? '']); ?>
	<div class="row">
		<div class="col-sm-12">
			<?php if ($hasPendingChanges) { ?>
					<form method="post" class="pull-right" style="margin-bottom: 10px;">
						<input type="hidden" name="slsmassnotifyserver_action" value="apply_settings">
						<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
					<button type="submit" class="btn btn-danger"><?php echo _('Apply Config'); ?></button>
				</form>
			<?php } ?>
			<h2><?php echo _('General Settings'); ?></h2>
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
				<input type="hidden" name="slsmassnotifyserver_action" value="save_other_settings">
				<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

				<h3 class="sls-settings-heading"><?php echo _('Phone Delivery'); ?></h3>
				<div class="row">
					<div class="col-md-4">
						<div class="form-group">
							<label><?php echo _('Public PBX Hostname'); ?></label>
							<input class="form-control" name="public_pbx_host" value="<?php echo htmlspecialchars($settings['public_pbx_host'] ?? ($settings['sipnotify']['pbx_host'] ?? '')); ?>" placeholder="pbx.example.com">
							<p class="help-block"><?php echo _('Used for API links and hosted phone media. Override this if auto-detection produced a short hostname such as pbx.'); ?></p>
						</div>
					</div>
					<div class="col-md-2">
						<div class="form-group">
							<label><?php echo _('Phone Image Transport'); ?></label>
							<select class="form-control" name="sipnotify_media_scheme">
								<option value="http" <?php echo (($settings['sipnotify']['media_scheme'] ?? 'http') === 'http') ? 'selected' : ''; ?>>HTTP</option>
								<option value="https" <?php echo (($settings['sipnotify']['media_scheme'] ?? 'http') === 'https') ? 'selected' : ''; ?>>HTTPS</option>
							</select>
							<p class="help-block"><?php echo _('HTTP is the compatibility default for legacy phones such as the Yealink T48G. Authenticated APIs remain HTTPS.'); ?></p>
						</div>
					</div>
					<div class="col-md-6">
						<div class="form-group">
							<div class="clearfix">
								<label class="pull-left"><?php echo _('Phone Format Overrides'); ?></label>
								<span class="pull-right sls-format-help" tabindex="0" role="note" aria-label="<?php echo htmlspecialchars(_('Phone format override examples')); ?>">
									<i class="fa fa-question-circle" aria-hidden="true"></i>
									<span class="sls-format-help-text">
										<strong><?php echo _('Use one entry per line:'); ?></strong><br>
										<code>1000=yealink</code><br>
										<code>2000=poly</code> <span class="text-muted"><?php echo _('(Polycom)'); ?></span><br>
										<code>3000=cisco</code><br>
										<code>4000=snom</code>
										<hr style="margin: 8px 0;">
										<div><?php echo _('Use yealink_text when a model cannot load generated PNG alerts.'); ?></div>
										<div style="margin-top: 6px;"><strong><?php echo _('Supported phone vendors:'); ?></strong> <?php echo _('Yealink, Cisco, Poly/Polycom, Grandstream, Fanvil, Snom, Aastra/Mitel, Sangoma, Avaya, VTech, and Alcatel-Lucent Enterprise (ALE). Brands not listed here are not officially supported.'); ?></div>
									</span>
								</span>
							</div>
							<textarea class="form-control" name="sipnotify_format_overrides" rows="3" placeholder="1190=cisco&#10;1000=yealink"><?php echo htmlspecialchars(implode("\n", $formatOverrides)); ?></textarea>
						</div>
					</div>
				</div>

				<h3 class="sls-settings-heading"><?php echo _('Audio and TTS'); ?></h3>
				<div class="row">
					<div class="col-md-4">
						<div class="form-group">
							<label><?php echo _('Opening Tone'); ?></label>
							<select class="form-control" name="opening_tone">
								<optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>">
								<?php foreach ($tones as $tone) { ?>
									<option value="<?php echo htmlspecialchars($tone); ?>" <?php echo ($settings['opening_tone'] ?? '') === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars($tone); ?></option>
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
							<label><?php echo _('Closing Tone'); ?></label>
							<select class="form-control" name="closing_tone">
								<optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>">
								<?php foreach ($tones as $tone) { ?>
									<option value="<?php echo htmlspecialchars($tone); ?>" <?php echo ($settings['closing_tone'] ?? '') === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars($tone); ?></option>
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

				<h3 class="sls-settings-heading"><?php echo _('Desktop Clients'); ?></h3>
					<p class="help-block"><?php echo _('Each desktop app should use its own username and password against /api/sipnotify/desktop. Client IDs are generated automatically and cannot be edited. Passwords are AES-encrypted in the central config file.'); ?></p>
				<div class="table-responsive sls-compact-table">
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
				<div class="well" style="margin-top: 12px;">
					<strong><?php echo _('Desktop Endpoint'); ?></strong>
					<div><code><?php echo htmlspecialchars(($settings['sipnotify']['base_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/sipnotify')) . '/desktop'); ?></code></div>
						<p class="help-block" style="margin-bottom: 0;"><?php echo _('Use HTTP Basic authentication with the desktop client username and password. Legacy Bearer token access is not accepted for desktop clients.'); ?></p>
				</div>

				<div class="panel panel-warning" style="border-width: 2px;">
					<div class="panel-heading"><strong><?php echo _('Control API'); ?></strong><span class="label label-success sls-labs-badge"><i class="fa fa-flask" aria-hidden="true"></i> <?php echo _('Labs'); ?></span></div>
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
								<p class="help-block" style="margin-top: 25px;"><?php echo _('Control API use is audited by source IP/action and retained for 30 days. Secrets are not written to the audit log.'); ?></p>
							</div>
						</div>
					</div>
				</div>

				<h3 class="sls-settings-heading"><?php echo _('Updates and Retention'); ?></h3>
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
						<label><?php echo _('Update Channel'); ?></label>
						<input type="hidden" name="updates[channel]" value="beta">
						<p class="form-control-static"><span class="label label-warning"><?php echo _('Beta'); ?></span></p>
						<p class="help-block"><?php echo _('Public beta updates are the only available channel right now.'); ?></p>
					</div>
					<div class="col-md-3">
						<label><?php echo _('Installed Package Version'); ?></label>
						<p class="form-control-static"><code><?php echo htmlspecialchars($package_version ?? 'unknown'); ?></code> <span class="label <?php echo $packageStatusClass; ?>"><?php echo htmlspecialchars($packageStatus['label'] ?? 'LATEST'); ?></span></p>
						<button type="submit" class="btn btn-default btn-sm" name="slsmassnotifyserver_action" value="manual_update"><i class="fa fa-refresh" aria-hidden="true"></i> <?php echo _('Update to Latest Release'); ?></button>
					</div>
				</div>

				<div style="margin-top: 18px;">
					<button type="submit" class="btn btn-primary"><?php echo _('Save'); ?></button>
				</div>
			</form>

			<hr>
			<h3><?php echo _('Config Backup'); ?></h3>
			<form method="post" style="margin-bottom: 15px;">
				<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
				<button type="submit" class="btn btn-default" name="slsmassnotifyserver_action" value="export_config"><?php echo _('Download .config'); ?></button>
			</form>
			<div class="panel panel-danger">
				<div class="panel-heading"><?php echo _('Danger Zone'); ?></div>
				<div class="panel-body">
					<h4><?php echo _('Installer Health'); ?></h4>
					<p class="text-danger"><?php echo _('Repair Installation refreshes runtime files, permissions, Apache API routes, cron, dialplan, dashboard widget files, and local signatures. It does not replace your central .config, but it may reload FreePBX and Asterisk dialplan.'); ?></p>
					<form method="post" style="margin-bottom: 18px;" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(_('Are you sure you want to repair/reinstall the Mass Notifications integration now? This may reload FreePBX and Asterisk dialplan. Your central .config will not be replaced.')), ENT_QUOTES, 'UTF-8'); ?>);">
						<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
						<button type="submit" class="btn btn-warning" name="slsmassnotifyserver_action" value="repair_installation"><?php echo _('Repair Installation'); ?></button>
					</form>
					<hr>
					<h4><?php echo _('Completely Uninstall'); ?></h4>
					<p class="text-danger"><strong><?php echo _('Warning:'); ?></strong> <?php echo _('This removes the module, runtime services, APIs, logs, desktop clients, credentials, tones, backups, and central configuration. This cannot be undone.'); ?></p>
					<form method="post" style="margin-bottom: 18px;" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(_('Are you sure you want to completely uninstall this module? All Mass Notifications configuration and data will be permanently deleted.')), ENT_QUOTES, 'UTF-8'); ?>);">
						<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
						<button type="submit" class="btn btn-danger" name="slsmassnotifyserver_action" value="complete_uninstall"><i class="fa fa-trash" aria-hidden="true"></i> <?php echo _('Completely Uninstall'); ?></button>
					</form>
					<hr>
					<p class="text-danger"><?php echo _('Replacing the config file wipes the current plugin data and overwrites API keys, desktop clients, voices, announcement groups, NWS settings, and retention settings.'); ?></p>
					<form method="post" enctype="multipart/form-data" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(_('Replace the Mass Notifications config? This requires Apply Config to become live.')), ENT_QUOTES, 'UTF-8'); ?>);">
						<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
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
	});
}());
</script>
