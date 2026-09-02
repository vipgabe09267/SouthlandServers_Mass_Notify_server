<?php
$announcementTargets = is_array($announcement_targets ?? null) ? $announcement_targets : [];
$announcementGroupTargets = is_array($announcement_group_targets ?? null) ? $announcement_group_targets : $announcementTargets;
$desktopClients = is_array($announcement_desktop_clients ?? null) ? $announcement_desktop_clients : [];
$announcementWebhooks = is_array($announcement_webhooks ?? null) ? $announcement_webhooks : [];
$announcementGroups = is_array($announcement_groups ?? null) ? $announcement_groups : [];
$announcementCooldown = (int)($announcement_cooldown_remaining ?? 0);
$announcementState = is_array($announcement_state ?? null) ? $announcement_state : [];
$announcementTones = is_array($announcement_tones ?? null) ? $announcement_tones : [];
$quietHoursActive = !empty($announcementState['quiet_hours_active']);
$setupComplete = !empty($setup_complete);
$setupModal = (string)($setup_modal ?? '');
$csrfToken = (string)($csrf_token ?? '');
$enabledDesktopCount = 0;
foreach ($desktopClients as $desktopClient) {
	if (!empty($desktopClient['enabled'])) {
		$enabledDesktopCount++;
	}
}
?>
<style>
#dashboard-sls-mass-notify-announcement {
	padding: 5px 7px 10px;
	color: #1f2937;
	box-sizing: border-box;
	max-width: 100%;
	min-width: 0;
	overflow: visible;
	overflow-wrap: anywhere;
}
#dashboard-sls-mass-notify-announcement form,
#dashboard-sls-mass-notify-announcement .sls-step-card,
#dashboard-sls-mass-notify-announcement .sls-color-designer,
#dashboard-sls-mass-notify-announcement .sls-destination-grid,
#dashboard-sls-mass-notify-announcement .sls-destination-panel { max-width: 100%; min-width: 0; box-sizing: border-box; }
#dashboard-sls-mass-notify-announcement .sls-widget-intro {
	margin: 0 0 10px;
	color: #64748b;
	font-size: 13px;
}
#dashboard-sls-mass-notify-announcement .sls-step-card {
	margin: 0 0 10px;
	padding: 12px;
	border: 1px solid #dfe5ec;
	border-radius: 9px;
	background: #fff;
	box-shadow: 0 2px 6px rgba(15, 23, 42, .045);
}
#dashboard-sls-mass-notify-announcement .sls-step-heading {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 9px;
	font-size: 15px;
	font-weight: 700;
}
#dashboard-sls-mass-notify-announcement .sls-step-number {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 23px;
	height: 23px;
	flex: 0 0 23px;
	border-radius: 50%;
	color: #fff;
	background: #6d28d9;
	font-size: 12px;
}
#dashboard-sls-mass-notify-announcement .sls-step-subtitle {
	margin: -4px 0 10px 31px;
	color: #64748b;
	font-size: 12px;
}
#dashboard-sls-mass-notify-announcement .sls-groups-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	min-width: 0;
}
#dashboard-sls-mass-notify-announcement .sls-groups-toolbar > label {
	margin: 0;
}
#dashboard-sls-mass-notify-announcement .sls-group-list {
	margin: 7px 0 9px;
	padding: 8px 10px;
	border: 1px solid #e5e7eb;
	border-radius: 7px;
	background: #f8fafc;
	max-height: 116px;
	overflow: auto;
}
#dashboard-sls-mass-notify-announcement .sls-group-list .checkbox {
	display: flex;
	align-items: flex-start;
	gap: 3px;
	margin: 0 0 5px;
	min-width: 0;
}
#dashboard-sls-mass-notify-announcement .sls-group-list .checkbox:last-child { margin-bottom: 0; }
#dashboard-sls-mass-notify-announcement .sls-group-list .checkbox > label {
	flex: 1 1 auto;
	min-width: 0;
	overflow-wrap: anywhere;
}
#dashboard-sls-mass-notify-announcement .sls-group-list .btn-link {
	flex: 0 0 auto;
	padding: 1px 4px;
}
#dashboard-sls-mass-notify-announcement .sls-destination-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
	gap: 8px;
}
#dashboard-sls-mass-notify-announcement .sls-destination-panel {
	align-self: start;
	border: 1px solid #dfe5ec;
	border-radius: 7px;
	background: #f8fafc;
	overflow: hidden;
}
#dashboard-sls-mass-notify-announcement .sls-destination-panel[open] {
	background: #fff;
	box-shadow: 0 1px 4px rgba(15, 23, 42, .05);
}
#dashboard-sls-mass-notify-announcement .sls-destination-panel summary {
	display: flex;
	align-items: center;
	gap: 7px;
	min-width: 0;
	padding: 9px 10px;
	font-weight: 600;
	cursor: pointer;
	list-style: none;
	user-select: none;
}
#dashboard-sls-mass-notify-announcement .sls-destination-panel summary::-webkit-details-marker { display: none; }
#dashboard-sls-mass-notify-announcement .sls-destination-panel summary:focus {
	outline: 2px solid #8b5cf6;
	outline-offset: -2px;
}
#dashboard-sls-mass-notify-announcement .sls-destination-panel summary .fa:first-child {
	width: 15px;
	color: #6d28d9;
	text-align: center;
}
#dashboard-sls-mass-notify-announcement .sls-destination-panel summary .sls-destination-label {
	flex: 1 1 auto;
	min-width: 0;
}
#dashboard-sls-mass-notify-announcement .sls-destination-count {
	padding: 1px 7px;
	border-radius: 999px;
	background: #e9e4f7;
	color: #4c1d95;
	font-size: 11px;
	font-weight: 700;
}
#dashboard-sls-mass-notify-announcement .sls-destination-chevron {
	color: #64748b;
	transition: transform .16s ease;
}
#dashboard-sls-mass-notify-announcement .sls-destination-panel[open] .sls-destination-chevron { transform: rotate(180deg); }
#dashboard-sls-mass-notify-announcement .sls-destination-body {
	padding: 0 10px 9px;
	border-top: 1px solid #e8edf3;
}
#dashboard-sls-mass-notify-announcement .sls-destination-body > .checkbox-inline {
	display: block;
	margin: 8px 0 5px;
}
#dashboard-sls-mass-notify-announcement .sls-compact-help {
	margin: 6px 0 0;
	font-size: 12px;
}
#dashboard-sls-mass-notify-announcement .sls-action-row {
	display: flex;
	align-items: center;
	justify-content: flex-start;
	gap: 10px;
	padding: 10px 12px;
	border-radius: 9px;
	background: linear-gradient(135deg, #f8fafc 0%, #f5f3ff 100%);
	border: 1px solid #d8dee8;
	box-shadow: 0 2px 6px rgba(15, 23, 42, .045);
}
#dashboard-sls-mass-notify-announcement .sls-announcement-inline-status {
	flex: 1 1 260px;
	min-width: 0;
	margin: 0;
	padding: 7px 9px;
	border-radius: 6px;
	overflow-wrap: anywhere;
}
#dashboard-sls-mass-notify-announcement .sls-announcement-inline-status .fa { margin-right: 6px; }
#dashboard-sls-mass-notify-announcement .sls-announcement-cooldown {
	flex: 0 0 auto;
	padding: 0;
	border: 1px solid transparent;
	border-radius: 999px;
	white-space: nowrap;
}
#dashboard-sls-mass-notify-announcement .sls-announcement-cooldown:not(:empty) {
	padding: 4px 8px;
	border-color: #d8dee8;
	background: #fff;
}
#dashboard-sls-mass-notify-announcement .sls-submit-spinner { display: none; margin-right: 5px; }
#dashboard-sls-mass-notify-announcement .sls-submit-busy .sls-submit-spinner { display: inline-block; }
#dashboard-sls-mass-notify-announcement .sls-submit-busy { cursor: wait; }
#dashboard-sls-mass-notify-announcement .sls-composer-grid {
	display: grid;
	grid-template-columns: minmax(0, 1.35fr) minmax(210px, .65fr);
	gap: 11px;
	align-items: start;
}
#dashboard-sls-mass-notify-announcement .sls-option-box {
	padding: 9px 10px;
	border: 1px solid #e5e7eb;
	border-radius: 7px;
	background: #f8fafc;
}
#dashboard-sls-mass-notify-announcement .sls-option-box .form-group:last-child { margin-bottom: 0; }
#dashboard-sls-mass-notify-announcement .sls-tone-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 8px;
	margin-top: 8px;
}
#dashboard-sls-mass-notify-announcement .sls-tone-grid > div { min-width: 0; }
#dashboard-sls-mass-notify-announcement .sls-tone-grid label { font-size: 12px; }
#dashboard-sls-mass-notify-announcement .sls-tone-grid .form-control { width: 100%; }
#dashboard-sls-mass-notify-announcement .sls-message-count {
	display: block;
	margin-top: 3px;
	text-align: right;
	color: #64748b;
	font-size: 11px;
}
#dashboard-sls-mass-notify-announcement .sls-target-list {
	max-height: 126px;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 4px 4px 0 0;
	margin: 0;
}
#dashboard-sls-mass-notify-announcement .modal .sls-target-list {
	max-height: 260px;
}
#dashboard-sls-mass-notify-announcement .sls-target-list .checkbox,
#dashboard-sls-mass-notify-announcement .sls-target-list .checkbox-inline {
	display: block;
	min-height: 22px;
	margin-top: 0;
	margin-bottom: 4px;
	overflow-wrap: anywhere;
	word-break: break-word;
}
#dashboard-sls-mass-notify-announcement .sls-target-list .col-sm-4,
#dashboard-sls-mass-notify-announcement .sls-target-list .col-sm-6,
#dashboard-sls-mass-notify-announcement .sls-target-list .col-sm-12 {
	margin-bottom: 4px;
}
#dashboard-sls-mass-notify-announcement .row { max-width: calc(100% + 30px); }
#dashboard-sls-mass-notify-announcement .form-control { max-width: 100%; }
#dashboard-sls-mass-notify-announcement textarea {
	resize: vertical;
	min-height: 96px;
}
#dashboard-sls-mass-notify-announcement .sls-labs-badge {
	display: inline-block;
	margin-left: 6px;
	vertical-align: middle;
}
#dashboard-sls-mass-notify-announcement .sls-color-designer {
	display: none;
	margin: 9px 0 1px;
	padding: 10px;
	border-left: 4px solid #22c55e;
	background: #f7faf8;
}
#dashboard-sls-mass-notify-announcement .sls-color-preview {
	min-height: 112px;
	padding: 14px;
	color: #fff;
	background: #1f2937;
	border-radius: 4px;
	overflow-wrap: anywhere;
}
#dashboard-sls-mass-notify-announcement .sls-color-preview-title {
	font-size: 18px;
	font-weight: 700;
	margin-bottom: 8px;
}
@media (max-width: 767px) {
	#dashboard-sls-mass-notify-announcement .sls-destination-grid,
	#dashboard-sls-mass-notify-announcement .sls-composer-grid { grid-template-columns: minmax(0, 1fr); }
	#dashboard-sls-mass-notify-announcement .sls-action-row { align-items: stretch; flex-direction: column; }
	#dashboard-sls-mass-notify-announcement .sls-action-row .btn { width: 100%; }
	#dashboard-sls-mass-notify-announcement .sls-announcement-inline-status { flex-basis: auto; }
	#dashboard-sls-mass-notify-announcement .sls-announcement-cooldown { align-self: center; }
	#dashboard-sls-mass-notify-announcement .sls-groups-toolbar { align-items: flex-start; flex-direction: column; }
}
@media (max-width: 420px) {
	#dashboard-sls-mass-notify-announcement { padding-left: 2px; padding-right: 2px; }
	#dashboard-sls-mass-notify-announcement .sls-step-card { padding: 10px; }
	#dashboard-sls-mass-notify-announcement .sls-tone-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>
<div class="container-fluid" id="dashboard-sls-mass-notify-announcement" data-quiet-hours-active="<?php echo $quietHoursActive ? '1' : '0'; ?>">
	<?php if (!$setupComplete) { ?>
		<div class="alert alert-warning">
			<strong><?php echo _('Setup Required'); ?></strong><br>
			<?php echo htmlspecialchars((string)($setup_required_message ?? _('Setup wizard must be completed before Mass Notifications can be used.'))); ?>
			<div style="margin-top: 10px;">
				<button type="button" class="btn btn-primary btn-sm" id="dashboard-sls-setup-open"><?php echo _('Open Setup Wizard'); ?></button>
			</div>
		</div>
		<?php echo $setupModal; ?>
		<script>
		(function() {
			var lifecycleKey = '__slsMassNotifyAnnouncementWidget';
			var previousLifecycle = window[lifecycleKey];
			if (previousLifecycle && typeof previousLifecycle.dispose === 'function') {
				previousLifecycle.dispose();
			}
			var open = document.getElementById('dashboard-sls-setup-open');
			if (!open) {
				return;
			}
			open.addEventListener('click', function() {
				var backdrop = document.querySelector('.sls-setup-backdrop');
				var shell = document.querySelector('.sls-setup-modal-shell');
				if (backdrop) {
					backdrop.style.display = '';
				}
				if (shell) {
					shell.style.display = '';
				}
			});
		}());
		</script>
	</div>
	<?php return; ?>
	<?php } ?>
	<div id="dashboard-sls-mass-notify-group-result" role="status" aria-live="polite" style="display: none;"></div>
	<p class="sls-widget-intro"><?php echo _('Choose destinations, write the message, and select how it should be delivered.'); ?></p>
	<form id="dashboard-sls-mass-notify-announcement-form" method="post" action="config.php?display=slsmassnotifyserver">
		<input type="hidden" name="slsmassnotifyserver_action" value="send_announcement">
		<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
		<section class="sls-step-card" aria-labelledby="sls-dashboard-destinations-heading">
			<div class="sls-step-heading" id="sls-dashboard-destinations-heading"><span class="sls-step-number">1</span><?php echo _('Destinations'); ?></div>
			<p class="sls-step-subtitle"><?php echo _('Select a saved group, or expand a destination type for individual targets.'); ?></p>
			<div class="sls-groups-toolbar">
				<label><?php echo _('Announcement Groups'); ?> <span class="badge" id="dashboard-announcement-group-count"><?php echo count($announcementGroups); ?></span></label>
				<button type="button" class="btn btn-xs btn-default" id="dashboard-announcement-new-group" <?php echo empty($announcementGroupTargets) && empty($desktopClients) ? 'disabled' : ''; ?>><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('New Group'); ?></button>
			</div>
			<div id="dashboard-announcement-groups" class="sls-group-list"></div>
			<div class="sls-destination-grid">
				<details class="sls-destination-panel">
					<summary>
						<i class="fa fa-phone" aria-hidden="true"></i>
						<span class="sls-destination-label"><?php echo _('Phones'); ?></span>
						<span class="sls-destination-count"><?php echo count($announcementTargets); ?></span>
						<i class="fa fa-chevron-down sls-destination-chevron" aria-hidden="true"></i>
					</summary>
					<div class="sls-destination-body">
						<label class="checkbox-inline">
							<input type="checkbox" name="announcement_all_phones" value="1">
							<?php echo _('All Phones'); ?>
						</label>
						<?php if (empty($announcementTargets)) { ?>
							<p class="help-block sls-compact-help"><?php echo _('No registered PJSIP phones are currently online.'); ?></p>
						<?php } else { ?>
							<div id="dashboard-extension-list" class="sls-target-list">
							<?php foreach ($announcementTargets as $target) { ?>
								<div class="dashboard-extension-row checkbox">
									<label>
										<input type="checkbox" name="announcement_extensions[]" value="<?php echo htmlspecialchars($target['extension']); ?>">
										<?php echo htmlspecialchars($target['extension']); ?>
										<?php if ($target['name'] !== '') { echo ' - ' . htmlspecialchars($target['name']); } ?>
										<span class="text-muted"><?php echo !empty($target['registered']) ? _('registered') : _('offline'); ?></span>
									</label>
								</div>
							<?php } ?>
							</div>
						<?php } ?>
					</div>
				</details>
				<details class="sls-destination-panel">
					<summary>
						<i class="fa fa-desktop" aria-hidden="true"></i>
						<span class="sls-destination-label"><?php echo _('Desktop Apps'); ?></span>
						<span class="sls-destination-count"><?php echo $enabledDesktopCount; ?></span>
						<i class="fa fa-chevron-down sls-destination-chevron" aria-hidden="true"></i>
					</summary>
					<div class="sls-destination-body">
						<label class="checkbox-inline">
							<input type="checkbox" name="announcement_all_desktops" value="1">
							<?php echo _('All Desktops'); ?>
						</label>
						<?php if ($enabledDesktopCount === 0) { ?>
							<p class="help-block sls-compact-help"><?php echo _('No enabled desktop app clients are configured.'); ?></p>
						<?php } else { ?>
							<div class="sls-target-list">
								<?php foreach ($desktopClients as $client) { ?>
									<?php if (empty($client['enabled'])) { continue; } ?>
									<div class="dashboard-desktop-row checkbox">
										<label>
											<input type="checkbox" name="announcement_desktop_clients[]" value="<?php echo htmlspecialchars($client['username'] ?? ''); ?>">
											<?php echo htmlspecialchars($client['name'] ?? 'Desktop App'); ?>
											<span class="text-muted"><?php echo htmlspecialchars($client['client_id'] ?? $client['username'] ?? ''); ?></span>
										</label>
									</div>
								<?php } ?>
							</div>
						<?php } ?>
					</div>
				</details>
				<details class="sls-destination-panel">
					<summary>
						<i class="fa fa-paper-plane" aria-hidden="true"></i>
						<span class="sls-destination-label"><?php echo _('Webhooks'); ?></span>
						<span class="sls-destination-count"><?php echo count($announcementWebhooks); ?></span>
						<i class="fa fa-chevron-down sls-destination-chevron" aria-hidden="true"></i>
					</summary>
					<div class="sls-destination-body">
						<?php if (empty($announcementWebhooks)) { ?>
							<p class="help-block sls-compact-help"><?php echo _('No Dashboard webhooks are configured. Add them under Mass Notify, General Settings.'); ?></p>
						<?php } else { ?>
							<div class="sls-target-list">
								<?php foreach ($announcementWebhooks as $destination) { ?>
									<div class="checkbox"><label>
										<input type="checkbox" name="announcement_webhooks[]" value="<?php echo htmlspecialchars((string)($destination['id'] ?? '')); ?>">
										<?php echo htmlspecialchars((string)($destination['name'] ?? _('Announcement Webhook'))); ?>
									</label></div>
								<?php } ?>
							</div>
							<p class="help-block sls-compact-help"><?php echo _('Each Discord or compatible HTTPS selection receives a branded Discord embed JSON payload.'); ?></p>
						<?php } ?>
					</div>
				</details>
			</div>
			<p class="help-block sls-compact-help"><?php echo _('Groups may include phones and desktop apps. Offline phones are skipped at send time.'); ?></p>
		</section>
		<section class="sls-step-card" aria-labelledby="sls-dashboard-message-heading">
			<div class="sls-step-heading" id="sls-dashboard-message-heading"><span class="sls-step-number">2</span><?php echo _('Message and delivery'); ?></div>
			<div class="sls-composer-grid">
				<div>
					<label for="dashboard_announcement_body"><?php echo _('Announcement Message'); ?></label>
					<textarea class="form-control" id="dashboard_announcement_body" name="announcement_body" rows="3" maxlength="500" placeholder="<?php echo htmlspecialchars(_('Type the announcement')); ?>" aria-describedby="dashboard-announcement-message-help dashboard-announcement-character-count"></textarea>
					<div id="dashboard-announcement-message-help" class="help-block sls-compact-help"><?php echo _('Shown on visual destinations and read aloud when TTS is enabled.'); ?></div>
					<span id="dashboard-announcement-character-count" class="sls-message-count">0 / 500</span>
				</div>
				<div class="sls-option-box">
					<div class="form-group">
						<label for="dashboard_announcement_audio_mode"><?php echo _('Audio'); ?></label>
						<select class="form-control" id="dashboard_announcement_audio_mode" name="announcement_audio_mode">
							<option value="none"><?php echo _('None (visual/text only)'); ?></option>
							<option value="tones"><?php echo _('Tones only'); ?></option>
							<option value="tts"><?php echo _('TTS only'); ?></option>
							<option value="tones_tts" selected><?php echo _('Tones and TTS'); ?></option>
						</select>
					</div>
					<label class="checkbox-inline" style="display:block; margin-left:0;">
						<input type="checkbox" id="dashboard_announcement_colored" name="announcement_colored" value="1">
						<?php echo _('Colored Yealink visual'); ?>
						<span class="label label-success sls-labs-badge"><i class="fa fa-flask" aria-hidden="true"></i> <?php echo _('Labs'); ?></span>
					</label>
				</div>
			</div>
			<div class="sls-tone-grid" id="dashboard_announcement_tone_options">
				<div>
					<label for="dashboard_announcement_opening_tone"><?php echo _('Opening Sound'); ?></label>
					<select class="form-control" id="dashboard_announcement_opening_tone" name="announcement_opening_tone">
						<option value=""><?php echo _('None'); ?></option>
						<?php foreach ($announcementTones as $tone) { ?><option value="<?php echo htmlspecialchars($tone); ?>" <?php echo ($announcementState['opening_tone'] ?? '') === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $tone)); ?></option><?php } ?>
					</select>
				</div>
				<div>
					<label for="dashboard_announcement_closing_tone"><?php echo _('Closing Sound'); ?></label>
					<select class="form-control" id="dashboard_announcement_closing_tone" name="announcement_closing_tone">
						<option value=""><?php echo _('None'); ?></option>
						<?php foreach ($announcementTones as $tone) { ?><option value="<?php echo htmlspecialchars($tone); ?>" <?php echo ($announcementState['closing_tone'] ?? '') === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $tone)); ?></option><?php } ?>
					</select>
				</div>
			</div>
			<div class="sls-color-designer" id="dashboard_announcement_color_designer" aria-hidden="true">
				<div class="row">
					<div class="col-sm-7">
						<div class="form-group">
							<label for="dashboard_announcement_title"><?php echo _('Image Title'); ?></label>
							<input class="form-control" id="dashboard_announcement_title" name="announcement_title" type="text" maxlength="80" value="Announcement">
						</div>
						<div class="form-group">
							<label for="dashboard_announcement_background_color"><?php echo _('Background Color'); ?></label>
							<input class="form-control" id="dashboard_announcement_background_color" name="announcement_background_color" type="color" value="#1f2937" style="max-width: 100px; padding: 3px;">
						</div>
					</div>
					<div class="col-sm-5">
						<label><?php echo _('Preview'); ?></label>
						<div class="sls-color-preview" id="dashboard_announcement_color_preview">
							<div class="sls-color-preview-title" id="dashboard_announcement_preview_title">Announcement</div>
							<div id="dashboard_announcement_preview_body"><?php echo _('Announcement text'); ?></div>
						</div>
					</div>
				</div>
			</div>
			<p class="help-block sls-compact-help"><?php echo _('Opening and closing sounds are included only when a tone audio mode is selected.'); ?></p>
		</section>
		<div class="sls-action-row">
			<button type="submit" id="dashboard-sls-mass-notify-announcement-submit" class="btn btn-warning" <?php echo $announcementCooldown > 0 ? 'disabled' : ''; ?>>
				<i class="fa fa-circle-o-notch fa-spin sls-submit-spinner" aria-hidden="true"></i><span class="sls-submit-label"><?php echo _('Send Announcement'); ?></span>
			</button>
			<div id="dashboard-sls-mass-notify-announcement-result" class="sls-announcement-inline-status" role="status" aria-live="polite" aria-atomic="true" aria-busy="false" style="display: none;"></div>
			<span id="dashboard-sls-mass-notify-announcement-cooldown" class="text-muted sls-announcement-cooldown" data-remaining="<?php echo $announcementCooldown; ?>"><?php echo $announcementCooldown > 0 ? sprintf(_('Cooldown: %ss'), $announcementCooldown) : ''; ?></span>
		</div>
	</form>
	<div class="modal fade" id="dashboard-announcement-group-modal" tabindex="-1" role="dialog" aria-hidden="true">
		<div class="modal-dialog" role="document">
			<div class="modal-content">
				<form id="dashboard-announcement-group-form">
					<div class="modal-header">
						<button type="button" class="close" data-dismiss="modal" aria-label="<?php echo htmlspecialchars(_('Close')); ?>"><span aria-hidden="true">&times;</span></button>
						<h4 class="modal-title"><?php echo _('Announcement Group'); ?></h4>
					</div>
					<div class="modal-body">
						<input type="hidden" name="slsmassnotifyserver_action" value="save_announcement_group">
						<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
						<input type="hidden" id="dashboard_group_id" name="group_id" value="">
						<div class="form-group">
							<label for="dashboard_group_name"><?php echo _('Group Name'); ?></label>
							<input class="form-control" id="dashboard_group_name" name="group_name" type="text" maxlength="64">
						</div>
						<div class="form-group">
							<label><?php echo _('Extensions'); ?></label>
							<?php if (empty($announcementGroupTargets)) { ?>
								<div class="alert alert-warning"><?php echo _('No PJSIP extensions are currently configured.'); ?></div>
							<?php } else { ?>
								<div class="row sls-target-list">
									<?php foreach ($announcementGroupTargets as $target) { ?>
										<div class="col-sm-4">
											<label class="checkbox-inline">
												<input type="checkbox" name="group_extensions[]" value="<?php echo htmlspecialchars($target['extension']); ?>">
												<?php echo htmlspecialchars($target['extension']); ?>
												<?php if ($target['name'] !== '') { ?>
													<?php echo ' - ' . htmlspecialchars($target['name']); ?>
												<?php } ?>
												<span class="text-muted">
													<?php echo !empty($target['registered']) ? _('online') : _('offline'); ?>
												</span>
											</label>
										</div>
									<?php } ?>
								</div>
							<?php } ?>
						</div>
						<div class="form-group">
							<label><?php echo _('Desktop App Clients'); ?></label>
							<?php if (empty($desktopClients)) { ?>
								<div class="alert alert-warning"><?php echo _('No desktop app clients are currently configured.'); ?></div>
							<?php } else { ?>
								<div class="row sls-target-list">
									<?php foreach ($desktopClients as $client) { ?>
										<?php if (empty($client['enabled'])) { continue; } ?>
										<div class="col-sm-6">
											<label class="checkbox-inline">
													<input type="checkbox" name="group_desktop_clients[]" value="<?php echo htmlspecialchars($client['username'] ?? ''); ?>">
													<?php echo htmlspecialchars($client['name'] ?? 'Desktop App'); ?>
													<span class="text-muted"><?php echo htmlspecialchars($client['client_id'] ?? $client['username'] ?? ''); ?></span>
												</label>
											</div>
									<?php } ?>
								</div>
							<?php } ?>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-default" data-dismiss="modal"><?php echo _('Cancel'); ?></button>
						<button type="submit" class="btn btn-primary"><?php echo _('Save Group'); ?></button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
<script>
(function() {
	var initialGroups = <?php echo json_encode(array_values($announcementGroups), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	var onlineExtensions = <?php echo json_encode(array_values(array_map(static function ($target) { return (string)($target['extension'] ?? ''); }, $announcementTargets)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	var desktopClients = <?php echo json_encode(array_values($desktopClients), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	var root = document.getElementById('dashboard-sls-mass-notify-announcement');
	if (!root || root.getAttribute('data-ready') === '1') {
		return;
	}
	var lifecycleKey = '__slsMassNotifyAnnouncementWidget';
	var previousLifecycle = window[lifecycleKey];
	if (previousLifecycle && typeof previousLifecycle.dispose === 'function') {
		previousLifecycle.dispose();
	}
	var lifecycle = {
		disposed: false,
		intervals: [],
		timeouts: [],
		resizeObserver: null,
		resizeHandler: null,
		dispose: function() {
			if (this.disposed) {
				return;
			}
			this.disposed = true;
			this.intervals.forEach(function(timer) { window.clearInterval(timer); });
			this.timeouts.forEach(function(timer) { window.clearTimeout(timer); });
			this.intervals = [];
			this.timeouts = [];
			if (this.resizeObserver) {
				this.resizeObserver.disconnect();
				this.resizeObserver = null;
			}
			if (this.resizeHandler) {
				window.removeEventListener('resize', this.resizeHandler);
				this.resizeHandler = null;
			}
			if (window[lifecycleKey] === this) {
				window[lifecycleKey] = null;
			}
		}
	};
	window[lifecycleKey] = lifecycle;
	function instanceActive() {
		var attached = !!(document.documentElement && document.documentElement.contains(root));
		if (lifecycle.disposed || window[lifecycleKey] !== lifecycle || !attached) {
			lifecycle.dispose();
			return false;
		}
		return true;
	}
	root.setAttribute('data-ready', '1');
	var form = document.getElementById('dashboard-sls-mass-notify-announcement-form');
	var submit = document.getElementById('dashboard-sls-mass-notify-announcement-submit');
	var cooldown = document.getElementById('dashboard-sls-mass-notify-announcement-cooldown');
	var result = document.getElementById('dashboard-sls-mass-notify-announcement-result');
	var groupResult = document.getElementById('dashboard-sls-mass-notify-group-result');
	var groupList = document.getElementById('dashboard-announcement-groups');
	var groupCount = document.getElementById('dashboard-announcement-group-count');
	var newGroup = document.getElementById('dashboard-announcement-new-group');
	var groupForm = document.getElementById('dashboard-announcement-group-form');
	var groupModal = $('#dashboard-announcement-group-modal');
	var groupId = document.getElementById('dashboard_group_id');
	var groupName = document.getElementById('dashboard_group_name');
	var coloredToggle = document.getElementById('dashboard_announcement_colored');
	var audioMode = document.getElementById('dashboard_announcement_audio_mode');
	var toneOptions = document.getElementById('dashboard_announcement_tone_options');
	var colorDesigner = document.getElementById('dashboard_announcement_color_designer');
	var colorInput = document.getElementById('dashboard_announcement_background_color');
	var titleInput = document.getElementById('dashboard_announcement_title');
	var messageInput = document.getElementById('dashboard_announcement_body');
	var colorPreview = document.getElementById('dashboard_announcement_color_preview');
	var previewTitle = document.getElementById('dashboard_announcement_preview_title');
	var previewBody = document.getElementById('dashboard_announcement_preview_body');
	var messageCount = document.getElementById('dashboard-announcement-character-count');
	var submitLabel = submit ? submit.querySelector('.sls-submit-label') : null;
	var destinationPanels = root.querySelectorAll('.sls-destination-panel');
	var dashboardItem = root.closest ? root.closest('.item') : null;
	var dashboardBox = root.closest ? root.closest('.displaybox') : null;
	if (dashboardItem) {
		dashboardItem.style.maxWidth = '100%';
		dashboardItem.style.minWidth = '0';
		dashboardItem.style.height = 'auto';
		dashboardItem.style.boxSizing = 'border-box';
	}
	if (dashboardBox) {
		dashboardBox.style.maxWidth = '100%';
		dashboardBox.style.minWidth = '0';
		dashboardBox.style.height = 'auto';
		dashboardBox.style.boxSizing = 'border-box';
		var dashboardContent = dashboardBox.querySelector('.content');
		if (dashboardContent) {
			dashboardContent.style.height = 'auto';
			dashboardContent.style.minWidth = '0';
		}
	}
	function scheduleDashboardLayout() {
		if (!instanceActive()) {
			return;
		}
		lifecycle.timeouts.forEach(function(timer) { window.clearTimeout(timer); });
		lifecycle.timeouts = [35, 180].map(function(delay) {
			return window.setTimeout(function() {
				if (!instanceActive()) {
					return;
				}
				var page = $(root).closest('.page');
				if (page.length && typeof page.packery === 'function') {
					page.packery('layout');
				}
			}, delay);
		});
	}
	if (!form || !submit || !cooldown || !result || !groupResult) {
		lifecycle.dispose();
		return;
	}
	function setAnnouncementStatus(level, message, busy) {
		if (!instanceActive()) {
			return;
		}
		var normalizedLevel = ['success', 'warning', 'danger', 'info'].indexOf(level) >= 0 ? level : 'info';
		var iconNames = {success: 'check-circle', warning: 'exclamation-triangle', danger: 'times-circle', info: 'circle-o-notch fa-spin'};
		result.style.display = 'block';
		result.className = 'alert alert-' + normalizedLevel + ' sls-announcement-inline-status sls-status-' + normalizedLevel;
		result.setAttribute('aria-live', normalizedLevel === 'danger' ? 'assertive' : 'polite');
		result.setAttribute('aria-busy', busy ? 'true' : 'false');
		result.innerHTML = '';
		var icon = document.createElement('i');
		icon.className = 'fa fa-' + (busy ? 'circle-o-notch fa-spin' : iconNames[normalizedLevel]);
		icon.setAttribute('aria-hidden', 'true');
		var statusText = document.createElement('span');
		statusText.textContent = message;
		result.appendChild(icon);
		result.appendChild(statusText);
		scheduleDashboardLayout();
	}
	function renderMessageCount() {
		if (messageCount && messageInput) {
			messageCount.textContent = String(messageInput.value.length) + ' / 500';
		}
	}
	var groups = Array.isArray(initialGroups) ? initialGroups : [];
	function renderColorDesigner() {
		if (!coloredToggle || !colorDesigner) {
			return;
		}
		var enabled = coloredToggle.checked;
		colorDesigner.style.display = enabled ? 'block' : 'none';
		colorDesigner.setAttribute('aria-hidden', enabled ? 'false' : 'true');
		if (colorPreview && colorInput) {
			colorPreview.style.backgroundColor = colorInput.value || '#1f2937';
		}
		if (previewTitle && titleInput) {
			previewTitle.textContent = titleInput.value.trim() || 'Announcement';
		}
		if (previewBody && messageInput) {
			previewBody.textContent = messageInput.value.trim() || 'Announcement text';
		}
		scheduleDashboardLayout();
	}
	function renderAudioOptions() {
		if (audioMode && toneOptions) {
			var showTones = audioMode.value === 'tones' || audioMode.value === 'tones_tts';
			toneOptions.style.display = showTones ? '' : 'none';
			toneOptions.setAttribute('aria-hidden', showTones ? 'false' : 'true');
			scheduleDashboardLayout();
		}
	}
	if (audioMode) {
		audioMode.addEventListener('change', renderAudioOptions);
	}
	[coloredToggle, colorInput, titleInput, messageInput].forEach(function(control) {
		if (control) {
			control.addEventListener(control === coloredToggle ? 'change' : 'input', renderColorDesigner);
		}
	});
	if (messageInput) {
		messageInput.addEventListener('input', renderMessageCount);
	}
	Array.prototype.forEach.call(destinationPanels, function(panel) {
		panel.addEventListener('toggle', scheduleDashboardLayout);
	});
	if (window.ResizeObserver) {
		lifecycle.resizeObserver = new ResizeObserver(scheduleDashboardLayout);
		lifecycle.resizeObserver.observe(root);
	}
	lifecycle.resizeHandler = scheduleDashboardLayout;
	window.addEventListener('resize', lifecycle.resizeHandler);
		var desktopLookup = {};
		(Array.isArray(desktopClients) ? desktopClients : []).forEach(function(client) {
			if (client && client.username) {
				desktopLookup[client.username] = (client.name || client.username) + (client.client_id ? ' (' + client.client_id + ')' : '');
			}
		});
	var onlineLookup = {};
	onlineExtensions.forEach(function(extension) {
		if (extension !== '') {
			onlineLookup[extension] = true;
		}
	});
	function renderGroups() {
		if (!groupList) {
			return;
		}
		if (groupCount) {
			groupCount.textContent = String(groups.length);
		}
		groupList.innerHTML = '';
		if (!groups.length) {
			var empty = document.createElement('div');
			empty.className = 'text-muted';
			empty.textContent = 'No announcement groups created yet.';
			groupList.appendChild(empty);
			return;
		}
		groups.forEach(function(group) {
			var row = document.createElement('div');
			row.className = 'checkbox';
			var label = document.createElement('label');
			var checkbox = document.createElement('input');
			checkbox.type = 'checkbox';
			checkbox.name = 'announcement_groups[]';
			checkbox.value = group.id || '';
			label.appendChild(checkbox);
			label.appendChild(document.createTextNode(' ' + (group.name || 'Group') + ' '));
			var muted = document.createElement('span');
			muted.className = 'text-muted';
			var groupParts = [];
			if ((group.extensions || []).length) {
				groupParts.push('Phones: ' + (group.extensions || []).join(', '));
			}
			if ((group.desktop_clients || []).length) {
				groupParts.push('Desktops: ' + (group.desktop_clients || []).map(function(username) {
					return desktopLookup[username] || username;
				}).join(', '));
			}
			muted.textContent = '(' + groupParts.join(' | ') + ')';
			label.appendChild(muted);
			row.appendChild(label);
			var edit = document.createElement('button');
			edit.type = 'button';
			edit.className = 'btn btn-link btn-xs';
			edit.textContent = 'Edit';
			edit.addEventListener('click', function() { openGroupModal(group); });
			row.appendChild(edit);
			var del = document.createElement('button');
			del.type = 'button';
			del.className = 'btn btn-link btn-xs text-danger';
			del.textContent = 'Delete';
			del.addEventListener('click', function() { deleteGroup(group.id || ''); });
			row.appendChild(del);
			groupList.appendChild(row);
		});
	}
	function openGroupModal(group) {
		group = group || {};
		groupId.value = group.id || '';
		groupName.value = group.name || '';
		var selected = {};
		(group.extensions || []).forEach(function(extension) { selected[extension] = true; });
		Array.prototype.forEach.call(groupForm.querySelectorAll('input[name="group_extensions[]"]'), function(input) {
			input.checked = !!selected[input.value];
		});
		var selectedDesktops = {};
		(group.desktop_clients || []).forEach(function(username) { selectedDesktops[username] = true; });
		Array.prototype.forEach.call(groupForm.querySelectorAll('input[name="group_desktop_clients[]"]'), function(input) {
			input.checked = !!selectedDesktops[input.value];
		});
		groupModal.modal('show');
	}
	function deleteGroup(id) {
		if (!instanceActive()) {
			return;
		}
		if (!id || !confirm('Delete this announcement group?')) {
			return;
		}
		var body = new FormData();
		body.append('slsmassnotifyserver_action', 'delete_announcement_group');
		body.append('slsmassnotifyserver_csrf', <?php echo json_encode($csrfToken); ?>);
		body.append('group_id', id);
			fetch(form.action, {method: 'POST', credentials: 'same-origin', body: body})
				.then(parseJsonResponse)
			.then(function(data) {
				if (!instanceActive()) {
					return;
				}
				groupResult.style.display = 'block';
				groupResult.className = 'alert alert-' + (data && data.success ? 'success' : 'warning');
				groupResult.textContent = data && data.message ? data.message : 'Group request finished.';
				if (data && Array.isArray(data.groups)) {
					groups = data.groups;
					renderGroups();
				}
			})
				.catch(function(error) {
					if (!instanceActive()) {
						return;
					}
					groupResult.style.display = 'block';
					groupResult.className = 'alert alert-danger';
					groupResult.textContent = 'Group request failed: ' + (error && error.message ? error.message : 'unknown error');
				});
	}
		function selectedGroupOfflineExtensions() {
		var offline = {};
		var selectedGroups = {};
		Array.prototype.forEach.call(form.querySelectorAll('input[name="announcement_groups[]"]:checked'), function(input) {
			selectedGroups[input.value] = true;
		});
		groups.forEach(function(group) {
			if (!selectedGroups[group.id || '']) {
				return;
			}
			(group.extensions || []).forEach(function(extension) {
				if (!onlineLookup[extension]) {
					offline[extension] = extension;
				}
			});
		});
			return Object.keys(offline).sort();
		}
		function parseJsonResponse(response) {
			return response.text().then(function(text) {
				try {
					return JSON.parse(text);
				} catch (e) {
					throw new Error(text ? text.replace(/\s+/g, ' ').slice(0, 220) : ('HTTP ' + response.status));
				}
			});
		}
	if (newGroup) {
		newGroup.addEventListener('click', function() { openGroupModal({}); });
	}
	if (groupForm) {
		groupForm.addEventListener('submit', function(event) {
			event.preventDefault();
			if (!instanceActive()) {
				return;
			}
				fetch(form.action, {method: 'POST', credentials: 'same-origin', body: new FormData(groupForm)})
					.then(parseJsonResponse)
				.then(function(data) {
					if (!instanceActive()) {
						return;
					}
					groupResult.style.display = 'block';
					groupResult.className = 'alert alert-' + (data && data.success ? 'success' : 'warning');
					groupResult.textContent = data && data.message ? data.message : 'Group request finished.';
					if (data && Array.isArray(data.groups)) {
						groups = data.groups;
						renderGroups();
					}
					if (data && data.success) {
						groupModal.modal('hide');
					}
				})
					.catch(function(error) {
						if (!instanceActive()) {
							return;
						}
						groupResult.style.display = 'block';
						groupResult.className = 'alert alert-danger';
						groupResult.textContent = 'Group request failed: ' + (error && error.message ? error.message : 'unknown error');
					});
		});
	}
	var remaining = parseInt(cooldown.getAttribute('data-remaining') || '0', 10) || 0;
	var deliveryOutcomeUnknown = false;
	var requestInFlight = false;
	function setSubmitBusy(busy) {
		requestInFlight = !!busy;
		submit.classList.toggle('sls-submit-busy', requestInFlight);
		submit.setAttribute('aria-busy', requestInFlight ? 'true' : 'false');
		if (submitLabel) {
			submitLabel.textContent = requestInFlight ? 'Sending…' : 'Send Announcement';
		}
	}
	function setCooldownText(message, iconName) {
		cooldown.innerHTML = '';
		if (!message) {
			return;
		}
		var icon = document.createElement('i');
		icon.className = 'fa fa-' + iconName;
		icon.setAttribute('aria-hidden', 'true');
		cooldown.appendChild(icon);
		cooldown.appendChild(document.createTextNode(' ' + message));
	}
	function renderCooldown() {
		if (requestInFlight) {
			submit.disabled = true;
			setCooldownText('Submitting', 'circle-o-notch fa-spin');
			return;
		}
		if (deliveryOutcomeUnknown) {
			submit.disabled = true;
			setCooldownText('Checking delivery status…', 'refresh fa-spin');
			return;
		}
		if (remaining > 0) {
			submit.disabled = true;
			setCooldownText('Cooldown: ' + remaining + 's', 'clock-o');
			return;
		}
		submit.disabled = false;
		setCooldownText('', '');
	}
	lifecycle.intervals.push(window.setInterval(function() {
		if (!instanceActive()) {
			return;
		}
		if (remaining > 0) {
			remaining -= 1;
			renderCooldown();
		}
	}, 1000));
	lifecycle.intervals.push(window.setInterval(function() {
		if (!instanceActive()) {
			return;
		}
		fetch('config.php?display=slsmassnotifyserver&slsmassnotifyserver_action=cooldowns', {credentials: 'same-origin'})
				.then(parseJsonResponse)
			.then(function(data) {
				if (!instanceActive()) {
					return;
				}
				if (data && data.cooldowns && data.cooldowns.announcement) {
					deliveryOutcomeUnknown = false;
					remaining = parseInt(data.cooldowns.announcement.remaining || '0', 10) || 0;
					renderCooldown();
				}
			})
			.catch(function() {});
		}, 10000));
	form.addEventListener('submit', function(event) {
		event.preventDefault();
		if (!instanceActive()) {
			return;
		}
		if (remaining > 0) {
			return;
		}
		if (root.getAttribute('data-quiet-hours-active') === '1') {
			if (!confirm('⚠ You are currently inside quiet hours. Are you sure you want to send this message after paging hours?')) {
				return;
			}
		}
		var offlineExtensions = selectedGroupOfflineExtensions();
		if (offlineExtensions.length > 0) {
			if (!confirm('⚠ Not all extensions in the selected announcement group are online. Offline extensions will be skipped: ' + offlineExtensions.join(', ') + '. Send to online extensions only?')) {
				return;
			}
		}
		setSubmitBusy(true);
		renderCooldown();
		setAnnouncementStatus('info', 'Preparing announcement and starting the selected delivery channels…', true);
		deliveryOutcomeUnknown = false;
		var body = new FormData(form);
			fetch(form.action, {method: 'POST', credentials: 'same-origin', body: body})
				.then(parseJsonResponse)
			.then(function(data) {
				if (!instanceActive()) {
					return;
				}
				setAnnouncementStatus(
					data && data.success ? 'success' : 'warning',
					data && data.message ? data.message : 'Announcement request finished.',
					false
				);
				setSubmitBusy(false);
				deliveryOutcomeUnknown = false;
				remaining = parseInt((data && data.cooldown_remaining) || '0', 10) || 0;
				renderCooldown();
			})
				.catch(function(error) {
					if (!instanceActive()) {
						return;
					}
					setSubmitBusy(false);
					deliveryOutcomeUnknown = true;
					setAnnouncementStatus(
						'danger',
						'Announcement response could not be confirmed. Wait for cooldown status before retrying. ' + (error && error.message ? error.message : 'Unknown response error.'),
						false
					);
					renderCooldown();
				});
	});
	renderGroups();
	renderColorDesigner();
	renderAudioOptions();
	renderMessageCount();
	setSubmitBusy(false);
	renderCooldown();
	scheduleDashboardLayout();
}());
</script>
