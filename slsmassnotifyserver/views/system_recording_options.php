<?php if (!empty($available_system_sounds)) { ?>
<optgroup label="<?php echo htmlspecialchars(_('System Recordings'), ENT_QUOTES, 'UTF-8'); ?>">
<?php foreach ($available_system_sounds as $recording) { ?>
<option value="<?php echo htmlspecialchars($recording['value'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($recording['label'], ENT_QUOTES, 'UTF-8'); ?></option>
<?php } ?>
</optgroup>
<?php } ?>
