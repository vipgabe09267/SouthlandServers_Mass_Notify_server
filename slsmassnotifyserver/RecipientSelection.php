<?php
namespace SLS\MassNotify;

final class RecipientSelection
{
    public const FIELDS = [
        'send_announcement' => ['announcement_extensions', 'announcement_groups', 'announcement_desktop_clients', 'announcement_webhooks', 'voice_recipient_ids', 'announcement_email_recipient_ids', 'announcement_sms_recipient_ids'],
        'save_announcement_group' => ['group_extensions', 'group_desktop_clients', 'group_voice_recipient_ids', 'group_email_recipient_ids', 'group_sms_recipient_ids', 'group_webhook_ids'],
        'save_scheduled_announcement' => ['schedule_extensions', 'schedule_groups', 'schedule_desktop_clients', 'schedule_voice_recipient_ids', 'schedule_email_recipient_ids', 'schedule_sms_recipient_ids', 'schedule_webhook_ids'],
    ];

    public static function decode(array $input): array
    {
        $action = $input['slsmassnotifyserver_action'] ?? '';
        if (!is_string($action) || !isset(self::FIELDS[$action]) || !isset($input['sls_recipient_selection_present'])) { return $input; }
        $raw = $input['sls_recipient_selection_json'] ?? null;
        if (($input['sls_recipient_selection_complete'] ?? '') !== '1' || !is_string($raw) || strlen($raw) > 512 * 1024) {
            throw new \DomainException('The recipient form is incomplete or too large. Enable JavaScript and reload before submitting; no notification or configuration change was made.');
        }
        $value = json_decode($raw, true, 8);
        $fields = self::FIELDS[$action];
        if (is_array($value)) { foreach ($fields as $field) { if ((str_ends_with($field,'_sms_recipient_ids') || str_ends_with($field, '_webhook_ids')) && !array_key_exists($field,$value)) { $value[$field]=[]; } } }
        if (!is_array($value) || array_diff(array_keys($value), $fields) || array_diff($fields, array_keys($value))) {
            throw new \DomainException('The recipient selection is invalid or missing fields. Reload the page and select the recipients again.');
        }
        foreach ($fields as $field) {
            $items = $value[$field];
            $emailField = in_array($field, ['announcement_email_recipient_ids', 'group_email_recipient_ids', 'schedule_email_recipient_ids'], true);
            if ($emailField && (!is_array($items) || count($items) > 50)) { throw new \DomainException('Select at most 50 saved email recipients.'); }
            if (!is_array($items) || !array_is_list($items) || count($items) > 1000) {
                throw new \DomainException('A recipient list is invalid or exceeds 1000 entries. Use a saved group or reduce the selection.');
            }
            $smsField = str_ends_with($field,'_sms_recipient_ids');
            if ($smsField && count($items)>50) { throw new \DomainException('Select at most 50 saved SMS recipients.'); }
            foreach ($items as $item) {
                if ($smsField && (!is_string($item) || !preg_match('/^sms_[a-f0-9]{24}$/D',$item))) { throw new \DomainException('Select saved SMS recipients from General Settings.'); }
                if ($emailField && (!is_string($item) || !preg_match('/^email_[a-f0-9]{24}$/D', $item))) { throw new \DomainException('An email recipient ID is invalid. Select saved email recipients from General Settings.'); }
                if (!is_string($item) || $item === '' || strlen($item) > 100 || preg_match('/[\x00-\x20\x7f]/', $item)) {
                    throw new \DomainException('A recipient identifier is invalid. Reload the page and select saved recipients.');
                }
            }
            $input[$field] = array_values(array_unique($items));
        }
        return $input;
    }
}
