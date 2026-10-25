#!/usr/bin/php
<?php
declare(strict_types=1);

// AGI only: no FreePBX bootstrap, HTTP entry point, or caller-supplied arguments.
if (PHP_SAPI !== 'cli' || !(($argc ?? 0) === 1 || (($argc ?? 0) === 2 && $argv[1] === '--ready'))) { exit(64); }
$library = __DIR__ . '/sls_live_paging.php';
if (!is_file($library)) { $library = __DIR__ . '/sls_mass_notify/sls_live_paging.php'; }
require_once $library;

class SlsLivePagingNotifyAgi extends \SLS\MassNotify\LivePagingAgi
{
    private $visualProcess = null;
    private $visualInput = null;
    private $visualOutput = null;
    private string $visualBuffer = '';
    private int $visualRequested = 0;
    private bool $visualStarted = false;
    private string $recordingCueRequest = '';

    protected function prepareCallerMenu(array $request): void
    {
        $encoded = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach (['SLS_PAGING_CUE_ARM'=>'', 'SLS_PAGING_CUE_DONE'=>'0', 'SLS_PAGING_CUE_RESULT'=>'',
                  'SLS_PAGING_CUE_REQUEST'=>$encoded, 'CONFBRIDGE(menu,A)'=>'dialplan_exec(sls-live-paging-ready,s,1)'] as $key=>$value) {
            $this->command('SET VARIABLE ' . $key . ' "' . addcslashes($value, '\\"') . '"');
        }
    }

    protected function checkCallerCue(string $request, string $mode = '--cue-stdin'): array
    {
        $pipes = [];
        $process = @proc_open(['/usr/bin/timeout', '--kill-after=1', '5', '/usr/bin/python3', '-I',
            '/usr/local/bin/sls_mass_notify/sls_live_paging_visual.py', $mode],
            [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file','/dev/null','w']], $pipes, null, null, ['bypass_shell'=>true]);
        if (!is_resource($process)) { return []; }
        $sent = fwrite($pipes[0], $request . "\n"); fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1], 4097); fclose($pipes[1]);
        if (strlen((string)$raw)>4096) { proc_terminate($process); }
        $exit = proc_close($process);
        $result = json_decode((string)$raw, true);
        return $sent===strlen($request)+1 && $exit===0 && is_array($result) ? $result : [];
    }

    public function recordingReadyCue(int $deadline = PHP_INT_MAX): bool
    {
        if ($this->recordingCueRequest==='') { return false; }
        $request = $this->recordingCueRequest;
        $this->recordingCueRequest='';
        $toneStarted=false;$opened=false;$stopped=true;
        try {
            foreach ([false,true] as $afterTone) {
                $proof=$this->checkCallerCue($request,'--recording-cue-stdin');
                if (($proof['ok']??false)!==true || ($proof['decision']??'')!=='recording'
                    || !is_int($proof['remaining_seconds']??null) || $proof['remaining_seconds']<1 || time()>=$deadline) { return false; }
                if (!$afterTone) {
                    $toneStarted=$this->playReadyTone();
                    if (!$toneStarted) { return false; }
                }
            }
            $this->autoHangup(min($proof['remaining_seconds'],max(1,$deadline-time())));
            $opened=$this->openPageInput();
        } finally {
            if ($toneStarted) { $stopped=$this->stopReadyTone(); }
        }
        return $opened && $stopped;
    }

    public function callerReadyCue(array $environment): string
    {
        $raw = $this->variable('${SLS_PAGING_CUE_REQUEST}');
        if (strlen($raw)>4096) { return 'ignored'; }
        $request = json_decode($raw, true);
        if (!is_array($request) || !is_string($request['owner'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $request['owner'])
            || ($request['channel'] ?? '') !== ($environment['agi_channel'] ?? '')
            || ($request['uniqueid'] ?? '') !== ($environment['agi_uniqueid'] ?? '')
            || $this->variable('${SLS_PAGING_CUE_DONE}') !== '0'
            || !hash_equals($request['owner'], $this->variable('${SLS_PAGING_CUE_ARM}'))) { return 'ignored'; }
        $this->command('SET VARIABLE SLS_PAGING_CUE_ARM ""');
        $this->command('SET VARIABLE SLS_PAGING_CUE_DONE "1"');
        $result = $this->checkCallerCue($raw);
        $decision = $result['decision'] ?? '';
        $remaining = $result['remaining_seconds'] ?? 0;
        $safe = ($result['ok'] ?? false)===true && is_int($remaining) && $remaining>0 && $remaining<=1800
            && in_array($decision, ['ready','recording'], true);
        $settings = $this->currentPagingSettings();
        $prompts = \SLS\MassNotify\LivePagingConfig::promptFiles($settings);
        if ($safe && $decision==='recording') {
            $safe = ($request['voicemail_ready'] ?? false)===true
                && ($request['token'] ?? '')===$this->variable('${SLS_VM_RECORD_TOKEN}')
                && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $this->variable('${SLS_VM_MIX_ID}'))
                && $this->variable('${SLS_VM_STOPPED}')==='0';
            if ($safe) { $this->play($prompts['recording']); }
        }
        if ($safe && $this->playReadyTone()) {
            // Recheck while the native tone continues. Ending a file beep
            // before starting this helper could clip the caller's first word.
            $confirmed = $this->checkCallerCue($raw);
            $remaining = ($confirmed['ok'] ?? false)===true && ($confirmed['decision'] ?? '')===$decision
                && is_int($confirmed['remaining_seconds'] ?? null) ? $confirmed['remaining_seconds'] : 0;
            // Keep the original absolute deadline. The caller's conference
            // menu stays suspended here; the final native dialplan step stops
            // the tone after this AGI exits, immediately before audio resumes.
            $remaining = min($remaining, (int)($request['expires_at'] ?? 0) - time());
            if ($remaining>0) {
                $this->autoHangup($remaining);
                if ($this->openPageInput()) {
                    $this->command('SET VARIABLE SLS_PAGING_CUE_RESULT "' . $decision . '"');
                    return $decision;
                }
            }
        }
        $this->command('SET VARIABLE MUTEAUDIO(in) "on"');
        $this->stopReadyTone();
        $this->command('SET VARIABLE SLS_PAGING_CUE_RESULT "unavailable"');
        $this->play($prompts[$decision==='unavailable' ? 'no_receivers' : 'unavailable']);
        $this->hangup();
        return 'unavailable';
    }

    protected function currentPagingSettings(): array
    {
        return \SLS\MassNotify\LivePagingSession::loadSettings(
            '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');
    }

    protected function pagingConfigHash(): string
    {
        return \FreePBX\modules\SlsConfigCrypto::fileFingerprint(
            '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');
    }

    private function visualMessage(int $seconds): array
    {
        $deadline = hrtime(true) + $seconds * 1000000000;
        while (strpos($this->visualBuffer, "\n") === false) {
            $remaining = ($deadline - hrtime(true)) / 1000000000;
            if ($remaining <= 0 || strlen($this->visualBuffer) > 4096) { throw new \RuntimeException('paging_visual_timeout'); }
            $read = [$this->visualOutput]; $write = null; $except = null;
            $ready = @stream_select($read, $write, $except, (int)$remaining, (int)(($remaining - floor($remaining)) * 1000000));
            if (!$ready) { throw new \RuntimeException('paging_visual_timeout'); }
            $chunk = fread($this->visualOutput, 4097 - strlen($this->visualBuffer));
            if ($chunk === false || $chunk === '') { throw new \RuntimeException('paging_visual_unavailable'); }
            $this->visualBuffer .= $chunk;
        }
        [$line, $this->visualBuffer] = explode("\n", $this->visualBuffer, 2);
        $message = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($message) || array_is_list($message)) { throw new \RuntimeException('paging_visual_unavailable'); }
        return $message;
    }

    private function closeVisualMonitor(): void
    {
        if (is_resource($this->visualInput)) { fclose($this->visualInput); }
        $this->visualInput = null;
        if (is_resource($this->visualProcess)) {
            $deadline = hrtime(true) + 1000000000;
            do {
                $status = proc_get_status($this->visualProcess);
                if (!$status['running']) { break; }
                usleep(10000);
            } while (hrtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($this->visualProcess);
                $deadline = hrtime(true) + 1000000000;
                do { usleep(10000); $status = proc_get_status($this->visualProcess); }
                while ($status['running'] && hrtime(true) < $deadline);
                if ($status['running']) { proc_terminate($this->visualProcess, 9); }
            }
            proc_close($this->visualProcess);
        }
        if (is_resource($this->visualOutput)) { fclose($this->visualOutput); }
        $this->visualProcess = $this->visualOutput = null;
        $this->visualBuffer = '';
    }

    protected function startVisualMonitor(array $request, int $duration): bool
    {
        $pipes = [];
        $process = @proc_open(['/usr/bin/timeout', '--signal=TERM', '--kill-after=1', (string)($duration + 30),
            '/usr/bin/python3', '-I', '/usr/local/bin/sls_mass_notify/sls_live_paging_visual.py', '--request-stdin'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { return false; }
        $this->visualProcess = $process; $this->visualInput = $pipes[0]; $this->visualOutput = $pipes[1];
        stream_set_blocking($this->visualOutput, false);
        try {
            $line = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (strlen($line) > 4096 || fwrite($this->visualInput, $line) !== strlen($line) || !fflush($this->visualInput)) {
                throw new \RuntimeException('paging_visual_unavailable');
            }
            $ready = $this->visualMessage(5);
            if ($ready !== ['ready' => true]) { throw new \RuntimeException('paging_visual_unavailable'); }
            return true;
        } catch (\Throwable $error) {
            $this->closeVisualMonitor();
            return false;
        }
    }

    protected function finishVisualMonitor(): array
    {
        if (!is_resource($this->visualProcess)) { return ['status' => 'not_requested']; }
        try {
            if (is_resource($this->visualInput)) { fclose($this->visualInput); $this->visualInput = null; }
            $result = $this->visualMessage(20);
            if (!in_array($result['status'] ?? '', ['submitted', 'not_requested', 'configuration_changed', 'failed'], true)
                || !is_int($result['attempted_contacts'] ?? null) || $result['attempted_contacts'] < 0
                || $result['attempted_contacts'] > 1000 || !is_int($result['failures'] ?? null) || $result['failures'] < 0
                || (isset($result['reason']) && (!is_string($result['reason']) || !preg_match('/^[a-z_]{1,64}$/D',$result['reason'])))
                || (isset($result['connected_contacts']) && (!is_int($result['connected_contacts'])
                    || $result['connected_contacts'] < 0 || $result['connected_contacts'] > 1000))) {
                return ['status' => 'failed'];
            }
            return $result;
        } catch (\Throwable $error) { return ['status' => 'uncertain']; }
        finally { $this->closeVisualMonitor(); }
    }

    public function notifyGroup(array $group, string $caller, array $settings): array
    {
        $targets = array_values(array_diff((array)($group['notify_extensions'] ?? []), [$caller]));
        $readyCue = ($this->notificationAdmission['live_dial'] ?? '') !== '';
        $recordingCue = ($this->notificationAdmission['voicemail_recording'] ?? false)===true;
        if (!$targets && !$readyCue && !$recordingCue) { return ['status' => 'not_requested', 'requested' => 0]; }
        $authorized = false;
        try {
            $sourceHash = $this->pagingConfigHash();
            $current = $this->currentPagingSettings();
            $fingerprint = static function (array $value): string {
                return hash('sha256', json_encode([$value['live_paging'] ?? [], $value['announcement_groups'] ?? []], JSON_THROW_ON_ERROR));
            };
            if (!hash_equals($sourceHash, $this->pagingConfigHash()) || !hash_equals($fingerprint($settings), $fingerprint($current))) {
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
            $origin = $this->notificationOrigin();
            $request = ['token' => (string)($this->notificationAdmission['token'] ?? ''), 'group' => $group['group_id'],
                'caller' => $caller, 'external' => $this->externalIngress, 'external_number' => $this->externalCallerNumber,
                'channel' => (string)($origin['agi_channel'] ?? ''), 'uniqueid' => (string)($origin['agi_uniqueid'] ?? ''),
                'owner' => bin2hex(random_bytes(16)), 'config_sha256' => $sourceHash,
                'ready_cue'=>$readyCue || $recordingCue, 'voicemail_ready'=>$recordingCue,
                'expires_at'=>(int)($this->notificationAdmission['paging_deadline'] ?? 0)];
            if (!preg_match('/^[a-f0-9]{32}$/D', $request['token'])
                || !preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $request['uniqueid'])
                || $request['channel'] === '' || strlen($request['channel']) > 256 || preg_match('/[\x00-\x20\x7f]/', $request['channel'])) {
                return ['status' => 'failed', 'requested' => count($targets), 'ready_gate'=>false];
            }
            if (!$readyCue && $recordingCue) {
                $this->recordingCueRequest=json_encode($request,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                return ['status'=>'not_requested','requested'=>count($targets),'ready_gate'=>true];
            }
            if ($readyCue) { $this->prepareCallerMenu($request); }
            if (!$this->startVisualMonitor($request, $paging['max_duration_seconds'])) {
                return ['status' => 'failed', 'requested' => count($targets), 'ready_gate'=>!$readyCue];
            }
            $this->visualStarted = true;
            $this->visualRequested = count($targets);
            // This starts observation only. The companion sends no popup until
            // the collector records an exact admitted handset bridge join.
            return ['status' => 'monitoring', 'requested' => count($targets), 'ready_gate'=>true];
        } catch (\Throwable $error) {
            return ['status' => $authorized ? 'failed' : 'configuration_changed', 'requested' => count($targets), 'ready_gate'=>!$readyCue];
        }
    }

    public function finishGroupNotification(): array
    {
        if (!$this->visualStarted) { return ['status' => 'not_requested', 'requested' => 0]; }
        $this->visualStarted = false;
        $requested = $this->visualRequested; $this->visualRequested = 0;
        return $this->finishVisualMonitor() + ['requested' => $requested];
    }
}

$agi = new SlsLivePagingNotifyAgi(STDIN, STDOUT);
// AGI stderr may be discarded by Asterisk. Keep bounded, input-free outcomes
// in the host journal as well; never log entered digits, PINs or raw responses.
$pagingLog = static function (string $message): void {
    error_log($message);
    if (function_exists('openlog') && function_exists('syslog')) {
        openlog('sls-mass-notify-paging', LOG_PID, LOG_DAEMON);
        syslog(LOG_NOTICE, $message);
        closelog();
    }
};
try {
    $environment = $agi->environment();
    if (($environment['agi_request'] ?? '') === '') { exit(64); }
    if (($argv[1] ?? '') === '--ready') {
        $result = $agi->callerReadyCue($environment);
        $pagingLog('SLS live paging caller cue: ' . $result);
        exit(0);
    }
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
    $pagingLog('SLS live paging: ' . (string)$result['status']
        . (isset($result['phone_admission_error']) ? '; phone_error=' . $result['phone_admission_error'] : '')
        . (isset($result['text_notification']) ? '; text=' . (string)($result['text_notification']['status'] ?? 'unknown')
            . '; text_requested=' . (int)($result['text_notification']['requested'] ?? 0) : '')
        . (isset($result['text_completion']) ? '; text_completion=' . (string)($result['text_completion']['status'] ?? 'unknown') : '')
        . (isset($result['text_completion']['reason']) && preg_match('/^[a-z_]{1,64}$/D', (string)$result['text_completion']['reason'])
            ? '; text_reason=' . $result['text_completion']['reason'] : '')
        . (isset($result['text_completion']['connected_contacts']) ? '; connected=' . (int)$result['text_completion']['connected_contacts'] : '')
        . (isset($result['requested_recipients']) ? '; requested=' . (int)$result['requested_recipients'] . '; dialed_contacts=' . (int)$result['dialed_contacts']
            . '; unavailable=' . implode(',', (array)($result['unavailable_recipients'] ?? [])) : '')
        . ($result['status'] === 'dial_string_capacity_exceeded' ? '; reduce the selected group size or the registrations per phone.' : ''));
} catch (\Throwable $error) {
    $known = ['agi_timeout', 'agi_hangup', 'agi_write_failed', 'agi_command_failed', 'invalid_agi_environment',
        'paging_config_unsafe', 'paging_config_unavailable', 'paging_config_invalid', 'paging_prompt_unavailable',
        'paging_state_unavailable', 'paging_state_unsafe', 'paging_state_corrupt', 'paging_state_capacity',
        'paging_state_write_failed', 'paging_auth_state_corrupt', 'paging_capacity_unavailable', 'paging_lock_timeout',
        'json_storage_invalid', 'json_storage_unsafe', 'json_storage_changed', 'json_storage_unavailable',
        'json_storage_missing', 'json_storage_marker_invalid', 'json_storage_busy', 'json_storage_capacity',
        'json_storage_duplicate_key', 'json_storage_write_failed', 'json_storage_commit_uncertain'];
    $category = $error instanceof \JsonException ? 'json_storage_invalid'
        : (in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'unexpected_failure');
    $pagingLog('SLS live paging: ' . $category . '; review live paging configuration, prepared prompts and PBX health.');
    if (str_starts_with($category, 'json_storage_')) {
        try {
            $current = \SLS\MassNotify\LivePagingSession::loadSettings($directory . '/mass-notifications.config');
            $prompt = \SLS\MassNotify\LivePagingConfig::promptFiles($current)['unavailable'];
            $file = $directory . '/sounds/' . substr($prompt, strlen('SLS_Mass_Notifications_Plugin/')) . '.wav';
            if (!is_link($file) && is_file($file) && is_readable($file)) { $agi->play($prompt); }
        } catch (\Throwable $ignored) { }
    }
} finally {
    if (!$agi->hasReturnedToIvr()) {
        try { $agi->hangup(); } catch (\Throwable $ignored) { }
    }
}
