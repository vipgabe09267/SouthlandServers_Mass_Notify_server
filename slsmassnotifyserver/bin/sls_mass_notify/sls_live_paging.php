<?php
declare(strict_types=1);

namespace SLS\MassNotify;

require_once __DIR__ . '/sls_config_crypto.php';
require_once __DIR__ . '/sls_runtime_state.php';

/** Bootstrap-independent live paging configuration and call controller. */
final class LivePagingConfig
{
    public static function defaults(): array
    {
        return ['enabled' => '0', 'extension' => '', 'allowed_callers' => [],
            'menu_prompt' => 'Select a paging group. Enter its number followed by the pound key.',
            'pin_prompt' => 'Please enter the paging PIN, followed by the pound key.',
            'max_duration_seconds' => 300, 'groups' => [], 'external_ivr_ids' => []];
    }

    public static function generatePin(int $length): string
    {
        if ($length < 4 || $length > 8) { throw new \InvalidArgumentException('Paging PIN length must be between 4 and 8 digits.'); }
        $pin = '';
        for ($i = 0; $i < $length; $i++) { $pin .= (string)random_int(0, 9); }
        return $pin;
    }

    public static function flag($value): string
    {
        if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            throw new \InvalidArgumentException('Paging checkbox values must be enabled or disabled.');
        }
        return in_array($value, [true, 1, '1'], true) ? '1' : '0';
    }

    public static function normalize(array $value): array
    {
        $config = array_replace(self::defaults(), $value);
        if (array_diff(array_keys($config), array_merge(array_keys(self::defaults()), ['external_access']))) {
            throw new \InvalidArgumentException('Unknown live paging configuration field.');
        }
        $config['enabled'] = self::flag($config['enabled']);
        if (!is_array($config['external_ivr_ids']) || !array_is_list($config['external_ivr_ids']) || count($config['external_ivr_ids']) > 100) {
            throw new \InvalidArgumentException('Select at most 100 existing IVRs for external paging.');
        }
        foreach ($config['external_ivr_ids'] as &$id) {
            if ((!is_string($id) && !is_int($id)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$id)) {
                throw new \InvalidArgumentException('The external paging IVR selection is invalid.');
            }
            $id = (string)$id;
        } unset($id);
        $config['external_ivr_ids'] = array_values(array_unique($config['external_ivr_ids']));
        if (array_key_exists('external_access', $config)) { $config['external_access'] = self::flag($config['external_access']); }
        foreach (['extension', 'menu_prompt', 'pin_prompt'] as $field) {
            if (!is_string($config[$field])) { throw new \InvalidArgumentException('Paging text fields must contain text.'); }
            $config[$field] = trim($config[$field]);
        }
        if ($config['extension'] !== '' && !preg_match('/^[0-9]{1,20}$/D', $config['extension'])) {
            throw new \InvalidArgumentException('The paging extension must contain 1–20 digits.');
        }
        foreach (['menu_prompt', 'pin_prompt'] as $field) {
            if ($config[$field] === '' || strlen($config[$field]) > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $config[$field])) {
                throw new \InvalidArgumentException('Paging prompts must contain 1–1,000 characters without control characters.');
            }
        }
        if (!is_int($config['max_duration_seconds']) || $config['max_duration_seconds'] < 30 || $config['max_duration_seconds'] > 1800) {
            throw new \InvalidArgumentException('Maximum live paging duration must be between 30 and 1,800 seconds.');
        }
        $config['allowed_callers'] = self::extensionList($config['allowed_callers'], false, 'Authorized paging callers');
        if (!is_array($config['groups']) || !array_is_list($config['groups']) || count($config['groups']) > 10) {
            throw new \InvalidArgumentException('Select no more than 10 live paging groups.');
        }
        $ids = []; $numbers = [];
        foreach ($config['groups'] as &$row) {
            if (!is_array($row) || array_diff(array_keys($row), ['group_id', 'menu_number', 'require_pin', 'pin_length', 'pin_hash',
                'name', 'extensions', 'notify_extensions', 'allowed_callers', 'text_message', 'allow_external', 'external_callers'])) {
                throw new \InvalidArgumentException('Unknown paging group configuration field.');
            }
            $row = array_replace(['require_pin' => '1', 'pin_length' => 4, 'pin_hash' => ''], $row);
            if (!is_string($row['group_id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $row['group_id'])) {
                throw new \InvalidArgumentException('A paging group must have a valid saved identifier.');
            }
            if (!is_int($row['menu_number'] ?? null) || $row['menu_number'] < 1 || $row['menu_number'] > 10) {
                throw new \InvalidArgumentException('Each paging menu number must be between 1 and 10.');
            }
            if (isset($ids[$row['group_id']]) || isset($numbers[$row['menu_number']])) {
                throw new \InvalidArgumentException('Paging groups and menu numbers must be unique.');
            }
            $ids[$row['group_id']] = true; $numbers[$row['menu_number']] = true;
            $row['require_pin'] = self::flag($row['require_pin']);
            if (array_key_exists('allow_external', $row)) { $row['allow_external'] = self::flag($row['allow_external']); }
            if (array_key_exists('external_callers', $row)) { $row['external_callers'] = self::externalCallers($row['external_callers']); }
            if (!is_int($row['pin_length']) || $row['pin_length'] < 4 || $row['pin_length'] > 8) {
                throw new \InvalidArgumentException('Paging PIN length must be between 4 and 8 digits.');
            }
            if (!is_string($row['pin_hash']) || strlen($row['pin_hash']) > 255
                || ($row['pin_hash'] !== '' && (password_get_info($row['pin_hash'])['algoName'] ?? 'unknown') === 'unknown')) {
                throw new \InvalidArgumentException('Stored paging PIN verification data is invalid. Randomize the group PIN.');
            }
            if ($config['enabled'] === '1' && $row['require_pin'] === '1' && $row['pin_hash'] === '') {
                throw new \InvalidArgumentException('Every paging group that requires a PIN must have a saved PIN.');
            }
            if (($row['allow_external'] ?? '0') === '1' && $row['pin_hash'] === '') {
                throw new \InvalidArgumentException('External paging always requires a saved group PIN, including when internal callers do not require one.');
            }
            if (($row['allow_external'] ?? '0') === '1' && empty($row['external_callers'])) {
                throw new \InvalidArgumentException('Add at least one approved external caller number before allowing external access to this group. An empty allowlist does not allow everyone.');
            }
            if (self::isStandalone($row)) {
                $row['name'] = self::groupName($row['name'] ?? null);
                $row['extensions'] = self::extensionList($row['extensions'] ?? null, true, 'Paging audio recipients');
                $row['notify_extensions'] = self::extensionList($row['notify_extensions'] ?? [], false, 'Paging text recipients');
                $row['allowed_callers'] = self::extensionList($row['allowed_callers'] ?? null, true, 'Authorized group callers');
                $message = $row['text_message'] ?? 'Live page in progress.';
                if (!is_string($message) || trim($message) === '' || strlen($message) > 1000
                    || preg_match('/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f]/', $message)) {
                    throw new \InvalidArgumentException('Paging text messages must contain 1–1,000 bytes without control characters.');
                }
                $row['text_message'] = trim($message);
            }
        }
        unset($row);
        usort($config['groups'], static function (array $a, array $b): int { return $a['menu_number'] <=> $b['menu_number']; });
        if ($config['enabled'] === '1' && ($config['extension'] === '' || !self::callerExtensions($config) || !$config['groups'])) {
            throw new \InvalidArgumentException('Choose a paging extension, authorized callers, and at least one paging group before enabling live paging.');
        }
        return $config;
    }

    /** Persist complete international numbers; never prefix, wildcard or suffix matches. */
    public static function externalCallers($value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new \InvalidArgumentException('Approved external callers must be a list of at most 100 phone numbers per group.');
        }
        $numbers = [];
        foreach ($value as $number) {
            if (!is_string($number) || strlen($number) > 40 || preg_match('/[^+0-9 ().-]/D', $number)) {
                throw new \InvalidArgumentException('Approved external caller numbers may contain digits, a leading +, spaces, parentheses, periods and hyphens only.');
            }
            $number = str_replace([' ', '(', ')', '.', '-'], '', $number);
            if (!preg_match('/^\+[1-9][0-9]{6,14}$/D', $number)) {
                throw new \InvalidArgumentException('Enter each approved caller in international format, such as +15125550123, including + and the country code (7–15 digits).');
            }
            if (isset($numbers[$number])) { throw new \InvalidArgumentException('An approved external caller number is listed more than once. Remove the duplicate.'); }
            $numbers[$number] = true;
        }
        $numbers = array_keys($numbers); sort($numbers, SORT_STRING);
        return $numbers;
    }

    /** Match the carrier's presented number to one explicit saved number. */
    public static function matchExternalCaller(string $presented, array $approved): string
    {
        if (strlen($presented) > 40 || preg_match('/[^+0-9 ().-]/D', $presented)) { return ''; }
        $number = str_replace([' ', '(', ')', '.', '-'], '', $presented);
        if (!preg_match('/^\+?[0-9]{7,17}$/D', $number)) { return ''; }
        if (str_starts_with($number, '+')) { $candidates = [$number]; }
        elseif (str_starts_with($number, '00')) { $candidates = ['+' . substr($number, 2)]; }
        else {
            $candidates = ['+' . $number];
            // Only an explicitly saved +1 number may also match a complete
            // ten-digit NANP presentation. No last-N-digits matching.
            if (preg_match('/^[2-9][0-9]{2}[2-9][0-9]{6}$/D', $number)) { $candidates[] = '+1' . $number; }
        }
        foreach ($candidates as $candidate) {
            if (preg_match('/^\+[1-9][0-9]{6,14}$/D', $candidate) && in_array($candidate, $approved, true)) { return $candidate; }
        }
        return '';
    }

    public static function isStandalone(array $row): bool
    {
        return (bool)array_intersect(['name', 'extensions', 'notify_extensions', 'allowed_callers', 'text_message'], array_keys($row));
    }

    private static function groupName($value): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 64 || preg_match('/[\\x00-\\x1f\\x7f]/', $value)) {
            throw new \InvalidArgumentException('Paging group names must contain 1–64 bytes without control characters.');
        }
        return trim($value);
    }

    private static function extensionList($value, bool $required, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 1000 || ($required && !$value)) {
            throw new \InvalidArgumentException($label . ($required ? ' must contain 1–1,000 extension numbers.' : ' must contain no more than 1,000 extension numbers.'));
        }
        foreach ($value as $extension) {
            if (!is_string($extension) || !preg_match('/^[0-9]{1,20}$/D', $extension)) {
                throw new \InvalidArgumentException($label . ' must contain numeric extension numbers.');
            }
        }
        return array_values(array_unique($value));
    }

    /** Global callers authorize legacy references only. New groups own their callers. */
    public static function callerExtensions(array $config): array
    {
        $callers = [];
        foreach ($config['groups'] as $row) {
            $callers = array_merge($callers, self::isStandalone($row) ? $row['allowed_callers'] : $config['allowed_callers']);
        }
        return array_values(array_unique($callers));
    }

    public static function resolvedGroups(array $config, array $groups): array
    {
        $available = [];
        foreach ($groups as $group) {
            if (is_array($group) && is_string($group['id'] ?? null)) {
                if (isset($available[$group['id']])) { throw new \InvalidArgumentException('Announcement group identifiers must be unique.'); }
                $available[$group['id']] = $group;
            }
        }
        $resolved = [];
        foreach ($config['groups'] as $row) {
            if (self::isStandalone($row)) {
                $resolved[(string)$row['menu_number']] = $row;
                continue;
            }
            $group = $available[$row['group_id']] ?? null;
            if (!is_array($group)) { throw new \InvalidArgumentException('A selected legacy paging group no longer exists. Update the paging menu.'); }
            $resolved[(string)$row['menu_number']] = $row + [
                'name' => self::groupName($group['name'] ?? null),
                'extensions' => self::extensionList($group['extensions'] ?? [], true, 'Legacy paging audio recipients'),
                'notify_extensions' => [], 'allowed_callers' => $config['allowed_callers'], 'text_message' => 'Live page in progress.'];
        }
        return $resolved;
    }

    public static function promptTexts(array $config, array $groups, array $automations = []): array
    {
        $menu = $config['menu_prompt'];
        foreach (self::resolvedGroups($config, $groups) as $row) {
            $menu .= ' For ' . $row['name'] . ', press ' . $row['menu_number'] . ', then pound.';
        }
        $texts=['menu' => $menu, 'pin' => $config['pin_prompt'],
            'ready' => 'Paging will begin after the tone. Speak clearly, then hang up when finished.',
            'partial' => 'Some phones in this group are unavailable. Paging will continue to the reachable phones.',
            'text_failure' => 'The paging text notification could not be confirmed. Audio paging will continue.',
            'invalid' => 'That selection or PIN was not accepted.',
            'busy' => 'The selected paging group is busy. Please hang up and try again later.',
            'unavailable' => 'Paging is unavailable. Please contact your PBX administrator.',
            'denied' => 'You are not authorized to perform that function.'];
        if (($config['external_access'] ?? '0') === '1') {
            $texts['external_menu'] = $config['menu_prompt'];
            foreach (self::resolvedGroups($config, $groups) as $row) {
                if (($row['allow_external'] ?? '0') === '1') { $texts['external_group_' . $row['menu_number']] = 'For ' . $row['name'] . ', press ' . $row['menu_number'] . ', then pound.'; }
            }
        }
        foreach ($automations['rules'] ?? [] as $rule) {
            if (($rule['kind'] ?? '')==='panic' && ($rule['enabled'] ?? false)===true && ($rule['dial_extension'] ?? '')!=='') {
                if (!preg_match('/^trg_[a-f0-9]{24}$/D',$rule['id'] ?? '') || !is_string($rule['confirmation_prompt'] ?? null) || strlen($rule['confirmation_prompt'])>1200) { throw new \InvalidArgumentException('Panic confirmation prompt is invalid.'); }
                $texts['panic_'.$rule['id']]=$rule['confirmation_prompt'];
                $texts['panic_queued']='The alert has been queued. Delivery has not yet been confirmed.';
                $texts['panic_failed']='The alert could not be confirmed. Contact your designated responder using another method.';
            }
        }
        return $texts;
    }

    public static function promptFiles(array $settings): array
    {
        $config = self::normalize((array)($settings['live_paging'] ?? []));
        $files = [];
        foreach (self::promptTexts($config, (array)($settings['announcement_groups'] ?? []), (array)($settings['automations'] ?? [])) as $key => $text) {
            $identity = json_encode([$text, (string)($settings['announcement_piper_voice'] ?? ''), (int)($settings['announcement_tts_volume'] ?? 25), 1], JSON_UNESCAPED_SLASHES);
            $files[$key] = 'SLS_Mass_Notifications_Plugin/paging/' . hash('sha256', (string)$identity);
        }
        return $files;
    }
}

/** Protocol traffic stays on the AGI pipe; PINs are never written to module logs. */
class LivePagingAgi
{
    private $input;
    private $output;
    private $buffer = '';
    private $admissionFailure = '';
    private bool $returnedToIvr = false;
    protected bool $externalIngress = false;
    protected string $externalCallerNumber = '';

    public function setExternalIngress(bool $external, string $number = ''): void
    {
        $this->externalIngress = $external;
        $this->externalCallerNumber = $external ? $number : '';
    }

    public function __construct($input, $output) { $this->input = $input; $this->output = $output; }

    private function line(int $timeout): string
    {
        $deadline = microtime(true) + $timeout;
        while (strpos($this->buffer, "\n") === false) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || strlen($this->buffer) > 65536) { throw new \RuntimeException('agi_timeout'); }
            $read = [$this->input]; $write = null; $except = null;
            $ready = @stream_select($read, $write, $except, (int)$remaining, (int)(($remaining - floor($remaining)) * 1000000));
            if ($ready === false || $ready === 0) { throw new \RuntimeException('agi_timeout'); }
            $chunk = fread($this->input, 8192);
            if ($chunk === false || $chunk === '') { throw new \RuntimeException('agi_hangup'); }
            $this->buffer .= $chunk;
        }
        [$line, $this->buffer] = explode("\n", $this->buffer, 2);
        return rtrim($line, "\r");
    }

    public function environment(): array
    {
        $environment = [];
        for ($i = 0; $i < 64; $i++) {
            $line = $this->line(5);
            if ($line === '') { return $environment; }
            if (strpos($line, ': ') === false) { throw new \RuntimeException('invalid_agi_environment'); }
            [$key, $value] = explode(': ', $line, 2);
            $environment[$key] = $value;
        }
        throw new \RuntimeException('invalid_agi_environment');
    }

    protected function command(string $command, int $timeout = 5): string
    {
        if (preg_match('/[\r\n\x00]/', $command)) { throw new \RuntimeException('invalid_agi_command'); }
        if (fwrite($this->output, $command . "\n") !== strlen($command) + 1 || !fflush($this->output)) { throw new \RuntimeException('agi_write_failed'); }
        $response = $this->line($timeout);
        if (!preg_match('/^200 result=/', $response)) { throw new \RuntimeException('agi_command_failed'); }
        return $response;
    }

    private static function quote(string $value): string { return '"' . addcslashes($value, '\\"') . '"'; }

    public function variable(string $expression): string
    {
        $result = $this->command('GET FULL VARIABLE ' . self::quote($expression));
        return preg_match('/^200 result=1 \((.*)\)$/D', $result, $matches) ? $matches[1] : '';
    }

    public function answer(): void
    {
        if (strpos($this->command('ANSWER'), '200 result=-1') === 0) { throw new \RuntimeException('agi_hangup'); }
        // Let the controller release recipient leases after normal hangup.
        $this->command('SET VARIABLE AGISIGHUP "no"');
    }
    public function hangup(): void { $this->command('HANGUP'); }
    public function hasReturnedToIvr(): bool { return $this->returnedToIvr; }
    public function returnToIvr(string $context): bool
    {
        // The session also checks this context against the selected IVRs.
        // Never accept a caller-supplied extension, priority or application.
        if (!preg_match('/^ivr-[1-9][0-9]{0,9}$/D', $context)
            || $this->variable('${DIALPLAN_EXISTS(' . $context . ',s,1)}') !== '1') { return false; }
        $response = $this->command('EXEC Goto ' . self::quote($context . ',s,1'));
        $this->returnedToIvr = preg_match('/^200 result=0(?:\s.*)?$/D', $response) === 1;
        return $this->returnedToIvr;
    }
    public function autoHangup(int $seconds): void { $this->command('SET AUTOHANGUP ' . $seconds); }
    public function play(string $file): void
    {
        if (strpos($this->command('STREAM FILE ' . self::quote($file) . ' ""', 300), '200 result=-1') === 0) {
            throw new \RuntimeException('agi_hangup');
        }
    }
    public function digits(string $file, int $maximum): string
    {
        $result = $this->command('GET DATA ' . self::quote($file) . ' 10000 ' . $maximum, 300);
        if (!preg_match('/^200 result=([0-9]*)(?: \(timeout\))?$/D', $result, $matches)) {
            throw new \RuntimeException('agi_hangup');
        }
        return $matches[1];
    }

    public function menu(array $files): string
    {
        if (!array_is_list($files) || !$files || count($files) > 11) { throw new \RuntimeException('paging_prompt_unavailable'); }
        foreach ($files as $file) {
            if (!is_string($file) || !preg_match('#^SLS_Mass_Notifications_Plugin/paging/[a-f0-9]{64}$#D', $file)) { throw new \RuntimeException('paging_prompt_unavailable'); }
        }
        // Read supports a file sequence and DTMF during playback. Only prepared
        // local prompts for this caller's permitted groups enter the sequence.
        $this->command('SET VARIABLE SLS_PAGING_MENU ""');
        $result = $this->command('EXEC Read ' . self::quote('SLS_PAGING_MENU,' . implode('&', $files) . ',3,,1,10'), 300);
        $status = $this->variable('${READSTATUS}');
        if (str_starts_with($result, '200 result=-1') || $status === 'HANGUP') { throw new \RuntimeException('agi_hangup'); }
        if (!in_array($status, ['OK', 'TIMEOUT'], true)) { throw new \RuntimeException('agi_command_failed'); }
        $digits = $this->variable('${SLS_PAGING_MENU}');
        return preg_match('/^[0-9]{0,3}$/D', $digits) ? $digits : '';
    }

    public function page(string $dial, int $answerTimeout, int $duration): void
    {
        $this->command('EXEC Page ' . self::quote($dial . ',b(sls-live-paging-autoanswer^s^1)i,' . $answerTimeout), $duration + 15);
    }

    public function notifyGroup(array $group, string $caller, array $settings): array
    {
        $targets = array_values(array_diff((array)($group['notify_extensions'] ?? []), [$caller]));
        return ['status' => $targets ? 'failed' : 'not_requested', 'requested' => count($targets)];
    }
    public function finishGroupNotification(): array { return ['status' => 'not_requested', 'requested' => 0]; }

    public function admissionFailure(): string { return $this->admissionFailure; }

    protected function rejectAdmission($result): array
    {
        $code = is_array($result) ? ($result['failure_code'] ?? '') : '';
        $known = ['phone_live_origin_invalid', 'phone_live_group_invalid', 'phone_live_audience_changed',
            'phone_audience_exceeds_limit', 'phone_capacity_busy', 'phone_recipient_busy',
            'phone_event_collector_unavailable', 'phone_no_registered_contacts', 'phone_channel_inventory_unavailable',
            'phone_state_commit_uncertain', 'phone_ticket_expired_or_used', 'phone_ticket_generation_changed'];
        $this->admissionFailure = is_string($code) && in_array($code, $known, true) ? $code : '';
        return [];
    }

    public function admitPhones(array $recipients, string $caller, string $groupId = ''): array
    {
        $this->admissionFailure = '';
        $request = json_encode(['internal' => $recipients, 'service' => 'live', 'live_origin' => $caller, 'live_group' => $groupId,
            'live_external' => $this->externalIngress, 'live_caller_number' => $this->externalCallerNumber, 'wait_seconds' => 0]);
        $pipes = [];
        $process = proc_open(['/usr/bin/timeout', '--kill-after=2', '40', '/usr/bin/python3', '-I',
            '/usr/local/bin/sls_mass_notify/sls_phone_admission.py', '--request-stdin'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { return []; }
        $sent = fwrite($pipes[0], $request); fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1], 131073); fclose($pipes[1]);
        if (strlen((string)$raw) > 131072) { proc_terminate($process); }
        $exit = proc_close($process);
        $result = json_decode((string)$raw, true);
        if ($sent !== strlen($request) || $exit !== 0 || !is_array($result) || empty($result['ok'])
            || !preg_match('/^[a-f0-9]{32}$/D', (string)($result['token'] ?? ''))
            || !is_string($result['live_dial'] ?? null) || strlen($result['live_dial']) > 32768
            || !is_array($result['unavailable'] ?? null)) { return $this->rejectAdmission($result); }
        $this->command('SET VARIABLE __SLS_PHONE_TOKEN ' . self::quote($result['token']));
        $this->command('SET VARIABLE __SLS_PHONE_RECIPIENT ' . self::quote($caller));
        $this->command('SET VARIABLE SLS_PHONE_ALLOW "0"');
        $this->command('EXEC AGI "/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,origin"');
        if ($this->variable('${SLS_PHONE_ALLOW}') !== '1' || $this->variable('${SLS_DIAL}') !== $result['live_dial']) { return []; }
        $this->command('SET VARIABLE CHANNEL(hangup_handler_push) "sls-phone-ended,s,1"');
        return $result;
    }
}

/** Shares the permanent audio reservation sidecar with recorded alerts. */
class LivePagingState
{
    private $directory;
    private $callSlots = [];
    public function __construct(string $directory) { $this->directory = $directory; }

    public function releaseCallSlot(): void
    {
        foreach ($this->callSlots as $handle) { fclose($handle); }
        $this->callSlots = [];
    }

    public function acquireCallSlot(string $caller): bool
    {
        $directory = $this->directory . '/live-paging-slots';
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0750) && !is_dir($directory))) {
            throw new \RuntimeException('paging_capacity_unavailable');
        }
        foreach (['caller-' . hash('sha256', $caller) => 2, 'global' => 16] as $prefix => $limit) {
            $claimed = false;
            for ($slot = 0; $slot < $limit; $slot++) {
                $path = $directory . '/' . $prefix . '-' . $slot . '.lock';
                if (is_link($path)) { continue; }
                $handle = @fopen($path, 'c+');
                if ($handle === false) { continue; }
                $stat = fstat($handle); $named = @lstat($path);
                if (is_array($stat) && is_array($named) && ($stat['mode'] & 0170000) === 0100000 && $stat['nlink'] === 1
                    && $stat['ino'] === $named['ino'] && $stat['dev'] === $named['dev'] && flock($handle, LOCK_EX | LOCK_NB)) {
                    @chmod($path, 0640); $this->callSlots[] = $handle; $claimed = true; break;
                }
                fclose($handle);
            }
            if (!$claimed) { $this->releaseCallSlot(); return false; }
        }
        return true;
    }

    /** json_decode otherwise silently discards duplicate reservation keys. */
    private static function audioUniqueJsonKeys(string $raw): bool
    {
        preg_match_all('~"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]|[^\s{}\[\],:]+~s', $raw, $matches);
        $tokens = $matches[0]; $position = 0;
        $parse = static function () use (&$parse, &$position, $tokens): bool {
            $token = $tokens[$position++] ?? null;
            if ($token === '{') {
                $seen = [];
                if (($tokens[$position] ?? null) === '}') { ++$position; return true; }
                while ($position < count($tokens)) {
                    $key = json_decode($tokens[$position++] ?? '');
                    if (!is_string($key) || isset($seen["key:" . $key])) { return false; }
                    $seen["key:" . $key] = true;
                    if (($tokens[$position++] ?? null) !== ':' || !$parse()) { return false; }
                    $separator = $tokens[$position++] ?? null;
                    if ($separator === '}') { return true; }
                    if ($separator !== ',') { return false; }
                }
                return false;
            }
            if ($token === '[') {
                if (($tokens[$position] ?? null) === ']') { ++$position; return true; }
                while ($position < count($tokens)) {
                    if (!$parse()) { return false; }
                    $separator = $tokens[$position++] ?? null;
                    if ($separator === ']') { return true; }
                    if ($separator !== ',') { return false; }
                }
                return false;
            }
            return $token !== null;
        };
        return $parse() && $position === count($tokens);
    }

    /** Audio uses a permanent sidecar; authentication retains its independent journal. */
    private function audioLocked(array $defaults, callable $operation)
    {
        $directory = $this->directory;
        $account = function_exists('posix_getpwnam') ? posix_getpwnam('asterisk') : false;
        $owners = [0];
        if (is_array($account)) { $owners[] = $account['uid']; }
        if ($directory === '' || $directory[0] !== '/' || preg_match('#(?:^|/)\.\.?(/|$)#', $directory)) {
            throw new \RuntimeException('paging_state_unsafe');
        }
        $part = '';
        foreach (explode('/', trim($directory, '/')) as $component) {
            $part .= '/' . $component; clearstatcache(true, $part); $meta = @lstat($part);
            if (!is_array($meta) || ($meta['mode'] & 0170000) !== 0040000 || !in_array($meta['uid'], $owners, true)
                || (($meta['mode'] & 0022) && !($part !== $directory && $meta['uid'] === 0 && ($meta['mode'] & 01000)))) { throw new \RuntimeException('paging_state_unsafe'); }
        }
        $parent = @fopen($directory, 'r');
        if ($parent === false) { throw new \RuntimeException('paging_state_unavailable'); }
        $parentStat = fstat($parent);
        if (($parentStat['mode'] & 07022) || !function_exists('posix_geteuid') || posix_geteuid() !== $parentStat['uid']) {
            fclose($parent); throw new \RuntimeException('paging_state_unsafe');
        }
        $owner = $parentStat['uid'];
        $lock = null; $data = null; $temp = null; $temporary = null;
        $safeFile = static function ($handle, string $path) use ($owner): array {
            clearstatcache(true, $path); $named = @lstat($path); $stat = fstat($handle);
            if (!is_array($stat) || !is_array($named) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
                || $stat['dev'] !== $named['dev'] || $stat['ino'] !== $named['ino']
                || $stat['uid'] !== $owner || ($stat['mode'] & 07022)) { throw new \RuntimeException('paging_state_unsafe'); }
            return $stat;
        };
        $openExisting = static function (string $path) use ($safeFile) {
            clearstatcache(true, $path); $before = @lstat($path);
            if (!is_array($before)) { return null; }
            if (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1) { throw new \RuntimeException('paging_state_unsafe'); }
            $handle = @fopen($path, 'r+');
            if ($handle === false) { throw new \RuntimeException('paging_state_unavailable'); }
            try { $safeFile($handle, $path); return $handle; }
            catch (\Throwable $error) { fclose($handle); throw $error; }
        };
        $writeAll = static function ($handle, string $body): void {
            $offset = 0;
            while ($offset < strlen($body)) {
                $written = fwrite($handle, substr($body, $offset));
                if (!is_int($written) || $written < 1) { throw new \RuntimeException('paging_state_write_failed'); }
                $offset += $written;
            }
            if (!fflush($handle) || !function_exists('fsync') || !fsync($handle)) { throw new \RuntimeException('paging_state_commit_uncertain'); }
        };
        try {
            $lockPath = $directory . '/audio-reservations.lock';
            $lock = $openExisting($lockPath);
            if ($lock === null) {
                $lock = @fopen($lockPath, 'x+');
                if ($lock === false) { $lock = $openExisting($lockPath); }
                else {
                    if (!chmod($lockPath, 0640) || !fsync($lock) || !fsync($parent)) { throw new \RuntimeException('paging_state_commit_uncertain'); }
                }
            }
            if (!is_resource($lock)) { throw new \RuntimeException('paging_state_unavailable'); }
            $deadline = hrtime(true) + 2000000000;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) { throw new \RuntimeException('paging_lock_timeout'); }
                usleep(20000);
            }
            $lockStat = $safeFile($lock, $lockPath);
            if ($lockStat['size'] > 19) { throw new \RuntimeException('paging_state_corrupt'); }
            rewind($lock); $marker = stream_get_contents($lock, 20);
            if ($marker !== '' && $marker !== "SLS_AUDIO_STATE_V1\n") { throw new \RuntimeException('paging_state_corrupt'); }
            $path = $directory . '/audio-reservations.json';
            $data = $openExisting($path);
            $fresh = $data === null;
            if ($fresh) {
                if ($marker !== '') { throw new \RuntimeException('paging_state_missing'); }
                $state = $defaults;
            } else {
                $stat = $safeFile($data, $path);
                if ($stat['size'] < 1 || $stat['size'] > 1048576) { throw new \RuntimeException('paging_state_corrupt'); }
                $raw = stream_get_contents($data, 1048577);
                $object = json_decode((string)$raw);
                $state = json_decode((string)$raw, true);
                if (strlen((string)$raw) > 1048576 || !is_object($object) || !is_array($state) || !self::audioUniqueJsonKeys((string)$raw)) { throw new \RuntimeException('paging_state_corrupt'); }
                foreach (['recipients', 'media', 'waiting'] as $field) {
                    if (property_exists($object, $field) && !is_object($object->$field) && $object->$field !== []) {
                        throw new \RuntimeException('paging_state_corrupt');
                    }
                }
                if (isset($object->waiting) && is_object($object->waiting)) {
                    foreach (get_object_vars($object->waiting) as $row) {
                        if (!is_object($row) || !is_array($row->recipients ?? null)) { throw new \RuntimeException('paging_state_corrupt'); }
                    }
                }
            }
            if (array_diff(array_keys($state), ['recipients', 'media', 'waiting'])) { throw new \RuntimeException('paging_state_corrupt'); }
            foreach (['recipients', 'media', 'waiting'] as $field) {
                if (!array_key_exists($field, $state)) {
                    if ($field !== 'waiting' && !$fresh) { throw new \RuntimeException('paging_state_corrupt'); }
                    $state[$field] = [];
                }
                if (!is_array($state[$field])) { throw new \RuntimeException('paging_state_corrupt'); }
            }
            $timestamp = static function ($value): bool {
                return (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0;
            };
            foreach (['recipients', 'media'] as $field) {
                foreach ($state[$field] as $key => $value) {
                    $pattern = $field === 'recipients' ? '/^[0-9]{1,20}$/D' : '/^[A-Za-z0-9_-]+\\.wav$/D';
                    if (!preg_match($pattern, (string)$key) || !$timestamp($value)) { throw new \RuntimeException('paging_state_corrupt'); }
                }
            }
            if (count($state['waiting']) > 100) { throw new \RuntimeException('paging_state_capacity'); }
            foreach ($state['waiting'] as $key => $row) {
                if (!preg_match('/^[a-f0-9]{32}$/D', (string)$key) || !is_array($row)
                    || count($row) !== 7 || array_diff(array_keys($row), ['recipients', 'priority', 'created', 'expires', 'heartbeat', 'duration', 'media_name'])
                    || !is_int($row['priority'] ?? null) || !in_array($row['priority'], [0, 1], true)
                    || !is_array($row['recipients'] ?? null) || !$row['recipients'] || count($row['recipients']) > 1000
                    || array_keys($row['recipients']) !== range(0, count($row['recipients']) - 1)
                    || count(array_unique($row['recipients'], SORT_REGULAR)) !== count($row['recipients'])
                    || !is_string($row['media_name'] ?? null) || !preg_match('/^[A-Za-z0-9_-]+\\.wav$/D', $row['media_name'])
                    || !$timestamp($row['duration'] ?? null) || $row['duration'] <= 0 || $row['duration'] > 1800) {
                    throw new \RuntimeException('paging_state_corrupt');
                }
                foreach ($row['recipients'] as $recipient) {
                    if (!is_string($recipient) || !preg_match('/^[0-9]{1,20}$/D', $recipient)) { throw new \RuntimeException('paging_state_corrupt'); }
                }
                foreach (['created', 'expires', 'heartbeat'] as $field) {
                    if (!$timestamp($row[$field] ?? null)) { throw new \RuntimeException('paging_state_corrupt'); }
                }
            }
            [$next, $result, $changed] = $operation($state);
            if ($changed || $fresh) {
                foreach (['recipients', 'media', 'waiting'] as $field) { $next[$field] = (object)($next[$field] ?? []); }
                $body = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (strlen($body) > 1048576) { throw new \RuntimeException('paging_state_capacity'); }
                $temporary = $directory . '/.audio-reservations.' . bin2hex(random_bytes(16)) . '.tmp';
                $temp = @fopen($temporary, 'x+');
                if ($temp === false || !chmod($temporary, 0640)) { throw new \RuntimeException('paging_state_write_failed'); }
                $writeAll($temp, $body); $safeFile($temp, $temporary);
                clearstatcache(true, $directory); $namedParent = @lstat($directory);
                if (!is_array($namedParent) || $namedParent['dev'] !== $parentStat['dev'] || $namedParent['ino'] !== $parentStat['ino']) { throw new \RuntimeException('paging_state_unsafe'); }
                $safeFile($lock, $lockPath);
                if ($data !== null) { $safeFile($data, $path); }
                elseif (file_exists($path) || is_link($path)) { throw new \RuntimeException('paging_state_unsafe'); }
                if (!rename($temporary, $path)) { throw new \RuntimeException('paging_state_write_failed'); }
                $temporary = null;
                if (!fsync($parent)) { throw new \RuntimeException('paging_state_commit_uncertain'); }
            } else {
                // Adoption of a legacy journal must be durable before its marker.
                if ($marker === '' && (!fsync($data) || !fsync($parent))) { throw new \RuntimeException('paging_state_commit_uncertain'); }
            }
            if ($marker === '') { rewind($lock); $writeAll($lock, "SLS_AUDIO_STATE_V1\n"); }
            return $result;
        } finally {
            if (is_resource($temp)) { fclose($temp); }
            if ($temporary !== null) { @unlink($temporary); }
            if (is_resource($data)) { fclose($data); }
            if (is_resource($lock)) { fclose($lock); }
            fclose($parent);
        }
    }

    private function locked(string $name, array $defaults, callable $operation, int $maxBytes = 1048576)
    {
        if (is_link($this->directory) || !is_dir($this->directory)) { throw new \RuntimeException('paging_state_unavailable'); }
        $path = $this->directory . '/' . $name;
        $before = @lstat($path);
        if (is_link($path) || (is_array($before) && (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1))) {
            throw new \RuntimeException('paging_state_unsafe');
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) { throw new \RuntimeException('paging_state_unavailable'); }
        try {
            $deadline = hrtime(true) + 2000000000;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) { throw new \RuntimeException('paging_lock_timeout'); }
                usleep(20000);
            }
            $stat = fstat($handle); $named = @lstat($path);
            if (!is_array($stat) || !is_array($named) || ($stat['mode'] & 0170000) !== 0100000
                || $stat['nlink'] !== 1 || $stat['ino'] !== $named['ino'] || $stat['dev'] !== $named['dev']
                || $stat['size'] > $maxBytes) { throw new \RuntimeException('paging_state_unsafe'); }
            @chmod($path, 0640);
            $raw = stream_get_contents($handle, $maxBytes + 1);
            $state = $raw === '' ? $defaults : json_decode((string)$raw, true);
            if (!is_array($state)) { throw new \RuntimeException('paging_state_corrupt'); }
            [$next, $result, $changed] = $operation($state);
            if ($changed) {
                $encoded = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (strlen($encoded) > $maxBytes) { throw new \RuntimeException('paging_state_capacity'); }
                rewind($handle);
                $offset = 0;
                while ($offset < strlen($encoded)) {
                    $written = fwrite($handle, substr($encoded, $offset));
                    if (!is_int($written) || $written < 1) { throw new \RuntimeException('paging_state_write_failed'); }
                    $offset += $written;
                }
                if (!ftruncate($handle, strlen($encoded)) || !fflush($handle)
                    || (function_exists('fsync') && !fsync($handle))) { throw new \RuntimeException('paging_state_write_failed'); }
            }
            return $result;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function authenticationAllowed(string $caller, bool $recordFailure = false, ?int $now = null): bool
    {
        return $this->locked('live-paging-auth.json', [], static function (array $state) use ($caller, $recordFailure, $now): array {
            $bucket = gmdate('YmdHi', $now ?? time());
            if (($state['bucket'] ?? '') !== $bucket) { $state = ['bucket' => $bucket, 'global' => 0, 'callers' => []]; }
            if (!is_int($state['global'] ?? null) || !is_array($state['callers'] ?? null) || count($state['callers']) > 1000) {
                throw new \RuntimeException('paging_auth_state_corrupt');
            }
            $identity = hash('sha256', $caller);
            $failed = $state['callers'][$identity] ?? 0;
            if (!is_int($failed) || $failed < 0 || $state['global'] < 0) { throw new \RuntimeException('paging_auth_state_corrupt'); }
            $allowed = $failed < 10 && $state['global'] < 60;
            if ($recordFailure) {
                $state['global'] = min(60, $state['global'] + 1);
                $state['callers'][$identity] = min(10, $failed + 1);
            }
            $state['callers'] = (object)$state['callers'];
            return [$state, $allowed, $recordFailure];
        }, 131072);
    }

    public function claim(array $recipients, int $duration, ?float $now = null): array
    {
        $now = $now ?? microtime(true);
        if (!$recipients || count($recipients) > 1000 || $duration < 30 || $duration > 1800) { throw new \InvalidArgumentException('invalid_paging_reservation'); }
        foreach ($recipients as $recipient) {
            if (!is_string($recipient) || !preg_match('/^[0-9]{1,20}$/D', $recipient)) { throw new \InvalidArgumentException('invalid_paging_reservation'); }
        }
        return $this->audioLocked(['recipients' => [], 'media' => [], 'waiting' => []], static function (array $state) use ($recipients, $duration, $now): array {
            foreach (['recipients', 'media', 'waiting'] as $field) {
                $state[$field] = $state[$field] ?? [];
                if (!is_array($state[$field])) { throw new \RuntimeException('paging_state_corrupt'); }
            }
            foreach (['recipients', 'media'] as $field) {
                foreach ($state[$field] as $key => $expires) {
                    if ((!is_int($expires) && !is_float($expires)) || !is_finite((float)$expires)) { throw new \RuntimeException('paging_state_corrupt'); }
                    if ($expires <= $now) { unset($state[$field][$key]); }
                }
            }
            foreach ($recipients as $recipient) {
                if (($state['recipients'][$recipient] ?? 0) > $now) { return [$state, [], false]; }
            }
            foreach ($state['waiting'] as $row) {
                if (!is_array($row) || !is_array($row['recipients'] ?? null)
                    || (!is_int($row['expires'] ?? null) && !is_float($row['expires'] ?? null))
                    || (!is_int($row['heartbeat'] ?? null) && !is_float($row['heartbeat'] ?? null))
                    || !is_finite((float)$row['expires']) || !is_finite((float)$row['heartbeat'])) { throw new \RuntimeException('paging_state_corrupt'); }
                if ($row['expires'] > $now && $row['heartbeat'] > $now && array_intersect($recipients, $row['recipients'])) {
                    return [$state, [], false];
                }
            }
            $until = $now + $duration + 5;
            foreach ($recipients as $recipient) { $state['recipients'][$recipient] = $until; }
            foreach (['recipients', 'media', 'waiting'] as $field) { $state[$field] = (object)$state[$field]; }
            return [$state, ['recipients' => $recipients, 'until' => $until], true];
        });
    }

    public function release(array $claim): void
    {
        if (!$claim) { return; }
        $this->audioLocked([], static function (array $state) use ($claim): array {
            if (!is_array($state['recipients'] ?? null) || !is_array($state['media'] ?? null) || !is_array($state['waiting'] ?? null)) {
                throw new \RuntimeException('paging_state_corrupt');
            }
            foreach ($claim['recipients'] as $recipient) {
                // An older producer may already have reserved a later page.
                // Never delete that newer reservation when the live caller ends.
                if ((float)($state['recipients'][$recipient] ?? 0) === (float)$claim['until']) { unset($state['recipients'][$recipient]); }
            }
            foreach (['recipients', 'media', 'waiting'] as $field) { $state[$field] = (object)$state[$field]; }
            return [$state, null, true];
        });
    }
}

final class LivePagingSession
{
    private $agi;
    private $state;
    private $settingsLoader;
    private $soundExists;
    private array $environment;
    private string $returnIvr = '';

    private function deny(string $status, array $settings, bool $answered = false): array
    {
        $prompt = LivePagingConfig::promptFiles($settings)['denied'];
        if (!(($this->soundExists)($prompt))) { throw new \RuntimeException('paging_prompt_unavailable'); }
        if (!$answered) { $this->agi->answer(); }
        $this->agi->play($prompt);
        return ['status' => $status, 'returned_to_ivr' => $this->returnIvr !== '' && $this->agi->returnToIvr($this->returnIvr)];
    }

    public function __construct(LivePagingAgi $agi, LivePagingState $state, callable $settingsLoader, callable $soundExists, array $environment = [])
    {
        $this->agi = $agi; $this->state = $state; $this->settingsLoader = $settingsLoader; $this->soundExists = $soundExists;
        $this->environment = $environment;
    }

    public static function loadSettings(string $path): array
    {
        $before = @lstat($path);
        if (is_link($path) || (is_array($before) && (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1))) {
            throw new \RuntimeException('paging_config_unsafe');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) { throw new \RuntimeException('paging_config_unavailable'); }
        try {
            $stat = fstat($handle); $named = @lstat($path);
            if (!is_array($stat) || !is_array($named) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
                || ($stat['mode'] & 0027) !== 0 || $stat['size'] > \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES
                || $stat['ino'] !== $named['ino'] || $stat['dev'] !== $named['dev']) { throw new \RuntimeException('paging_config_unsafe'); }
            $decoded = \FreePBX\modules\SlsConfigCrypto::decode((string)stream_get_contents($handle, \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES + 1));
            if (!is_array($decoded)) { throw new \RuntimeException('paging_config_invalid'); }
            return $decoded;
        } finally { fclose($handle); }
    }

    private function caller(array $config): array
    {
        if ($this->agi->variable('${CHANNEL(channeltype)}') !== 'PJSIP') { return []; }
        $endpoint = $this->agi->variable('${CHANNEL(endpoint)}');
        if (!preg_match('/^[0-9]{1,20}$/D', $endpoint)) { return []; }
        $dial = $this->agi->variable('${DB(DEVICE/' . $endpoint . '/dial)}');
        if ($dial !== 'PJSIP/' . $endpoint) { return []; }
        $user = $this->agi->variable('${DB(DEVICE/' . $endpoint . '/user)}');
        if ($user === '' || $user === 'none') { $user = $endpoint; }
        if (!preg_match('/^[0-9]{1,20}$/D', $user) || !in_array($user, LivePagingConfig::callerExtensions($config), true)) { return []; }
        return ['extension' => $user, 'endpoint' => $endpoint];
    }

    private function dialContacts(string $extension): array
    {
        $endpoint = $extension;
        $contacts = $this->agi->variable('${PJSIP_DIAL_CONTACTS(' . $endpoint . ')}');
        if ($contacts === '') {
            $device = $this->agi->variable('${DB(DEVICE/' . $extension . '/dial)}');
            if (!preg_match('#^PJSIP/([A-Za-z0-9_.-]{1,80})$#D', $device, $matches)) { return []; }
            $endpoint = $matches[1];
            $contacts = $this->agi->variable('${PJSIP_DIAL_CONTACTS(' . $endpoint . ')}');
        }
        if ($contacts === '' || strlen($contacts) > 16384 || preg_match('/[\r\n\x00,"<>]/', $contacts)) { return []; }
        $targets = explode('&', $contacts);
        foreach ($targets as $target) {
            if (strpos($target, 'PJSIP/' . $endpoint . '/sip:') !== 0 && strpos($target, 'PJSIP/' . $endpoint . '/sips:') !== 0) {
                return [];
            }
        }
        return $targets;
    }

    public function run(): array
    {
        $settings = ($this->settingsLoader)();
        if (!RuntimeState::running($settings)) { return ['status' => 'runtime_stopped']; }
        $config = LivePagingConfig::normalize((array)($settings['live_paging'] ?? []));
        if ($config['enabled'] !== '1') { return ['status' => 'disabled']; }
        $external = ($this->environment['agi_context'] ?? '') === 'sls-live-paging-external';
        if ($external && (($config['external_access'] ?? '0') !== '1' || ($this->environment['agi_extension'] ?? '') !== 's')) { return ['status' => 'external_access_disabled']; }
        $this->returnIvr = '';
        if ($external) {
            $origin = $this->agi->variable('${IVR_CONTEXT}');
            if (!in_array($origin, array_map(static fn($id) => 'ivr-'.$id, $config['external_ivr_ids']), true)) {
                return ['status' => 'external_ivr_not_approved'];
            }
            $this->returnIvr = $origin;
        }
        // Only an explicitly selected IVR destination admits external callers.
        // The caller-number allowlist is checked before the menu; the group
        // PIN remains mandatory. One shared rate bucket bounds all trunks.
        $caller = $external ? ['extension' => $config['extension'], 'endpoint' => '', 'external' => true] : $this->caller($config);
        if (!$caller) { return $this->deny('unauthorized_caller', $settings); }
        $this->agi->setExternalIngress($external);
        $authIdentity = $external ? 'external' : $caller['extension'];
        if (!$this->state->acquireCallSlot($authIdentity)) { return ['status' => 'paging_capacity_reached']; }
        try {
        $groups = LivePagingConfig::resolvedGroups($config, (array)($settings['announcement_groups'] ?? []));
        $approvedNumbers = [];
        if ($external) {
            $presented = $this->agi->variable('${CALLERID(num)}');
            $presentation = $this->agi->variable('${CALLERID(num-pres)}');
            if ($this->agi->variable('${CALLERID(num-valid)}') !== '1'
                || !in_array($presentation, ['allowed', 'allowed_not_screened', 'allowed_passed_screen'], true)) {
                return $this->deny('external_caller_not_approved', $settings);
            }
            foreach ($groups as $number => $row) {
                $match = ($row['allow_external'] ?? '0') === '1' ? LivePagingConfig::matchExternalCaller($presented, $row['external_callers'] ?? []) : '';
                if ($match === '') { unset($groups[$number]); }
                else { $approvedNumbers[$number] = $match; }
            }
            if (!$groups) { return $this->deny('external_caller_not_approved', $settings); }
        }
        if (!$groups) { return ['status' => 'no_permitted_groups']; }
        $prompts = LivePagingConfig::promptFiles($settings);
        foreach ($prompts as $key => $prompt) {
            if (str_starts_with($key, 'panic_')) { continue; }
            if (str_starts_with($key, 'external_') && (!$external || ($key !== 'external_menu' && !isset($groups[(int)substr($key, 15)])))) { continue; }
            if ($external && $key === 'menu') { continue; }
            if (!(($this->soundExists)($prompt))) { throw new \RuntimeException('paging_prompt_unavailable'); }
        }
        $this->agi->answer();
        $this->agi->autoHangup(300);
        $externalMenu = $external ? array_merge([$prompts['external_menu']], array_map(static fn($number) => $prompts['external_group_' . $number], array_keys($groups))) : [];
        $group = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $selection = $external ? $this->agi->menu($externalMenu) : $this->agi->digits($prompts['menu'], 3);
            if (preg_match('/^(?:[1-9]|10)$/D', $selection) && isset($groups[$selection])) { $group = $groups[$selection]; break; }
            $this->agi->play($prompts['invalid']);
        }
        if ($group === null) { return ['status' => 'invalid_menu_selection']; }
        if ($external) { $this->agi->setExternalIngress(true, $approvedNumbers[$selection]); }
        if (!$external && !in_array($caller['extension'], $group['allowed_callers'], true)) {
            $this->agi->play($prompts['denied']);
            return ['status' => 'unauthorized_group'];
        }
        if ($external || $group['require_pin'] === '1') {
            $verified = false;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                if (!$this->state->authenticationAllowed($authIdentity)) { return $this->deny('pin_rate_limited', $settings, true); }
                // Read at most nine digits so an overlong PIN is rejected, never
                // silently truncated into a valid eight-digit prefix.
                $pin = $this->agi->digits($prompts['pin'], 9);
                $verified = strlen($pin) === $group['pin_length'] && preg_match('/^[0-9]{4,8}$/D', $pin)
                    && password_verify($pin, $group['pin_hash']);
                if (function_exists('sodium_memzero')) { sodium_memzero($pin); } else { $pin = ''; }
                if ($verified) { break; }
                $this->state->authenticationAllowed($authIdentity, true);
                $this->agi->play($prompts['invalid']);
            }
            if (!$verified) { return $this->deny('invalid_pin', $settings, true); }
        }
        // Recheck after the menu/PIN exchange. A revoked caller or changed
        // group must not page using an earlier configuration snapshot.
        $fresh = ($this->settingsLoader)();
        if (!hash_equals(hash('sha256', json_encode([$settings['live_paging'], $settings['announcement_groups'] ?? []])),
            hash('sha256', json_encode([$fresh['live_paging'] ?? [], $fresh['announcement_groups'] ?? []])))) {
            $this->agi->play($prompts['unavailable']);
            return ['status' => 'configuration_changed'];
        }
        $recipients = array_values(array_diff($group['extensions'], [$caller['extension'], $caller['endpoint']]));
        $dial = []; $unavailable = [];
        foreach ($recipients as $recipient) {
            $contacts = $this->dialContacts($recipient);
            if (!$contacts) { $unavailable[] = $recipient; }
            $dial = array_merge($dial, $contacts);
        }
        $dial = array_values(array_unique($dial));
        if (!$dial) {
            $this->agi->play($prompts['unavailable']);
            return ['status' => 'no_reachable_recipients'];
        }
        if (strlen(implode('&', $dial)) > 32768) {
            $this->agi->play($prompts['unavailable']);
            return ['status' => 'dial_string_capacity_exceeded'];
        }
        $claim = $this->state->claim($recipients, $config['max_duration_seconds']);
        if (!$claim) { $this->agi->play($prompts['busy']); return ['status' => 'group_busy']; }
        try {
            $admissionSettings = ($this->settingsLoader)();
            if (!hash_equals(hash('sha256', json_encode([$settings['live_paging'], $settings['announcement_groups'] ?? []])),
                hash('sha256', json_encode([$admissionSettings['live_paging'] ?? [], $admissionSettings['announcement_groups'] ?? []])))) {
                $this->agi->play($prompts['unavailable']);
                return ['status' => 'configuration_changed'];
            }
            $admission = $this->agi->admitPhones($recipients, $caller['extension'], $group['group_id']);
            if (!$admission) {
                $this->agi->play($prompts['unavailable']);
                $result = ['status' => 'phone_admission_rejected'];
                if ($this->agi->admissionFailure() !== '') { $result['phone_admission_error'] = $this->agi->admissionFailure(); }
                return $result;
            }
            $dial = explode('&', $admission['live_dial']);
            $unavailable = $admission['unavailable'];
            try { $notification = $this->agi->notifyGroup($group, $caller['extension'], $settings); }
            catch (\Throwable $error) {
                $notification = ['status' => 'failed', 'requested' => count(array_diff($group['notify_extensions'], [$caller['extension']]))];
            }
            if (($notification['status'] ?? '') === 'configuration_changed') {
                $this->agi->play($prompts['unavailable']);
                return ['status' => 'configuration_changed', 'text_notification' => $notification];
            }
            if (!in_array($notification['status'] ?? '', ['submitted', 'not_requested'], true)) {
                $this->agi->play($prompts['text_failure']);
            }
            $this->agi->autoHangup($config['max_duration_seconds']);
            if ($unavailable) { $this->agi->play($prompts['partial']); }
            $this->agi->play($prompts['ready']);
            $this->agi->page(implode('&', $dial), min(5, max(1, (int)($settings['paging_answer_timeout'] ?? 5))), $config['max_duration_seconds']);
        } finally {
            try { $completion = $this->agi->finishGroupNotification(); }
            catch (\Throwable $error) { $completion = ['status' => 'failed']; }
            $this->state->release($claim);
        }
        return ['status' => 'page_ended', 'group_id' => $group['group_id'], 'requested_recipients' => count($recipients),
            'unavailable_recipients' => $unavailable, 'dialed_contacts' => count($dial), 'text_notification' => $notification,
            'text_completion' => $completion];
        } finally { $this->state->releaseCallSlot(); }
    }
}
