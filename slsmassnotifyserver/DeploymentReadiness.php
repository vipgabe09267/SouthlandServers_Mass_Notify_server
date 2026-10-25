<?php
namespace FreePBX\modules;
require_once __DIR__.'/api/sls-mass-notify/security.php';

/** Read-only observations; no repair, notification, clock or service changes. */
final class SlsDeploymentReadiness
{
    private const RUNTIME_CODES = [
        'Central config'=>'central_config', 'Central config loader'=>'central_config_loader',
        'SIP NOTIFY sender'=>'sip_notify_sender', 'NWS poller'=>'weather_observer',
        'Weather scheduler'=>'weather_scheduler', 'Weather delivery worker'=>'weather_worker',
        'Announcement scheduler'=>'announcement_scheduler', 'General announcement worker'=>'announcement_worker',
        'Phone admission and outcome collector'=>'phone_collector', 'Xweather poller'=>'lightning_observer',
        'Branded email sender'=>'email_sender', 'Branded Discord sender'=>'discord_sender',
        'Notification destination dispatcher'=>'destination_dispatcher', 'System/error email notifier'=>'fault_email',
        'Weather zone status helper'=>'weather_status', 'Weather cross-zone delivery coordinator'=>'weather_deduplication',
        'Maintenance worker'=>'maintenance_worker', 'Piper binary'=>'speech_binary',
        'Executable runtime ownership'=>'runtime_permissions', 'Piper voice'=>'speech_voice',
        'Notification log'=>'notification_log', 'Desktop journal'=>'desktop_journal',
        'Local email transport'=>'local_mail', 'Postfix service and queue'=>'postfix_service', 'Storage and delivery monitor'=>'storage_monitor', 'Control API'=>'control_api',
        'Storage free-space measurement'=>'storage_measurement', 'External delivery queue'=>'external_queue',
        'Weather delivery queue'=>'weather_queue',
    ];

    public static function runtimeCode(array $check): string
    {
        if (is_string($check['code'] ?? null) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $check['code'])) {
            return $check['code'];
        }
        foreach (self::RUNTIME_CODES as $label=>$code) {
            if (($check['label'] ?? null) === _($label)) { return $code; }
        }
        // Unknown legacy checks remain visible, but cannot acquire a misleading
        // stable identity from a translated label or their position in a list.
        return 'unclassified_runtime_check';
    }
    public static function snapshot(string $path,int $maximum=65536): array
    {
        if($maximum<1||$maximum>4194304){return ['error'=>'invalid_limit'];}
        clearstatcache(true, $path);
        $named = @lstat($path);
        if ($named === false) { return ['error'=>'missing']; }
        $runtime=function_exists('posix_getpwnam')?posix_getpwnam('asterisk'):false;
        $owners=array_values(array_unique([0,function_exists('posix_geteuid')?posix_geteuid():-1,is_array($runtime)?$runtime['uid']:-1]));
        $safe=static fn($meta): bool=>is_array($meta)&&($meta['mode']&0170000)===0100000&&$meta['nlink']===1
            && !($meta['mode']&0037)&&in_array($meta['uid'],$owners,true)&&$meta['size']<=$maximum;
        $parent=@lstat(dirname($path));
        if (!$safe($named) || !$parent || ($parent['mode']&0170000)!==0040000 || ($parent['mode']&0022)
            || !in_array($parent['uid'],$owners,true) || realpath(dirname($path))!==dirname($path) || realpath($path) !== $path) {
            return ['error'=>'unsafe'];
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) { return ['error'=>'unavailable']; }
        try {
            $opened = fstat($handle);
            if (!$safe($opened)
                || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) {
                return ['error'=>'changed'];
            }
            if (!flock($handle, LOCK_SH | LOCK_NB)) { return ['error'=>'busy']; }
            $raw = stream_get_contents($handle, $maximum+1);
            $after=fstat($handle);clearstatcache(true, $path); $current = @lstat($path);
            foreach(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $field){
                if(!$safe($after)||!$safe($current)||$named[$field]!==$opened[$field]
                    ||$opened[$field]!==$after[$field]||$after[$field]!==$current[$field]){return ['error'=>'changed'];}
            }
            if (!is_string($raw) || strlen($raw) > $maximum) { return ['error'=>'oversized']; }
            $data = \SLS\MassNotify\ApiSecurity::jsonObject($raw,false,32);
            return ['data'=>$data];
        } catch (\Throwable $error) { return ['error'=>'invalid']; }
        finally { fclose($handle); }
    }

    private static function timestamp(string $value): ?int
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$value)){return null;}
        $canonical=str_ends_with($value,'Z')?substr($value,0,-1).'+00:00':$value;
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$canonical);
        $errors=\DateTimeImmutable::getLastErrors();
        return $parsed!==false&&($errors===false||($errors['warning_count']===0&&$errors['error_count']===0))
            &&$parsed->format('Y-m-d\TH:i:sP')===$canonical?$parsed->getTimestamp():null;
    }

    public static function check(string $id, string $label, string $state, string $detail): array
    {
        return ['id'=>$id, 'code'=>$id, 'label'=>$label, 'state'=>$state, 'detail'=>$detail,
            'reason_code'=>$state === 'ok' ? 'healthy' : ($state === 'unknown' ? 'verification_unavailable' : 'attention_required')];
    }

    public static function heartbeat(string $id, string $label, array $snapshot, int $maximumAge, int $now): array
    {
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        $value = $data['checked_at'] ?? null;
        $timestamp = null;
        if (is_int($value) && $value > 0) { $timestamp = $value; }
        elseif (is_string($value) && strlen($value) <= 40
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            $timestamp = self::timestamp($value);
        }
        if($maximumAge<1||$now<1){return self::check($id,$label,'warning',_('The health-check time boundary is invalid. Review the deployment readiness configuration.'));}
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
        $storage = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        $localActive = $storage['audit_fault_active'] ?? (!empty($storage['audit_failed_records']) || !empty($storage['audit_failure_code']));
        $complete = is_bool($storage['audit_fault_active'] ?? null) && is_bool($storage['audit_at_capacity'] ?? null)
            && is_int($storage['audit_failed_records'] ?? null) && $storage['audit_failed_records']>=0;
        $localOk = !isset($snapshot['error']) && $complete && $localActive === false && $storage['audit_at_capacity'] === false;
        $localUnknown = !isset($snapshot['error']) && !$complete && $localActive === false && empty($storage['audit_at_capacity']);
        $local = self::check('audit_storage', _('Control API audit storage'), $localOk ? 'ok' : ($localUnknown ? 'unknown' : 'warning'),
            $localOk ? sprintf(_('No current write fault. Historical missing records: %d; recovery does not reconstruct those records.'), (int)($storage['audit_failed_records'] ?? 0))
                : ($localUnknown ? _('Audit health measurements are incomplete. Check the next SLS maintenance snapshot; no healthy state was inferred.')
                    : _('Audit storage has a current fault or unreadable health data. Check storage capacity, control-api-audit-fault.json and the maintenance log. Do not replay accepted API actions.')));
        $state = 'ok'; $detail = _('Optional forwarding to the system logger is disabled.');
        if ($forwarding) {
            $forwardingComplete=($storage['audit_forwarding_enabled']??null)===true
                && is_bool($storage['audit_forwarding_active']??null)
                && is_int($storage['audit_forwarding_last_success_at']??null)
                && $storage['audit_forwarding_last_success_at']>=0
                && is_int($storage['audit_forwarding_failed_records']??null)
                && $storage['audit_forwarding_failed_records']>=0;
            if (isset($snapshot['error']) || !$forwardingComplete
                || $storage['audit_forwarding_active'] !== false) {
                $state = 'warning';
                $detail = _('Local audit forwarding could not be confirmed. Check /dev/log, system logger health and the next maintenance snapshot. Remote receipt must be checked at the collector.');
            } elseif ($storage['audit_forwarding_last_success_at'] === 0) {
                $state = 'unknown';
                $detail = _('Forwarding is enabled, but no successful local handoff has been recorded. Make an authorized read-only API request and check both local logging and the separate collector.');
            } else {
                $detail = sprintf(_('Last local logger acceptance: %s UTC. Historical failed handoffs: %d. This does not confirm remote collector receipt.'),
                    gmdate('Y-m-d H:i:s', $storage['audit_forwarding_last_success_at']), $storage['audit_forwarding_failed_records']);
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
            $message = $probe['message'] ?? null;
            if (!is_string($message) || $message === '' || strlen($message) > 1024 || !preg_match('//u', $message)
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message)) {
                $message = _('The local HTTPS certificate and API route could not be verified.');
            }
            return self::check('certificate', _('Local HTTPS certificate'), 'warning',
                $message);
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
        $errorsValid = is_array($probe['errors'] ?? null);
        $errors = $errorsValid ? $probe['errors'] : [];
        if (!$errorsValid || (($probe['schema'] ?? null) !== 1 && !$errors)) {
            return self::check('capacity', _('PBX resources and SLS workspace'), 'unknown', _('The resource check did not return a valid measurement. Run Repair Installation and review the capacity checker.'));
        }
        $resourceChecks = is_array($probe['resource_checks'] ?? null) ? $probe['resource_checks'] : [];
        if (!$errors) {
            foreach (['cpu','memory','storage','temporary'] as $metric) {
                $measurement = is_array($resourceChecks[$metric] ?? null) ? $resourceChecks[$metric] : [];
                $ok=$measurement['ok'] ?? null;
                if (!is_bool($ok)) {
                    return self::check('capacity', _('PBX resources and SLS workspace'), 'unknown', _('Resource measurements are incomplete. Rerun the capacity check and inspect its diagnostics before increasing device limits.'));
                }
                if (!$ok) {
                    return self::check('capacity', _('PBX resources and SLS workspace'), 'warning', _('A resource check failed without a shortage detail. Inspect the capacity diagnostics before increasing device limits.'));
                }
            }
        }
        $details = [];
        foreach (array_slice($errors, 0, 8) as $error) {
            $message = is_array($error) ? ($error['message'] ?? null) : null;
            if (is_string($message) && preg_match('//u', $message) && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $message)) {
                $details[] = function_exists('mb_substr') ? mb_substr($message, 0, 1024, 'UTF-8') : substr($message, 0, 1024);
            }
        }
        return self::check('capacity', _('PBX resources and SLS workspace'), $errors ? 'warning' : 'ok',
            $errors ? (implode(' ', $details) ?: _('The PBX does not meet its configured device capacity. Review CPU, RAM and free SLS workspace in capacity diagnostics.'))
                : _('CPU, RAM and free workspace meet the policy for the configured phone and desktop capacities. Retained history and actual PBX load still need separate provisioning.'));
    }
}
