<?php if ($event === null) { ?>
	<div class="alert alert-warning">
		<?php echo _('That event could not be found.'); ?>
	</div>
	<p><a class="btn btn-default" href="config.php?display=slsmassnotifyserver"><?php echo _('Back'); ?></a></p>
<?php return; } ?>

<div class="container-fluid">
	<div class="display full-border">
		<div class="fpbx-container">
			<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
			<h1><?php echo _('Notification Detail'); ?></h1>
			<p>
				<a class="btn btn-default" href="config.php?display=slsmassnotifyserver"><?php echo _('Back to Log'); ?></a>
			</p>

			<table class="table table-bordered">
				<tbody>
					<tr><th><?php echo _('Time'); ?></th><td><?php echo htmlspecialchars($event['display_time']); ?></td></tr>
					<tr><th><?php echo _('Type'); ?></th><td><?php echo htmlspecialchars($event['type_label']); ?></td></tr>
					<tr><th><?php echo _('Status'); ?></th><td><?php echo htmlspecialchars($event['status']); ?></td></tr>
					<tr><th><?php echo _('Event'); ?></th><td><?php echo htmlspecialchars($event['event']); ?></td></tr>
					<tr><th><?php echo _('Severity'); ?></th><td><?php echo htmlspecialchars($event['severity']); ?></td></tr>
					<tr><th><?php echo _('Message Type'); ?></th><td><?php echo htmlspecialchars($event['message_type']); ?></td></tr>
					<tr><th><?php echo _('Trigger Source'); ?></th><td><?php echo htmlspecialchars($event['trigger_source']); ?></td></tr>
					<tr><th><?php echo _('Trigger Extension'); ?></th><td><?php echo htmlspecialchars($event['trigger_extension']); ?></td></tr>
					<tr><th><?php echo _('Trigger Name'); ?></th><td><?php echo htmlspecialchars($event['trigger_name']); ?></td></tr>
					<tr><th><?php echo _('Source Name'); ?></th><td><?php echo htmlspecialchars($event['source_name']); ?></td></tr>
					<tr><th><?php echo _('NWS Recipients'); ?></th><td><?php echo htmlspecialchars($event['page_group']); ?></td></tr>
					<tr><th><?php echo _('Audio'); ?></th><td><?php echo htmlspecialchars($event['audio']); ?></td></tr>
					<tr><th><?php echo _('Audio Sequence'); ?></th><td><?php echo htmlspecialchars(implode(', ', $event['audio_sequence'])); ?></td></tr>
					<?php if (!empty($event['body'])) { ?>
						<tr><th><?php echo _('Announcement Body'); ?></th><td><?php echo nl2br(htmlspecialchars($event['body'])); ?></td></tr>
					<?php } ?>
					<?php if (!empty($event['announcement_style'])) { ?>
						<tr><th><?php echo _('Announcement Style'); ?></th><td><?php echo htmlspecialchars($event['announcement_style']); ?></td></tr>
					<?php } ?>
					<?php if (!empty($event['desktop_all']) || !empty($event['desktop_clients'])) { ?>
						<tr><th><?php echo _('Desktop Targets'); ?></th><td><?php echo !empty($event['desktop_all']) ? _('All desktops') : htmlspecialchars(implode(', ', $event['desktop_clients'])); ?></td></tr>
					<?php } ?>
					<?php if (!empty($event['notify_delay_seconds'])) { ?>
						<tr><th><?php echo _('Notify Delay'); ?></th><td><?php echo (int)$event['notify_delay_seconds']; ?>s</td></tr>
					<?php } ?>
					<tr><th><?php echo _('Alert ID'); ?></th><td><code><?php echo htmlspecialchars($event['alert_id']); ?></code></td></tr>
					<tr><th><?php echo _('Zone'); ?></th><td><?php echo htmlspecialchars($event['zone']); ?></td></tr>
					<tr><th><?php echo _('Email Subject'); ?></th><td><?php echo htmlspecialchars($event['mail_subject']); ?></td></tr>
				</tbody>
			</table>

			<h3><?php echo _('Email Body'); ?></h3>
			<pre style="white-space: pre-wrap;"><?php echo htmlspecialchars($event['mail_body']); ?></pre>
		</div>
	</div>
</div>
