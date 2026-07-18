<?php
$announcementTargets = is_array($announcement_targets ?? null) ? $announcement_targets : [];
$announcementGroupTargets = is_array($announcement_group_targets ?? null) ? $announcement_group_targets : $announcementTargets;
$desktopClients = is_array($announcement_desktop_clients ?? null) ? $announcement_desktop_clients : [];
$announcementGroups = is_array($announcement_groups ?? null) ? $announcement_groups : [];
$announcementCooldown = (int)($announcement_cooldown_remaining ?? 0);
$announcementState = is_array($announcement_state ?? null) ? $announcement_state : [];
$announcementTones = is_array($announcement_tones ?? null) ? $announcement_tones : [];
$quietHoursActive = !empty($announcementState['quiet_hours_active']);
$setupComplete = !empty($setup_complete);
$setupModal = (string)($setup_modal ?? '');
$csrfToken = (string)($csrf_token ?? '');
?>
<style>
#dashboard-sls-mass-notify-announcement {
	padding: 4px 8px 12px;
	color: #1f2937;
	box-sizing: border-box;
	max-width: 100%;
	overflow: visible;
}
#dashboard-sls-mass-notify-announcement form,
#dashboard-sls-mass-notify-announcement .sls-step-card,
#dashboard-sls-mass-notify-announcement .sls-color-designer { max-width: 100%; box-sizing: border-box; }
#dashboard-sls-mass-notify-announcement .sls-widget-intro {
	margin: 0 0 14px;
	color: #64748b;
}
#dashboard-sls-mass-notify-announcement .sls-step-card {
	margin: 0 0 14px;
	padding: 15px;
	border: 1px solid #dfe5ec;
	border-radius: 8px;
	background: #fff;
	box-shadow: 0 2px 7px rgba(15, 23, 42, .05);
}
#dashboard-sls-mass-notify-announcement .sls-step-heading {
	display: flex;
	align-items: center;
	gap: 9px;
	margin: 0 0 12px;
	font-size: 16px;
	font-weight: 700;
}
#dashboard-sls-mass-notify-announcement .sls-step-number {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 25px;
	height: 25px;
	border-radius: 50%;
	color: #fff;
	background: #6d28d9;
	font-size: 13px;
}
#dashboard-sls-mass-notify-announcement .sls-target-card {
	height: 100%;
	padding: 12px;
	border: 1px solid #e5e7eb;
	border-radius: 6px;
	background: #f8fafc;
}
#dashboard-sls-mass-notify-announcement .sls-action-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 13px 15px;
	border-radius: 8px;
	background: #f8fafc;
	border: 1px solid #dfe5ec;
}
#dashboard-sls-mass-notify-announcement .sls-target-list {
	max-height: 145px;
	overflow-y: auto;
	overflow-x: hidden;
	padding-right: 6px;
	margin-bottom: 6px;
}
#dashboard-sls-mass-notify-announcement .modal .sls-target-list {
	max-height: 260px;
}
#dashboard-sls-mass-notify-announcement .sls-target-list .checkbox,
#dashboard-sls-mass-notify-announcement .sls-target-list .checkbox-inline {
	display: block;
	min-height: 24px;
	margin-top: 0;
	margin-bottom: 6px;
	overflow-wrap: anywhere;
	word-break: break-word;
}
#dashboard-sls-mass-notify-announcement .sls-target-list .col-sm-4,
#dashboard-sls-mass-notify-announcement .sls-target-list .col-sm-6,
#dashboard-sls-mass-notify-announcement .sls-target-list .col-sm-12 {
	margin-bottom: 4px;
}
#dashboard-sls-mass-notify-announcement .sls-dashboard-target-row .form-group {
	margin-bottom: 10px;
}
#dashboard-sls-mass-notify-announcement textarea {
	resize: vertical;
}
#dashboard-sls-mass-notify-announcement .sls-labs-badge {
	display: inline-block;
	margin-left: 6px;
	vertical-align: middle;
}
#dashboard-sls-mass-notify-announcement .sls-color-designer {
	display: none;
	margin: 10px 0 4px;
	padding: 12px;
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
	#dashboard-sls-mass-notify-announcement .sls-target-card { margin-bottom: 10px; }
	#dashboard-sls-mass-notify-announcement .sls-action-row { display: block; }
	#dashboard-sls-mass-notify-announcement .sls-action-row .btn { width: 100%; margin-bottom: 8px; }
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
	<div id="dashboard-sls-mass-notify-announcement-result" style="display: none;"></div>
	<p class="sls-widget-intro"><?php echo _('Build an announcement from top to bottom: choose recipients, write the message, select delivery options, then send.'); ?></p>
		<form id="dashboard-sls-mass-notify-announcement-form" method="post" action="config.php?display=slsmassnotifyserver">
			<input type="hidden" name="slsmassnotifyserver_action" value="send_announcement">
			<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
		<div class="form-group sls-step-card">
			<div class="sls-step-heading"><span class="sls-step-number">1</span><?php echo _('Choose announcement groups'); ?></div>
			<div class="clearfix">
				<label class="pull-left"><?php echo _('Announcement Groups'); ?></label>
				<button type="button" class="btn btn-xs btn-default pull-right" id="dashboard-announcement-new-group" <?php echo empty($announcementGroupTargets) && empty($desktopClients) ? 'disabled' : ''; ?>><?php echo _('Create New Announcement Group'); ?></button>
			</div>
			<div id="dashboard-announcement-groups" style="margin-top: 6px;"></div>
			<p class="help-block"><?php echo _('Groups can include extensions and desktop app clients. Offline extensions are skipped when an announcement is sent.'); ?></p>
		</div>
		<div class="sls-step-card">
			<div class="sls-step-heading"><span class="sls-step-number">2</span><?php echo _('Choose individual recipients'); ?></div>
		<div class="row sls-dashboard-target-row">
			<div class="col-sm-6">
				<div class="form-group sls-target-card">
					<div class="clearfix">
						<label class="pull-left"><?php echo _('Phone Targets'); ?></label>
					</div>
					<label class="checkbox-inline" style="margin-bottom: 6px;">
						<input type="checkbox" name="announcement_all_phones" value="1">
						<?php echo _('All Phones'); ?>
					</label>
					<?php if (empty($announcementTargets)) { ?>
						<div class="alert alert-warning">
							<?php echo _('No online registered PJSIP extensions are currently available for SIP NOTIFY.'); ?>
						</div>
					<?php } else { ?>
						<div id="dashboard-extension-list" class="row sls-target-list">
						<?php foreach ($announcementTargets as $target) { ?>
							<div class="col-sm-12 dashboard-extension-row">
								<div class="checkbox">
									<label>
										<input type="checkbox" name="announcement_extensions[]" value="<?php echo htmlspecialchars($target['extension']); ?>">
										<?php echo htmlspecialchars($target['extension']); ?>
										<?php if ($target['name'] !== '') { ?>
											<?php echo ' - ' . htmlspecialchars($target['name']); ?>
										<?php } ?>
										<span class="text-muted">
											<?php echo !empty($target['registered']) ? _('registered') : _('not registered'); ?>
										</span>
									</label>
								</div>
							</div>
						<?php } ?>
						</div>
					<?php } ?>
				</div>
			</div>
			<div class="col-sm-6">
				<div class="form-group sls-target-card">
					<div class="clearfix">
						<label class="pull-left"><?php echo _('Desktop App Targets'); ?></label>
					</div>
					<label class="checkbox-inline" style="display:block; margin-left: 0;">
						<input type="checkbox" name="announcement_all_desktops" value="1">
						<?php echo _('All Desktops'); ?>
					</label>
					<?php if (empty($desktopClients)) { ?>
						<p class="help-block"><?php echo _('No desktop app clients are configured yet. Add clients under Mass Notifications, General Settings.'); ?></p>
					<?php } else { ?>
						<div class="row sls-target-list">
							<?php foreach ($desktopClients as $client) { ?>
								<?php if (empty($client['enabled'])) { continue; } ?>
								<div class="col-sm-12 dashboard-desktop-row">
									<label class="checkbox-inline">
											<input type="checkbox" name="announcement_desktop_clients[]" value="<?php echo htmlspecialchars($client['username'] ?? ''); ?>">
											<?php echo htmlspecialchars($client['name'] ?? 'Desktop App'); ?>
											<span class="text-muted"><?php echo htmlspecialchars($client['client_id'] ?? $client['username'] ?? ''); ?></span>
								</label>
							</div>
							<?php } ?>
						</div>
					<?php } ?>
				</div>
			</div>
		</div>
		</div>
		<div class="form-group sls-step-card">
			<div class="sls-step-heading"><span class="sls-step-number">3</span><?php echo _('Write the announcement'); ?></div>
			<label for="dashboard_announcement_body"><?php echo _('Message'); ?></label>
			<textarea class="form-control" id="dashboard_announcement_body" name="announcement_body" rows="3" maxlength="500" placeholder="<?php echo htmlspecialchars(_('Announcement text')); ?>"></textarea>
			<p class="help-block"><?php echo _('Phones display the title "Announcement" and this message body.'); ?></p>
		</div>
		<div class="form-group sls-step-card">
			<div class="sls-step-heading"><span class="sls-step-number">4</span><?php echo _('Choose delivery options'); ?></div>
			<div class="row">
				<div class="col-sm-5">
					<label for="dashboard_announcement_audio_mode"><?php echo _('Audio Mode'); ?></label>
					<select class="form-control" id="dashboard_announcement_audio_mode" name="announcement_audio_mode">
						<option value="none"><?php echo _('None (visual/text only)'); ?></option>
						<option value="tones"><?php echo _('Tones only (do not read text)'); ?></option>
						<option value="tts"><?php echo _('TTS only'); ?></option>
						<option value="tones_tts" selected><?php echo _('Tones and TTS'); ?></option>
					</select>
				</div>
				<div class="col-sm-7">
					<label class="checkbox-inline">
						<input type="checkbox" id="dashboard_announcement_colored" name="announcement_colored" value="1">
						<?php echo _('Colored Announcement'); ?>
						<span class="label label-success sls-labs-badge"><i class="fa fa-flask" aria-hidden="true"></i> <?php echo _('Labs'); ?></span>
					</label>
					<div class="help-block" style="margin: 3px 0 0 20px;"><small><em><?php echo _('*Yealink phones only*'); ?></em></small></div>
				</div>
			</div>
			<div class="row" id="dashboard_announcement_tone_options">
				<div class="col-sm-6">
					<label for="dashboard_announcement_opening_tone"><?php echo _('Opening Sound'); ?></label>
					<select class="form-control" id="dashboard_announcement_opening_tone" name="announcement_opening_tone">
						<option value=""><?php echo _('None'); ?></option>
						<?php foreach ($announcementTones as $tone) { ?><option value="<?php echo htmlspecialchars($tone); ?>" <?php echo ($announcementState['opening_tone'] ?? '') === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $tone)); ?></option><?php } ?>
					</select>
				</div>
				<div class="col-sm-6">
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
			<p class="help-block"><?php echo _('Choose sounds for each announcement, use tones without TTS, use TTS without tones, or send only the visual/text notification.'); ?></p>
		</div>
		<div class="sls-action-row"><button type="submit" id="dashboard-sls-mass-notify-announcement-submit" class="btn btn-warning" <?php echo $announcementCooldown > 0 ? 'disabled' : ''; ?>>
			<?php echo _('Send Announcement'); ?>
		</button>
		<span id="dashboard-sls-mass-notify-announcement-cooldown" class="text-muted" data-remaining="<?php echo $announcementCooldown; ?>" style="margin-left: 10px;">
			<?php echo $announcementCooldown > 0 ? sprintf(_('Cooldown: %ss'), $announcementCooldown) : ''; ?>
		</span>
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
	root.setAttribute('data-ready', '1');
	var form = document.getElementById('dashboard-sls-mass-notify-announcement-form');
	var submit = document.getElementById('dashboard-sls-mass-notify-announcement-submit');
	var cooldown = document.getElementById('dashboard-sls-mass-notify-announcement-cooldown');
	var result = document.getElementById('dashboard-sls-mass-notify-announcement-result');
	var groupList = document.getElementById('dashboard-announcement-groups');
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
	var dashboardItem = root.closest ? root.closest('.item') : null;
	if (dashboardItem) {
		dashboardItem.style.maxWidth = '100%';
		dashboardItem.style.boxSizing = 'border-box';
	}
	var layoutTimer = null;
	function scheduleDashboardLayout() {
		if (layoutTimer) {
			window.clearTimeout(layoutTimer);
		}
		layoutTimer = window.setTimeout(function() {
			layoutTimer = null;
			var page = $(root).closest('.page');
			if (page.length && typeof page.packery === 'function') {
				page.packery('layout');
			}
		}, 40);
	}
	if (!form || !submit || !cooldown || !result) {
		return;
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
	if (window.ResizeObserver) {
		new ResizeObserver(scheduleDashboardLayout).observe(root);
	}
	window.addEventListener('resize', scheduleDashboardLayout);
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
				result.style.display = 'block';
				result.className = 'alert alert-' + (data && data.success ? 'success' : 'warning');
				result.textContent = data && data.message ? data.message : 'Group request finished.';
				if (data && Array.isArray(data.groups)) {
					groups = data.groups;
					renderGroups();
				}
			})
				.catch(function(error) {
					result.style.display = 'block';
					result.className = 'alert alert-danger';
					result.textContent = 'Group request failed: ' + (error && error.message ? error.message : 'unknown error');
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
				fetch(form.action, {method: 'POST', credentials: 'same-origin', body: new FormData(groupForm)})
					.then(parseJsonResponse)
				.then(function(data) {
					result.style.display = 'block';
					result.className = 'alert alert-' + (data && data.success ? 'success' : 'warning');
					result.textContent = data && data.message ? data.message : 'Group request finished.';
					if (data && Array.isArray(data.groups)) {
						groups = data.groups;
						renderGroups();
					}
					if (data && data.success) {
						groupModal.modal('hide');
					}
				})
					.catch(function(error) {
						result.style.display = 'block';
						result.className = 'alert alert-danger';
						result.textContent = 'Group request failed: ' + (error && error.message ? error.message : 'unknown error');
					});
		});
	}
	var remaining = parseInt(cooldown.getAttribute('data-remaining') || '0', 10) || 0;
	function renderCooldown() {
		if (remaining > 0) {
			submit.disabled = true;
			cooldown.textContent = 'Cooldown: ' + remaining + 's';
			return;
		}
		submit.disabled = false;
		cooldown.textContent = '';
	}
	setInterval(function() {
		if (remaining > 0) {
			remaining -= 1;
			renderCooldown();
		}
	}, 1000);
	setInterval(function() {
			fetch('config.php?display=slsmassnotifyserver&slsmassnotifyserver_action=cooldowns', {credentials: 'same-origin'})
				.then(parseJsonResponse)
			.then(function(data) {
				if (data && data.cooldowns && data.cooldowns.announcement) {
					remaining = parseInt(data.cooldowns.announcement.remaining || '0', 10) || 0;
					renderCooldown();
				}
			})
			.catch(function() {});
		}, 10000);
	form.addEventListener('submit', function(event) {
		event.preventDefault();
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
		submit.disabled = true;
		var body = new FormData(form);
			fetch(form.action, {method: 'POST', credentials: 'same-origin', body: body})
				.then(parseJsonResponse)
			.then(function(data) {
				result.style.display = 'block';
				result.className = 'alert alert-' + (data && data.success ? 'success' : 'warning');
				result.textContent = data && data.message ? data.message : 'Announcement request finished.';
				remaining = parseInt((data && data.cooldown_remaining) || '0', 10) || 0;
				renderCooldown();
			})
				.catch(function(error) {
					result.style.display = 'block';
					result.className = 'alert alert-danger';
					result.textContent = 'Announcement request failed: ' + (error && error.message ? error.message : 'unknown error');
					renderCooldown();
				});
	});
	renderGroups();
	renderColorDesigner();
	renderAudioOptions();
	renderCooldown();
}());
</script>
