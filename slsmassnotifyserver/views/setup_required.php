<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group
$setupModal = (string)($setup_modal ?? '');
$page = trim((string)($requested_page ?? ''));
$titleMap = [
	'main' => _('Notification Logs'),
	'settings' => _('NWS Alerts'),
	'testing' => _('NWS Alerts'),
	'nws_alerts' => _('NWS Alerts'),
	'other_settings' => _('General Settings'),
	'help' => _('Help'),
];
$title = $titleMap[$page] ?? _('Mass Notifications');
?>
<div class="container-fluid">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image ?? '']); ?>
	<div class="panel panel-warning">
		<div class="panel-heading">
			<strong><?php echo htmlspecialchars($title); ?></strong>
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
