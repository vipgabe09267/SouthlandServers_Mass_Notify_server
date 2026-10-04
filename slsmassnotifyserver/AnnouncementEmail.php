<?php
namespace FreePBX\modules;

/** Saved announcement email destinations belong only to the central config. */
trait SlsAnnouncementEmail
{
    private function defaultAnnouncementEmail()
    {
        return ['enabled' => '0', 'recipients' => []];
    }

    private function validAnnouncementEmailAddress($value)
    {
        if (!is_string($value) || strlen($value) > 254 || $value !== trim($value) ||
            !preg_match('/^[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}$/D', $value)) { return false; }
        [$local, $domain] = explode('@', $value, 2);
        return strlen($local) <= 64 && $local[0] !== '.' && substr($local, -1) !== '.' && strpos($local, '..') === false &&
            $this->normalizeEmailSenderDomain($domain) !== '';
    }

    private function validateAnnouncementEmail($value, $allowNewRecipients = false)
    {
        if (!is_array($value) || ($value && array_is_list($value))) { return [_('Announcement email settings must be an object.')]; }
        $errors = [];
        if (array_diff(array_keys($value), ['enabled', 'recipients'])) { $errors[] = _('Announcement email contains an unknown setting.'); }
        $value = array_replace($this->defaultAnnouncementEmail(), $value);
        if (!in_array($value['enabled'], ['0', '1', 0, 1, false, true], true)) { $errors[] = _('Announcement email Enabled must be on or off.'); }
        if (!is_array($value['recipients']) || !array_is_list($value['recipients']) || count($value['recipients']) > 50) {
            return array_merge($errors, [_('Announcement email supports a list of at most 50 saved recipients.')]);
        }
        $ids = []; $addresses = [];
        foreach ($value['recipients'] as $index => $row) {
            $prefix = sprintf(_('Announcement email recipient %d: '), $index + 1);
            if (!is_array($row) || array_is_list($row)) { $errors[] = $prefix . _('expected an object.'); continue; }
            if (array_diff(array_keys($row), ['id', 'name', 'address', 'enabled'])) { $errors[] = $prefix . _('unknown field.'); }
            $id = $row['id'] ?? '';
            if (!is_string($id) || ($id === '' && !$allowNewRecipients) || ($id !== '' && !preg_match('/^email_[a-f0-9]{24}$/D', $id))) {
                $errors[] = $prefix . _('invalid saved recipient ID.');
            } elseif ($id !== '') {
                if (isset($ids[$id])) { $errors[] = $prefix . _('duplicate recipient ID.'); }
                $ids[$id] = true;
            }
            $name = $row['name'] ?? null;
            if (!is_string($name) || trim($name) === '' || strlen($name) > 240 || !preg_match('//u', $name) || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                $errors[] = $prefix . _('enter a name without control characters (maximum 240 UTF-8 bytes).');
            }
            $address = $row['address'] ?? null;
            if (!$this->validAnnouncementEmailAddress($address)) { $errors[] = $prefix . _('enter one valid email address without spaces or a display name (maximum 254 characters).'); }
            else {
                $key = strtolower($address);
                if (isset($addresses[$key])) { $errors[] = $prefix . _('that email address is already saved.'); }
                $addresses[$key] = true;
            }
            if (!in_array($row['enabled'] ?? '1', ['0', '1', 0, 1, false, true], true) || (array_key_exists('enabled', $row) && $row['enabled'] === null)) {
                $errors[] = $prefix . _('Enabled must be on or off.');
            }
            if (array_key_exists('id', $row) && $row['id'] === null) { $errors[] = $prefix . _('invalid saved recipient ID.'); }
        }
        return array_values(array_unique($errors));
    }

    private function normalizeAnnouncementEmail($value, $allowNewRecipients = false)
    {
        $errors = $this->validateAnnouncementEmail($value, $allowNewRecipients);
        if ($errors) { throw new \DomainException(implode(' ', $errors)); }
        $value = array_replace($this->defaultAnnouncementEmail(), $value);
        $value['enabled'] = empty($value['enabled']) ? '0' : '1';
        foreach ($value['recipients'] as &$row) {
            $row['id'] = ($row['id'] ?? '') !== '' ? $row['id'] : 'email_' . bin2hex(random_bytes(12));
            $row['name'] = trim($row['name']);
            $row['enabled'] = empty($row['enabled'] ?? '1') ? '0' : '1';
        }
        unset($row);
        return $value;
    }

    public function getAnnouncementEmailRecipients($settings = null)
    {
        $settings = is_array($settings) ? $settings : $this->getActiveSettings();
        $email = $this->normalizeAnnouncementEmail(array_key_exists('announcement_email', $settings) ? $settings['announcement_email'] : []);
        return empty($email['enabled']) ? [] : array_values(array_filter($email['recipients'], static function ($row) { return !empty($row['enabled']); }));
    }

    private function announcementEmailTargets(array $settings)
    {
        $recipients = $this->getAnnouncementEmailRecipients($settings);
        if (!$recipients) { return []; }
        $name = $settings['mail_from_name'] ?? 'SLS Mass Notification System';
        if (!is_string($name) || trim($name) === '' || strlen($name) > 320 || !preg_match('//u', $name) || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new \DomainException(_('Configure a valid email sender name before sending announcements.'));
        }
        if (array_key_exists('mail_from_domain', $settings)) {
            if (!is_string($settings['mail_from_domain']) || !is_string($settings['mail_from_local_part'] ?? 'no-reply')) {
                throw new \DomainException(_('The configured email sender domain or local part has an invalid type.'));
            }
            $domain = $this->normalizeEmailSenderDomain($settings['mail_from_domain']);
            $local = $this->normalizeEmailSenderLocalPart($settings['mail_from_local_part'] ?? 'no-reply');
            $address = $local . '@' . $domain;
            if ($domain === '' || $local === '') { throw new \DomainException(_('Configure a valid email sender address before sending announcements.')); }
        } else { $address = $settings['mail_from_addr'] ?? ''; }
        if (!$this->validAnnouncementEmailAddress($address)) { throw new \DomainException(_('Configure a valid email sender address before sending announcements.')); }
        $targets = [];
        foreach ($recipients as $row) { $targets[$row['id']] = ['id' => $row['id'], 'address' => $row['address'], 'sender' => ['name' => $name, 'address' => $address]]; }
        return $targets;
    }

    private function announcementEmailFingerprint(array $target)
    {
        return hash('sha256', json_encode([$target['id'], $target['address'], $target['sender']['name'], $target['sender']['address']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function validateAnnouncementEmailRecipientIds($value)
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) { return [_('Email recipients must be a list of at most 50 saved recipient IDs.')]; }
        $seen = [];
        foreach ($value as $id) {
            if (!is_string($id) || !preg_match('/^email_[a-f0-9]{24}$/D', $id) || isset($seen[$id])) { return [_('Select distinct saved email recipients from General Settings.')]; }
            $seen[$id] = true;
        }
        return [];
    }

    private function readAnnouncementEmailForm(array $input, array $current)
    {
        if (!array_key_exists('announcement_email_present', $input)) { return $current; }
        $json = $input['announcement_email_recipients_json'] ?? null;
        if (($input['announcement_email_present'] ?? '') !== '1' || ($input['announcement_email_complete'] ?? '') !== '1' || !is_string($json) || strlen($json) > 65536) {
            throw new \DomainException(_('The announcement email editor is incomplete or too large. Reload with JavaScript enabled; no settings were changed.'));
        }
        try { $rows = json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new \DomainException(_('The announcement email recipient list is invalid. Reload before saving.')); }
        return $this->normalizeAnnouncementEmail(['enabled' => $input['announcement_email_enabled'] ?? '0', 'recipients' => $rows], true);
    }
}
