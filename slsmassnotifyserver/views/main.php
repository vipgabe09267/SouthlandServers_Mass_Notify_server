<?php
$typeOptions = [
	'' => _('All Events'),
	'nws' => _('NWS Weather Alerts'),
	'test' => _('Manual Tests'),
	'announcement' => _('Announcements'),
	'announcement_audio' => _('Announcement Audio'),
];
$limitOptions = [50, 100, 200, 500];
$statusCards = is_array($status_summary ?? null) ? $status_summary : [];
$statusClasses = [
	'ok' => 'success',
	'fault' => 'danger',
	'notice' => 'warning',
	'unknown' => 'default',
];
?>
<div class="container-fluid">
	<div class="display full-border">
		<div class="fpbx-container">
			<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
			<h1><?php echo _('Notification Logs'); ?></h1>
			<p class="text-muted">
				<?php echo _('History for NWS-triggered notifications, manual tests, announcement audio, and Mass Notifications events.'); ?>
			</p>

			<?php if (!empty($statusCards)) { ?>
				<div class="row" style="margin-bottom: 20px;">
					<?php foreach ($statusCards as $card) {
						$state = $card['state'] ?? 'unknown';
						$panelClass = $statusClasses[$state] ?? 'default';
					?>
						<div class="col-md-4">
							<div class="panel panel-<?php echo $panelClass; ?>">
								<div class="panel-heading">
									<strong><?php echo htmlspecialchars($card['label'] ?? ''); ?></strong>
								</div>
								<div class="panel-body">
									<p style="margin-bottom: 8px;"><?php echo htmlspecialchars($card['message'] ?? ''); ?></p>
									<?php if (!empty($card['time'])) { ?>
										<div class="text-muted"><?php echo htmlspecialchars($card['time']); ?></div>
									<?php } ?>
									<?php if (!empty($card['details'])) { ?>
										<div class="text-muted" style="margin-top: 6px;"><?php echo htmlspecialchars($card['details']); ?></div>
									<?php } ?>
								</div>
							</div>
						</div>
					<?php } ?>
				</div>
			<?php } ?>

			<form method="get" action="config.php" class="form-inline" style="margin-bottom: 15px;">
				<input type="hidden" name="display" value="slsmassnotifyserver">
				<div class="form-group" style="margin-right: 10px;">
					<label for="log_type" style="margin-right: 8px;"><?php echo _('Type'); ?></label>
					<select class="form-control" id="log_type" name="log_type">
						<?php foreach ($typeOptions as $value => $label) { ?>
							<option value="<?php echo htmlspecialchars($value); ?>" <?php echo $selected_type === $value ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($label); ?>
							</option>
						<?php } ?>
					</select>
				</div>
				<div class="form-group" style="margin-right: 10px;">
					<label for="limit" style="margin-right: 8px;"><?php echo _('Rows'); ?></label>
					<select class="form-control" id="limit" name="limit">
						<?php foreach ($limitOptions as $value) { ?>
							<option value="<?php echo (int)$value; ?>" <?php echo (int)$selected_limit === (int)$value ? 'selected' : ''; ?>>
								<?php echo (int)$value; ?>
							</option>
						<?php } ?>
					</select>
				</div>
				<button type="submit" class="btn btn-default"><?php echo _('Apply'); ?></button>
			</form>

			<?php if (empty($events)) { ?>
				<div class="alert alert-info">
					<?php echo sprintf(_('No structured notification events were found yet. The dashboard reads %s.'), '<code>' . htmlspecialchars($log_path) . '</code>'); ?>
				</div>
			<?php } else { ?>
				<div class="table-responsive">
					<table class="table table-striped table-bordered">
						<thead>
							<tr>
								<th><?php echo _('Time'); ?></th>
								<th><?php echo _('Type'); ?></th>
								<th><?php echo _('Event'); ?></th>
								<th><?php echo _('Severity'); ?></th>
								<th><?php echo _('Triggered By'); ?></th>
								<th><?php echo _('Audio'); ?></th>
								<th><?php echo _('Page Group'); ?></th>
								<th><?php echo _('Details'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($events as $event) { ?>
								<tr>
									<td><?php echo htmlspecialchars($event['display_time']); ?></td>
									<td><?php echo htmlspecialchars($event['type_label']); ?></td>
									<td><?php echo htmlspecialchars($event['event'] !== '' ? $event['event'] : _('Unknown')); ?></td>
									<td><?php echo htmlspecialchars($event['severity']); ?></td>
									<td><?php echo htmlspecialchars($event['triggered_by']); ?></td>
									<td><?php echo htmlspecialchars($event['audio']); ?></td>
									<td><?php echo htmlspecialchars($event['page_group']); ?></td>
									<td>
										<a class="btn btn-default btn-sm" href="config.php?display=slsmassnotifyserver&amp;view=detail&amp;id=<?php echo urlencode($event['event_id']); ?>">
											<?php echo _('View'); ?>
										</a>
									</td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			<?php } ?>
		</div>
	</div>
</div>
