<?php
declare(strict_types=1);
namespace FreePBX\modules;

/** Record secret-bearing exports before handing their bytes to a caller. */
trait SlsAdminAudit
{
    private function auditSensitiveExport(string $kind, array $settings): void
    {
        if (!in_array($kind, ['plain', 'encrypted', 'native'], true)) {
            throw new \LogicException('Unknown configuration export kind.');
        }
        $user = $_SESSION['AMP_user'] ?? null;
        $identity = is_object($user) && is_string($user->username ?? null) ? $user->username : '';
        $actor = $identity !== '' ? 'web:' . $identity : (PHP_SAPI === 'cli' ? 'cli' : 'web:identity-unavailable');
        $actor = substr(preg_replace('/[^\x20-\x7e]/', '_', $actor), 0, 160);
        $address = $_SERVER['REMOTE_ADDR'] ?? '';
        $record = [
            'created_at' => gmdate('c'), 'ip' => is_string($address) && filter_var($address, FILTER_VALIDATE_IP) ? $address : '',
            'method' => PHP_SAPI === 'cli' ? 'CLI' : 'POST', 'action' => 'config_export_' . $kind,
            'status' => 200, 'ok' => true, 'actor' => $actor, 'event_id' => 'audit_' . bin2hex(random_bytes(16)),
        ];
        // Never pass exported settings, passwords, configuration hashes, request
        // bodies, cookies or forwarded address headers to the audit helper.
        $body = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $command = ['/usr/bin/timeout', '--signal=KILL', '2', '/usr/bin/python3', '-I',
            self::RUNTIME_DIR . '/sls_storage_maintenance.py', '--append-security-audit'];
        if (($settings['control_api']['audit_syslog'] ?? '0') === '1') { $command[] = '--syslog'; }
        $process = null; $pipes = []; $failure = 'audit_helper_unavailable';
        try {
            $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes, null, null, ['bypass_shell' => true]);
            if (is_resource($process)) {
                $written = @fwrite($pipes[0], $body); fclose($pipes[0]); unset($pipes[0]);
                $output = stream_get_contents($pipes[1], 4097); fclose($pipes[1]); unset($pipes[1]);
                $exit = proc_close($process); $process = null;
                $result = is_string($output) && strlen($output) <= 4096 ? json_decode($output, true) : null;
                if ($written === strlen($body) && $exit === 0 && is_array($result) && ($result['ok'] ?? null) === true) { return; }
                $failure = is_array($result) && preg_match('/^audit_[a-z_]{1,58}$/D', (string)($result['error_code'] ?? ''))
                    ? $result['error_code'] : 'audit_helper_failed';
            }
        } catch (\Throwable $error) { $failure = 'audit_helper_failed'; }
        finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_close($process); }
        }
        throw new \DomainException(sprintf(_('Configuration export was withheld because its security audit could not be recorded (%s). Check the audit storage and forwarding status in Deployment Readiness, correct the problem, and retry.'), $failure));
    }
}
