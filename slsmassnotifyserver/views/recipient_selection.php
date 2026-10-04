<?php
// Each including form supplies only its own explicit, server-recognized keys.
$recipientSelectionKeys = array_values((array)($recipient_selection_keys ?? []));
?>
<input type="hidden" name="sls_recipient_selection_present" value="1">
<input type="hidden" name="sls_recipient_selection_json" value="">
<input type="hidden" name="sls_recipient_selection_complete" value="0">
<script>
(function () {
	'use strict';
	var form = document.getElementById(<?php echo json_encode((string)($recipient_selection_form_id ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
	var keys = <?php echo json_encode($recipientSelectionKeys, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	if (!form) return;
	var field = form.querySelector('[name="sls_recipient_selection_json"]');
	var complete = form.querySelector('[name="sls_recipient_selection_complete"]');
	form.addEventListener('submit', function () {
		complete.value = '0';
		var selection = {}, inputs = [];
		keys.forEach(function (key) {
			selection[key] = [];
			form.querySelectorAll('input[type="checkbox"][name="' + key + '[]"]').forEach(function (input) {
				if (input.checked && !input.disabled) selection[key].push(input.value);
				inputs.push({input: input, disabled: input.disabled});
			});
		});
		field.value = JSON.stringify(selection);
		complete.value = '1';
		inputs.forEach(function (state) { state.input.disabled = true; });
		window.setTimeout(function () {
			inputs.forEach(function (state) { state.input.disabled = state.disabled; });
		}, 0);
	}, true);
}());
</script>
