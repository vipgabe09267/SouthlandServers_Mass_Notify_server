<?php
declare(strict_types=1);
namespace FreePBX\modules;

/** Recovery evidence has no path back into executable work queues. */
trait SlsOperationalBackup
{
    private function operationalBackupCommand(string $action, string $file, ?string $sha256 = null): array
    {
        if (!in_array($action, ['snapshot', 'verify', 'archive'], true) || $file === '' || $file[0] !== '/'
            || ($sha256 !== null && !preg_match('/^[a-f0-9]{64}$/D', $sha256))) {
            throw new \LogicException('Invalid operational backup invocation.');
        }
        $command = ['/usr/bin/timeout', '--signal=KILL', '20', '/usr/bin/python3', '-I',
            self::RUNTIME_DIR . '/sls_operational_backup.py', $action, '--data', self::PLUGIN_DATA_DIR, '--file', $file];
        if ($sha256 !== null) { $command[] = '--sha256'; $command[] = $sha256; }
        $process = null; $pipes = [];
        try {
            $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) { throw new \RuntimeException(_('The operational backup helper could not start. Verify the installed runtime and PHP process support.')); }
            $output = stream_get_contents($pipes[1], 4097); fclose($pipes[1]); unset($pipes[1]);
            $exit = proc_close($process); $process = null;
            $result = is_string($output) && strlen($output) <= 4096 ? json_decode($output, true) : null;
            if ($exit !== 0 || !is_array($result) || ($result['ok'] ?? null) !== true) {
                $message = is_array($result) && is_string($result['message'] ?? null) ? $result['message']
                    : _('Operational recovery evidence could not be verified within 20 seconds. Check runtime files, protected storage and backup load before retrying. No delivery history was activated.');
                throw new \RuntimeException($message);
            }
            return $result;
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_close($process); }
        }
    }
}
