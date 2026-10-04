<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__ . '/DeviceAcceptance.php';
use SLS\MassNotify\DeviceAcceptance;

trait SlsDeploymentAcceptance
{
    private function requireDeviceAcceptanceAdministrator(): array
    {
        $principal = $this->currentOperator();
        if (($principal['operator_role'] ?? '') !== 'administrator') {
            throw new \DomainException('Only an SLS administrator can record installation test observations.');
        }
        return $principal;
    }

    private function deviceAcceptanceCatalog(array $settings): array
    {
        $result = []; $catalog = $this->locationCatalog($settings);
        foreach (['extensions' => 'phone', 'desktop_client_ids' => 'desktop', 'voice_recipient_ids' => 'external_voice',
            'email_recipient_ids' => 'email', 'sms_recipient_ids' => 'sms', 'webhook_ids' => 'webhook'] as $key => $type) {
            foreach ($catalog[$key] ?? [] as $id => $row) {
                $result[] = ['type' => $type, 'id' => (string)$id, 'label' => $row['label']];
            }
        }
        foreach ($settings['live_paging']['groups'] ?? [] as $group) {
            $result[] = ['type' => 'paging', 'id' => $group['group_id'], 'label' => $group['name'] ?? ('Paging group ' . $group['menu_number'])];
        }
        foreach ($settings['automations']['actions'] ?? [] as $action) {
            $result[] = ['type' => 'action', 'id' => $action['id'], 'label' => $action['name']];
        }
        return $result;
    }

    /** Read exact stored values; recording evidence must not apply defaults. */
    private function deviceAcceptanceConfiguration(string $path): array
    {
        $raw = $this->readNativeBackupFile($path, SlsConfigCrypto::MAX_FILE_BYTES,
            'Protected configuration could not be read consistently. Preserve it and reload before recording a device test.');
        $settings = SlsConfigCrypto::decode($raw);
        if (!is_array($settings) || !$settings || array_is_list($settings)) { throw new \DomainException('Protected configuration must be a valid JSON object. No observation was written.'); }
        return ['settings' => $settings, 'sha256' => hash('sha256', $raw)];
    }

    private static function deviceAcceptanceRevision(string $configurationHash, array $catalog): string
    {
        return hash('sha256', json_encode([$configurationHash, $catalog], JSON_THROW_ON_ERROR));
    }

    public function deviceAcceptanceState(): array
    {
        try { $this->requireDeviceAcceptanceAdministrator(); }
        catch (\Throwable $error) { return ['success' => false, 'error_code' => 'permission_denied', 'message' => 'Sign in as an SLS administrator to record or review device-test observations.']; }
        try {
            $stored = $this->deviceAcceptanceConfiguration(self::SETTINGS_JSON);
            $settings = $this->getActiveSettings(); $catalog = $this->deviceAcceptanceCatalog($settings);
            if (!hash_equals($stored['sha256'], $this->deviceAcceptanceConfiguration(self::SETTINGS_JSON)['sha256'])) {
                return ['success' => false, 'error_code' => 'configuration_changed', 'message' => 'Settings changed while device tests were being loaded. Reload before recording an observation.'];
            }
            return ['success' => true, 'revision' => self::deviceAcceptanceRevision($stored['sha256'], $catalog), 'catalog' => $catalog,
                'checks' => DeviceAcceptance::CHECKS, 'report' => DeviceAcceptance::report($stored['settings'], self::MODULE_VERSION)];
        } catch (\Throwable $error) {
            return ['success' => false, 'error_code' => 'device_tests_unavailable',
                'message' => 'Saved device-test observations or current recipients could not be read safely. Check protected configuration and device discovery before retrying.'];
        }
    }

    public function recordDeviceAcceptance(array $input): array
    {
        try { $principal = $this->requireDeviceAcceptanceAdministrator(); }
        catch (\Throwable $error) { return ['success' => false, 'error_code' => 'permission_denied', 'message' => 'Only an SLS administrator can record a device-test observation.']; }
        $lock = null;
        try {
            $revision = $input['revision'] ?? null; unset($input['revision']);
            if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)) { throw new \DomainException('Reload the device-test form before saving.'); }
            $observation = DeviceAcceptance::observation($input, time());
            $stored = $this->deviceAcceptanceConfiguration(self::SETTINGS_JSON);
            $settings = $this->getActiveSettings(); $catalog = $this->deviceAcceptanceCatalog($settings);
            $target = array_values(array_filter($catalog, static fn($row) => $row['type'] === $observation['target_type'] && $row['id'] === $observation['target_id']));
            if (count($target) !== 1) { throw new \DomainException('That destination is no longer configured. Reload the form and select its current saved identity.'); }
            if (!hash_equals(self::deviceAcceptanceRevision($stored['sha256'], $catalog), $revision)) {
                return ['success' => false, 'error_code' => 'configuration_changed', 'message' => 'Settings, recipient identities or test history changed. Reload before recording this observation.'];
            }
            $lock = $this->acquireSettingsLock(true);
            $stored = $this->deviceAcceptanceConfiguration(self::SETTINGS_JSON); $active = $stored['settings'];
            if (!hash_equals(self::deviceAcceptanceRevision($stored['sha256'], $catalog), $revision)) {
                return ['success' => false, 'error_code' => 'configuration_changed', 'message' => 'Another request changed SLS settings. Reload before recording this observation.'];
            }
            $history = DeviceAcceptance::normalize($active['device_acceptance'] ?? []);
            array_unshift($history['records'], $observation + ['id' => 'dtest_' . bin2hex(random_bytes(12)),
                'target_label' => $target[0]['label'], 'recorded_at' => gmdate('c'), 'recorded_by' => $principal['username'],
                'module_version' => self::MODULE_VERSION, 'configuration_fingerprint' => DeviceAcceptance::fingerprint($active, self::MODULE_VERSION)]);
            if (count($history['records']) > DeviceAcceptance::MAX_RECORDS) { array_pop($history['records']); $history['retired_records']++; }
            $history = DeviceAcceptance::normalize($history);
            if (is_file(self::PENDING_SETTINGS_JSON) || is_link(self::PENDING_SETTINGS_JSON)) {
                $pending = $this->deviceAcceptanceConfiguration(self::PENDING_SETTINGS_JSON)['settings']; $pending['device_acceptance'] = $history;
                $this->writeSettingsFileUnlocked(self::PENDING_SETTINGS_JSON, $pending, false);
            }
            $active['device_acceptance'] = $history;
            $this->writeSettingsFileUnlocked(self::SETTINGS_JSON, $active, true);
            return ['success' => true, 'message' => 'Observation recorded. No notification was sent and no Apply Config is needed.',
                'report' => DeviceAcceptance::report($active, self::MODULE_VERSION)];
        } catch (\Throwable $error) {
            return ['success' => false, 'error_code' => 'device_test_save_failed',
                'message' => $error instanceof \DomainException || $error instanceof SlsConfigurationWriteException ? $error->getMessage()
                    : 'The device-test record could not be confirmed. Reload its history and inspect protected configuration storage before retrying.'];
        } finally { if ($lock !== null) { $this->releaseSettingsLock($lock); } }
    }
}
