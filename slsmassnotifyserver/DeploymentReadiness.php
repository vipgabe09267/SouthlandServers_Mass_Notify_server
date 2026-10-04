<?php
namespace FreePBX\modules;

/** Read-only observations; no repair, notification, clock or service changes. */
final class SlsDeploymentReadiness
{
    public static function snapshot(string $path): array
    {
        clearstatcache(true, $path);
        $named = @lstat($path);
        if ($named === false) { return ['error'=>'missing']; }
        if (($named['mode'] & 0170000) !== 0100000 || $named['nlink'] !== 1
            || ($named['mode'] & 0022) || $named['size'] > 65536 || realpath($path) !== $path) {
            return ['error'=>'unsafe'];
        }
        // r+b avoids blocking indefinitely if a FIFO replaces the checked path.
        // Nothing is written, even if the snapshot is corrupt or absent.
        $handle = @fopen($path, 'r+b');
        if (!$handle) { return ['error'=>'unavailable']; }
        try {
            $opened = fstat($handle);
            if (!$opened || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1
                || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) {
                return ['error'=>'changed'];
            }
            if (!flock($handle, LOCK_SH | LOCK_NB)) { return ['error'=>'busy']; }
            $raw = stream_get_contents($handle, 65537);
            clearstatcache(true, $path); $current = @lstat($path);
            if (!$current || $current['ino'] !== $opened['ino'] || $current['dev'] !== $opened['dev']) {
                return ['error'=>'changed'];
            }
            if (!is_string($raw) || strlen($raw) > 65536) { return ['error'=>'oversized']; }
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            return is_array($data) && isset($raw[0]) && $raw[0] === '{' ? ['data'=>$data] : ['error'=>'invalid'];
        } catch (\Throwable $error) { return ['error'=>'invalid']; }
        finally { fclose($handle); }
    }

    public static function check(string $id, string $label, string $state, string $detail): array
    {
        return ['id'=>$id, 'label'=>$label, 'state'=>$state, 'detail'=>$detail];
    }

    public static function heartbeat(string $id, string $label, array $snapshot, int $maximumAge, int $now): array
    {
        $value = $snapshot['data']['checked_at'] ?? null;
        $timestamp = null;
        if (is_int($value) && $value > 0) { $timestamp = $value; }
        elseif (is_string($value) && strlen($value) <= 40
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            $parsed = strtotime($value); if ($parsed !== false) { $timestamp = $parsed; }
        }
        if (isset($snapshot['error'])) {
            $detail = $snapshot['error'] === 'busy'
                ? _('The health snapshot is being updated. Retry this check shortly.')
                : _('The health snapshot is missing or unreadable. Check the SLS maintenance job and protected data permissions.');
            return self::check($id, $label, 'warning', $detail);
        }
        if ($timestamp === null || $timestamp > $now + 60) {
            return self::check($id, $label, 'warning', _('The health timestamp is invalid or ahead of the PBX clock. Check time synchronization and rerun maintenance.'));
        }
        $age = max(0, $now - $timestamp);
        return self::check($id, $label, $age <= $maximumAge ? 'ok' : 'warning',
            $age <= $maximumAge ? sprintf(_('Updated %d seconds ago.'), $age)
                : sprintf(_('No fresh health snapshot for %d seconds; expected within %d seconds. Check cron and the SLS maintenance log.'), $age, $maximumAge))
            + ['age_seconds'=>$age, 'maximum_age_seconds'=>$maximumAge];
    }

    public static function clockResult(string $output, int $exitCode): array
    {
        if ($exitCode !== 0 || strlen($output) > 4096) {
            return self::check('clock', _('Clock synchronization'), 'unknown',
                _('The operating system did not provide its clock synchronization status. Check the configured time service; no clock settings were changed.'));
        }
        $values = [];
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/^(NTPSynchronized|NTP)=(yes|no)$/D', $line, $match)) { $values[$match[1]] = $match[2]; }
        }
        if (!isset($values['NTPSynchronized'])) { return self::clockResult('', 1); }
        return self::check('clock', _('Clock synchronization'), $values['NTPSynchronized'] === 'yes' ? 'ok' : 'warning',
            $values['NTPSynchronized'] === 'yes' ? _('The operating system reports a synchronized clock.')
                : _('The operating system does not report a synchronized clock. Check its NTP or other time service; scheduling, expiry and signed callbacks depend on correct time.'));
    }

    public static function audit(array $snapshot, bool $forwarding): array
    {
        $storage = $snapshot['data'] ?? [];
        $localActive = $storage['audit_fault_active'] ?? (!empty($storage['audit_failed_records']) || !empty($storage['audit_failure_code']));
        $localOk = !isset($snapshot['error']) && $localActive === false && empty($storage['audit_at_capacity']);
        $local = self::check('audit_storage', _('Control API audit storage'), $localOk ? 'ok' : 'warning',
            $localOk ? sprintf(_('No current write fault. Historical missing records: %d; recovery does not reconstruct those records.'), (int)($storage['audit_failed_records'] ?? 0))
                : _('Audit storage has a current fault or unreadable health data. Check storage capacity, control-api-audit-fault.json and the maintenance log. Do not replay accepted API actions.'));
        $state = 'ok'; $detail = _('Optional forwarding to the system logger is disabled.');
        if ($forwarding) {
            if (isset($snapshot['error']) || ($storage['audit_forwarding_enabled'] ?? false) !== true
                || ($storage['audit_forwarding_active'] ?? false) !== false) {
                $state = 'warning';
                $detail = _('Local audit forwarding could not be confirmed. Check /dev/log, system logger health and the next maintenance snapshot. Remote receipt must be checked at the collector.');
            } elseif (empty($storage['audit_forwarding_last_success_at'])) {
                $state = 'unknown';
                $detail = _('Forwarding is enabled, but no successful local handoff has been recorded. Make an authorized read-only API request and check both local logging and the separate collector.');
            } else {
                $detail = sprintf(_('Last local logger acceptance: %s UTC. Historical failed handoffs: %d. This does not confirm remote collector receipt.'),
                    gmdate('Y-m-d H:i:s', (int)$storage['audit_forwarding_last_success_at']), (int)($storage['audit_forwarding_failed_records'] ?? 0));
            }
        }
        return [$local, self::check('audit_forwarding', _('Control API audit forwarding'), $state, $detail)];
    }

    public static function clock(): array
    {
        $output = []; $code = 1;
        @exec('/usr/bin/timeout --kill-after=1 2 /usr/bin/timedatectl show -p NTPSynchronized -p NTP 2>/dev/null', $output, $code);
        return self::clockResult(implode("\n", $output), $code);
    }

    public static function certificate(array $probe, int $now): array
    {
        if (($probe['ok'] ?? false) !== true) {
            return self::check('certificate', _('Local HTTPS certificate'), 'warning',
                (string)($probe['message'] ?? _('The local HTTPS certificate and API route could not be verified.')));
        }
        $expiry = $probe['certificate_expires_at'] ?? null;
        if (!is_int($expiry)) {
            return self::check('certificate', _('Local HTTPS certificate'), 'unknown',
                _('The HTTPS connection verified successfully, but its certificate expiry could not be read. Inspect the certificate in FreePBX Certificate Management.'));
        }
        $remaining = $expiry - $now;
        return self::check('certificate', _('Local HTTPS certificate'), $remaining > 30 * 86400 ? 'ok' : 'warning',
            $remaining > 30 * 86400 ? sprintf(_('Trusted certificate expires %s UTC.'), gmdate('Y-m-d H:i', $expiry))
                : sprintf(_('Certificate expiry is %s UTC. Check renewal and confirm that HTTPS serves the renewed certificate.'), gmdate('Y-m-d H:i', $expiry)))
            + ['expires_at'=>gmdate('c', $expiry), 'seconds_remaining'=>$remaining];
    }

    public static function capacity(array $probe): array
    {
        $errors = is_array($probe['errors'] ?? null) ? $probe['errors'] : [];
        if (($probe['schema'] ?? null) !== 1 && !$errors) {
            return self::check('capacity', _('PBX resources and SLS workspace'), 'unknown', _('The resource check did not return a valid measurement. Run Repair Installation and review the capacity checker.'));
        }
        $details = [];
        foreach (array_slice($errors, 0, 8) as $error) {
            if (is_array($error) && is_string($error['message'] ?? null)) { $details[] = mb_substr($error['message'], 0, 1024); }
        }
        return self::check('capacity', _('PBX resources and SLS workspace'), $errors ? 'warning' : 'ok',
            $errors ? (implode(' ', $details) ?: _('The PBX does not meet its configured device capacity. Review CPU, RAM and free SLS workspace in capacity diagnostics.'))
                : _('CPU, RAM and free workspace meet the policy for the configured phone and desktop capacities. Retained history and actual PBX load still need separate provisioning.'));
    }
}
