<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/AutomationConfig.php';
require_once __DIR__ . '/AutomationStore.php';
require_once __DIR__ . '/api/sls-mass-notify/security.php';
require_once __DIR__ . '/ConfigCrypto.php';
require_once __DIR__ . '/bin/sls_mass_notify/sls_runtime_state.php';

/** Read-only authorization at an external transmission boundary. */
final class DeliveryAuthorization
{
    public const CONFIG = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';

    public static function settings(string $path = self::CONFIG): array
    {
        clearstatcache(true, $path);
        $parent = @lstat(dirname($path));
        if (!$parent || ($parent['mode'] & 0170000) !== 0040000 || realpath(dirname($path)) !== dirname($path)
            || ($parent['mode'] & 0022)) { throw new \RuntimeException('Protected configuration is unavailable.'); }
        return \FreePBX\modules\SlsConfigCrypto::readFile($path);
    }

    public static function parseInput(string $raw): array
    {
        if ($raw === '' || strlen($raw) > 16384) { throw new \InvalidArgumentException('Invalid authorization input.'); }
        return ApiSecurity::jsonObject($raw, false, 12);
    }

    /** Return the webhook audience frozen by the current automation template. */
    private static function automationWebhookIds(array $settings, array $context, int $now): ?array
    {
        try {
            $resolved = AutomationConfig::resolvePermitted($settings, $context, $now);
            if ($resolved === null) { return null; }
            if (!is_array($resolved['template'] ?? null)) { return []; }
            $delivery = IncidentConfig::delivery($resolved['template']['delivery'] ?? []);
        } catch (\Throwable $error) { return null; }
        $allowed = $delivery['webhook_ids'];
        if (!$delivery['group_ids']) { return $allowed; }
        $groups = [];
        $savedGroups = $settings['announcement_groups'] ?? [];
        if (!is_array($savedGroups) || !array_is_list($savedGroups)) { return null; }
        foreach ($savedGroups as $group) {
            if (!is_array($group) || !is_string($group['id'] ?? null) || isset($groups[$group['id']])) { return null; }
            $groups[$group['id']] = $group;
        }
        foreach ($delivery['group_ids'] as $groupId) {
            $group = $groups[$groupId] ?? null;
            $ids = is_array($group) ? ($group['webhook_ids'] ?? []) : null;
            if (!is_array($ids) || !array_is_list($ids)) { return null; }
            foreach ($ids as $id) {
                if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) { return null; }
                $allowed[] = $id;
            }
        }
        return array_values(array_unique($allowed));
    }

    public static function permits(array $settings, array $input, string $journal = AutomationStore::DIRECTORY, ?int $now = null): bool
    {
        $now ??= time();
        if (array_diff(array_keys($input), ['automation_context', 'api_credential_id', 'webhook_ids'])
            || (!array_key_exists('automation_context', $input) && !array_key_exists('api_credential_id', $input))
            || !RuntimeState::running($settings)) { return false; }
        $ids = $input['webhook_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 10) { return false; }
        $seen=[];
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) || isset($seen[$id])) { return false; }
            $seen[$id]=true;
        }
        if (array_key_exists('automation_context', $input)) {
            $context = $input['automation_context'];
            if (!is_array($context) || !is_dir($journal) || !(new AutomationStore($journal))->permits($context, $now)) { return false; }
            $allowed = self::automationWebhookIds($settings, $context, $now);
            if ($allowed === null || array_diff($ids, $allowed)) { return false; }
        }
        if (array_key_exists('api_credential_id', $input)) {
            if (!is_string($input['api_credential_id'])) { return false; }
            $principal = ApiSecurity::currentCredential($settings, $input['api_credential_id']);
            if (!$principal || !ApiSecurity::permits($principal, 'send')) { return false; }
            if (($principal['audience']['unrestricted'] ?? false) !== true
                && array_diff($ids, ApiSecurity::allowedAudience($principal, $settings)['webhooks'])) { return false; }
        }
        return true;
    }
}
