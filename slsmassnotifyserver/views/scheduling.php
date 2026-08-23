<?php

// Southland Servers Mass Notifications Server by the Southland Servers Group

$schedules = is_array($scheduled_announcements ?? null)
	? $scheduled_announcements
	: (is_array($schedules ?? null) ? $schedules : []);
$executionState = is_array($schedule_execution_state ?? null)
	? $schedule_execution_state
	: (is_array($execution_state ?? null) ? $execution_state : []);
$extensions = is_array($available_extensions ?? null) ? $available_extensions : [];
$groups = is_array($announcement_groups ?? null) ? $announcement_groups : [];
$desktopClients = is_array($desktop_clients ?? null) ? $desktop_clients : [];
$voices = is_array($available_voices ?? null) ? $available_voices : [];
$tones = is_array($available_tones ?? null) ? $available_tones : [];
$settings = is_array($settings ?? null) ? $settings : [];
$saveResult = is_array($save_result ?? null) ? $save_result : null;
$csrfToken = (string)($csrf_token ?? '');
$timezoneName = trim((string)($pbx_timezone ?? ''));
if ($timezoneName === '') {
	$timezoneName = date_default_timezone_get() ?: 'UTC';
}
try {
	$pbxTimezone = new \DateTimeZone($timezoneName);
} catch (\Throwable $exception) {
	$timezoneName = 'UTC';
	$pbxTimezone = new \DateTimeZone('UTC');
}
$nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

$scheduleId = static function (array $schedule) {
	return preg_replace('/[^A-Za-z0-9_-]/', '', (string)($schedule['id'] ?? ''));
};
$scheduleOccurrences = static function (array $schedule) {
	$values = $schedule['occurrences'] ?? [];
	return is_array($values) ? $values : [];
};
$scheduleRecurrenceMode = static function (array $schedule) {
	$recurrence = is_array($schedule['recurrence'] ?? null) ? $schedule['recurrence'] : [];
	$mode = strtolower(trim((string)($recurrence['mode'] ?? 'none')));
	return in_array($mode, ['every_7_days', 'every_14_days'], true) ? $mode : 'none';
};
$scheduleRecurrenceSummary = static function (array $schedule) use ($scheduleRecurrenceMode, $scheduleOccurrences) {
	$mode = $scheduleRecurrenceMode($schedule);
	$count = count($scheduleOccurrences($schedule));
	$runLabel = $count === 1 ? _('1 planned run') : sprintf(_('%d planned runs'), $count);
	if ($mode === 'every_7_days') {
		return _('Every 7 days') . ' · ' . $runLabel;
	}
	if ($mode === 'every_14_days') {
		return _('Every 14 days') . ' · ' . $runLabel;
	}
	return $count === 1 ? _('One-time announcement') : sprintf(_('%d one-time dates'), $count);
};
$scheduleState = static function (array $schedule, array $executionState) use ($scheduleId) {
	$id = $scheduleId($schedule);
	$value = is_array($executionState[$id] ?? null) ? $executionState[$id] : [];
	if (is_array($schedule['execution'] ?? null)) {
		$value = array_replace($value, $schedule['execution']);
	}
	return $value;
};
$formatInstant = static function ($value) use ($pbxTimezone) {
	$value = trim((string)$value);
	if ($value === '') {
		return '';
	}
	try {
		return (new \DateTimeImmutable($value))->setTimezone($pbxTimezone)->format('M j, Y g:i A T');
	} catch (\Throwable $exception) {
		return '';
	}
};
$nextOccurrence = static function (array $schedule) use ($scheduleOccurrences, $nowUtc) {
	$next = null;
	foreach ($scheduleOccurrences($schedule) as $occurrence) {
		if (!is_array($occurrence)) {
			continue;
		}
		$value = trim((string)($occurrence['run_at_utc'] ?? $occurrence['run_at'] ?? ''));
		if ($value === '') {
			continue;
		}
		try {
			$instant = new \DateTimeImmutable($value);
		} catch (\Throwable $exception) {
			continue;
		}
		if ($instant < $nowUtc) {
			continue;
		}
		if ($next === null || $instant < $next) {
			$next = $instant;
		}
	}
	return $next;
};
$targetSummary = static function (array $schedule) {
	$targets = is_array($schedule['targets'] ?? null) ? $schedule['targets'] : [];
	$parts = [];
	if (!empty($targets['phones_all'])) {
		$parts[] = _('All phones');
	} elseif (!empty($targets['extensions'])) {
		$parts[] = sprintf(_('%d phone(s)'), count((array)$targets['extensions']));
	}
	if (!empty($targets['groups'])) {
		$parts[] = sprintf(_('%d group(s)'), count((array)$targets['groups']));
	}
	if (!empty($targets['desktop_all'])) {
		$parts[] = _('All desktops');
	} elseif (!empty($targets['desktop_clients'])) {
		$parts[] = sprintf(_('%d desktop(s)'), count((array)$targets['desktop_clients']));
	}
	return $parts ? implode(' · ', $parts) : _('No recipients');
};
$statusMeta = [
	'pending' => ['label' => _('Pending'), 'class' => 'primary', 'icon' => 'fa-clock-o'],
	'claimed' => ['label' => _('Running'), 'class' => 'info', 'icon' => 'fa-spinner'],
	'running' => ['label' => _('Running'), 'class' => 'info', 'icon' => 'fa-spinner'],
	'success' => ['label' => _('Submitted'), 'class' => 'success', 'icon' => 'fa-check-circle'],
	'failed' => ['label' => _('Failed'), 'class' => 'danger', 'icon' => 'fa-exclamation-circle'],
	'missed' => ['label' => _('Missed'), 'class' => 'warning', 'icon' => 'fa-calendar-times-o'],
	'uncertain' => ['label' => _('Needs review'), 'class' => 'warning', 'icon' => 'fa-question-circle'],
	'disabled' => ['label' => _('Disabled'), 'class' => 'default', 'icon' => 'fa-pause-circle'],
	'complete' => ['label' => _('Complete'), 'class' => 'success', 'icon' => 'fa-check'],
];

$activeCount = 0;
$upcomingCount = 0;
$attentionCount = 0;
$clientSchedules = [];
foreach ($schedules as $scheduleIndex => $schedule) {
	if (!is_array($schedule)) {
		continue;
	}
	$enabled = !empty($schedule['enabled']);
	if ($enabled) {
		$activeCount++;
	}
	if ($enabled && $nextOccurrence($schedule) !== null) {
		$upcomingCount++;
	}
	$state = $scheduleState($schedule, $executionState);
	if (in_array(strtolower((string)($state['state'] ?? $state['last_status'] ?? '')), ['failed', 'missed', 'uncertain'], true)) {
		$attentionCount++;
	}
	$clientSchedule = $schedule;
	foreach ($scheduleOccurrences($schedule) as $occurrenceIndex => $occurrence) {
		if (!is_array($occurrence)) {
			continue;
		}
		// UTC is authoritative. Rebuild the editor value in the current PBX timezone so
		// changing the PBX timezone cannot leave a stale stored local time in the form.
		$utcValue = trim((string)($occurrence['run_at_utc'] ?? $occurrence['run_at'] ?? ''));
		$localValue = '';
		if ($utcValue !== '') {
			try {
				$localValue = (new \DateTimeImmutable($utcValue))->setTimezone($pbxTimezone)->format('Y-m-d\TH:i');
			} catch (\Throwable $exception) {
				$localValue = '';
			}
		}
		if ($localValue === '') {
			$localValue = trim((string)($occurrence['local_datetime'] ?? ''));
		}
		$clientSchedule['occurrences'][$occurrenceIndex]['editor_datetime'] = str_replace(' ', 'T', substr($localValue, 0, 16));
	}
	$recurrenceMode = $scheduleRecurrenceMode($schedule);
	$clientSchedule['recurrence'] = is_array($clientSchedule['recurrence'] ?? null) ? $clientSchedule['recurrence'] : [];
	$clientSchedule['recurrence']['mode'] = $recurrenceMode;
	$clientSchedule['recurrence']['editor_start_datetime'] = '';
	if ($recurrenceMode !== 'none') {
		foreach ($scheduleOccurrences($clientSchedule) as $occurrence) {
			$utcValue = trim((string)($occurrence['run_at_utc'] ?? ''));
			if ($utcValue === '') {
				continue;
			}
			try {
				$instant = new \DateTimeImmutable($utcValue);
			} catch (\Throwable $exception) {
				continue;
			}
			if ($instant >= $nowUtc) {
				$clientSchedule['recurrence']['editor_start_datetime'] = (string)($occurrence['editor_datetime'] ?? '');
				break;
			}
		}
		if ($clientSchedule['recurrence']['editor_start_datetime'] === '') {
			$clientSchedule['recurrence']['editor_start_datetime'] = str_replace(' ', 'T', substr((string)($clientSchedule['recurrence']['starts_at_local'] ?? ''), 0, 16));
		}
	}
	$clientSchedules[] = $clientSchedule;
}

$defaultOpeningTone = (string)($settings['opening_tone'] ?? 'opening_Paging_Tone_Opening');
$defaultClosingTone = (string)($settings['closing_tone'] ?? 'closing_Paging_Tone_Closing');
$defaultVoice = (string)($settings['announcement_piper_voice'] ?? '');
$defaultVolume = max(1, min(200, (int)($settings['announcement_tts_volume'] ?? 25)));
$nextPbxHour = (new \DateTimeImmutable('now', $pbxTimezone))->modify('+1 hour');
$defaultOccurrenceLocal = $nextPbxHour->setTime((int)$nextPbxHour->format('H'), 0)->format('Y-m-d\TH:i');
?>
<style>
.sls-schedule-page { color:#1f2937; }
.sls-schedule-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:18px; }
.sls-schedule-heading h1 { margin:0 0 5px; font-size:30px; font-weight:700; line-height:1.2; }
.sls-timezone-pill { display:inline-flex; align-items:center; gap:7px; padding:8px 12px; border:1px solid #dfe5ec; border-radius:999px; background:#f8fafc; color:#475569; font-weight:600; white-space:nowrap; }
.sls-schedule-summary { display:flex; flex-wrap:wrap; margin:0 -7px 20px; }
.sls-schedule-summary-wrap { width:25%; padding:0 7px; display:flex; }
.sls-schedule-summary-card { width:100%; min-height:94px; padding:15px 16px; border:1px solid #e2e8f0; border-radius:9px; background:#fff; box-shadow:0 2px 8px rgba(15,23,42,.05); }
.sls-schedule-summary-card i { margin-right:8px; color:#6d28d9; font-size:18px; }
.sls-schedule-summary-number { margin-top:8px; font-size:25px; font-weight:700; }
.sls-schedule-panel { border:1px solid #dfe5ec; border-radius:9px; overflow:hidden; background:#fff; box-shadow:0 2px 10px rgba(15,23,42,.04); }
.sls-schedule-panel-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; border-bottom:1px solid #dfe5ec; background:#f8fafc; }
.sls-schedule-panel-head h3 { margin:0; font-size:18px; font-weight:700; }
.sls-schedule-table { margin:0; }
.sls-schedule-table thead th { padding:11px 12px; border-bottom:1px solid #dfe5ec !important; background:#f1f5f9; color:#475569; font-size:11px; text-transform:uppercase; letter-spacing:.5px; }
.sls-schedule-table tbody td { padding:13px 12px; vertical-align:middle; border-top:1px solid #edf1f5; }
.sls-schedule-table tbody tr:hover { background:#f8fafc; }
.sls-schedule-name { margin-bottom:4px; font-weight:700; }
.sls-schedule-meta { color:#64748b; font-size:12px; line-height:1.45; overflow-wrap:anywhere; }
.sls-schedule-actions { display:flex; justify-content:flex-end; gap:5px; white-space:nowrap; }
.sls-schedule-actions form { display:inline; margin:0; }
.sls-schedule-empty { padding:48px 20px; text-align:center; color:#64748b; }
.sls-schedule-empty i { display:block; margin-bottom:12px; color:#94a3b8; font-size:36px; }
.sls-schedule-modal .modal-dialog { width:min(980px, calc(100% - 30px)); }
.sls-schedule-modal .modal-body { max-height:72vh; overflow-y:auto; background:#f8fafc; }
.sls-editor-card { margin-bottom:14px; padding:15px; border:1px solid #dfe5ec; border-radius:8px; background:#fff; }
.sls-editor-card h4 { display:flex; align-items:center; gap:8px; margin:0 0 13px; font-size:16px; font-weight:700; }
.sls-editor-step { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; color:#fff; background:#6d28d9; font-size:12px; }
.sls-occurrence-row { display:flex; align-items:center; gap:9px; margin-bottom:8px; }
.sls-occurrence-row .form-control { flex:1 1 auto; }
.sls-target-box { min-height:125px; max-height:210px; overflow:auto; padding:10px 12px; border:1px solid #dfe5ec; border-radius:6px; background:#f8fafc; }
.sls-target-box .checkbox { margin:0 0 7px; }
.sls-color-designer { display:none; margin-top:12px; padding:13px; border-left:4px solid #22c55e; background:#f7faf8; }
.sls-color-preview { min-height:112px; padding:14px; border-radius:5px; background:#1f2937; color:#fff; overflow-wrap:anywhere; }
.sls-color-preview-title { margin-bottom:8px; font-size:18px; font-weight:700; }
.sls-schedule-note { margin-top:16px; padding:12px 14px; border:1px solid #dbeafe; border-radius:7px; background:#eff6ff; color:#1e3a8a; }
.sls-schedule-note.warning { border-color:#fde68a; background:#fffbeb; color:#92400e; }
@media (max-width:991px) { .sls-schedule-summary-wrap { width:50%; margin-bottom:10px; } .sls-schedule-heading { display:block; } .sls-timezone-pill { margin-top:10px; } }
@media (max-width:600px) { .sls-schedule-summary-wrap { width:100%; } .sls-schedule-panel-head { display:block; } .sls-schedule-panel-head .btn { width:100%; margin-top:10px; } .sls-occurrence-row { align-items:stretch; flex-direction:column; } .sls-occurrence-row .btn { width:100%; } }
</style>
<div class="container-fluid sls-schedule-page">
	<div class="display full-border">
		<div class="fpbx-container">
			<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image]); ?>
			<div class="sls-schedule-heading">
				<div>
					<h1><i class="fa fa-calendar text-primary" aria-hidden="true"></i> <?php echo _('Scheduling'); ?></h1>
					<div class="text-muted"><?php echo _('Plan one-time or repeating announcements while retaining the same phone, desktop, audio, and color controls as a live announcement.'); ?></div>
				</div>
				<div class="sls-timezone-pill"><i class="fa fa-globe" aria-hidden="true"></i> <?php echo htmlspecialchars($timezoneName); ?></div>
			</div>

			<?php if ($saveResult !== null) { ?>
				<div class="alert alert-<?php echo !empty($saveResult['success']) ? 'success' : 'danger'; ?>">
					<strong><?php echo htmlspecialchars((string)($saveResult['message'] ?? '')); ?></strong>
					<?php if (!empty($saveResult['errors'])) { ?><ul style="margin-top:8px;margin-bottom:0"><?php foreach ((array)$saveResult['errors'] as $error) { ?><li><?php echo htmlspecialchars((string)$error); ?></li><?php } ?></ul><?php } ?>
				</div>
			<?php } ?>

			<div class="sls-schedule-summary">
				<div class="sls-schedule-summary-wrap"><div class="sls-schedule-summary-card" data-schedule-metric="total" data-value="<?php echo count($clientSchedules); ?>"><div><i class="fa fa-calendar-check-o" aria-hidden="true"></i><?php echo _('Schedules'); ?></div><div class="sls-schedule-summary-number"><?php echo count($clientSchedules); ?></div></div></div>
				<div class="sls-schedule-summary-wrap"><div class="sls-schedule-summary-card" data-schedule-metric="enabled" data-value="<?php echo $activeCount; ?>"><div><i class="fa fa-play-circle" aria-hidden="true"></i><?php echo _('Enabled'); ?></div><div class="sls-schedule-summary-number"><?php echo $activeCount; ?></div></div></div>
				<div class="sls-schedule-summary-wrap"><div class="sls-schedule-summary-card" data-schedule-metric="upcoming" data-value="<?php echo $upcomingCount; ?>"><div><i class="fa fa-clock-o" aria-hidden="true"></i><?php echo _('Upcoming'); ?></div><div class="sls-schedule-summary-number"><?php echo $upcomingCount; ?></div></div></div>
				<div class="sls-schedule-summary-wrap"><div class="sls-schedule-summary-card" data-schedule-metric="attention" data-value="<?php echo $attentionCount; ?>"><div><i class="fa fa-exclamation-triangle" aria-hidden="true"></i><?php echo _('Needs attention'); ?></div><div class="sls-schedule-summary-number"><?php echo $attentionCount; ?></div></div></div>
			</div>

			<div class="sls-schedule-panel">
				<div class="sls-schedule-panel-head">
					<div><h3><i class="fa fa-list" aria-hidden="true"></i> <?php echo _('Scheduled announcements'); ?></h3><div class="sls-schedule-meta"><?php echo _('Schedules use the PBX timezone shown above. Offline phone endpoints are skipped when delivery begins.'); ?></div></div>
					<button type="button" class="btn btn-primary" id="sls-schedule-create"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Create Schedule'); ?></button>
				</div>
				<?php if (empty($clientSchedules)) { ?>
					<div class="sls-schedule-empty"><i class="fa fa-calendar-o" aria-hidden="true"></i><strong><?php echo _('No announcements are scheduled'); ?></strong><div><?php echo _('Create a schedule to add one or more future delivery dates.'); ?></div></div>
				<?php } else { ?>
					<div class="table-responsive"><table class="table sls-schedule-table">
						<thead><tr><th><?php echo _('Schedule'); ?></th><th><?php echo _('Next run'); ?></th><th><?php echo _('Recipients'); ?></th><th><?php echo _('Delivery'); ?></th><th><?php echo _('Status'); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ($clientSchedules as $schedule) {
							$id = $scheduleId($schedule);
							$enabled = !empty($schedule['enabled']);
							$next = $nextOccurrence($schedule);
							$state = $scheduleState($schedule, $executionState);
							$stateName = strtolower(trim((string)($state['state'] ?? $state['last_status'] ?? '')));
							if (!$enabled) {
								$stateName = 'disabled';
							} elseif ($next !== null && !in_array($stateName, ['failed', 'missed', 'uncertain'], true)) {
								// A prior successful occurrence must not make a schedule with future dates look complete.
								$stateName = 'pending';
							} elseif ($stateName === '') {
								$stateName = 'complete';
							}
							$meta = $statusMeta[$stateName] ?? $statusMeta['pending'];
							$delivery = is_array($schedule['delivery'] ?? null) ? $schedule['delivery'] : [];
							$audioMode = (string)($delivery['audio_mode'] ?? 'none');
							$style = (string)($delivery['style'] ?? 'standard');
						?>
							<tr data-schedule-id="<?php echo htmlspecialchars($id); ?>" data-rendered-state="<?php echo htmlspecialchars($stateName); ?>">
								<td><div class="sls-schedule-name"><?php echo htmlspecialchars((string)($schedule['name'] ?? _('Scheduled announcement'))); ?></div><div class="sls-schedule-meta"><?php echo htmlspecialchars($scheduleRecurrenceSummary($schedule)); ?></div></td>
								<td><?php if ($next !== null) { ?><strong><?php echo htmlspecialchars($formatInstant($next->format(DATE_ATOM))); ?></strong><?php } else { ?><span class="text-muted"><?php echo _('No future dates'); ?></span><?php } ?></td>
								<td><div><?php echo htmlspecialchars($targetSummary($schedule)); ?></div></td>
								<td><div><?php echo htmlspecialchars(ucwords(str_replace('_', ' + ', $audioMode))); ?></div><div class="sls-schedule-meta"><?php echo $style === 'colored' ? _('Colored announcement · Labs') : _('Standard announcement'); ?></div></td>
								<td><span class="label label-<?php echo $meta['class']; ?>"><i class="fa <?php echo $meta['icon']; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($meta['label']); ?></span><?php if (!empty($state['message'])) { ?><div class="sls-schedule-meta" style="margin-top:5px"><?php echo htmlspecialchars((string)$state['message']); ?></div><?php } ?></td>
								<td><div class="sls-schedule-actions">
									<button type="button" class="btn btn-default btn-sm sls-schedule-edit" data-schedule-id="<?php echo htmlspecialchars($id); ?>" title="<?php echo htmlspecialchars(_('Edit schedule')); ?>"><i class="fa fa-pencil" aria-hidden="true"></i></button>
									<form method="post" action="config.php?display=slsmassnotifyserver_scheduling" class="sls-schedule-action-form"><input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="slsmassnotifyserver_action" value="toggle_scheduled_announcement"><input type="hidden" name="schedule_id" value="<?php echo htmlspecialchars($id); ?>"><input type="hidden" name="schedule_enabled" value="<?php echo $enabled ? '0' : '1'; ?>"><button type="submit" class="btn btn-<?php echo $enabled ? 'warning' : 'success'; ?> btn-sm" title="<?php echo htmlspecialchars($enabled ? _('Disable schedule') : _('Enable schedule')); ?>"><i class="fa <?php echo $enabled ? 'fa-pause' : 'fa-play'; ?>" aria-hidden="true"></i></button></form>
									<form method="post" action="config.php?display=slsmassnotifyserver_scheduling" class="sls-schedule-delete-form sls-schedule-action-form"><input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="slsmassnotifyserver_action" value="delete_scheduled_announcement"><input type="hidden" name="schedule_id" value="<?php echo htmlspecialchars($id); ?>"><button type="submit" class="btn btn-danger btn-sm" title="<?php echo htmlspecialchars(_('Delete schedule')); ?>"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
								</div></td>
							</tr>
						<?php } ?>
						</tbody>
					</table></div>
				<?php } ?>
			</div>

			<div class="sls-schedule-note"><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo _('The scheduler checks once per minute and serializes delivery. Schedules placed closer together than the configured announcement cooldown can run late; the scheduler does not bypass the cooldown or an announcement already in progress. A delayed delivery remains eligible only during its protected missed-run window.'); ?></div>
			<div class="sls-schedule-note warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <strong><?php echo _('Schedule recovery:'); ?></strong> <?php echo _('Configuration imports and FreePBX restores automatically disable imported schedules because the execution ledger is PBX-local. Review their dates and targets before enabling them. To re-arm a failed or missed occurrence, edit the schedule, remove that old date, and add a new future date; an uncertain occurrence is never replayed automatically.'); ?></div>
		</div>
	</div>
</div>

<div class="modal fade sls-schedule-modal" id="sls-schedule-modal" tabindex="-1" role="dialog" aria-labelledby="sls-schedule-modal-title" aria-hidden="true">
	<div class="modal-dialog" role="document"><div class="modal-content">
		<form method="post" action="config.php?display=slsmassnotifyserver_scheduling" id="sls-schedule-form">
			<div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="<?php echo htmlspecialchars(_('Close')); ?>"><span aria-hidden="true">&times;</span></button><h3 class="modal-title" id="sls-schedule-modal-title"><?php echo _('Create Scheduled Announcement'); ?></h3></div>
			<div class="modal-body">
				<input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
				<input type="hidden" name="slsmassnotifyserver_action" value="save_scheduled_announcement">
				<input type="hidden" name="schedule_id" id="sls-schedule-id" value="">
				<input type="hidden" name="schedule_timezone" value="<?php echo htmlspecialchars($timezoneName); ?>">
				<div class="sls-editor-card"><h4><span class="sls-editor-step">1</span><?php echo _('Announcement'); ?></h4>
					<div class="row"><div class="col-sm-8"><div class="form-group"><label for="sls-schedule-name"><?php echo _('Schedule name'); ?></label><input class="form-control" type="text" id="sls-schedule-name" name="schedule_name" maxlength="80" required placeholder="<?php echo htmlspecialchars(_('Example: Friday closing reminder')); ?>"></div></div><div class="col-sm-4"><div class="form-group"><label style="display:block"><?php echo _('State'); ?></label><input type="hidden" name="schedule_enabled" value="0"><label class="checkbox-inline"><input type="checkbox" id="sls-schedule-enabled" name="schedule_enabled" value="1" checked> <?php echo _('Enabled'); ?></label></div></div></div>
					<div class="form-group"><label for="sls-schedule-message"><?php echo _('Message'); ?></label><textarea class="form-control" id="sls-schedule-message" name="schedule_message" rows="3" maxlength="500" required placeholder="<?php echo htmlspecialchars(_('Announcement text')); ?>"></textarea><p class="help-block"><?php echo _('This text is displayed on phones and desktops and is read when TTS is selected.'); ?></p></div>
				</div>

				<div class="sls-editor-card"><h4><span class="sls-editor-step">2</span><?php echo _('Dates and times'); ?></h4>
					<div class="row"><div class="col-sm-6"><div class="form-group"><label for="sls-schedule-recurrence"><?php echo _('Repeat'); ?></label><select class="form-control" id="sls-schedule-recurrence" name="schedule_recurrence_mode"><option value="none"><?php echo _('Does not repeat'); ?></option><option value="every_7_days"><?php echo _('Every 7 days'); ?></option><option value="every_14_days"><?php echo _('Every 14 days'); ?></option></select></div></div></div>
					<p class="text-muted" id="sls-schedule-date-help"><?php echo sprintf(_('Times are interpreted in %s. Add each one-time occurrence below.'), $timezoneName); ?></p>
					<div class="alert alert-warning" style="padding:9px 11px"><i class="fa fa-clock-o" aria-hidden="true"></i> <?php echo _('Leave enough time between announcements for the configured cooldown. Closely scheduled items are serialized and may be delayed rather than played at the same moment.'); ?></div>
					<div id="sls-occurrence-list"></div><button type="button" class="btn btn-default btn-sm" id="sls-occurrence-add"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add date and time'); ?></button>
				</div>

				<div class="sls-editor-card"><h4><span class="sls-editor-step">3</span><?php echo _('Recipients'); ?></h4>
					<div class="row"><div class="col-sm-6"><div class="form-group"><label><?php echo _('Phones'); ?></label><div class="sls-target-box"><div class="checkbox"><label><input type="checkbox" name="schedule_all_phones" value="1"> <strong><?php echo _('All phones available at delivery time'); ?></strong></label></div><?php if (empty($extensions)) { ?><p class="text-muted"><?php echo _('No PJSIP extensions are configured.'); ?></p><?php } foreach ($extensions as $extension) { $number = preg_replace('/[^0-9]/', '', (string)($extension['extension'] ?? '')); if ($number === '') continue; ?><div class="checkbox"><label><input type="checkbox" name="schedule_extensions[]" value="<?php echo htmlspecialchars($number); ?>"> <?php echo htmlspecialchars($number); ?><?php if (!empty($extension['name'])) echo ' - ' . htmlspecialchars((string)$extension['name']); ?> <span class="text-muted"><?php echo !empty($extension['registered']) ? _('online') : _('offline'); ?></span></label></div><?php } ?></div></div></div>
					<div class="col-sm-6"><div class="form-group"><label><?php echo _('Announcement groups'); ?></label><div class="sls-target-box"><?php if (empty($groups)) { ?><p class="text-muted"><?php echo _('No announcement groups are configured.'); ?></p><?php } foreach ($groups as $group) { $groupId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($group['id'] ?? '')); if ($groupId === '') continue; ?><div class="checkbox"><label><input type="checkbox" name="schedule_groups[]" value="<?php echo htmlspecialchars($groupId); ?>"> <?php echo htmlspecialchars((string)($group['name'] ?? _('Announcement group'))); ?></label></div><?php } ?></div></div></div></div>
					<div class="row"><div class="col-sm-12"><div class="form-group"><label><?php echo _('Desktop app targets'); ?></label><div class="sls-target-box"><div class="checkbox"><label><input type="checkbox" name="schedule_all_desktops" value="1"> <strong><?php echo _('All enabled desktops'); ?></strong></label></div><?php if (empty($desktopClients)) { ?><p class="text-muted"><?php echo _('No desktop app clients are configured.'); ?></p><?php } foreach ($desktopClients as $client) { if (empty($client['enabled'])) continue; $username = trim((string)($client['username'] ?? '')); if ($username === '') continue; ?><div class="checkbox"><label><input type="checkbox" name="schedule_desktop_clients[]" value="<?php echo htmlspecialchars($username); ?>"> <?php echo htmlspecialchars((string)($client['name'] ?? _('Desktop App'))); ?> <span class="text-muted"><?php echo htmlspecialchars((string)($client['client_id'] ?? $username)); ?></span></label></div><?php } ?></div></div></div></div>
				</div>

				<div class="sls-editor-card"><h4><span class="sls-editor-step">4</span><?php echo _('Delivery options'); ?></h4>
					<div class="row"><div class="col-sm-4"><div class="form-group"><label for="sls-schedule-audio-mode"><?php echo _('Audio mode'); ?></label><select class="form-control" id="sls-schedule-audio-mode" name="schedule_audio_mode"><option value="none"><?php echo _('None (visual/text only)'); ?></option><option value="tones"><?php echo _('Tones only'); ?></option><option value="tts"><?php echo _('TTS only'); ?></option><option value="tones_tts" selected><?php echo _('Tones and TTS'); ?></option></select></div></div><div class="col-sm-5 sls-schedule-voice-option"><div class="form-group"><label for="sls-schedule-voice"><?php echo _('Piper voice'); ?></label><select class="form-control" id="sls-schedule-voice" name="schedule_voice"><?php foreach ($voices as $voice) { $path = (string)($voice['path'] ?? ''); if ($path === '') continue; ?><option value="<?php echo htmlspecialchars($path); ?>" <?php echo $path === $defaultVoice ? 'selected' : ''; ?> <?php echo array_key_exists('available', $voice) && empty($voice['available']) ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($voice['name'] ?? basename($path))); ?></option><?php } ?></select></div></div><div class="col-sm-3 sls-schedule-voice-option"><div class="form-group"><label for="sls-schedule-volume"><?php echo _('Volume'); ?></label><div class="input-group"><input class="form-control" type="number" min="1" max="200" id="sls-schedule-volume" name="schedule_tts_volume" value="<?php echo $defaultVolume; ?>"><span class="input-group-addon">%</span></div></div></div></div>
					<div class="row sls-schedule-tone-option"><div class="col-sm-6"><div class="form-group"><label for="sls-schedule-opening-tone"><?php echo _('Opening sound'); ?></label><select class="form-control" id="sls-schedule-opening-tone" name="schedule_opening_tone"><option value=""><?php echo _('None'); ?></option><?php foreach ($tones as $tone) { ?><option value="<?php echo htmlspecialchars((string)$tone); ?>" <?php echo (string)$tone === $defaultOpeningTone ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', (string)$tone)); ?></option><?php } ?></select></div></div><div class="col-sm-6"><div class="form-group"><label for="sls-schedule-closing-tone"><?php echo _('Closing sound'); ?></label><select class="form-control" id="sls-schedule-closing-tone" name="schedule_closing_tone"><option value=""><?php echo _('None'); ?></option><?php foreach ($tones as $tone) { ?><option value="<?php echo htmlspecialchars((string)$tone); ?>" <?php echo (string)$tone === $defaultClosingTone ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', (string)$tone)); ?></option><?php } ?></select></div></div></div>
					<div class="form-group"><input type="hidden" name="schedule_colored" value="0"><label class="checkbox-inline"><input type="checkbox" id="sls-schedule-colored" name="schedule_colored" value="1"> <?php echo _('Colored announcement'); ?> <span class="label label-success"><i class="fa fa-flask" aria-hidden="true"></i> <?php echo _('Labs · Yealink color'); ?></span></label><p class="help-block" style="margin-left:20px"><?php echo _('Other supported phones receive their safe text format.'); ?></p></div>
					<div class="sls-color-designer" id="sls-schedule-color-designer"><div class="row"><div class="col-sm-7"><div class="form-group"><label for="sls-schedule-title"><?php echo _('Image title'); ?></label><input class="form-control" type="text" maxlength="80" id="sls-schedule-title" name="schedule_title" value="Announcement"></div><div class="form-group"><label for="sls-schedule-color"><?php echo _('Background color'); ?></label><input class="form-control" type="color" id="sls-schedule-color" name="schedule_background_color" value="#1f2937" style="max-width:100px;padding:3px"></div></div><div class="col-sm-5"><label><?php echo _('Preview'); ?></label><div class="sls-color-preview" id="sls-schedule-preview"><div class="sls-color-preview-title" id="sls-schedule-preview-title">Announcement</div><div id="sls-schedule-preview-message"><?php echo _('Announcement text'); ?></div></div></div></div></div>
				</div>
			</div>
			<div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal"><?php echo _('Cancel'); ?></button><button type="submit" class="btn btn-primary"><i class="fa fa-calendar-check-o" aria-hidden="true"></i> <?php echo _('Save Schedule'); ?></button></div>
		</form>
	</div></div>
</div>

<script>
(function() {
	'use strict';
	var schedules = <?php echo json_encode(array_values($clientSchedules), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
	var modal = window.jQuery ? window.jQuery('#sls-schedule-modal') : null;
	var form = document.getElementById('sls-schedule-form');
	var createButton = document.getElementById('sls-schedule-create');
	var occurrenceList = document.getElementById('sls-occurrence-list');
	var occurrenceAdd = document.getElementById('sls-occurrence-add');
	var recurrence = document.getElementById('sls-schedule-recurrence');
	var dateHelp = document.getElementById('sls-schedule-date-help');
	var audioMode = document.getElementById('sls-schedule-audio-mode');
	var colored = document.getElementById('sls-schedule-colored');
	var colorDesigner = document.getElementById('sls-schedule-color-designer');
	var color = document.getElementById('sls-schedule-color');
	var title = document.getElementById('sls-schedule-title');
	var message = document.getElementById('sls-schedule-message');
	var preview = document.getElementById('sls-schedule-preview');
	var previewTitle = document.getElementById('sls-schedule-preview-title');
	var previewMessage = document.getElementById('sls-schedule-preview-message');
	var scheduleSubmit = form ? form.querySelector('button[type="submit"]') : null;
	var scheduleFormSubmitting = false;
	var defaults = {
		voice: <?php echo json_encode($defaultVoice); ?>,
		volume: <?php echo (int)$defaultVolume; ?>,
		openingTone: <?php echo json_encode($defaultOpeningTone); ?>,
		closingTone: <?php echo json_encode($defaultClosingTone); ?>,
		occurrence: <?php echo json_encode($defaultOccurrenceLocal); ?>
	};
	if (!form || !occurrenceList) {
		return;
	}

	function localDefaultDateTime() {
		var existing = occurrenceList.querySelectorAll('input[name="schedule_occurrences[]"]');
		if (existing.length) {
			var lastValue = existing[existing.length - 1].value;
			if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(lastValue)) {
				var nextValue = new Date(lastValue + ':00Z');
				if (!isNaN(nextValue.getTime())) {
					nextValue.setUTCHours(nextValue.getUTCHours() + 1);
					return nextValue.toISOString().slice(0, 16);
				}
			}
		}
		return defaults.occurrence;
	}
	function addOccurrence(value) {
		var row = document.createElement('div');
		row.className = 'sls-occurrence-row';
		var input = document.createElement('input');
		input.type = 'datetime-local';
		input.className = 'form-control';
		input.name = 'schedule_occurrences[]';
		input.required = true;
		input.min = <?php echo json_encode((new \DateTimeImmutable('now', $pbxTimezone))->modify('-1 minute')->format('Y-m-d\TH:i')); ?>;
		input.value = value || localDefaultDateTime();
		var remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'btn btn-danger btn-sm sls-occurrence-remove';
		remove.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i> ';
		remove.appendChild(document.createTextNode(<?php echo json_encode(_('Remove')); ?>));
		remove.addEventListener('click', function() {
			row.parentNode.removeChild(row);
			if (!occurrenceList.children.length) addOccurrence('');
		});
		row.appendChild(input);
		row.appendChild(remove);
		occurrenceList.appendChild(row);
	}
	function renderRecurrenceOptions() {
		var repeating = recurrence && recurrence.value !== 'none';
		if (repeating) {
			while (occurrenceList.children.length > 1) {
				occurrenceList.removeChild(occurrenceList.lastElementChild);
			}
		}
		if (occurrenceAdd) occurrenceAdd.style.display = repeating ? 'none' : '';
		Array.prototype.forEach.call(occurrenceList.querySelectorAll('.sls-occurrence-remove'), function(button) {
			button.style.display = repeating ? 'none' : '';
		});
		if (dateHelp) {
			dateHelp.textContent = repeating
				? <?php echo json_encode(sprintf(_('Choose the first local date and time in %s. The announcement will repeat at that same local time for up to five years.'), $timezoneName)); ?>
				: <?php echo json_encode(sprintf(_('Times are interpreted in %s. Add each one-time occurrence below.'), $timezoneName)); ?>;
		}
	}
	function setChecked(name, values) {
		var lookup = {};
		(values || []).forEach(function(value) { lookup[String(value)] = true; });
		Array.prototype.forEach.call(form.querySelectorAll('[name="' + name + '"]'), function(input) {
			input.checked = !!lookup[String(input.value)];
		});
	}
	function setSingleChecked(name, value) {
		var input = form.querySelector('[name="' + name + '"][value="1"]');
		if (input) input.checked = !!value && String(value) !== '0';
	}
	function setValue(id, value, fallback) {
		var input = document.getElementById(id);
		if (input) input.value = value === undefined || value === null || value === '' ? (fallback || '') : value;
	}
	function renderOptions() {
		var mode = audioMode ? audioMode.value : 'none';
		var tonesVisible = mode === 'tones' || mode === 'tones_tts';
		var voiceVisible = mode === 'tts' || mode === 'tones_tts';
		Array.prototype.forEach.call(document.querySelectorAll('.sls-schedule-tone-option'), function(node) { node.style.display = tonesVisible ? '' : 'none'; });
		Array.prototype.forEach.call(document.querySelectorAll('.sls-schedule-voice-option'), function(node) { node.style.display = voiceVisible ? '' : 'none'; });
		if (colorDesigner) colorDesigner.style.display = colored && colored.checked ? 'block' : 'none';
		if (preview && color) preview.style.backgroundColor = color.value || '#1f2937';
		if (previewTitle && title) previewTitle.textContent = title.value.trim() || 'Announcement';
		if (previewMessage && message) previewMessage.textContent = message.value.trim() || 'Announcement text';
	}
	function resetEditor() {
		form.reset();
		setValue('sls-schedule-id', '');
		setValue('sls-schedule-volume', defaults.volume);
		setValue('sls-schedule-voice', defaults.voice);
		setValue('sls-schedule-opening-tone', defaults.openingTone);
		setValue('sls-schedule-closing-tone', defaults.closingTone);
		setValue('sls-schedule-color', '#1f2937');
		setValue('sls-schedule-title', 'Announcement');
		document.getElementById('sls-schedule-enabled').checked = true;
		occurrenceList.innerHTML = '';
		addOccurrence('');
		document.getElementById('sls-schedule-modal-title').textContent = <?php echo json_encode(_('Create Scheduled Announcement')); ?>;
		renderRecurrenceOptions();
		renderOptions();
	}
	function openEditor(schedule) {
		resetEditor();
		if (schedule) {
			var targets = schedule.targets && typeof schedule.targets === 'object' ? schedule.targets : {};
			var delivery = schedule.delivery && typeof schedule.delivery === 'object' ? schedule.delivery : {};
			var recurrenceSettings = schedule.recurrence && typeof schedule.recurrence === 'object' ? schedule.recurrence : {};
			setValue('sls-schedule-id', schedule.id || '');
			setValue('sls-schedule-name', schedule.name || '');
			setValue('sls-schedule-message', schedule.message || '');
			document.getElementById('sls-schedule-enabled').checked = !!schedule.enabled && String(schedule.enabled) !== '0';
			setSingleChecked('schedule_all_phones', targets.phones_all);
			setSingleChecked('schedule_all_desktops', targets.desktop_all);
			setChecked('schedule_extensions[]', targets.extensions || []);
			setChecked('schedule_groups[]', targets.groups || []);
			setChecked('schedule_desktop_clients[]', targets.desktop_clients || []);
			setValue('sls-schedule-audio-mode', delivery.audio_mode || 'none');
			setValue('sls-schedule-voice', delivery.voice || delivery.piper_voice || defaults.voice);
			setValue('sls-schedule-volume', delivery.tts_volume || defaults.volume);
			setValue('sls-schedule-opening-tone', delivery.opening_tone, '');
			setValue('sls-schedule-closing-tone', delivery.closing_tone, '');
			document.getElementById('sls-schedule-colored').checked = delivery.style === 'colored' || !!delivery.colored;
			setValue('sls-schedule-title', delivery.title || 'Announcement');
			setValue('sls-schedule-color', delivery.background_color || '#1f2937');
			setValue('sls-schedule-recurrence', recurrenceSettings.mode || 'none');
			occurrenceList.innerHTML = '';
			if (recurrenceSettings.mode && recurrenceSettings.mode !== 'none') {
				addOccurrence(recurrenceSettings.editor_start_datetime || recurrenceSettings.starts_at_local || '');
			} else {
				(schedule.occurrences || []).forEach(function(occurrence) {
					if (occurrence && occurrence.editor_datetime) addOccurrence(occurrence.editor_datetime);
				});
			}
			if (!occurrenceList.children.length) addOccurrence('');
			document.getElementById('sls-schedule-modal-title').textContent = <?php echo json_encode(_('Edit Scheduled Announcement')); ?>;
			renderRecurrenceOptions();
			renderOptions();
		}
		if (modal) modal.modal('show');
	}
	if (occurrenceAdd) occurrenceAdd.addEventListener('click', function() { addOccurrence(''); });
	if (recurrence) recurrence.addEventListener('change', renderRecurrenceOptions);
	if (createButton) createButton.addEventListener('click', function() { openEditor(null); });
	Array.prototype.forEach.call(document.querySelectorAll('.sls-schedule-edit'), function(button) {
		button.addEventListener('click', function() {
			var id = button.getAttribute('data-schedule-id') || '';
			var selected = schedules.find(function(schedule) { return schedule && schedule.id === id; });
			if (selected) openEditor(selected);
		});
	});
	Array.prototype.forEach.call(document.querySelectorAll('.sls-schedule-delete-form'), function(deleteForm) {
		deleteForm.addEventListener('submit', function(event) {
			if (!window.confirm(<?php echo json_encode(_('Delete this schedule and all of its future occurrences?')); ?>)) event.preventDefault();
		});
	});
	Array.prototype.forEach.call(document.querySelectorAll('.sls-schedule-action-form'), function(actionForm) {
		actionForm.addEventListener('submit', function(event) {
			if (event.defaultPrevented) return;
			if (actionForm.getAttribute('data-submitting') === '1') {
				event.preventDefault();
				return;
			}
			actionForm.setAttribute('data-submitting', '1');
			actionForm.setAttribute('aria-busy', 'true');
			var button = actionForm.querySelector('button[type="submit"]');
			if (button) button.disabled = true;
		});
	});
	[audioMode, colored].forEach(function(input) { if (input) input.addEventListener('change', renderOptions); });
	[color, title, message].forEach(function(input) { if (input) input.addEventListener('input', renderOptions); });
	form.addEventListener('submit', function(event) {
		if (scheduleFormSubmitting) {
			event.preventDefault();
			return;
		}
		if (!occurrenceList.querySelector('input[name="schedule_occurrences[]"]')) {
			event.preventDefault();
			window.alert(<?php echo json_encode(_('Add at least one date and time.')); ?>);
			return;
		}
		var recipient = form.querySelector('input[name="schedule_all_phones"]:checked, input[name="schedule_all_desktops"]:checked, input[name="schedule_extensions[]"]:checked, input[name="schedule_groups[]"]:checked, input[name="schedule_desktop_clients[]"]:checked');
		if (!recipient) {
			event.preventDefault();
			window.alert(<?php echo json_encode(_('Select at least one phone, group, or desktop recipient.')); ?>);
			return;
		}
		scheduleFormSubmitting = true;
		form.setAttribute('aria-busy', 'true');
		if (scheduleSubmit) {
			scheduleSubmit.disabled = true;
			scheduleSubmit.innerHTML = '<i class="fa fa-spinner fa-spin" aria-hidden="true"></i> ' + <?php echo json_encode(_('Saving…')); ?>;
		}
	});
	renderRecurrenceOptions();
	renderOptions();
}());
</script>
