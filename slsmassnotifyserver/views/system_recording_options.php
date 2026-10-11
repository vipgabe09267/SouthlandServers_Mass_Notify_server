<?php if (isset($recording_catalogue) || !empty($available_system_sounds)) { ?>
<optgroup label="<?php echo htmlspecialchars(_('System Recordings'), ENT_QUOTES, 'UTF-8'); ?>" data-sls-recordings="<?php echo htmlspecialchars(json_encode(['csrf'=>$csrf_token ?? $csrfToken ?? '', 'portal'=>!empty($recording_portal), 'catalogue'=>array_intersect_key($recording_catalogue ?? [], array_flip(['total','has_more','next_offset','warning','scan_complete']))], JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8'); ?>">
<?php foreach (($available_system_sounds ?? []) as $recording) { ?>
<option value="<?php echo htmlspecialchars($recording['value'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($recording['label'], ENT_QUOTES, 'UTF-8'); ?></option>
<?php } ?>
</optgroup>
<?php } ?>
<?php if (empty($recordingBrowserRendered)) { $recordingBrowserRendered = true; ?>
<script><?php readfile(__DIR__.'/system_recording_browser.js'); ?></script>
<?php } ?>
