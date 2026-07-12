<?php
$announcementTargets = is_array($announcement_targets ?? null) ? $announcement_targets : [];
$announcementGroupTargets = is_array($announcement_group_targets ?? null) ? $announcement_group_targets : $announcementTargets;
$desktopClients = is_array($announcement_desktop_clients ?? null) ? $announcement_desktop_clients : [];
$announcementGroups = is_array($announcement_groups ?? null) ? $announcement_groups : [];
$announcementCooldown = (int)($announcement_cooldown_remaining ?? 0);
$announcementState = is_array($announcement_state ?? null) ? $announcement_state : [];
$quietHoursActive = !empty($announcementState['quiet_hours_active']);
$setupComplete = !empty($setup_complete);
$setupModal = (string)($setup_modal ?? '');
$csrfToken = (string)($csrf_token ?? '');
?>
<style>
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
		<form id="dashboard-sls-mass-notify-announcement-form" method="post" action="config.php?display=slsmassnotifyserver">
			<input type="hidden" name="slsmassnotifyserver_action" value="send_announcement">
			<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
		<div class="form-group">
			<div class="clearfix">
				<label class="pull-left"><?php echo _('Announcement Groups'); ?></label>
				<button type="button" class="btn btn-xs btn-default pull-right" id="dashboard-announcement-new-group" <?php echo empty($announcementGroupTargets) && empty($desktopClients) ? 'disabled' : ''; ?>><?php echo _('Create New Announcement Group'); ?></button>
			</div>
			<div id="dashboard-announcement-groups" style="margin-top: 6px;"></div>
			<p class="help-block"><?php echo _('Groups can include extensions and desktop app clients. Offline extensions are skipped when an announcement is sent.'); ?></p>
		</div>
		<div class="row sls-dashboard-target-row">
			<div class="col-sm-6">
				<div class="form-group">
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
				<div class="form-group">
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
		<div class="form-group">
			<label for="dashboard_announcement_body"><?php echo _('Message'); ?></label>
			<textarea class="form-control" id="dashboard_announcement_body" name="announcement_body" rows="3" maxlength="500" placeholder="<?php echo htmlspecialchars(_('Announcement text')); ?>"></textarea>
			<p class="help-block"><?php echo _('Phones display the title "Announcement" and this message body.'); ?></p>
		</div>
		<div class="form-group">
			<label><?php echo _('Delivery Options'); ?></label>
			<div class="row">
				<div class="col-sm-4">
					<label class="checkbox-inline">
						<input type="checkbox" name="announcement_tts_audio" value="1" checked>
						<?php echo _('TTS Audio'); ?>
					</label>
				</div>
			</div>
			<p class="help-block"><?php echo _('TTS Audio pages selected phone extensions with the configured opening tone, Piper voice, and closing tone. Desktop app delivery is controlled by the desktop target selections above.'); ?></p>
		</div>
		<button type="submit" id="dashboard-sls-mass-notify-announcement-submit" class="btn btn-warning" <?php echo $announcementCooldown > 0 ? 'disabled' : ''; ?>>
			<?php echo _('Send Announcement'); ?>
		</button>
		<span id="dashboard-sls-mass-notify-announcement-cooldown" class="text-muted" data-remaining="<?php echo $announcementCooldown; ?>" style="margin-left: 10px;">
			<?php echo $announcementCooldown > 0 ? sprintf(_('Cooldown: %ss'), $announcementCooldown) : ''; ?>
		</span>
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
	if (!form || !submit || !cooldown || !result) {
		return;
	}
	var groups = Array.isArray(initialGroups) ? initialGroups : [];
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
	renderCooldown();
}());
</script>
