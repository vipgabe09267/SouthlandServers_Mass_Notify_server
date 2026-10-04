<?php
namespace FreePBX\modules;

/** Outbound voice settings live exclusively in the protected central config. */
trait SlsOutboundVoice
{
    private function defaultOutboundVoice()
    {
        return ['enabled' => '0', 'route_mode' => 'pbx_routes', 'trunk_id' => '', 'caller_id' => '', 'piper_voice' => '',
            'acknowledgement_required' => false, 'ack_timeout_seconds' => 10, 'daily_call_limit' => 0, 'recipients' => []];
    }

    private function validateOutboundVoice($value, $allowNewRecipients = false)
    {
        if (!is_array($value) || ($value && array_is_list($value))) {
            return [_('Outbound voice settings must be an object.')];
        }
        $errors = [];
        foreach (array_keys($value) as $key) {
            if (!array_key_exists($key, $this->defaultOutboundVoice())) {
                $errors[] = _('Outbound voice contains an unknown setting.');
            }
        }
        $value = array_replace($this->defaultOutboundVoice(), $value);
        if (!in_array($value['enabled'], ['0', '1', 0, 1, false, true], true)) {
            $errors[] = _('Outbound voice Enabled must be on or off.');
        }
        if (!in_array($value['route_mode'], ['pbx_routes', 'trunk'], true)) {
            $errors[] = _('Outbound voice routing must use existing PBX routes or an explicit trunk.');
        }
        $trunk = $value['trunk_id'];
        if (!is_string($trunk) || ($trunk !== '' && !preg_match('/^[1-9][0-9]{0,8}$/D', $trunk))) {
            $errors[] = _('Outbound voice trunk ID must be empty or a valid FreePBX trunk number.');
        } elseif (($value['route_mode'] ?? '') === 'trunk' && $trunk === '') {
            $errors[] = _('Select a trunk for explicit outbound voice routing.');
        }
        $caller = $value['caller_id'];
        if (!is_string($caller) || ($caller !== '' && !preg_match('/^\+?[1-9][0-9]{1,14}$/D', $caller))) {
            $errors[] = _('Outbound caller ID (DID) must be a phone number with at most 15 digits, optionally starting with +. Leave it empty to use PBX policy.');
        }
        $model = $value['piper_voice'];
        if (!is_string($model) || ($model !== '' && !in_array($model, array_column($this->getAvailablePiperVoices(), 'path'), true))) {
            $errors[] = _('External speech voice must use the announcement voice or a voice from the installed catalog.');
        }
        $rows = $value['recipients'];
        if (!is_bool($value['acknowledgement_required'])) {
            $errors[] = _('External keypad acknowledgement must be a boolean.');
        }
        if (!is_int($value['ack_timeout_seconds']) || $value['ack_timeout_seconds'] < 5 || $value['ack_timeout_seconds'] > 30) {
            $errors[] = _('The external acknowledgement wait must be a whole number from 5 through 30 seconds.');
        }
        if (!is_int($value['daily_call_limit']) || $value['daily_call_limit'] < 0 || $value['daily_call_limit'] > 10000) {
            $errors[] = _('The daily external call limit must be a whole number from 0 through 10000. Zero leaves this optional limit disabled.');
        }
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1000) {
            return array_merge($errors, [_('Outbound voice recipients must be a list of at most 1000 saved numbers.')]);
        }
        $ids = []; $numbers = [];
        foreach ($rows as $index => $row) {
            $prefix = sprintf(_('Outbound voice recipient %d: '), $index + 1);
            if (!is_array($row) || array_is_list($row)) { $errors[] = $prefix . _('expected an object.'); continue; }
            if (array_diff(array_keys($row), ['id', 'name', 'number', 'enabled'])) { $errors[] = $prefix . _('unknown field.'); }
            $id = array_key_exists('id', $row) ? $row['id'] : '';
            if (!is_string($id) || ($id === '' && !$allowNewRecipients) || ($id !== '' && !preg_match('/^voice_[a-f0-9]{24}$/D', $id))) {
                $errors[] = $prefix . _('invalid recipient ID.');
            } elseif ($id !== '') {
                if (isset($ids[$id])) { $errors[] = $prefix . _('duplicate recipient ID.'); }
                $ids[$id] = true;
            }
            $name = $row['name'] ?? '';
            if (!is_string($name) || trim($name) === '' || strlen($name) > 240 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                $errors[] = $prefix . _('enter a name without control characters (maximum 240 bytes).');
            }
            $number = $row['number'] ?? '';
            if (!is_string($number) || !preg_match('/^\+[1-9][0-9]{1,14}$/D', $number)) {
                $errors[] = $prefix . _('use an international number such as +15551234567, with at most 15 digits and no spaces.');
            } else {
                if (isset($numbers[$number])) { $errors[] = $prefix . _('that phone number is already saved.'); }
                $numbers[$number] = true;
            }
            if (!in_array(array_key_exists('enabled', $row) ? $row['enabled'] : '1', ['0', '1', 0, 1, false, true], true)) {
                $errors[] = $prefix . _('Enabled must be on or off.');
            }
        }
        return array_values(array_unique($errors));
    }

    private function normalizeOutboundVoice($value, $allowNewRecipients = false)
    {
        if (!is_array($value) || ($value && array_is_list($value))) {
            throw new \DomainException(_('Outbound voice settings must be an object.'));
        }
        $value = array_replace($this->defaultOutboundVoice(), $value);
        $errors = $this->validateOutboundVoice($value, $allowNewRecipients);
        if ($errors) { throw new \DomainException(implode(' ', $errors)); }
        $value['enabled'] = empty($value['enabled']) ? '0' : '1';
        foreach ($value['recipients'] as &$row) {
            $row['id'] = ($row['id'] ?? '') !== '' ? $row['id'] : 'voice_' . bin2hex(random_bytes(12));
            $row['name'] = trim($row['name']);
            $row['enabled'] = empty($row['enabled'] ?? '1') ? '0' : '1';
        }
        unset($row);
        return $value;
    }

    public function getOutboundVoiceRecipients($settings = null)
    {
        $settings = is_array($settings) ? $settings : $this->getActiveSettings();
        $voice = $this->normalizeOutboundVoice(array_key_exists('outbound_voice', $settings) ? $settings['outbound_voice'] : []);
        return empty($voice['enabled']) ? [] : array_values(array_filter($voice['recipients'], static function ($row) { return !empty($row['enabled']); }));
    }

    public function getOutboundVoiceTrunks()
    {
        try {
            // Read only public routing fields; never retrieve provider secrets.
            $statement = $this->FreePBX->Database()->query('SELECT trunkid, name, tech, disabled FROM trunks ORDER BY trunkid LIMIT 1000');
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
            return array_map(static function ($row) {
                return ['id' => (string)$row['trunkid'], 'name' => (string)$row['name'],
                    'tech' => strtoupper((string)$row['tech']), 'enabled' => !in_array((string)$row['disabled'], ['on', '1'], true)];
            }, $rows);
        } catch (\Throwable $error) { return []; }
    }

    public function getOutboundVoiceDids()
    {
        try {
            $statement = $this->FreePBX->Database()->query('SELECT DISTINCT extension FROM incoming ORDER BY extension LIMIT 1000');
            return array_values(array_filter($statement->fetchAll(\PDO::FETCH_COLUMN), static function ($value) {
                return is_string($value) && preg_match('/^\+?[1-9][0-9]{1,14}$/D', $value);
            }));
        } catch (\Throwable $error) { return []; }
    }

    private function outboundVoiceTargets(array $settings)
    {
        $voice = $this->normalizeOutboundVoice(array_key_exists('outbound_voice', $settings) ? $settings['outbound_voice'] : []);
        $targets = [];
        foreach ($this->getOutboundVoiceRecipients($settings) as $row) {
            $targets[$row['id']] = ['id' => $row['id'], 'number' => $row['number'],
                'route_mode' => $voice['route_mode'], 'trunk_id' => $voice['route_mode'] === 'trunk' ? $voice['trunk_id'] : '',
                'caller_id' => $voice['caller_id'], 'acknowledgement_required' => $voice['acknowledgement_required'],
                'ack_timeout_seconds' => $voice['ack_timeout_seconds']];
        }
        return $targets;
    }

    private function outboundVoiceFingerprint(array $target)
    {
        return hash('sha256', implode("\0", array_map(static function ($key) use ($target) { return (string)($target[$key] ?? ''); },
            ['id', 'number', 'route_mode', 'trunk_id', 'caller_id'])));
    }

    private function validateVoiceRecipientIds($value)
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 1000) {
            return [_('External voice recipients must be a list of at most 1000 saved recipient IDs.')];
        }
        foreach ($value as $id) {
            if (!is_string($id) || !preg_match('/^voice_[a-f0-9]{24}$/D', $id)) {
                return [_('An external voice recipient ID is invalid. Select saved recipients from General Settings.')];
            }
        }
        return [];
    }

    private function readOutboundVoiceForm(array $input, array $current)
    {
        if (empty($input['outbound_voice_present'])) { return $current; }
        $json = $input['outbound_voice_recipients_json'] ?? '';
        $rows = is_string($json) && strlen($json) <= 512 * 1024 ? json_decode($json, true, 8) : null;
        if (($input['outbound_voice_complete'] ?? '') !== '1' || !is_array($rows) || !array_is_list($rows)) {
            throw new \DomainException(_('The outbound voice form is incomplete. Enable JavaScript and reload before saving; no settings were changed.'));
        }
        $policy = array_intersect_key(array_replace($this->defaultOutboundVoice(), $current), array_flip([
            'acknowledgement_required', 'ack_timeout_seconds', 'daily_call_limit']));
        if (($input['outbound_voice_policy_present'] ?? '') === '1') {
            $policy['acknowledgement_required'] = ($input['outbound_voice_acknowledgement_required'] ?? '') === '1';
            foreach (['ack_timeout_seconds'=>[5,30], 'daily_call_limit'=>[0,10000]] as $key=>$range) {
                $value = $input['outbound_voice_' . $key] ?? null;
                if ($value === null || filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$range[0], 'max_range'=>$range[1]]]) === false) {
                    throw new \DomainException(sprintf(_('External voice %s must be a whole number from %d through %d. Reload incomplete forms before saving.'), $key, $range[0], $range[1]));
                }
                $policy[$key] = (int)$value;
            }
        }
        return $this->normalizeOutboundVoice(['enabled' => empty($input['outbound_voice_enabled']) ? '0' : '1',
            'route_mode' => $input['outbound_voice_route_mode'] ?? 'pbx_routes', 'trunk_id' => $input['outbound_voice_trunk_id'] ?? '',
            'caller_id' => $input['outbound_voice_caller_id'] ?? '',
            'piper_voice' => $input['outbound_voice_piper_voice'] ?? ($current['piper_voice'] ?? ''), 'recipients' => $rows] + $policy, true);
    }
}
