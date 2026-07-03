<div class="container-fluid">
	<div class="display full-border">
		<div class="fpbx-container">
			<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
			<h2><?php echo _('Testing'); ?></h2>
			<p class="text-muted">
				<?php echo _('Trigger a manual Piper TTS alert using the configured opening and closing tones.'); ?>
			</p>

			<div id="sls-test-cooldown-alert" class="alert alert-warning" <?php echo empty($cooldown_remaining) ? 'style="display: none;"' : ''; ?>>
				<span id="sls-test-cooldown-text" data-remaining="<?php echo (int)$cooldown_remaining; ?>">
					<?php echo !empty($cooldown_remaining) ? sprintf(_('Manual testing is on cooldown. Wait %s seconds before triggering another test.'), (int)$cooldown_remaining) : ''; ?>
				</span>
			</div>

			<div id="sls-test-result" style="display: none;"></div>

			<?php if (is_array($test_result)) { ?>
				<div class="alert alert-<?php echo !empty($test_result['success']) ? 'success' : 'warning'; ?>">
					<?php echo htmlspecialchars($test_result['message']); ?>
				</div>
			<?php } ?>

			<form id="sls-test-form" method="post" action="config.php?display=slsmassnotifyserver_testing">
				<input type="hidden" name="slsmassnotifyserver_action" value="trigger_test">
				<input type="hidden" name="ajax" value="1">

				<div class="alert alert-danger">
					<?php echo _('Warning: this test will trigger all configured NWS audio recipients.'); ?>
				</div>

				<button type="submit" id="sls-test-submit" class="btn btn-danger" <?php echo !empty($cooldown_remaining) ? 'disabled' : ''; ?>><?php echo _('Trigger Piper TTS Test'); ?></button>
			</form>

			<script>
			(function() {
				var form = document.getElementById('sls-test-form');
				var submit = document.getElementById('sls-test-submit');
				var cooldownAlert = document.getElementById('sls-test-cooldown-alert');
				var cooldownText = document.getElementById('sls-test-cooldown-text');
				var result = document.getElementById('sls-test-result');
				if (!form || !submit) {
					return;
				}
				var remaining = parseInt(cooldownText.getAttribute('data-remaining') || '0', 10) || 0;
				function renderCooldown() {
					if (remaining > 0) {
						submit.disabled = true;
						cooldownAlert.style.display = 'block';
						cooldownText.textContent = 'Manual testing is on cooldown. Wait ' + remaining + ' seconds before triggering another test.';
						return;
					}
					submit.disabled = false;
					cooldownAlert.style.display = 'none';
					cooldownText.textContent = '';
				}
				setInterval(function() {
					if (remaining > 0) {
						remaining -= 1;
						renderCooldown();
					}
				}, 1000);
				setInterval(function() {
					fetch('config.php?display=slsmassnotifyserver_testing&slsmassnotifyserver_action=cooldowns', {credentials: 'same-origin'})
						.then(function(response) { return response.json(); })
						.then(function(data) {
							if (data && data.cooldowns && data.cooldowns.test) {
								remaining = parseInt(data.cooldowns.test.remaining || '0', 10) || 0;
								renderCooldown();
							}
						})
						.catch(function() {});
				}, 10000);
				form.addEventListener('submit', function(event) {
					event.preventDefault();
					if (remaining > 0 || !confirm('Are you sure you wish to trigger a test? This will trigger all extensions.')) {
						return;
					}
					submit.disabled = true;
					var body = new FormData(form);
					fetch(form.action, {method: 'POST', credentials: 'same-origin', body: body})
						.then(function(response) { return response.json(); })
						.then(function(data) {
							result.style.display = 'block';
							result.className = 'alert alert-' + (data && data.success ? 'success' : 'warning');
							result.textContent = data && data.message ? data.message : 'Test request finished.';
							if (data && data.cooldowns && data.cooldowns.test) {
								remaining = parseInt(data.cooldowns.test.remaining || '0', 10) || 0;
							}
							renderCooldown();
						})
						.catch(function() {
							result.style.display = 'block';
							result.className = 'alert alert-danger';
							result.textContent = 'Test request failed.';
							renderCooldown();
						});
				});
				renderCooldown();
			}());
			</script>
		</div>
	</div>
</div>
