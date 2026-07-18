<?php
$typeOptions = [
	'' => _('All Events'),
	'nws' => _('Weather Alerts'),
	'xweather' => _('Lightning Alerts'),
	'test' => _('Manual Tests'),
	'announcement' => _('Announcements'),
	'announcement_audio' => _('Announcement Audio'),
];
$limitOptions = [50, 100, 200, 500];
$statusCards = is_array($status_summary ?? null) ? $status_summary : [];
$statusMeta = [
	'ok' => ['class' => 'success', 'icon' => 'fa-check-circle'],
	'fault' => ['class' => 'danger', 'icon' => 'fa-exclamation-circle'],
	'notice' => ['class' => 'warning', 'icon' => 'fa-clock-o'],
	'unknown' => ['class' => 'default', 'icon' => 'fa-question-circle'],
];
$eventMeta = [
	'nws' => ['class' => 'info', 'icon' => 'fa-cloud'],
	'xweather' => ['class' => 'warning', 'icon' => 'fa-bolt'],
	'test' => ['class' => 'primary', 'icon' => 'fa-flask'],
	'announcement' => ['class' => 'primary', 'icon' => 'fa-bullhorn'],
	'announcement_audio' => ['class' => 'success', 'icon' => 'fa-volume-up'],
	'' => ['class' => 'default', 'icon' => 'fa-circle-o'],
];
$severityClass = static function ($severity) {
	$value = strtolower(trim((string)$severity));
	if (preg_match('/extreme|emergency|tornado|severe|critical/', $value)) return 'danger';
	if (preg_match('/warning|moderate|test/', $value)) return 'warning';
	if (preg_match('/clear|minor|notice|advisory/', $value)) return 'success';
	return 'default';
};
?>
<style>
.sls-log-page { color:#1f2937; }
.sls-log-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:18px; }
.sls-log-heading h1 { margin:0 0 5px; font-size:27px; }
.sls-log-count { display:inline-flex; align-items:center; gap:7px; padding:8px 12px; border:1px solid #dfe5ec; border-radius:999px; background:#f8fafc; color:#475569; font-weight:600; white-space:nowrap; }
.sls-status-grid { display:flex; flex-wrap:wrap; margin:0 -7px 20px; }
.sls-status-wrap { width:33.333%; padding:0 7px; display:flex; }
.sls-status-card { width:100%; min-height:132px; padding:16px 17px; border:1px solid #e2e8f0; border-left:4px solid #94a3b8; border-radius:9px; background:#fff; box-shadow:0 2px 8px rgba(15,23,42,.05); }
.sls-status-card.success { border-left-color:#16a34a; } .sls-status-card.danger { border-left-color:#dc2626; } .sls-status-card.warning { border-left-color:#d97706; }
.sls-status-title { display:flex; align-items:center; gap:9px; margin-bottom:9px; font-weight:700; color:#334155; }
.sls-status-title i { font-size:18px; } .sls-status-card.success .sls-status-title i { color:#16a34a; } .sls-status-card.danger .sls-status-title i { color:#dc2626; } .sls-status-card.warning .sls-status-title i { color:#d97706; }
.sls-status-message { margin-bottom:8px; line-height:1.45; } .sls-status-meta { color:#64748b; font-size:12px; line-height:1.45; }
.sls-log-toolbar { margin-bottom:16px; padding:13px 15px; border:1px solid #dfe5ec; border-radius:8px; background:#f8fafc; }
.sls-log-toolbar label { color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:.45px; }
.sls-log-filter-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px 16px; }
.sls-log-filter-row .form-group { margin:0; }
.sls-log-filter-row label { display:block; margin:0 0 5px; }
.sls-log-filter-actions { display:flex; align-items:center; gap:8px; }
.sls-log-table-wrap { border:1px solid #dfe5ec; border-radius:9px; overflow:hidden; background:#fff; box-shadow:0 2px 10px rgba(15,23,42,.04); }
.sls-log-table { margin:0; table-layout:fixed; }
.sls-log-table thead th { padding:11px 12px; border-bottom:1px solid #dfe5ec !important; background:#f1f5f9; color:#475569; font-size:11px; text-transform:uppercase; letter-spacing:.55px; }
.sls-log-table tbody td { padding:13px 12px; vertical-align:middle; border-top:1px solid #edf1f5; }
.sls-log-table tbody tr:hover { background:#f8fafc; }
.sls-log-time { color:#475569; font-size:12px; line-height:1.45; white-space:normal; }
.sls-event-title { margin-top:7px; font-weight:700; color:#1f2937; line-height:1.35; }
.sls-event-summary { margin-top:4px; color:#64748b; font-size:12px; line-height:1.4; overflow-wrap:anywhere; }
.sls-log-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 8px; border-radius:999px; font-size:11px; font-weight:700; }
.sls-log-badge.info { color:#075985; background:#e0f2fe; } .sls-log-badge.warning { color:#92400e; background:#fef3c7; } .sls-log-badge.primary { color:#3730a3; background:#e0e7ff; } .sls-log-badge.success { color:#166534; background:#dcfce7; } .sls-log-badge.default { color:#475569; background:#e2e8f0; }
.sls-log-target { color:#475569; font-size:12px; overflow-wrap:anywhere; } .sls-log-audio { margin-bottom:5px; font-weight:600; }
.sls-log-empty { padding:46px 20px; border:1px dashed #cbd5e1; border-radius:9px; background:#f8fafc; text-align:center; color:#64748b; }
.sls-log-empty i { display:block; margin-bottom:12px; color:#94a3b8; font-size:34px; }
@media (max-width:991px) { .sls-status-wrap { width:100%; margin-bottom:10px; } .sls-log-heading { display:block; } .sls-log-count { margin-top:10px; } }
@media (max-width:600px) { .sls-log-filter-row, .sls-log-filter-actions { display:block; } .sls-log-filter-row .form-group, .sls-log-filter-actions .btn { width:100%; margin-bottom:10px; } .sls-log-filter-row .form-control { width:100%; } }
</style>
<div class="container-fluid sls-log-page">
	<div class="display full-border">
		<div class="fpbx-container">
			<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
			<div class="sls-log-heading">
				<div><h1><i class="fa fa-list-alt text-primary" aria-hidden="true"></i> <?php echo _('Notification Logs'); ?></h1><div class="text-muted"><?php echo _('Delivery history and operational health for Weather, Lightning, desktop, phone, and audio notifications.'); ?></div></div>
				<div class="sls-log-count"><i class="fa fa-database" aria-hidden="true"></i> <?php echo sprintf(_('%d event(s) shown'), count((array)$events)); ?></div>
			</div>

			<?php if (!empty($statusCards)) { ?><div class="sls-status-grid">
				<?php foreach ($statusCards as $card) { $state = $card['state'] ?? 'unknown'; $meta = $statusMeta[$state] ?? $statusMeta['unknown']; ?>
					<div class="sls-status-wrap"><div class="sls-status-card <?php echo $meta['class']; ?>">
						<div class="sls-status-title"><i class="fa <?php echo $meta['icon']; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($card['label'] ?? ''); ?></div>
						<div class="sls-status-message"><?php echo htmlspecialchars($card['message'] ?? ''); ?></div>
						<?php if (!empty($card['time'])) { ?><div class="sls-status-meta"><i class="fa fa-clock-o" aria-hidden="true"></i> <?php echo htmlspecialchars($card['time']); ?></div><?php } ?>
						<?php if (!empty($card['details'])) { ?><div class="sls-status-meta"><?php echo htmlspecialchars($card['details']); ?></div><?php } ?>
					</div></div>
				<?php } ?>
			</div><?php } ?>

			<form method="get" action="config.php" class="sls-log-toolbar">
				<input type="hidden" name="display" value="slsmassnotifyserver">
				<div class="sls-log-filter-row">
					<div class="form-group"><label for="log_type"><i class="fa fa-filter" aria-hidden="true"></i> <?php echo _('Event Type'); ?></label><select class="form-control" id="log_type" name="log_type"><?php foreach ($typeOptions as $value => $label) { ?><option value="<?php echo htmlspecialchars($value); ?>" <?php echo $selected_type === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php } ?></select></div>
					<div class="form-group"><label for="log_date"><i class="fa fa-calendar" aria-hidden="true"></i> <?php echo _('Date'); ?></label><input class="form-control" id="log_date" name="log_date" type="date" value="<?php echo htmlspecialchars((string)($selected_date ?? '')); ?>"></div>
					<div class="form-group"><label for="limit"><?php echo _('Show'); ?></label><select class="form-control" id="limit" name="limit"><?php foreach ($limitOptions as $value) { ?><option value="<?php echo (int)$value; ?>" <?php echo (int)$selected_limit === (int)$value ? 'selected' : ''; ?>><?php echo sprintf(_('%d rows'), (int)$value); ?></option><?php } ?></select></div>
					<div class="sls-log-filter-actions"><button type="submit" class="btn btn-primary"><i class="fa fa-refresh" aria-hidden="true"></i> <?php echo _('Refresh View'); ?></button><?php if (!empty($selected_type) || !empty($selected_date)) { ?><a class="btn btn-default" href="config.php?display=slsmassnotifyserver"><i class="fa fa-times" aria-hidden="true"></i> <?php echo _('Clear Filters'); ?></a><?php } ?></div>
				</div>
			</form>

			<?php if (empty($events)) { ?>
				<div class="sls-log-empty"><i class="fa fa-inbox" aria-hidden="true"></i><strong><?php echo _('No matching notification events'); ?></strong><div><?php echo _('New deliveries will appear here automatically after they are recorded.'); ?></div></div>
			<?php } else { ?>
				<div class="table-responsive sls-log-table-wrap"><table class="table sls-log-table">
					<colgroup><col style="width:14%"><col style="width:27%"><col style="width:10%"><col style="width:17%"><col style="width:24%"><col style="width:8%"></colgroup>
					<thead><tr><th><?php echo _('Time'); ?></th><th><?php echo _('Event'); ?></th><th><?php echo _('Severity'); ?></th><th><?php echo _('Triggered By'); ?></th><th><?php echo _('Delivery'); ?></th><th></th></tr></thead>
					<tbody><?php foreach ($events as $event) { $type = (string)($event['type'] ?? ''); $meta = $eventMeta[$type] ?? $eventMeta['']; $body = trim((string)($event['body'] ?? '')); if (function_exists('mb_strlen') && mb_strlen($body) > 140) $body = mb_substr($body, 0, 137) . '…'; elseif (strlen($body) > 140) $body = substr($body, 0, 137) . '…'; ?>
						<tr>
							<td><div class="sls-log-time"><i class="fa fa-clock-o" aria-hidden="true"></i> <?php echo htmlspecialchars($event['display_time']); ?></div></td>
							<td><span class="sls-log-badge <?php echo $meta['class']; ?>"><i class="fa <?php echo $meta['icon']; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($event['type_label']); ?></span><div class="sls-event-title"><?php echo htmlspecialchars($event['event'] !== '' ? $event['event'] : _('Unknown event')); ?></div><?php if ($body !== '') { ?><div class="sls-event-summary"><?php echo htmlspecialchars($body); ?></div><?php } ?></td>
							<td><span class="label label-<?php echo $severityClass($event['severity']); ?>"><?php echo htmlspecialchars($event['severity'] !== '' ? $event['severity'] : _('Unknown')); ?></span></td>
							<td><strong><?php echo htmlspecialchars($event['triggered_by']); ?></strong><?php if (!empty($event['source_name'])) { ?><div class="sls-event-summary"><?php echo htmlspecialchars($event['source_name']); ?></div><?php } ?></td>
							<td><div class="sls-log-audio"><i class="fa fa-volume-up text-muted" aria-hidden="true"></i> <?php echo htmlspecialchars($event['audio'] !== '' ? $event['audio'] : _('No audio')); ?></div><div class="sls-log-target"><i class="fa fa-users" aria-hidden="true"></i> <?php echo htmlspecialchars($event['page_group'] !== '' ? $event['page_group'] : _('No phone recipients recorded')); ?></div></td>
							<td class="text-right"><a class="btn btn-default btn-sm" title="<?php echo htmlspecialchars(_('View full event details')); ?>" href="config.php?display=slsmassnotifyserver&amp;view=detail&amp;id=<?php echo urlencode($event['event_id']); ?>"><i class="fa fa-chevron-right" aria-hidden="true"></i></a></td>
						</tr>
					<?php } ?></tbody>
				</table></div>
			<?php } ?>
		</div>
	</div>
</div>
