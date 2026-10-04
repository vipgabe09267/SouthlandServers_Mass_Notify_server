<?php
$outboundVoice = is_array($settings['outbound_voice'] ?? null) ? $settings['outbound_voice'] : [];
$voiceRecipients = is_array($outboundVoice['recipients'] ?? null) ? $outboundVoice['recipients'] : [];
$voiceTrunks = is_array($outbound_voice_trunks ?? null) ? $outbound_voice_trunks : [];
$voiceDids = is_array($outbound_voice_dids ?? null) ? $outbound_voice_dids : [];
$voiceRouteMode = (string)($outboundVoice['route_mode'] ?? 'pbx_routes');
$voiceTrunkId = (string)($outboundVoice['trunk_id'] ?? '');
$voiceCallerId = (string)($outboundVoice['caller_id'] ?? '');
$voiceEscape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$voiceEligibleTrunks = array_values(array_filter($voiceTrunks, static function ($trunk) {
	return is_array($trunk) && !empty($trunk['enabled']) && strtolower((string)($trunk['tech'] ?? '')) === 'pjsip';
}));
$voiceKnownTrunks = array_map(static function ($trunk) { return (string)$trunk['id']; }, $voiceEligibleTrunks);
?>
<style>
.sls-voice-route-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin:16px 0}
.sls-voice-route-grid>.form-group{width:100%;max-width:none;flex:none;padding:0;min-width:0;margin:0}
.sls-voice-route-grid label{display:block;line-height:1.5;margin-bottom:8px}
.sls-voice-route-grid select.form-control{display:block;width:100%;height:42px;padding:8px 12px;text-overflow:ellipsis}
.sls-voice-route-grid .help-block{font-size:13px;margin-top:8px;line-height:1.5}
@media(max-width:900px){.sls-voice-route-grid{grid-template-columns:1fr}}
</style>
<section id="sls-outbound-voice" aria-labelledby="sls-outbound-voice-heading">
	<h3 class="sls-settings-heading" id="sls-outbound-voice-heading"><i class="fa fa-phone-square text-primary" aria-hidden="true"></i> <?php echo _('External Voice Calls'); ?> <?php include __DIR__ . '/labs.php'; ?></h3>
<?php if (!empty($slsRecipientEditorOnly)) { ?><p class="text-muted"><?php echo _('Save a name and international phone number for each external call recipient. Configure routing and audio in Delivery providers.'); ?></p><?php } else { ?>
	<p class="text-muted"><?php echo _('Call saved recipients through a PJSIP trunk. Each call plays the announcement after that recipient answers and uses the shared simultaneous phone capacity.'); ?></p>
	<p class="help-block"><?php echo _('Test the selected route before use. Unsupported custom hooks, route PINs, callbacks and unbounded SIP forwarding are rejected before a call is placed.'); ?></p>
<?php } ?>
	<input type="hidden" name="outbound_voice_present" value="1">
	<input type="hidden" name="outbound_voice_complete" id="sls-outbound-voice-complete" value="0">
	<input type="hidden" name="outbound_voice_recipients_json" id="sls-outbound-voice-json" value="">
<div data-sls-provider-fields <?php echo !empty($slsRecipientEditorOnly) ? 'hidden' : ''; ?>>
	<div class="checkbox"><label><input type="checkbox" name="outbound_voice_enabled" value="1" <?php echo !empty($outboundVoice['enabled']) ? 'checked' : ''; ?>> <?php echo _('Enable external voice calls'); ?></label></div>
	<div class="sls-voice-route-grid">
		<div class="form-group"><label for="sls-outbound-voice-route"><?php echo _('Outbound routing'); ?></label><select class="form-control" name="outbound_voice_route_mode" id="sls-outbound-voice-route">
			<option value="pbx_routes" <?php echo $voiceRouteMode === 'pbx_routes' ? 'selected' : ''; ?>><?php echo _('Use existing FreePBX outbound routes'); ?></option>
			<option value="trunk" <?php echo $voiceRouteMode === 'trunk' ? 'selected' : ''; ?>><?php echo _('Use a specific PJSIP trunk'); ?></option>
		</select><p class="help-block"><?php echo _('The default follows the existing outbound route order and dial patterns. Selecting a trunk uses that trunk directly.'); ?></p></div>
		<div class="form-group" id="sls-outbound-voice-trunk-wrap"><label for="sls-outbound-voice-trunk"><?php echo _('PJSIP trunk'); ?></label><select class="form-control" name="outbound_voice_trunk_id" id="sls-outbound-voice-trunk">
			<option value=""><?php echo _('Select a trunk'); ?></option>
			<?php foreach ($voiceEligibleTrunks as $trunk) { ?><option value="<?php echo $voiceEscape($trunk['id']); ?>" <?php echo (string)$trunk['id'] === $voiceTrunkId ? 'selected' : ''; ?>><?php echo $voiceEscape($trunk['name'] ?? $trunk['id']); ?></option><?php } ?>
			<?php if ($voiceTrunkId !== '' && !in_array($voiceTrunkId, $voiceKnownTrunks, true)) { ?><option value="<?php echo $voiceEscape($voiceTrunkId); ?>" selected><?php echo $voiceEscape(sprintf(_('Unavailable trunk (%s) — select an enabled PJSIP trunk'), $voiceTrunkId)); ?></option><?php } ?>
		</select></div>
		<div class="form-group"><label for="sls-outbound-voice-caller-id"><?php echo _('Outbound caller ID'); ?></label><select class="form-control" name="outbound_voice_caller_id" id="sls-outbound-voice-caller-id">
			<option value=""><?php echo _('Use PBX / trunk caller ID'); ?></option>
			<?php foreach ($voiceDids as $did) { ?><option value="<?php echo $voiceEscape($did); ?>" <?php echo (string)$did === $voiceCallerId ? 'selected' : ''; ?>><?php echo $voiceEscape($did); ?></option><?php } ?>
			<?php if ($voiceCallerId !== '' && !in_array($voiceCallerId, $voiceDids, true)) { ?><option value="<?php echo $voiceEscape($voiceCallerId); ?>" selected><?php echo $voiceEscape(sprintf(_('Unavailable DID (%s) — review this selection'), $voiceCallerId)); ?></option><?php } ?>
		</select><p class="help-block"><?php echo _('Choose a DID configured in FreePBX, or keep the PBX default. A forced trunk caller ID or provider policy may override this value.'); ?></p></div>
	</div>
	<div class="row">
		<div class="col-md-6 form-group">
			<label for="sls-outbound-voice-model"><i class="fa fa-volume-up" aria-hidden="true"></i> <?php echo _('External speech voice'); ?></label>
			<select class="form-control" id="sls-outbound-voice-model" name="outbound_voice_piper_voice">
				<option value=""><?php echo _('Use the announcement voice'); ?></option>
				<?php foreach (($available_voices ?? []) as $model) { ?>
				<option value="<?php echo $voiceEscape($model['path']); ?>" <?php echo ($outboundVoice['piper_voice'] ?? '') === $model['path'] ? 'selected' : ''; ?>><?php echo $voiceEscape($model['name']); ?></option>
				<?php } ?>
			</select>
			<p class="help-block"><?php echo _('Choose a separate voice for external calls without changing internal announcements. Lessac medium improves synthesis detail; the carrier still determines call bandwidth. Queued announcements retain their original voice selections.'); ?></p>
		</div>
	</div>
	<p class="help-block"><?php echo _('Use international numbers beginning with + and the country code, for example +15551234567. Save and Apply Config before selecting recipients on the Dashboard or in schedules.'); ?></p>
	<div class="panel panel-default">
		<div class="panel-heading"><strong><i class="fa fa-check-square-o" aria-hidden="true"></i> <?php echo _('Acknowledgement and call allowance'); ?></strong></div>
		<div class="panel-body">
			<input type="hidden" name="outbound_voice_policy_present" value="1">
			<div class="checkbox"><label><input type="checkbox" name="outbound_voice_acknowledgement_required" value="1" <?php echo !empty($outboundVoice['acknowledgement_required']) ? 'checked' : ''; ?>> <?php echo _('Ask external recipients to press 1 after the announcement'); ?></label></div>
			<p class="help-block"><?php echo _('Each answered call plays its complete message first, then says “Please press one to acknowledge this announcement.” The result is recorded for that call. Timeout does not trigger another call. A keypad response does not identify who answered or prove they understood the message.'); ?></p>
			<div class="row">
				<div class="col-sm-4 form-group"><label for="sls-external-ack-timeout"><?php echo _('Acknowledgement wait'); ?></label><div class="input-group"><input id="sls-external-ack-timeout" class="form-control" type="number" name="outbound_voice_ack_timeout_seconds" min="5" max="30" step="1" required value="<?php echo (int)($outboundVoice['ack_timeout_seconds'] ?? 10); ?>"><span class="input-group-addon"><?php echo _('sec'); ?></span></div></div>
				<div class="col-sm-4 form-group"><label for="sls-external-call-limit"><?php echo _('Daily external call limit'); ?></label><input id="sls-external-call-limit" class="form-control" type="number" name="outbound_voice_daily_call_limit" min="0" max="10000" step="1" required aria-describedby="sls-external-call-limit-help" value="<?php echo (int)($outboundVoice['daily_call_limit'] ?? 0); ?>"></div>
			</div>
			<p id="sls-external-call-limit-help" class="help-block"><?php echo _('Zero disables the optional daily limit. Each admitted external recipient consumes one call, including failed or uncertain attempts; reservations are not refunded. The counter resets at 00:00 UTC and survives service restarts. It counts SLS calls from this upgrade onward, not ordinary PBX calls or earlier carrier usage. A limit rejection leaves internal announcements eligible. Carrier charges and call rates remain separate.'); ?></p>
		</div>
	</div>
</div>
<?php if (!empty($slsRecipientDirectoryManaged)) { ?><p class="help-block"><i class="fa fa-address-book-o" aria-hidden="true"></i> <?php echo _('Manage saved recipients in'); ?> <a href="config.php?display=slsmassnotifyserver_locations#recipients"><?php echo _('Locations and Audiences → Recipients'); ?></a>.</p><?php } ?>
<div data-sls-recipient-fields <?php echo !empty($slsRecipientDirectoryManaged) ? 'hidden' : ''; ?>>
	<div class="table-responsive" style="max-height:340px;overflow:auto"><table class="table table-condensed" id="sls-outbound-voice-table">
		<thead><tr><th><?php echo _('Enabled'); ?></th><th><?php echo _('Recipient name'); ?></th><th><?php echo _('Phone number'); ?></th><th></th></tr></thead>
		<tbody><?php foreach ($voiceRecipients as $recipient) { if (!is_array($recipient)) continue; ?><tr data-voice-recipient>
			<td><input type="hidden" data-voice-field="id" value="<?php echo $voiceEscape($recipient['id'] ?? ''); ?>"><input type="checkbox" data-voice-field="enabled" value="1" aria-label="<?php echo _('Enable recipient'); ?>" <?php echo !empty($recipient['enabled']) ? 'checked' : ''; ?>></td>
			<td><input class="form-control input-sm" data-voice-field="name" maxlength="80" aria-label="<?php echo _('Recipient name'); ?>" value="<?php echo $voiceEscape($recipient['name'] ?? ''); ?>" required></td>
			<td><input class="form-control input-sm" data-voice-field="number" type="tel" pattern="\+[1-9][0-9]{1,14}" maxlength="16" aria-label="<?php echo _('Phone number'); ?>" value="<?php echo $voiceEscape($recipient['number'] ?? ''); ?>" placeholder="+15551234567" required></td>
			<td><button type="button" class="btn btn-default btn-sm" data-remove-voice-recipient><?php echo _('Remove'); ?></button></td>
		</tr><?php } ?></tbody>
	</table></div>
	<button type="button" class="btn btn-default btn-sm" id="sls-add-voice-recipient"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add external recipient'); ?></button>
	<p id="sls-outbound-voice-error" class="text-danger" role="status" aria-live="polite"></p>
	<noscript><p class="text-danger"><?php echo _('Enable JavaScript to edit and save external voice recipients.'); ?></p></noscript>
</div>
</section>
<script>
(function () {
	'use strict';
	var table = document.querySelector('#sls-outbound-voice-table tbody');
	var add = document.getElementById('sls-add-voice-recipient');
	var field = document.getElementById('sls-outbound-voice-json');
	var complete = document.getElementById('sls-outbound-voice-complete');
	var error = document.getElementById('sls-outbound-voice-error');
	var mode = document.getElementById('sls-outbound-voice-route');
	var trunkWrap = document.getElementById('sls-outbound-voice-trunk-wrap');
	var trunk = document.getElementById('sls-outbound-voice-trunk');
	if (!table || !add || !field || !field.form || !complete) return;
	function routeVisibility() {
		var direct = mode.value === 'trunk';
		trunkWrap.hidden = !direct;
		trunk.required = direct;
	}
	mode.addEventListener('change', routeVisibility);
	routeVisibility();
	table.addEventListener('click', function (event) {
		var button = event.target.closest('[data-remove-voice-recipient]');
		if (button) { button.closest('[data-voice-recipient]').remove(); error.textContent = ''; }
	});
	add.addEventListener('click', function () {
		if (table.querySelectorAll('[data-voice-recipient]').length >= 1000) {
			error.textContent = 'At most 1000 external recipients may be saved. Remove a recipient before adding another.';
			return;
		}
		error.textContent = '';
		var row = document.createElement('tr');
		row.setAttribute('data-voice-recipient', '1');
		row.innerHTML = '<td><input type="hidden" data-voice-field="id" value=""><input type="checkbox" data-voice-field="enabled" value="1" aria-label="Enable recipient" checked></td>' +
			'<td><input class="form-control input-sm" data-voice-field="name" maxlength="80" aria-label="Recipient name" required></td>' +
			'<td><input class="form-control input-sm" data-voice-field="number" type="tel" pattern="\\+[1-9][0-9]{1,14}" maxlength="16" aria-label="Phone number" placeholder="+15551234567" required></td>' +
			'<td><button type="button" class="btn btn-default btn-sm" data-remove-voice-recipient>Remove</button></td>';
		table.appendChild(row);
		row.querySelector('[data-voice-field="name"]').focus();
	});
	field.form.addEventListener('submit', function () {
		complete.value = '0';
		var recipients = [];
		table.querySelectorAll('[data-voice-recipient]').forEach(function (row) {
			var recipient = {enabled: '0'};
			row.querySelectorAll('[data-voice-field]').forEach(function (input) {
				if (input.type !== 'checkbox' || input.checked) recipient[input.getAttribute('data-voice-field')] = input.value;
			});
			recipients.push(recipient);
		});
		field.value = JSON.stringify(recipients);
		complete.value = '1';
	}, true);
}());
</script>
