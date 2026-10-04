<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/AutomationConfig.php';
require_once __DIR__ . '/AutomationStore.php';
require_once __DIR__ . '/api/sls-mass-notify/security.php';
require_once __DIR__ . '/ConfigCrypto.php';

/** Read-only authorization at an external transmission boundary. */
final class DeliveryAuthorization
{
    public const CONFIG = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';

    public static function settings(string $path = self::CONFIG): array
    {
        clearstatcache(true, $path);
        $parent = @lstat(dirname($path)); $named = @lstat($path);
        if (!$parent || realpath(dirname($path)) !== dirname($path) || ($parent['mode'] & 0022)
            || !$named || realpath($path) !== $path || ($named['mode'] & 0170000) !== 0100000
            || $named['nlink'] !== 1 || ($named['mode'] & 0027) || $named['size'] > \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES
            || !in_array($named['uid'], [0, $parent['uid']], true)) { throw new \RuntimeException('Protected configuration is unavailable.'); }
        $handle = @fopen($path, 'r+b');
        if (!$handle) { throw new \RuntimeException('Protected configuration is unreadable.'); }
        try {
            $opened = fstat($handle);
            if (!$opened || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']
                || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1 || ($opened['mode'] & 0027)) {
                throw new \RuntimeException('Protected configuration changed.');
            }
            $raw = stream_get_contents($handle, \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES + 1);
            if (!is_string($raw) || strlen($raw) > \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES) { throw new \RuntimeException('Protected configuration exceeds its read limit.'); }
            $settings = \FreePBX\modules\SlsConfigCrypto::decode($raw);
            if (!is_array($settings) || array_is_list($settings)) { throw new \RuntimeException('Protected configuration is invalid.'); }
            return $settings;
        } finally { fclose($handle); }
    }

    public static function permits(array $settings, array $input, string $journal = AutomationStore::DIRECTORY, ?int $now = null): bool
    {
        $now ??= time();
        if (array_diff(array_keys($input), ['automation_context', 'api_credential_id', 'webhook_ids']) || empty($settings['enabled'])) { return false; }
        $ids = $input['webhook_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 10) { return false; }
        foreach ($ids as $id) { if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) { return false; } }
        if (isset($input['automation_context'])) {
            $context = $input['automation_context'];
            if (!is_array($context) || !AutomationConfig::permits($settings, $context, $now) || !is_dir($journal)
                || !(new AutomationStore($journal))->permits($context, $now)) { return false; }
        }
        if (isset($input['api_credential_id'])) {
            if (!is_string($input['api_credential_id'])) { return false; }
            $principal = ApiSecurity::currentCredential($settings, $input['api_credential_id']);
            if (!$principal || !ApiSecurity::permits($principal, 'send')) { return false; }
            if (($principal['audience']['unrestricted'] ?? false) !== true
                && array_diff($ids, ApiSecurity::allowedAudience($principal, $settings)['webhooks'])) { return false; }
        }
        return true;
    }
}
