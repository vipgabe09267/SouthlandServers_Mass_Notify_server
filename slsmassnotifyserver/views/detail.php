<?php if ($event === null) { ?>
	<div class="alert alert-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?php echo _('That event could not be found.'); ?></div>
	<p><a class="btn btn-default" href="config.php?display=slsmassnotifyserver"><i class="fa fa-arrow-left" aria-hidden="true"></i> <?php echo _('Back to Notification Logs'); ?></a></p>
<?php return; } ?>
<?php
$typeMeta = [
	'nws' => ['icon' => 'fa-cloud', 'class' => 'info'],
	'xweather' => ['icon' => 'fa-bolt', 'class' => 'warning'],
	'test' => ['icon' => 'fa-flask', 'class' => 'primary'],
	'announcement' => ['icon' => 'fa-bullhorn', 'class' => 'primary'],
	'announcement_audio' => ['icon' => 'fa-volume-up', 'class' => 'success'],
];
$meta = $typeMeta[$event['type'] ?? ''] ?? ['icon' => 'fa-circle-o', 'class' => 'default'];
?>
<style>
.sls-detail-page { color:#1f2937; }
.sls-detail-top { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:18px; }
.sls-detail-top h1 { margin:0 0 8px; font-size:26px; }
.sls-detail-badges { display:flex; flex-wrap:wrap; gap:7px; }
.sls-detail-badge { display:inline-flex; align-items:center; gap:6px; padding:5px 9px; border-radius:999px; background:#e2e8f0; color:#475569; font-size:11px; font-weight:700; }
.sls-detail-badge.info { background:#e0f2fe;color:#075985; } .sls-detail-badge.warning { background:#fef3c7;color:#92400e; } .sls-detail-badge.primary { background:#e0e7ff;color:#3730a3; } .sls-detail-badge.success { background:#dcfce7;color:#166534; }
.sls-detail-grid { display:flex; flex-wrap:wrap; margin:0 -7px; }
.sls-detail-col { width:50%; padding:0 7px; }
.sls-detail-card { margin-bottom:14px; border:1px solid #dfe5ec; border-radius:9px; background:#fff; box-shadow:0 2px 8px rgba(15,23,42,.04); overflow:hidden; }
.sls-detail-card-title { padding:12px 15px; border-bottom:1px solid #e8edf2; background:#f8fafc; color:#334155; font-weight:700; }
.sls-detail-card-body { padding:14px 15px; }
.sls-detail-list { margin:0; }
.sls-detail-row { display:grid; grid-template-columns:145px minmax(0,1fr); gap:12px; padding:8px 0; border-bottom:1px solid #edf1f5; }
.sls-detail-row:last-child { border-bottom:0; }
.sls-detail-row dt { color:#64748b; font-size:12px; font-weight:600; } .sls-detail-row dd { margin:0; overflow-wrap:anywhere; }
.sls-detail-message { padding:16px; border-left:4px solid #6366f1; border-radius:7px; background:#f8fafc; white-space:pre-wrap; line-height:1.55; }
.sls-detail-email { margin:0; max-height:360px; padding:15px; border:0; border-radius:0; background:#0f172a; color:#e2e8f0; white-space:pre-wrap; overflow:auto; }
@media (max-width:900px) { .sls-detail-col { width:100%; } .sls-detail-top { display:block; } .sls-detail-top .btn { margin-top:12px; } .sls-detail-row { grid-template-columns:110px minmax(0,1fr); } }
</style>
<div class="container-fluid sls-detail-page"><div class="display full-border"><div class="fpbx-container">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
	<div class="sls-detail-top">
		<div><h1><i class="fa <?php echo $meta['icon']; ?> text-primary" aria-hidden="true"></i> <?php echo htmlspecialchars($event['event'] !== '' ? $event['event'] : _('Notification Detail')); ?></h1><div class="sls-detail-badges"><span class="sls-detail-badge <?php echo $meta['class']; ?>"><i class="fa <?php echo $meta['icon']; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($event['type_label']); ?></span><?php if ($event['severity'] !== '') { ?><span class="sls-detail-badge"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> <?php echo htmlspecialchars($event['severity']); ?></span><?php } ?><?php if ($event['status'] !== '') { ?><span class="sls-detail-badge"><i class="fa fa-check-circle" aria-hidden="true"></i> <?php echo htmlspecialchars($event['status']); ?></span><?php } ?></div></div>
		<a class="btn btn-default" href="config.php?display=slsmassnotifyserver"><i class="fa fa-arrow-left" aria-hidden="true"></i> <?php echo _('Back to Logs'); ?></a>
	</div>

	<div class="sls-detail-grid">
		<div class="sls-detail-col"><div class="sls-detail-card"><div class="sls-detail-card-title"><i class="fa fa-info-circle text-primary" aria-hidden="true"></i> <?php echo _('Event Summary'); ?></div><div class="sls-detail-card-body"><dl class="sls-detail-list">
			<div class="sls-detail-row"><dt><?php echo _('Time'); ?></dt><dd><?php echo htmlspecialchars($event['display_time']); ?></dd></div>
			<div class="sls-detail-row"><dt><?php echo _('Message Type'); ?></dt><dd><?php echo htmlspecialchars($event['message_type'] ?: _('Not recorded')); ?></dd></div>
			<div class="sls-detail-row"><dt><?php echo _('Zone'); ?></dt><dd><?php echo htmlspecialchars($event['zone'] ?: _('Not applicable')); ?></dd></div>
			<div class="sls-detail-row"><dt><?php echo _('Alert ID'); ?></dt><dd><code><?php echo htmlspecialchars($event['alert_id'] ?: $event['event_id']); ?></code></dd></div>
		</dl></div></div></div>
		<div class="sls-detail-col"><div class="sls-detail-card"><div class="sls-detail-card-title"><i class="fa fa-paper-plane text-success" aria-hidden="true"></i> <?php echo _('Trigger and Delivery'); ?></div><div class="sls-detail-card-body"><dl class="sls-detail-list">
			<div class="sls-detail-row"><dt><?php echo _('Triggered By'); ?></dt><dd><?php echo htmlspecialchars($event['triggered_by']); ?></dd></div>
			<div class="sls-detail-row"><dt><?php echo _('Source'); ?></dt><dd><?php echo htmlspecialchars($event['source_name'] ?: $event['trigger_source']); ?></dd></div>
			<div class="sls-detail-row"><dt><?php echo _('Phone Recipients'); ?></dt><dd><?php echo htmlspecialchars($event['page_group'] ?: _('None recorded')); ?></dd></div>
			<div class="sls-detail-row"><dt><?php echo _('Audio'); ?></dt><dd><?php echo htmlspecialchars($event['audio'] ?: _('No audio')); ?></dd></div>
			<?php if (!empty($event['desktop_all']) || !empty($event['desktop_clients'])) { ?><div class="sls-detail-row"><dt><?php echo _('Desktop Targets'); ?></dt><dd><?php echo !empty($event['desktop_all']) ? _('All desktops') : htmlspecialchars(implode(', ', $event['desktop_clients'])); ?></dd></div><?php } ?>
			<?php if (!empty($event['notify_delay_seconds'])) { ?><div class="sls-detail-row"><dt><?php echo _('Notify Delay'); ?></dt><dd><?php echo (int)$event['notify_delay_seconds']; ?>s</dd></div><?php } ?>
		</dl></div></div></div>
	</div>

	<?php if (!empty($event['body'])) { ?><div class="sls-detail-card"><div class="sls-detail-card-title"><i class="fa fa-comment text-primary" aria-hidden="true"></i> <?php echo _('Notification Message'); ?></div><div class="sls-detail-card-body"><div class="sls-detail-message"><?php echo htmlspecialchars($event['body']); ?></div></div></div><?php } ?>
	<?php if (!empty($event['audio_sequence'])) { ?><div class="sls-detail-card"><div class="sls-detail-card-title"><i class="fa fa-volume-up text-success" aria-hidden="true"></i> <?php echo _('Audio Sequence'); ?></div><div class="sls-detail-card-body"><code><?php echo htmlspecialchars(implode(', ', $event['audio_sequence'])); ?></code></div></div><?php } ?>
	<?php if (!empty($event['mail_subject']) || !empty($event['mail_body'])) { ?><div class="sls-detail-card"><div class="sls-detail-card-title"><i class="fa fa-envelope text-warning" aria-hidden="true"></i> <?php echo htmlspecialchars($event['mail_subject'] ?: _('Email Content')); ?></div><?php if (!empty($event['mail_body'])) { ?><pre class="sls-detail-email"><?php echo htmlspecialchars($event['mail_body']); ?></pre><?php } ?></div><?php } ?>
</div></div></div>
