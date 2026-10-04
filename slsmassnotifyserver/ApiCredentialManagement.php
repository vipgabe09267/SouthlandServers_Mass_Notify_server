<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__ . '/api/sls-mass-notify/security.php';

/** Administrator-only methods; the existing FreePBX controller enforces CSRF. */
trait SlsApiCredentialManagement
{
    public function apiCredentialMetadata(): array
    {
        $rows = \SLS\MassNotify\ApiSecurity::credentials($this->getActiveSettings()['control_api']['credentials'] ?? []);
        foreach ($rows as &$row) { unset($row['secret_hash']); } unset($row);
        return $rows;
    }

    public function manageApiCredential(array $input): array
    {
        $lock = null; $issued = null;
        try {
            $action = $input['credential_action'] ?? '';
            if (!in_array($action, ['create', 'revoke'], true)) { throw new \DomainException('Choose Create credential or Revoke.'); }
            if ($action === 'create') {
                if (!is_string($input['credential_json'] ?? null) || strlen($input['credential_json']) > 131072) {
                    throw new \DomainException('The credential permission form is missing or too large. Reload and try again.');
                }
                $definition = json_decode($input['credential_json'], true, 16);
                if (!is_array($definition) || array_diff(array_keys($definition), ['name', 'scopes', 'audience'])) {
                    throw new \DomainException('The credential permission form is invalid.');
                }
                $issued = \SLS\MassNotify\ApiSecurity::issue($definition);
            } elseif (!is_string($input['credential_id'] ?? null) || !preg_match('/^api_[a-f0-9]{24}$/D', $input['credential_id'])) {
                throw new \DomainException('Select an existing credential to revoke.');
            }
            $this->ensurePluginDataDir();
            // Re-read both files under the existing configuration mutex. Do not
            // replace unrelated staged edits with the active configuration.
            $lock = $this->acquireSettingsLock();
            $active = $this->loadSettingsFile(self::SETTINGS_JSON);
            if (!is_array($active['control_api'] ?? null)) { throw new \DomainException('The active Control API configuration is unavailable.'); }
            $rows = \SLS\MassNotify\ApiSecurity::credentials($active['control_api']['credentials'] ?? []);
            if ($action === 'create') {
                $rows[] = $issued['credential'];
                $rows = \SLS\MassNotify\ApiSecurity::credentials($rows);
            } else { $rows = \SLS\MassNotify\ApiSecurity::revoke($rows, $input['credential_id']); }
            // Write the pending copy first. If its write fails, active keys have
            // not changed. If the active write then fails, a later Apply cannot
            // accidentally resurrect a successfully staged revocation.
            if (is_file(self::PENDING_SETTINGS_JSON) || is_link(self::PENDING_SETTINGS_JSON)) {
                $pending = $this->loadSettingsFile(self::PENDING_SETTINGS_JSON);
                if (!is_array($pending['control_api'] ?? null)) { throw new \DomainException('Staged Control API settings are invalid. Resolve them before changing credentials.'); }
                $pending['control_api']['credentials'] = $rows;
                $this->writeSettingsFileUnlocked(self::PENDING_SETTINGS_JSON, $pending, false);
            }
            $active['control_api']['credentials'] = $rows;
            $this->writeSettingsFileUnlocked(self::SETTINGS_JSON, $active, true);
            foreach ($rows as &$row) { unset($row['secret_hash']); } unset($row);
            $result = ['success' => true, 'credentials' => $rows,
                'message' => $action === 'create' ? 'Credential created. Copy its secret now; it will not be displayed again.' : 'Credential revoked immediately. Applying staged settings will not restore it.'];
            if ($issued !== null) { $result['secret'] = $issued['secret']; $result['credential_id'] = $issued['credential']['id']; }
            return $result;
        } catch (\Throwable $error) {
            $message = $error instanceof \DomainException || $error instanceof \FreePBX\modules\SlsConfigurationWriteException
                ? $error->getMessage() : 'The credential change could not be confirmed. Reload the credential list and check protected configuration storage before retrying.';
            return ['success' => false, 'message' => $message];
        } finally { if ($lock !== null) { $this->releaseSettingsLock($lock); } }
    }
}
