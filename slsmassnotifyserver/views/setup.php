<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group
$settings = is_array($settings ?? null) ? $settings : [];
$saveResult = $save_result ?? null;
$extensions = is_array($available_extensions ?? null) ? $available_extensions : [];
$voices = is_array($available_voices ?? null) ? $available_voices : [];
$brandOptions = is_array($brands ?? null) ? $brands : [];
$setup = is_array($settings['setup'] ?? null) ? $settings['setup'] : [];
$sipnotify = is_array($settings['sipnotify'] ?? null) ? $settings['sipnotify'] : [];
$endpointMap = [];
foreach ((array)($sipnotify['endpoints'] ?? []) as $endpoint) {
	if (is_array($endpoint) && !empty($endpoint['slug'])) {
		$endpointMap[$endpoint['slug']] = $endpoint;
	}
}
$criticalEvents = is_array($settings['quiet_critical_events'] ?? null) ? $settings['quiet_critical_events'] : [];
$selectedRecipients = array_fill_keys((array)($settings['alert_recipients'] ?? []), true);
$dismissible = !empty($dismissible);
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
			<h2><?php echo _('Setup Wizard'); ?></h2>
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

				<h3><?php echo _('Required Acknowledgements'); ?></h3>
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

				<h3><?php echo _('NWS Weather Alerts'); ?></h3>
				<div class="row">
					<div class="col-md-3">
						<div class="form-group">
							<label><?php echo _('Enable NWS System'); ?></label>
							<select class="form-control" name="enabled">
								<option value="0" <?php echo (($settings['enabled'] ?? '0') === '0') ? 'selected' : ''; ?>><?php echo _('No'); ?></option>
								<option value="1" <?php echo (($settings['enabled'] ?? '0') !== '0') ? 'selected' : ''; ?>><?php echo _('Yes'); ?></option>
							</select>
						</div>
					</div>
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

				<h3><?php echo _('Remote API'); ?></h3>
				<div class="form-group">
					<label><?php echo _('Enable Control API'); ?></label>
					<select class="form-control" name="control_api_enabled">
						<option value="0" <?php echo empty($settings['control_api']['enabled']) ? 'selected' : ''; ?>><?php echo _('No'); ?></option>
						<option value="1" <?php echo !empty($settings['control_api']['enabled']) ? 'selected' : ''; ?>><?php echo _('Yes'); ?></option>
					</select>
					<p class="help-block"><?php echo htmlspecialchars($settings['control_api']['base_url'] ?? ''); ?></p>
				</div>

				<h3><?php echo _('SIP NOTIFY Endpoints'); ?></h3>
				<div class="row">
					<?php foreach ($endpointMap as $slug => $endpoint) { ?>
						<div class="col-md-3">
							<label class="checkbox-inline">
								<input type="checkbox" name="sipnotify_endpoints[<?php echo htmlspecialchars($slug); ?>]" value="1" <?php echo !empty($endpoint['enabled']) ? 'checked' : ''; ?>>
								<?php echo htmlspecialchars($endpoint['brand'] ?? ucfirst($slug)); ?>
							</label>
						</div>
					<?php } ?>
					<?php foreach ($brandOptions as $slug => $brand) { ?>
						<?php if (isset($endpointMap[$slug])) { continue; } ?>
						<div class="col-md-3">
							<label class="checkbox-inline">
								<input type="checkbox" name="sipnotify_endpoints[<?php echo htmlspecialchars($slug); ?>]" value="1">
								<?php echo htmlspecialchars($brand); ?>
							</label>
						</div>
					<?php } ?>
				</div>

				<h3><?php echo _('TTS Settings'); ?></h3>
				<?php
				$missingVoices = array_filter($voices, static function ($voice) {
					return empty($voice['available']);
				});
				if (!empty($missingVoices)) { ?>
					<div class="alert alert-warning">
						<?php echo _('One or more Piper voice files are not present yet. The installer attempts to download them during install; if TTS fails, check internet access and rerun module install or install Piper voices manually.'); ?>
					</div>
				<?php } ?>
				<div class="row">
					<div class="col-md-6">
						<label><?php echo _('Announcement Voice'); ?></label>
						<select class="form-control" name="announcement_piper_voice">
							<?php foreach ($voices as $voice) { ?>
								<option value="<?php echo htmlspecialchars($voice['path']); ?>" <?php echo (($settings['announcement_piper_voice'] ?? '') === $voice['path']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($voice['name']); ?></option>
							<?php } ?>
						</select>
					</div>
					<div class="col-md-6">
						<label><?php echo _('NWS Voice'); ?></label>
						<select class="form-control" name="nws_piper_voice">
							<?php foreach ($voices as $voice) { ?>
								<option value="<?php echo htmlspecialchars($voice['path']); ?>" <?php echo (($settings['nws_piper_voice'] ?? '') === $voice['path']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($voice['name']); ?></option>
							<?php } ?>
						</select>
					</div>
				</div>
				<div class="row" style="margin-top: 12px;">
					<div class="col-md-4">
						<label><?php echo _('Announcement Volume'); ?></label>
						<input class="form-control" name="announcement_tts_volume" type="number" min="1" max="200" value="<?php echo (int)($settings['announcement_tts_volume'] ?? 50); ?>">
					</div>
					<div class="col-md-4">
						<label><?php echo _('NWS Volume'); ?></label>
						<input class="form-control" name="nws_tts_volume" type="number" min="1" max="200" value="<?php echo (int)($settings['nws_tts_volume'] ?? 85); ?>">
					</div>
					<div class="col-md-4">
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
