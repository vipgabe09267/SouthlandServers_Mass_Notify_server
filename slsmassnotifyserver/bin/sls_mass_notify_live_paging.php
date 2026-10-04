#!/usr/bin/php
<?php
declare(strict_types=1);

// AGI only: no FreePBX bootstrap, HTTP entry point, or caller-supplied arguments.
if (PHP_SAPI !== 'cli' || ($argc ?? 0) !== 1) { exit(64); }
$library = __DIR__ . '/sls_live_paging.php';
if (!is_file($library)) { $library = __DIR__ . '/sls_mass_notify/sls_live_paging.php'; }
require_once $library;

class SlsLivePagingNotifyAgi extends \SLS\MassNotify\LivePagingAgi
{
    private array $visualTargets = [];
    private string $visualOwner = '';
    protected function runVisualCommand(array $command): int
    {
        $pipes = [];
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, null, null, ['bypass_shell' => true]);
        return is_resource($process) ? proc_close($process) : -1;
    }

    protected function currentPagingSettings(): array
    {
        return \SLS\MassNotify\LivePagingSession::loadSettings(
            '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');
    }

    public function notifyGroup(array $group, string $caller, array $settings): array
    {
        $targets = array_values(array_diff((array)($group['notify_extensions'] ?? []), [$caller]));
        if (!$targets) { return ['status' => 'not_requested', 'requested' => 0]; }
        if (count($targets) > 1000 || count($targets) !== count(array_unique($targets))) {
            return ['status' => 'failed', 'requested' => count($targets)];
        }
        foreach ($targets as $target) {
            if (!is_string($target) || !preg_match('/^[0-9]{1,20}$/D', $target)) {
                return ['status' => 'failed', 'requested' => count($targets)];
            }
        }
        $message = $group['text_message'] ?? '';
        if (!is_string($message) || trim($message) === '' || strlen($message) > 1000
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message)) {
            return ['status' => 'failed', 'requested' => count($targets)];
        }
        $authorized = false;
        try {
            $current = $this->currentPagingSettings();
            $fingerprint = static function (array $value): string {
                return hash('sha256', json_encode([$value['live_paging'] ?? [], $value['announcement_groups'] ?? []], JSON_THROW_ON_ERROR));
            };
            if (!hash_equals($fingerprint($settings), $fingerprint($current))) {
                return ['status' => 'configuration_changed', 'requested' => count($targets)];
            }
            $paging = \SLS\MassNotify\LivePagingConfig::normalize((array)($current['live_paging'] ?? []));
            $resolved = \SLS\MassNotify\LivePagingConfig::resolvedGroups($paging, (array)($current['announcement_groups'] ?? []));
            $saved = $resolved[(string)($group['menu_number'] ?? '')] ?? null;
            if (!is_array($saved) || $saved !== $group || $paging['enabled'] !== '1'
                || ($this->externalIngress
                    ? (($paging['external_access'] ?? '0') !== '1' || ($saved['allow_external'] ?? '0') !== '1' || $caller !== $paging['extension'] || empty($saved['pin_hash'])
                        || $this->externalCallerNumber === '' || !in_array($this->externalCallerNumber, $saved['external_callers'] ?? [], true))
                    : !in_array($caller, $saved['allowed_callers'] ?? [], true))) {
                return ['status' => 'configuration_changed', 'requested' => count($targets)];
            }
            $authorized = true;
            $timeout = ($current['announcement_timeout_mode'] ?? 'none') === 'custom'
                ? min(86400, max(0, (int)($current['announcement_timeout_seconds'] ?? 300))) : 0;
            // Keep a finite fallback if a process or PBX dies before cleanup.
            $timeout = $timeout > 0 ? min($timeout, $paging['max_duration_seconds'] + 5) : $paging['max_duration_seconds'] + 5;
            $this->visualTargets = $targets;
            $this->visualOwner = bin2hex(random_bytes(16));
            // A fixed argv array cannot interpret message text as shell code.
            // Never publish a desktop event or expand an empty target list.
            $exit = $this->runVisualCommand(['/usr/bin/timeout', '--signal=TERM', '--kill-after=1', '12',
                '/usr/bin/python3', '-I', '/usr/local/bin/sls_mass_notify/sls_notify.py',
                '--targets', implode(',', $targets), '--announcement=' . $message,
                '--announcement-timeout-seconds', (string)$timeout, '--no-api', '--no-retry', '--visual-owner', $this->visualOwner]);
            return ['status' => $exit === 0 ? 'submitted' : (in_array($exit, [124, 137, 143], true) ? 'uncertain' : 'failed'),
                'requested' => count($targets)];
        } catch (\Throwable $error) {
            return ['status' => $authorized ? 'failed' : 'configuration_changed', 'requested' => count($targets)];
        }
    }

    public function finishGroupNotification(): array
    {
        if (!$this->visualOwner || !$this->visualTargets) { return ['status' => 'not_requested', 'requested' => 0]; }
        $targets = $this->visualTargets; $owner = $this->visualOwner;
        $this->visualTargets = []; $this->visualOwner = '';
        $exit = $this->runVisualCommand(['/usr/bin/timeout', '--signal=TERM', '--kill-after=1', '12',
            '/usr/bin/python3', '-I', '/usr/local/bin/sls_mass_notify/sls_notify.py',
            '--targets', implode(',', $targets), '--announcement=Paging ended.',
            '--announcement-timeout-seconds', '1', '--no-api', '--no-retry', '--clear-visual-owner', $owner]);
        return ['status' => $exit === 0 ? 'submitted' : (in_array($exit, [124, 137, 143], true) ? 'uncertain' : 'failed'),
            'requested' => count($targets)];
    }
}

$agi = new SlsLivePagingNotifyAgi(STDIN, STDOUT);
try {
    $environment = $agi->environment();
    if (($environment['agi_request'] ?? '') === '') { exit(64); }
    $directory = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin';
    $session = new \SLS\MassNotify\LivePagingSession($agi, new \SLS\MassNotify\LivePagingState($directory),
        static function () use ($directory): array {
            return \SLS\MassNotify\LivePagingSession::loadSettings($directory . '/mass-notifications.config');
        },
        static function (string $sound) use ($directory): bool {
            $path = $directory . '/sounds/' . substr($sound, strlen('SLS_Mass_Notifications_Plugin/')) . '.wav';
            return !is_link($path) && is_file($path) && is_readable($path);
        }, $environment);
    $result = $session->run();
    // Deliberately omit entered digits, PIN hashes, config contents and AGI
    // responses. Asterisk-wide AGI/DTMF debug must stay off for secret input.
    error_log('SLS live paging: ' . (string)$result['status']
        . (isset($result['phone_admission_error']) ? '; phone_error=' . $result['phone_admission_error'] : '')
        . (isset($result['text_notification']) ? '; text=' . (string)($result['text_notification']['status'] ?? 'unknown')
            . '; text_requested=' . (int)($result['text_notification']['requested'] ?? 0) : '')
        . (isset($result['text_completion']) ? '; text_completion=' . (string)($result['text_completion']['status'] ?? 'unknown') : '')
        . (isset($result['requested_recipients']) ? '; requested=' . (int)$result['requested_recipients'] . '; dialed_contacts=' . (int)$result['dialed_contacts']
            . '; unavailable=' . implode(',', (array)($result['unavailable_recipients'] ?? [])) : '')
        . ($result['status'] === 'dial_string_capacity_exceeded' ? '; reduce the selected group size or the registrations per phone.' : ''));
} catch (\Throwable $error) {
    $known = ['agi_timeout', 'agi_hangup', 'agi_write_failed', 'agi_command_failed', 'invalid_agi_environment',
        'paging_config_unsafe', 'paging_config_unavailable', 'paging_config_invalid', 'paging_prompt_unavailable',
        'paging_state_unavailable', 'paging_state_unsafe', 'paging_state_corrupt', 'paging_state_capacity',
        'paging_state_write_failed', 'paging_auth_state_corrupt', 'paging_capacity_unavailable', 'paging_lock_timeout'];
    $category = in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'unexpected_failure';
    error_log('SLS live paging: ' . $category . '; review live paging configuration, prepared prompts and PBX health.');
} finally {
    if (!$agi->hasReturnedToIvr()) {
        try { $agi->hangup(); } catch (\Throwable $ignored) { }
    }
}
