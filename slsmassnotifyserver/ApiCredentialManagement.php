<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__.'/ImmediateSettings.php';
require_once __DIR__ . '/api/sls-mass-notify/security.php';

/** Administrator-only methods; the existing FreePBX controller enforces CSRF. */
trait SlsApiCredentialManagement
{
    public function apiCredentialMetadata(): array
    {
        $rows = \SLS\MassNotify\ApiSecurity::credentials($this->getActiveSettings()['control_api']['credentials'] ?? []);
        foreach ($rows as &$row) { unset($row['secret_hash']); } unset($row);
        return $this->apiCredentialUsageMetadata($rows);
    }

    private function apiCredentialUsageMetadata(array $rows): array
    {
        require_once __DIR__.'/DeploymentReadiness.php';
        $snapshot=SlsDeploymentReadiness::snapshot(dirname(self::SETTINGS_JSON).'/support-snapshot.json')['data']??[];
        $sample=$snapshot['checked_at']??null;
        $valid=($snapshot['schema']??null)===1&&is_int($sample)&&$sample>0&&$sample<=time()+60;
        foreach($rows as &$row){
            $at=$snapshot['api']['credential_usage'][$row['id']]??null;
            $row['last_used_at']=$valid&&is_int($at)&&$at>0&&$at<=$sample+60?$at:null;
            $row['usage_sampled_at']=$valid?$sample:null;
        }unset($row);
        return $rows;
    }

    public function manageApiCredential(array $input): array
    {
        $lock = null; $issued = null;
        try {
            $action = $input['credential_action'] ?? '';
            if (!in_array($action, ['create', 'revoke', 'rotate'], true)) { throw new \DomainException('Choose Create credential, Rotate or Revoke.'); }
            if ($action === 'create') {
                if (!is_string($input['credential_json'] ?? null) || strlen($input['credential_json']) > 131072) {
                    throw new \DomainException('The credential permission form is missing or too large. Reload and try again.');
                }
                try {
                    $definition = \SLS\MassNotify\ApiSecurity::jsonObject($input['credential_json'], false, 16);
                } catch (\JsonException | \InvalidArgumentException $error) {
                    throw new \DomainException('The credential permission form is invalid.');
                }
                if (!is_array($definition) || array_diff(array_keys($definition), ['name', 'scopes', 'audience', 'expires_at'])) {
                    throw new \DomainException('The credential permission form is invalid.');
                }
                $issued = \SLS\MassNotify\ApiSecurity::issue($definition);
            } elseif (!is_string($input['credential_id'] ?? null) || !preg_match('/^api_[a-f0-9]{24}$/D', $input['credential_id'])) {
                throw new \DomainException('Select an existing credential.');
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
            } elseif ($action === 'rotate') {
                $overlap = $input['overlap_seconds'] ?? '3600';
                if (!is_string($overlap) || !preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', $overlap) || (int)$overlap > 86400) {
                    throw new \DomainException('Rotation overlap must be between zero and 86,400 seconds.');
                }
                $found = false;
                foreach ($rows as &$row) {
                    if ($row['id'] !== $input['credential_id']) { continue; }
                    if (!\SLS\MassNotify\ApiSecurity::credentialActive($row)) { throw new \DomainException('An expired, disabled or revoked credential cannot be rotated. Create a new credential instead.'); }
                    $issued = \SLS\MassNotify\ApiSecurity::issue($row);
                    if ((int)$overlap === 0) { $row['revoked_at'] = gmdate('c'); }
                    else { $row['expires_at'] = $row['expires_at'] === 0 ? time() + (int)$overlap : min($row['expires_at'], time() + (int)$overlap); }
                    $found = true; break;
                }
                unset($row);
                if (!$found) { throw new \DomainException('That credential no longer exists. Reload the list.'); }
                $rows[] = $issued['credential'];
                $rows = \SLS\MassNotify\ApiSecurity::credentials($rows);
            } else { $rows = \SLS\MassNotify\ApiSecurity::revoke($rows, $input['credential_id']); }
            $pending=null;
            if (is_file(self::PENDING_SETTINGS_JSON) || is_link(self::PENDING_SETTINGS_JSON)) {
                $pending = $this->loadSettingsFile(self::PENDING_SETTINGS_JSON);
                if (!is_array($pending['control_api'] ?? null)) { throw new \DomainException('Staged Control API settings are invalid. Resolve them before changing credentials.'); }
                $pending['control_api']['credentials'] = $rows;
            }
            $active['control_api']['credentials'] = $rows;
            [$active,$pending]=\SLS\MassNotify\ImmediateSettings::prepare($active,$pending,['control_api']);
            \SLS\MassNotify\ImmediateSettings::commitFiles(self::SETTINGS_JSON, self::PENDING_SETTINGS_JSON,
                $active, $pending, function (string $path, array $settings, bool $backup): void {
                    $this->writeSettingsFileUnlocked($path, $settings, $backup);
                });
            foreach ($rows as &$row) { unset($row['secret_hash']); } unset($row);
            $result = ['success' => true, 'credentials' => $this->apiCredentialUsageMetadata($rows),
                'message' => $issued !== null
                    ? ($action === 'rotate' ? 'Replacement credential created. Copy its secret now. The previous credential is retired after the selected overlap; permissions and absolute expiry are unchanged.' : 'Credential created. Copy its secret now; it will not be displayed again.')
                    : 'Credential revoked immediately. Applying staged settings will not restore it.'];
            if ($issued !== null) { $result['secret'] = $issued['secret']; $result['credential_id'] = $issued['credential']['id']; }
            return $result;
        } catch (\Throwable $error) {
            $message = $error instanceof \DomainException || $error instanceof \FreePBX\modules\SlsConfigurationWriteException
                ? $error->getMessage() : 'The credential change could not be confirmed. Reload the credential list and check protected configuration storage before retrying.';
            return ['success' => false, 'message' => $message];
        } finally { if ($lock !== null) { $this->releaseSettingsLock($lock); } }
    }
}
