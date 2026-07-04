<?php
// Southland Servers Mass Notification Plugin
$saveResult = $save_result ?? null;
$applyResult = $apply_result ?? null;
$tokenResult = $token_result ?? null;
$hasPendingChanges = !empty($has_pending_changes);
$sipnotify = is_array($settings['sipnotify'] ?? null) ? $settings['sipnotify'] : [];
$endpoints = is_array($sipnotify['endpoints'] ?? null) ? $sipnotify['endpoints'] : [];
$brandOptions = is_array($brands ?? null) ? $brands : [];
$pbxHost = $sipnotify['pbx_host'] ?? 'localhost';
$baseUrl = $sipnotify['base_url'] ?? ('https://' . $pbxHost . '/api/sipnotify');
$apiToken = $api_token ?? '';
$availableTones = is_array($available_tones ?? null) ? $available_tones : [];
?>
<div class="container-fluid">
	<div class="display full-border">
		<div class="fpbx-container">
			<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
			<div style="display: flex; justify-content: space-between; gap: 15px; align-items: flex-start;">
				<div>
					<h1><?php echo _('SipNotify Settings'); ?></h1>
					<p class="text-muted"><?php echo _('Manage token-protected Mass Notify API endpoint aliases for supported SIP phone families.'); ?></p>
				</div>
				<?php if ($hasPendingChanges) { ?>
					<form method="post" action="config.php?display=slsmassnotifyserver_sipnotify">
						<input type="hidden" name="slsmassnotifyserver_action" value="apply_settings">
						<button type="submit" class="btn btn-danger"><?php echo _('Apply Changes'); ?></button>
					</form>
				<?php } ?>
			</div>

			<?php if (is_array($saveResult)) { ?>
				<div class="alert alert-<?php echo !empty($saveResult['success']) ? 'success' : 'warning'; ?>">
					<?php echo htmlspecialchars($saveResult['message']); ?>
					<?php if (!empty($saveResult['errors'])) { ?>
						<ul style="margin-top: 10px;">
							<?php foreach ($saveResult['errors'] as $error) { ?>
								<li><?php echo htmlspecialchars($error); ?></li>
							<?php } ?>
						</ul>
					<?php } ?>
				</div>
			<?php } ?>

			<?php if (is_array($applyResult)) { ?>
				<div class="alert alert-<?php echo !empty($applyResult['success']) ? 'success' : 'warning'; ?>">
					<?php echo htmlspecialchars($applyResult['message']); ?>
					<?php if (!empty($applyResult['errors'])) { ?>
						<ul style="margin-top: 10px;">
							<?php foreach ($applyResult['errors'] as $error) { ?>
								<li><?php echo htmlspecialchars($error); ?></li>
							<?php } ?>
						</ul>
					<?php } ?>
				</div>
			<?php } ?>

			<?php if (is_array($tokenResult)) { ?>
				<div class="alert alert-<?php echo !empty($tokenResult['success']) ? 'success' : 'warning'; ?>">
					<?php echo htmlspecialchars($tokenResult['message']); ?>
					<?php if (!empty($tokenResult['errors'])) { ?>
						<ul style="margin-top: 10px;">
							<?php foreach ($tokenResult['errors'] as $error) { ?>
								<li><?php echo htmlspecialchars($error); ?></li>
							<?php } ?>
						</ul>
					<?php } ?>
				</div>
			<?php } ?>

			<?php if ($hasPendingChanges) { ?>
				<div class="alert alert-info"><?php echo _('Saved SIP NOTIFY settings are waiting to be applied.'); ?></div>
			<?php } ?>

			<form method="post" action="config.php?display=slsmassnotifyserver_sipnotify" id="sipnotify-settings-form" enctype="multipart/form-data">
				<input type="hidden" name="slsmassnotifyserver_action" value="save_sipnotify_settings">

				<input type="hidden" name="sipnotify[pbx_host]" value="<?php echo htmlspecialchars($pbxHost); ?>">
				<div class="row">
					<div class="col-md-8">
						<div class="well">
							<strong><?php echo _('Base Endpoint'); ?></strong>
							<div style="margin-top: 8px;"><code id="sipnotify-base-url"><?php echo htmlspecialchars($baseUrl); ?></code></div>
							<p class="help-block" style="margin-bottom: 0;"><?php echo _('Generated from the FreePBX host. The desktop app uses /desktop with Bearer token authentication. Phone endpoints use their brand path with username/password authentication.'); ?></p>
						</div>
					</div>
				</div>

				<div class="well">
					<div class="row">
						<div class="col-md-8">
							<label for="sipnotify-api-token"><?php echo _('SLS Mass Notify App Bearer Token'); ?></label>
							<div class="input-group">
								<input class="form-control" id="sipnotify-api-token" type="text" readonly value="<?php echo htmlspecialchars($apiToken); ?>">
								<span class="input-group-btn">
									<button type="button" class="btn btn-default" id="sipnotify-copy-token"><?php echo _('Copy'); ?></button>
								</span>
							</div>
							<p class="help-block"><?php echo _('This token is only for the SLS Mass Notify desktop app endpoint. Phone endpoints use the per-brand username and password below.'); ?></p>
						</div>
						<div class="col-md-4" style="padding-top: 25px;">
							<button type="submit" class="btn btn-warning" form="sipnotify-token-form"><?php echo _('Regenerate Token'); ?></button>
						</div>
					</div>
				</div>

				<h3><?php echo _('Audio Configuration'); ?></h3>
				<p class="help-block">
					<?php echo _('These tones are shared by live NWS alerts, manual tests, and dashboard announcement TTS audio. Piper TTS speech defaults to 30 seconds and can be capped at up to 600 seconds.'); ?>
				</p>
				<div class="row">
					<div class="col-md-5">
						<div class="form-group">
							<label for="opening_tone"><?php echo _('Opening Tone'); ?></label>
							<select class="form-control" id="opening_tone" name="opening_tone">
								<?php foreach ($availableTones as $toneName) { ?>
									<option value="<?php echo htmlspecialchars($toneName); ?>" <?php echo ($settings['opening_tone'] ?? 'opening_Paging_Tone_Opening') === $toneName ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars($toneName); ?>
									</option>
								<?php } ?>
							</select>
							<p class="help-block"><?php echo _('Upload WAV tones only. Uploads are converted to 8 kHz mono for Asterisk playback.'); ?></p>
							<input class="form-control" id="opening_tone_upload" name="opening_tone_upload" type="file" accept=".wav,audio/wav,audio/x-wav">
						</div>
					</div>
					<div class="col-md-5">
						<div class="form-group">
							<label for="closing_tone"><?php echo _('Closing Tone'); ?></label>
							<select class="form-control" id="closing_tone" name="closing_tone">
								<?php foreach ($availableTones as $toneName) { ?>
									<option value="<?php echo htmlspecialchars($toneName); ?>" <?php echo ($settings['closing_tone'] ?? 'closing_Paging_Tone_Closing') === $toneName ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars($toneName); ?>
									</option>
								<?php } ?>
							</select>
							<p class="help-block"><?php echo _('Keep tones short so announcement audio remains concise.'); ?></p>
							<input class="form-control" id="closing_tone_upload" name="closing_tone_upload" type="file" accept=".wav,audio/wav,audio/x-wav">
						</div>
					</div>
				</div>
				<div class="well">
					<strong><?php echo _('Piper TTS'); ?></strong>
					<div style="margin-top: 8px;"><code><?php echo htmlspecialchars($settings['piper_voice'] ?? '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/en_US-lessac-low.onnx'); ?></code></div>
					<div class="text-muted"><?php echo sprintf(_('Maximum spoken audio: %s seconds'), (int)($settings['tts_max_seconds'] ?? 30)); ?></div>
				</div>

				<div class="table-responsive">
					<table class="table table-striped table-bordered" id="sipnotify-endpoints-table">
						<thead>
							<tr>
								<th><?php echo _('Enabled'); ?></th>
								<th><?php echo _('Brand'); ?></th>
								<th><?php echo _('Slug'); ?></th>
								<th><?php echo _('Auth'); ?></th>
								<th><?php echo _('Format'); ?></th>
								<th><?php echo _('Username'); ?></th>
								<th><?php echo _('Password'); ?></th>
								<th><?php echo _('Endpoint URL'); ?></th>
								<th><?php echo _('Action'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($endpoints as $index => $endpoint) {
								$slug = $endpoint['slug'] ?? '';
								$brand = $endpoint['brand'] ?? '';
								$locked = !empty($endpoint['locked']);
								$url = $baseUrl . '/' . $slug;
							?>
								<tr data-endpoint-row>
									<td>
										<input type="hidden" name="sipnotify[endpoints][<?php echo (int)$index; ?>][slug]" value="<?php echo htmlspecialchars($slug); ?>">
										<input type="hidden" name="sipnotify[endpoints][<?php echo (int)$index; ?>][brand]" value="<?php echo htmlspecialchars($brand); ?>">
										<input type="hidden" name="sipnotify[endpoints][<?php echo (int)$index; ?>][auth_type]" value="<?php echo htmlspecialchars($endpoint['auth_type'] ?? ($slug === 'desktop' ? 'token' : 'basic')); ?>">
										<input type="checkbox" name="sipnotify[endpoints][<?php echo (int)$index; ?>][enabled]" value="1" <?php echo !empty($endpoint['enabled']) ? 'checked' : ''; ?>>
									</td>
									<td><?php echo htmlspecialchars($brand); ?><?php echo $locked ? ' ' . htmlspecialchars(_('Default')) : ''; ?></td>
									<td><code><?php echo htmlspecialchars($slug); ?></code></td>
									<td><?php echo ($endpoint['auth_type'] ?? '') === 'token' ? _('Bearer Token') : _('Username / Password'); ?></td>
									<td><code><?php echo htmlspecialchars($endpoint['payload_format'] ?? 'generic_xml'); ?></code></td>
									<td><input class="form-control input-sm" name="sipnotify[endpoints][<?php echo (int)$index; ?>][username]" type="text" value="<?php echo htmlspecialchars($endpoint['username'] ?? ''); ?>" <?php echo ($endpoint['auth_type'] ?? '') === 'token' ? 'readonly' : ''; ?>></td>
									<td><input class="form-control input-sm" name="sipnotify[endpoints][<?php echo (int)$index; ?>][password]" type="text" value="<?php echo htmlspecialchars($endpoint['password'] ?? ''); ?>" <?php echo ($endpoint['auth_type'] ?? '') === 'token' ? 'readonly' : ''; ?>></td>
									<td><code data-url-slug="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($url); ?></code></td>
									<td>
										<?php if ($locked) { ?>
											<span class="text-muted"><?php echo _('Cannot delete'); ?></span>
										<?php } else { ?>
											<button type="button" class="btn btn-default btn-sm" data-remove-endpoint><?php echo _('Delete'); ?></button>
										<?php } ?>
									</td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>

				<div class="row" style="margin-top: 15px;">
					<div class="col-md-4">
						<div class="form-group">
							<label for="sipnotify-add-brand"><?php echo _('Add Endpoint'); ?></label>
							<select class="form-control" id="sipnotify-add-brand">
								<?php foreach ($brandOptions as $slug => $label) { ?>
									<option value="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($label); ?></option>
								<?php } ?>
							</select>
						</div>
					</div>
					<div class="col-md-3" style="padding-top: 25px;">
						<button type="button" class="btn btn-default" id="sipnotify-add-endpoint"><?php echo _('Add Endpoint'); ?></button>
					</div>
				</div>

				<button type="submit" class="btn btn-primary"><?php echo _('Save Configuration'); ?></button>
			</form>
			<form method="post" action="config.php?display=slsmassnotifyserver_sipnotify" id="sipnotify-token-form" onsubmit="return confirm('<?php echo htmlspecialchars(_('Regenerate the SLS Mass Notify App token? Existing desktop app clients must be updated immediately.'), ENT_QUOTES); ?>');">
				<input type="hidden" name="slsmassnotifyserver_action" value="regenerate_api_token">
			</form>

			<script>
			(function() {
				var form = document.getElementById('sipnotify-settings-form');
				var baseUrlNode = document.getElementById('sipnotify-base-url');
				var tableBody = document.querySelector('#sipnotify-endpoints-table tbody');
				var addButton = document.getElementById('sipnotify-add-endpoint');
				var brandSelect = document.getElementById('sipnotify-add-brand');
				var copyToken = document.getElementById('sipnotify-copy-token');
				var tokenInput = document.getElementById('sipnotify-api-token');
				if (!form || !tableBody || !addButton || !brandSelect) {
					return;
				}
				function baseUrl() {
					return baseUrlNode.textContent || 'https://localhost/api/sipnotify';
				}
				function syncUrls() {
					var base = baseUrl();
					baseUrlNode.textContent = base;
					document.querySelectorAll('[data-url-slug]').forEach(function(node) {
						var slug = node.getAttribute('data-url-slug');
						node.textContent = base + '/' + slug;
					});
				}
				function nextIndex() {
					return tableBody.querySelectorAll('[data-endpoint-row]').length;
				}
				function rowExists(slug) {
					return !!tableBody.querySelector('[data-url-slug="' + slug + '"]');
				}
				tableBody.addEventListener('click', function(event) {
					if (!event.target.matches('[data-remove-endpoint]')) {
						return;
					}
					event.target.closest('tr').remove();
					syncUrls();
				});
				addButton.addEventListener('click', function() {
					var slug = brandSelect.value;
					var label = brandSelect.options[brandSelect.selectedIndex].text;
					if (rowExists(slug) || tableBody.querySelectorAll('[data-endpoint-row]').length >= 10) {
						return;
					}
					var index = nextIndex();
					var row = document.createElement('tr');
					row.setAttribute('data-endpoint-row', '1');
					row.innerHTML =
						'<td><input type="hidden" name="sipnotify[endpoints][' + index + '][slug]" value="' + slug + '">' +
						'<input type="hidden" name="sipnotify[endpoints][' + index + '][brand]" value="' + label.replace(/"/g, '&quot;') + '">' +
						'<input type="hidden" name="sipnotify[endpoints][' + index + '][auth_type]" value="basic">' +
						'<input type="checkbox" name="sipnotify[endpoints][' + index + '][enabled]" value="1" checked></td>' +
						'<td></td><td><code></code></td>' +
						'<td>Username / Password</td><td><code>generic_xml</code></td>' +
						'<td><input class="form-control input-sm" name="sipnotify[endpoints][' + index + '][username]" type="text" value="sipnotify_' + slug + '"></td>' +
						'<td><input class="form-control input-sm" name="sipnotify[endpoints][' + index + '][password]" type="text" value=""></td>' +
						'<td><code data-url-slug="' + slug + '"></code></td>' +
						'<td><button type="button" class="btn btn-default btn-sm" data-remove-endpoint>Delete</button></td>';
					row.children[1].textContent = label;
					row.children[2].firstChild.textContent = slug;
					tableBody.appendChild(row);
					syncUrls();
				});
				if (copyToken && tokenInput) {
					copyToken.addEventListener('click', function() {
						tokenInput.focus();
						tokenInput.select();
						document.execCommand('copy');
					});
				}
				syncUrls();
			})();
			</script>
		</div>
	</div>
</div>
