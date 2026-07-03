<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group
$settings = is_array($settings ?? null) ? $settings : [];
$saveResult = $save_result ?? null;
$applyResult = $apply_result ?? null;
$tokenResult = $token_result ?? null;
$importResult = $import_result ?? null;
$hasPendingChanges = !empty($has_pending_changes);
$voices = is_array($available_voices ?? null) ? $available_voices : [];
$control = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : [];
$updates = is_array($settings['updates'] ?? null) ? $settings['updates'] : [];
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
			<h2><?php echo _('Other Settings'); ?></h2>
			<p class="text-muted"><?php echo _('Control API and TTS settings are stored in the centralized Mass Notifications configuration. Announcement groups are managed from the dashboard announcement panel.'); ?></p>
			<div class="clearfix"></div>
			<?php foreach ([$saveResult, $applyResult, $tokenResult, $importResult] as $result) { ?>
				<?php if (is_array($result)) { ?>
					<div class="alert alert-<?php echo !empty($result['success']) ? 'success' : 'warning'; ?>">
						<?php echo htmlspecialchars($result['message']); ?>
						<?php if (!empty($result['errors'])) { ?>
							<ul>
								<?php foreach ($result['errors'] as $error) { ?>
									<li><?php echo htmlspecialchars($error); ?></li>
								<?php } ?>
							</ul>
						<?php } ?>
					</div>
				<?php } ?>
			<?php } ?>
			<form method="post">

				<h3><?php echo _('Control API'); ?></h3>
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
					<p class="help-block"><?php echo _('Use Authorization: Bearer <key> or X-API-Key. Disabled by default. Regeneration is staged until Apply Config is run.'); ?></p>
				</div>

				<h3><?php echo _('TTS Settings'); ?></h3>
				<div class="row">
					<div class="col-md-6">
						<div class="form-group">
							<label><?php echo _('Announcement TTS Voice'); ?></label>
							<select class="form-control" name="announcement_piper_voice">
								<?php foreach ($voices as $voice) { ?>
									<option value="<?php echo htmlspecialchars($voice['path']); ?>" <?php echo ($settings['announcement_piper_voice'] ?? '') === $voice['path'] ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars($voice['name']); ?>
									</option>
								<?php } ?>
							</select>
						</div>
					</div>
					<div class="col-md-6">
						<div class="form-group">
							<label><?php echo _('Announcement Volume'); ?></label>
							<input class="form-control" name="announcement_tts_volume" type="number" min="1" max="200" value="<?php echo (int)($settings['announcement_tts_volume'] ?? 50); ?>">
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-md-6">
						<div class="form-group">
							<label><?php echo _('NWS TTS Voice'); ?></label>
							<select class="form-control" name="nws_piper_voice">
								<?php foreach ($voices as $voice) { ?>
									<option value="<?php echo htmlspecialchars($voice['path']); ?>" <?php echo ($settings['nws_piper_voice'] ?? '') === $voice['path'] ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars($voice['name']); ?>
									</option>
								<?php } ?>
							</select>
						</div>
					</div>
					<div class="col-md-6">
						<div class="form-group">
							<label><?php echo _('NWS Volume'); ?></label>
							<input class="form-control" name="nws_tts_volume" type="number" min="1" max="200" value="<?php echo (int)($settings['nws_tts_volume'] ?? 85); ?>">
						</div>
					</div>
				</div>
				<h3><?php echo _('Log Retention'); ?></h3>
				<div class="form-group">
					<label><?php echo _('Notification Log Retention Days'); ?></label>
					<input class="form-control" name="log_retention_days" type="number" min="1" max="365" value="<?php echo (int)($settings['log_retention_days'] ?? 90); ?>">
					<p class="help-block"><?php echo _('Stores notification log entries for 1 to 365 days. Default is 90 days.'); ?></p>
				</div>
				<h3><?php echo _('Updates'); ?></h3>
				<div class="row">
					<div class="col-md-6">
						<div class="form-group">
							<label><?php echo _('Automatic GitHub Updates'); ?></label>
							<select class="form-control" name="updates[github_enabled]">
								<option value="0" <?php echo empty($updates['github_enabled']) ? 'selected' : ''; ?>><?php echo _('Disabled'); ?></option>
								<option value="1" <?php echo !empty($updates['github_enabled']) ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option>
							</select>
							<p class="help-block"><?php echo _('When enabled, future update tooling should use the GitHub release channel without changing this central config.'); ?></p>
						</div>
					</div>
					<div class="col-md-6">
						<div class="form-group">
							<label><?php echo _('Update Channel'); ?></label>
							<select class="form-control" name="updates[channel]">
								<option value="beta" <?php echo (($updates['channel'] ?? 'beta') === 'beta') ? 'selected' : ''; ?>><?php echo _('Beta'); ?></option>
								<option value="stable" <?php echo (($updates['channel'] ?? '') === 'stable') ? 'selected' : ''; ?>><?php echo _('Stable'); ?></option>
							</select>
							<p class="help-block"><?php echo htmlspecialchars($updates['repository'] ?? 'vipgabe09267/SouthlandServers_Mass_Notify_server'); ?></p>
						</div>
					</div>
				</div>
				<button type="submit" class="btn btn-primary" name="slsmassnotifyserver_action" value="save_other_settings"><?php echo _('Save'); ?></button>
			</form>
			<hr>
			<h3><?php echo _('Config Backup'); ?></h3>
			<form method="post" style="margin-bottom: 15px;">
				<button type="submit" class="btn btn-default" name="slsmassnotifyserver_action" value="export_config"><?php echo _('Download .config'); ?></button>
			</form>
			<div class="panel panel-danger">
				<div class="panel-heading"><?php echo _('Danger Zone'); ?></div>
				<div class="panel-body">
					<p class="text-danger">
						<?php echo _('Replacing the config file wipes the current plugin data and overwrites API keys, endpoints, voices, announcement groups, NWS settings, and retention settings. Review your backup before applying.'); ?>
					</p>
					<form method="post" enctype="multipart/form-data" onsubmit="return confirm('<?php echo htmlspecialchars(_('Replace the Mass Notifications config? This will wipe the current staged plugin data and requires Apply Config to become live.')); ?>');">
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
	var button = document.getElementById('copy_control_api_key');
	var input = document.getElementById('control_api_key');
	if (!button || !input) {
		return;
	}
	button.addEventListener('click', function() {
		input.focus();
		input.select();
		try {
			document.execCommand('copy');
		} catch (e) {}
	});
}());
</script>
