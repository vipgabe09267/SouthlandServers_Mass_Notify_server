<?php
declare(strict_types=1);
// Southland Servers Mass Notifications Server by the Southland Servers Group
$setupModal = is_string($setup_modal ?? null) ? $setup_modal : '';
$page = is_string($requested_page ?? null) ? trim($requested_page) : '';
$heroImage = is_string($hero_image ?? null) ? $hero_image : '';
$titleMap = [
	'main' => _('Notification Logs'),
	'settings' => _('Weather Alerts'),
	'testing' => _('Weather Alerts'),
	'nws_alerts' => _('Weather Alerts'),
	'other_settings' => _('General Settings'),
	'help' => _('Help'),
];
$title = $titleMap[$page] ?? _('Mass Notifications');
?>
<div class="container-fluid">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $heroImage]); ?>
	<div class="panel panel-warning">
		<div class="panel-heading">
			<strong><?php echo htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
		</div>
		<div class="panel-body">
			<div class="alert alert-warning" style="margin-bottom: 0;">
				<strong><?php echo _('Setup Required'); ?></strong><br>
				<?php echo _('Setup wizard must be completed before Mass Notifications settings, testing, logs, APIs, and dashboard controls can be used.'); ?>
				<div style="margin-top: 12px;">
					<button type="button" class="btn btn-primary" id="sls-setup-required-open"><?php echo _('Open Setup Wizard'); ?></button>
				</div>
			</div>
		</div>
	</div>
</div>
<?php echo $setupModal; ?>
<script>
(function() {
	var open = document.getElementById('sls-setup-required-open');
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
