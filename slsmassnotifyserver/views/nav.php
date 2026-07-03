<?php $active = $active ?? 'alerts'; ?>
<div class="btn-group" style="margin-bottom: 15px;">
	<a class="btn btn-default <?php echo $active === 'alerts' ? 'active' : ''; ?>" href="config.php?display=slsmassnotifyserver">
		<?php echo _('Alerts'); ?>
	</a>
	<a class="btn btn-default <?php echo $active === 'settings' ? 'active' : ''; ?>" href="config.php?display=slsmassnotifyserver&amp;view=settings">
		<?php echo _('Settings'); ?>
	</a>
</div>
