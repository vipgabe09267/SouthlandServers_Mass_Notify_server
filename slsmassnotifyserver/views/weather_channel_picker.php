<?php
$weatherChannelGroup = $weather_channel_group ?? [];
$weatherChannelPrefix = $weather_channel_prefix ?? 'zone';
?>
<details class="sls-source-picker" style="margin:12px 0">
    <summary><i class="fa fa-flask" aria-hidden="true"></i> <?php echo _('SMS and external calls'); ?> <span class="label label-info">Labs</span></summary>
    <div class="sls-source-picker-body">
        <p class="help-block"><?php echo _('Live alerts only. Select saved, enabled destinations. SMS requires a configured provider and recipient consent; external calls use the saved outbound route and play after answer. Manual weather tests never use these channels.'); ?></p>
        <div class="row">
        <?php foreach (['voice' => [_('External calls'), 'voice_recipient_ids', $weather_voice_recipients ?? []], 'sms' => [_('SMS'), 'sms_recipient_ids', $weather_sms_recipients ?? []]] as $kind => [$label, $field, $rows]) { ?>
            <div class="col-md-6"><strong><?php echo $label; ?></strong><div class="sls-recipient-grid" style="max-height:190px;overflow:auto;padding:8px">
                <?php $known = []; foreach ($rows as $recipient) { $id = (string)($recipient['id'] ?? ''); $known[] = $id; $selected = in_array($id, $weatherChannelGroup[$field] ?? [], true); ?>
                    <div class="checkbox"><label><input type="checkbox" data-<?php echo $weatherChannelPrefix . '-' . $kind; ?> value="<?php echo htmlspecialchars($id); ?>" <?php echo $selected ? 'checked' : ''; ?> <?php echo empty($recipient['enabled']) && !$selected ? 'disabled' : ''; ?>> <?php echo htmlspecialchars((string)($recipient['name'] ?? $id)); ?><?php if (empty($recipient['enabled'])) { ?> <span class="text-muted"><?php echo _('disabled'); ?></span><?php } ?></label></div>
                <?php } foreach (array_diff($weatherChannelGroup[$field] ?? [], $known) as $id) { ?>
                    <div class="checkbox"><label><input type="checkbox" data-<?php echo $weatherChannelPrefix . '-' . $kind; ?> value="<?php echo htmlspecialchars($id); ?>" checked> <?php echo htmlspecialchars($id); ?> <span class="text-danger"><?php echo _('removed — uncheck to clear'); ?></span></label></div>
                <?php } if (!$rows) { ?><p class="text-muted"><?php echo _('No saved destinations. Configure this channel in General Settings.'); ?></p><?php } ?>
            </div></div>
        <?php } ?>
        </div>
    </div>
</details>
