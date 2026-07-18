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
$hasPackageUpdate = (($packageStatus['state'] ?? '') === 'update');
$updateProgress = is_array($update_progress ?? null) ? $update_progress : ['state' => 'idle', 'message' => ''];
$updateMonitorActive = !empty($update_monitor_active) || in_array(($updateProgress['state'] ?? 'idle'), ['queued', 'checking', 'installing'], true);
$packageStatusClass = (($packageStatus['state'] ?? '') === 'update') ? 'label-warning' : 'label-success';
$formatOverrides = [];
$formatLabels = [
	'yealink' => _('Yealink - Color'), 'yealink_text' => _('Yealink - Text Only'),
	'cisco' => _('Cisco'), 'poly' => _('Poly / Polycom'), 'grandstream' => _('Grandstream'),
	'fanvil' => _('Fanvil'), 'snom' => _('Snom'), 'aastra' => _('Aastra / Mitel'),
	'sangoma' => _('Sangoma'), 'avaya' => _('Avaya'), 'vtech' => _('VTech'),
	'ale' => _('Alcatel-Lucent Enterprise'), 'panasonic' => _('Panasonic KX Series'),
];
$notificationEmails = preg_split('/[\s,;]+/', trim((string)($settings['mail_to'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$csrfToken = (string)($csrf_token ?? '');
foreach ((array)($settings['sipnotify']['format_overrides'] ?? []) as $extension => $format) {
	$extension = preg_replace('/[^0-9]/', '', (string)$extension);
	$format = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$format));
	if ($extension !== '' && $format !== '') {
		$formatOverrides[] = ['extension' => $extension, 'format' => $format];
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
.sls-general-title { margin:0 0 7px; font-size:30px; line-height:1.2; font-weight:700; }
.sls-compact-table {
	max-height: 330px;
	overflow: auto;
}
.sls-desktop-client-scroll {
	max-height: 300px;
	overflow: auto;
	border: 1px solid #d7dce2;
	border-radius: 6px;
	background: #fff;
}
.sls-desktop-client-scroll table { margin-bottom: 0; min-width: 860px; }
.sls-desktop-client-scroll thead th {
	position: sticky;
	top: 0;
	z-index: 2;
	background: #f3f6f9;
	box-shadow: inset 0 -1px 0 #d7dce2;
}
.sls-manager-card { border:1px solid #dfe5ec; border-radius:8px; background:#f8fafc; padding:14px 16px; min-height:92px; }
.sls-manager-card h4 { margin:0 0 6px; font-size:16px; }
.sls-manager-summary { color:#64748b; margin-bottom:10px; }
.sls-manager-modal .modal-dialog { width:min(900px, calc(100% - 30px)); }
.sls-manager-modal .modal-body { max-height:68vh; overflow:auto; background:#f8fafc; }
.sls-editor-row { display:flex; gap:10px; align-items:center; padding:10px; margin-bottom:8px; background:#fff; border:1px solid #e5e7eb; border-radius:6px; }
.sls-editor-row .sls-editor-grow { flex:1 1 auto; }
.sls-editor-row .sls-editor-format { flex:0 1 330px; }
#sls-format-editor-list .sls-editor-row { display:grid; grid-template-columns:minmax(150px,1fr) minmax(220px,330px) auto; align-items:end; }
#sls-format-editor-list .sls-editor-row [data-remove-format] { margin:0 0 1px !important; padding-left:10px; padding-right:10px; white-space:nowrap; }
.sls-summary-table { margin-bottom:8px; background:#fff; }
.sls-save-actions { margin:26px 0 34px; padding:16px; border:1px solid #dfe5ec; border-radius:8px; background:#f8fafc; }
.sls-update-controls { display:flex; align-items:center; flex-wrap:wrap; gap:8px; }
.sls-update-controls .alert { display:inline-flex; align-items:center; gap:6px; margin:0; padding:7px 10px; }
.sls-config-backup { margin:0 0 14px; padding-top:24px; border-top:1px solid #d7dce2; }
.sls-danger-panel { margin-top:24px; border-width:2px; }
.sls-danger-panel .panel-heading { padding:13px 16px; font-size:16px; font-weight:700; }
.sls-danger-panel .panel-body { padding:18px; }
.sls-danger-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; }
.sls-danger-action { display:flex; flex-direction:column; min-width:0; padding:16px; border:1px solid #f4c7c3; border-radius:8px; background:#fffafa; }
.sls-danger-action h4 { margin:0 0 9px; font-size:16px; }
.sls-danger-action p { margin:0 0 15px; line-height:1.5; }
.sls-danger-action form { margin-top:auto; }
.sls-danger-action .form-group { margin-bottom:12px; }
.sls-danger-action--critical { border-color:#f0a8a1; background:#fff5f5; }
@media (max-width:991px) { .sls-danger-grid { grid-template-columns:1fr; } }
@media(max-width:767px){.sls-editor-row,#sls-format-editor-list .sls-editor-row{display:block}.sls-editor-row>*{margin-bottom:8px}.sls-manager-modal .modal-dialog{width:auto}#sls-format-editor-list .sls-editor-row [data-remove-format]{margin-top:4px !important}}
</style>
<div class="container-fluid">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image ?? '']); ?>
	<div class="row">
		<div class="col-sm-12">
			<h1 class="sls-general-title"><i class="fa fa-cogs text-primary" aria-hidden="true"></i> <?php echo _('General Settings'); ?></h1>
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

				<h3 class="sls-settings-heading"><i class="fa fa-phone text-primary" aria-hidden="true"></i> <?php echo _('Phone Delivery'); ?></h3>
				<div class="row">
					<div class="col-md-6">
						<div class="form-group">
							<label><?php echo _('Public PBX Hostname'); ?></label>
							<div class="input-group"><span class="input-group-addon"><i class="fa fa-lock" aria-hidden="true"></i></span><input class="form-control" value="<?php echo htmlspecialchars($settings['public_pbx_host'] ?? ($settings['sipnotify']['pbx_host'] ?? '')); ?>" readonly aria-readonly="true"></div>
							<p class="help-block"><?php echo _('Automatically detected by the PBX and used for API links and hosted phone media.'); ?></p>
						</div>
					</div>
					<div class="col-md-3">
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
						<div class="sls-manager-card">
							<h4><i class="fa fa-phone"></i> <?php echo _('Phone Format Overrides'); ?></h4>
							<div class="sls-manager-summary"><?php echo empty($formatOverrides) ? _('Automatic vendor detection is used for every extension.') : sprintf(_('%d extension override(s) configured.'), count($formatOverrides)); ?></div>
							<button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#sls-format-manager"><i class="fa fa-pencil"></i> <?php echo _('Manage Overrides'); ?></button>
						</div>
					</div>
				</div>

				<div class="modal fade sls-manager-modal" id="sls-format-manager" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
					<div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><?php echo _('Manage Phone Format Overrides'); ?></h4></div>
					<div class="modal-body"><input type="hidden" name="sipnotify_format_overrides_present" value="1"><p class="text-muted"><?php echo _('Enter the extension and select its phone family. Leave extensions without an override on automatic detection. Use Yealink text fallback only when a model cannot display generated image alerts.'); ?></p><div id="sls-format-editor-list">
					<?php foreach ($formatOverrides as $index => $override) { ?><div class="sls-editor-row" data-format-row><div class="sls-editor-grow"><label><?php echo _('Extension'); ?></label><input class="form-control" inputmode="numeric" pattern="[0-9]+" name="sipnotify_format_overrides[<?php echo (int)$index; ?>][extension]" value="<?php echo htmlspecialchars($override['extension']); ?>"></div><div class="sls-editor-format"><label><?php echo _('Phone family'); ?></label><select class="form-control" name="sipnotify_format_overrides[<?php echo (int)$index; ?>][format]"><?php foreach ($formatLabels as $formatValue => $formatLabel) { ?><option value="<?php echo htmlspecialchars($formatValue); ?>" <?php echo $override['format'] === $formatValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($formatLabel); ?></option><?php } ?></select></div><button type="button" class="btn btn-link text-danger" data-remove-format style="margin-top:20px"><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div><?php } ?>
					</div><button type="button" class="btn btn-default" id="sls-add-format"><i class="fa fa-plus"></i> <?php echo _('Add Override'); ?></button></div>
					<div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal"><?php echo _('Done'); ?></button></div>
				</div></div></div>
				<script type="text/template" id="sls-format-row-template"><div class="sls-editor-row" data-format-row><div class="sls-editor-grow"><label><?php echo _('Extension'); ?></label><input class="form-control" inputmode="numeric" pattern="[0-9]+"></div><div class="sls-editor-format"><label><?php echo _('Phone family'); ?></label><select class="form-control"><?php foreach ($formatLabels as $formatValue => $formatLabel) { ?><option value="<?php echo htmlspecialchars($formatValue); ?>"><?php echo htmlspecialchars($formatLabel); ?></option><?php } ?></select></div><button type="button" class="btn btn-link text-danger" data-remove-format style="margin-top:20px"><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div></script>

				<h3 class="sls-settings-heading"><i class="fa fa-envelope text-warning" aria-hidden="true"></i> <?php echo _('Notification Destinations'); ?></h3>
				<div class="sls-manager-card">
					<div class="row"><div class="col-md-8"><h4><i class="fa fa-envelope"></i> <?php echo _('Shared Email and Discord Delivery'); ?></h4><div class="sls-manager-summary"><?php echo sprintf(_('%d notification email(s); Discord webhook %s.'), count($notificationEmails), !empty($settings['discord_webhook_url']) ? _('configured') : _('not configured')); ?> <?php echo _('These destinations receive both Weather and Lightning alerts.'); ?></div></div><div class="col-md-4 text-right"><button type="button" class="btn btn-default" data-toggle="modal" data-target="#sls-notification-manager"><i class="fa fa-pencil"></i> <?php echo _('Manage Destinations'); ?></button></div></div>
				</div>
				<div class="modal fade sls-manager-modal" id="sls-notification-manager" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
					<div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><?php echo _('Notification Destinations'); ?></h4></div>
					<div class="modal-body"><input type="hidden" name="mail_recipients_present" value="1"><h4><?php echo _('Email Recipients'); ?></h4><p class="text-muted"><?php echo _('Each address receives the branded Southland Servers alert card with its plain-text alternative.'); ?></p><div id="sls-email-editor-list"><?php foreach ($notificationEmails as $emailIndex => $email) { ?><div class="sls-editor-row" data-email-row><div class="sls-editor-grow"><input class="form-control" type="email" name="mail_recipients[]" value="<?php echo htmlspecialchars($email); ?>" placeholder="alerts@example.com"></div><button type="button" class="btn btn-link text-danger" data-remove-email><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div><?php } ?></div><button type="button" class="btn btn-default btn-sm" id="sls-add-email"><i class="fa fa-plus"></i> <?php echo _('Add Email'); ?></button><hr><div class="form-group"><label for="sls-discord-webhook"><?php echo _('Discord Webhook'); ?></label><div class="input-group"><input class="form-control" id="sls-discord-webhook" name="discord_webhook_url" type="password" value="<?php echo htmlspecialchars($settings['discord_webhook_url'] ?? ''); ?>" autocomplete="off" placeholder="https://discord.com/api/webhooks/..."><span class="input-group-btn"><button type="button" class="btn btn-default" data-toggle-secret title="<?php echo htmlspecialchars(_('Show or hide webhook')); ?>"><i class="fa fa-eye"></i></button></span></div><p class="help-block"><?php echo _('Optional. Leave blank to disable Discord delivery.'); ?></p></div><div class="well" style="margin-bottom:0"><strong><?php echo _('Email From'); ?>:</strong> <?php echo htmlspecialchars($settings['mail_from_name'] ?? 'SLS Mass Notification System'); ?> &lt;<?php echo htmlspecialchars($settings['mail_from_addr'] ?? ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost.localdomain'))); ?>&gt;</div></div>
					<div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal"><?php echo _('Done'); ?></button></div>
				</div></div></div>
				<script type="text/template" id="sls-email-row-template"><div class="sls-editor-row" data-email-row><div class="sls-editor-grow"><input class="form-control" type="email" placeholder="alerts@example.com"></div><button type="button" class="btn btn-link text-danger" data-remove-email><i class="fa fa-trash"></i> <?php echo _('Remove'); ?></button></div></script>

				<h3 class="sls-settings-heading"><i class="fa fa-volume-up text-success" aria-hidden="true"></i> <?php echo _('Regular Paging Audio'); ?></h3>
				<div class="alert alert-info"><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo _('These defaults apply only to dashboard and API announcements. Weather Alerts and Lightning Alerts keep their own independent sounds and volume settings.'); ?></div>
				<div class="row">
					<div class="col-md-4">
						<div class="form-group">
							<label><?php echo _('Paging Opening Tone'); ?></label>
							<select class="form-control" name="opening_tone">
								<option value="" <?php echo ($settings['opening_tone'] ?? '') === '' ? 'selected' : ''; ?>><?php echo _('None'); ?></option>
								<optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>">
								<?php foreach ($tones as $tone) { ?>
									<option value="<?php echo htmlspecialchars($tone); ?>" <?php echo ($settings['opening_tone'] ?? 'opening_Paging_Tone_Opening') === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars($tone === 'opening_Paging_Tone_Opening' ? _('Paging Tone Opening (bundled default)') : str_replace('_', ' ', $tone)); ?></option>
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
							<label><?php echo _('Paging Closing Tone'); ?></label>
							<select class="form-control" name="closing_tone">
								<option value="" <?php echo ($settings['closing_tone'] ?? '') === '' ? 'selected' : ''; ?>><?php echo _('None'); ?></option>
								<optgroup label="<?php echo htmlspecialchars(_('Mass Notify tones')); ?>">
								<?php foreach ($tones as $tone) { ?>
									<option value="<?php echo htmlspecialchars($tone); ?>" <?php echo ($settings['closing_tone'] ?? 'closing_Paging_Tone_Closing') === $tone ? 'selected' : ''; ?>><?php echo htmlspecialchars($tone === 'closing_Paging_Tone_Closing' ? _('Paging Tone Closing (bundled default)') : str_replace('_', ' ', $tone)); ?></option>
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
					<div class="col-md-6">
						<div class="form-group">
							<label><?php echo _('Announcement TTS Voice'); ?></label>
							<select class="form-control" name="announcement_piper_voice">
								<?php foreach ($voices as $voice) { ?>
									<option value="<?php echo htmlspecialchars($voice['path']); ?>" <?php echo ($settings['announcement_piper_voice'] ?? '') === $voice['path'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($voice['name'] . (basename($voice['path']) === 'en_US-lessac-low.onnx' ? ' (' . _('default') . ')' : '')); ?></option>
								<?php } ?>
							</select>
							<p class="help-block"><?php echo _('Lessac is the default regular-announcement voice. Weather and Lightning use the separate Weather voice, which defaults to Amy.'); ?></p>
						</div>
					</div>
					<div class="col-md-3">
						<div class="form-group">
							<label><?php echo _('General Paging Volume'); ?></label>
							<input class="form-control" name="announcement_tts_volume" type="number" min="1" max="200" value="<?php echo (int)($settings['announcement_tts_volume'] ?? 25); ?>">
							<p class="help-block"><?php echo _('Default 25%. Applies to the regular paging tones and generated announcement speech.'); ?></p>
						</div>
					</div>
					<div class="col-md-3">
						<div class="form-group">
							<label><?php echo _('Maximum Spoken Length'); ?></label>
							<div class="input-group"><input class="form-control" name="tts_max_seconds" type="number" min="1" max="600" value="<?php echo (int)($settings['tts_max_seconds'] ?? 30); ?>"><span class="input-group-addon"><?php echo _('sec'); ?></span></div>
							<p class="help-block"><?php echo _('Default 30; maximum 600 seconds.'); ?></p>
						</div>
					</div>
				</div>

				<h3 class="sls-settings-heading"><i class="fa fa-desktop text-info" aria-hidden="true"></i> <?php echo _('Desktop Clients'); ?></h3>
					<p class="help-block"><?php echo _('Each desktop app should use its own username and password against /api/sipnotify/desktop. Client IDs are generated automatically and cannot be edited. Passwords are AES-encrypted in the central config file.'); ?></p>
				<div class="table-responsive sls-desktop-client-scroll" id="desktop-client-scroll" aria-label="<?php echo htmlspecialchars(_('Desktop client list')); ?>">
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
					<div class="panel-heading"><strong><i class="fa fa-code text-warning" aria-hidden="true"></i> <?php echo _('Control API'); ?></strong><span class="label label-success sls-labs-badge"><i class="fa fa-flask" aria-hidden="true"></i> <?php echo _('Labs'); ?></span></div>
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

				<h3 class="sls-settings-heading"><i class="fa fa-history text-muted" aria-hidden="true"></i> <?php echo _('Updates and Retention'); ?></h3>
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
						<div class="sls-update-controls">
							<?php if ($hasPackageUpdate) { ?>
								<button type="submit" class="btn btn-warning btn-sm" name="slsmassnotifyserver_action" value="manual_update"><i class="fa fa-refresh" aria-hidden="true"></i> <?php echo _('Update to Latest Release'); ?></button>
							<?php } ?>
							<div id="sls-update-progress" class="alert alert-info" role="status" aria-live="polite" data-active="<?php echo $updateMonitorActive ? '1' : '0'; ?>" data-status-url="config.php?display=slsmassnotifyserver_other&amp;sls_update_status=1" style="<?php echo $updateMonitorActive ? '' : 'display:none;'; ?>">
								<i class="fa fa-spinner fa-spin" aria-hidden="true"></i>
								<span><?php echo htmlspecialchars((string)($updateProgress['message'] ?? _('Preparing update status...'))); ?></span>
							</div>
						</div>
					</div>
				</div>

				<div class="sls-save-actions">
					<button type="submit" class="btn btn-primary btn-lg"><i class="fa fa-save" aria-hidden="true"></i> <?php echo _('Save General Settings'); ?></button>
				</div>
			</form>

			<h3 class="sls-config-backup"><i class="fa fa-download text-primary" aria-hidden="true"></i> <?php echo _('Config Backup'); ?></h3>
			<form method="post" style="margin-bottom: 15px;">
				<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
				<button type="submit" class="btn btn-default" name="slsmassnotifyserver_action" value="export_config"><?php echo _('Download .config'); ?></button>
			</form>
			<div class="panel panel-danger sls-danger-panel">
				<div class="panel-heading"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?php echo _('Danger Zone'); ?></div>
				<div class="panel-body">
					<div class="sls-danger-grid">
						<section class="sls-danger-action">
							<h4><i class="fa fa-wrench text-warning" aria-hidden="true"></i> <?php echo _('Installer Health'); ?></h4>
							<p><?php echo _('Repair Installation refreshes runtime files, permissions, Apache API routes, cron, dialplan, dashboard widget files, and local signatures. It does not replace your central .config, but it may reload FreePBX and Asterisk dialplan.'); ?></p>
							<form method="post" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(_('Are you sure you want to repair/reinstall the Mass Notifications integration now? This may reload FreePBX and Asterisk dialplan. Your central .config will not be replaced.')), ENT_QUOTES, 'UTF-8'); ?>);">
								<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
								<button type="submit" class="btn btn-warning" name="slsmassnotifyserver_action" value="repair_installation"><?php echo _('Repair Installation'); ?></button>
							</form>
						</section>
						<section class="sls-danger-action sls-danger-action--critical">
							<h4><i class="fa fa-trash text-danger" aria-hidden="true"></i> <?php echo _('Completely Uninstall'); ?></h4>
							<p><strong><?php echo _('Warning:'); ?></strong> <?php echo _('This removes the module, runtime services, APIs, logs, desktop clients, credentials, tones, backups, and central configuration. This cannot be undone.'); ?></p>
							<form method="post" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(_('Are you sure you want to completely uninstall this module? All Mass Notifications configuration and data will be permanently deleted.')), ENT_QUOTES, 'UTF-8'); ?>);">
								<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
								<button type="submit" class="btn btn-danger" name="slsmassnotifyserver_action" value="complete_uninstall"><i class="fa fa-trash" aria-hidden="true"></i> <?php echo _('Completely Uninstall'); ?></button>
							</form>
						</section>
						<section class="sls-danger-action sls-danger-action--critical">
							<h4><i class="fa fa-upload text-danger" aria-hidden="true"></i> <?php echo _('Replace Configuration'); ?></h4>
							<p><?php echo _('Replacing the config file wipes the current plugin data and overwrites API keys, desktop clients, voices, announcement groups, NWS settings, and retention settings.'); ?></p>
							<form method="post" enctype="multipart/form-data" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(_('Replace the Mass Notifications config? This requires Apply Config to become live.')), ENT_QUOTES, 'UTF-8'); ?>);">
								<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
								<div class="form-group"><label><?php echo _('Upload .config'); ?></label><input type="file" name="config_upload" accept=".config,application/json"></div>
								<button type="submit" class="btn btn-danger" name="slsmassnotifyserver_action" value="import_config"><?php echo _('Replace Config'); ?></button>
							</form>
						</section>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<script>
(function() {
	var updateProgress = document.getElementById('sls-update-progress');
	if (updateProgress && updateProgress.getAttribute('data-active') === '1') {
		var updatePolls = 0;
		var updateStatusUrl = updateProgress.getAttribute('data-status-url');
		var updateIcon = updateProgress.querySelector('i');
		var updateText = updateProgress.querySelector('span');
		function finishUpdateDisplay(state, message) {
			updateProgress.className = state === 'complete' ? 'alert alert-success' : 'alert alert-danger';
			updateIcon.className = state === 'complete' ? 'fa fa-check-circle' : 'fa fa-times-circle';
			updateText.textContent = message || (state === 'complete' ? 'Update completed.' : 'Update failed.');
			if (state === 'complete') {
				window.setTimeout(function() {
					var cleanUrl = new URL(window.location.href);
					cleanUrl.searchParams.delete('sls_update_queued');
					window.location.replace(cleanUrl.toString());
				}, 1800);
			}
		}
		function pollUpdateStatus() {
			updatePolls += 1;
			fetch(updateStatusUrl, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
				.then(function(response) { if (!response.ok) throw new Error('status request failed'); return response.json(); })
				.then(function(data) {
					var state = String(data.state || 'checking');
					var message = String(data.message || 'Checking for update status...');
					if (state === 'complete' || state === 'failed') {
						finishUpdateDisplay(state, message);
						return;
					}
					updateProgress.style.display = 'block';
					updateProgress.className = 'alert alert-info';
					updateIcon.className = 'fa fa-spinner fa-spin';
					updateText.textContent = message;
					window.setTimeout(pollUpdateStatus, 2000);
				})
				.catch(function() {
					if (updatePolls >= 300) {
						finishUpdateDisplay('failed', 'Update status could not be confirmed. Check Notification Logs and try again.');
						return;
					}
					updateText.textContent = 'Update is running; waiting for the PBX interface to respond...';
					window.setTimeout(pollUpdateStatus, 3000);
				});
		}
		window.setTimeout(pollUpdateStatus, 500);
	}
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
	var formatList = document.getElementById('sls-format-editor-list');
	var addFormat = document.getElementById('sls-add-format');
	var formatTemplate = document.getElementById('sls-format-row-template');
	function reindexFormats() {
		if (!formatList) return;
		Array.prototype.forEach.call(formatList.querySelectorAll('[data-format-row]'), function(row, index) {
			var extension = row.querySelector('input'); var format = row.querySelector('select');
			if (extension) extension.name = 'sipnotify_format_overrides[' + index + '][extension]';
			if (format) format.name = 'sipnotify_format_overrides[' + index + '][format]';
		});
	}
	if (formatList) {
		formatList.addEventListener('click', function(event) { var remove = event.target.closest('[data-remove-format]'); if (remove) { remove.closest('[data-format-row]').remove(); reindexFormats(); } });
	}
	if (addFormat && formatList && formatTemplate) {
		addFormat.addEventListener('click', function() { var shell=document.createElement('div'); shell.innerHTML=formatTemplate.innerHTML.trim(); formatList.appendChild(shell.firstElementChild); reindexFormats(); });
	}
	reindexFormats();
	var emailList = document.getElementById('sls-email-editor-list');
	var addEmail = document.getElementById('sls-add-email');
	var emailTemplate = document.getElementById('sls-email-row-template');
	function nameEmails() { if (!emailList) return; Array.prototype.forEach.call(emailList.querySelectorAll('[data-email-row] input'), function(input) { input.name='mail_recipients[]'; }); }
	if (emailList) {
		emailList.addEventListener('click', function(event) { var remove=event.target.closest('[data-remove-email]'); if(remove){remove.closest('[data-email-row]').remove();nameEmails();} });
	}
	if (addEmail && emailList && emailTemplate) {
		addEmail.addEventListener('click', function(){var shell=document.createElement('div');shell.innerHTML=emailTemplate.innerHTML.trim();emailList.appendChild(shell.firstElementChild);nameEmails();});
	}
	nameEmails();
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
	var desktopScroller = document.getElementById('desktop-client-scroll');
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
			if (desktopScroller) {
				desktopScroller.scrollTop = desktopScroller.scrollHeight;
			}
	});
}());
</script>
