<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__ . '/LabsSafety.php';
use SLS\MassNotify\LabsSafety;

/** Labs configuration uses the same protected writer as ordinary module settings. */
trait SlsEnterpriseAdministration
{
    public function assertEnterprisePageAdministrator(): void { $this->assertEnterpriseAdministrator(); }
    protected function assertEnterpriseAdministrator(): array
    {
        // A portal administrator administers SLS operations, not PBX security.
        // Labs activation is confined to the authenticated FreePBX admin panel.
        if (isset($GLOBALS['sls_operator_portal_principal']) || isset($GLOBALS['sls_control_principal'])) {
            throw new \DomainException('Manage Enterprise Labs from the FreePBX administrator panel.');
        }
        $principal = $this->currentOperator();
        if (($principal['id'] ?? '') !== 'recovery_admin' || ($principal['operator_role'] ?? '') !== 'administrator') {
            throw new \DomainException('A current FreePBX administrator login is required to configure Enterprise Labs.');
        }
        return $principal;
    }

    protected function saveEnterpriseNamespace(string $key, array $config,?string $expectedRevision=null): void
    {
        $this->saveEnterpriseNamespaces([$key=>$config],$expectedRevision===null?[]:[$key=>$expectedRevision]);
    }

    protected function saveEnterpriseNamespaces(array $changes,array $expected=[]): void
    {
        $this->assertEnterpriseAdministrator();
        if (!$changes || array_diff(array_keys($changes), ['labs_safety','enterprise_cluster','enterprise_identity','directory_sync',
            'subscriber_browser','enterprise_integrations','enterprise_operations','incident_workflows'])) {
            throw new \InvalidArgumentException('Unsupported Enterprise Labs configuration section.');
        }
        foreach ($changes as $config) { if (!is_array($config)) { throw new \InvalidArgumentException('Enterprise configuration must be structured settings.'); } }
        $activity = $this->acquireAnnouncementActivityLock(true, 5);
        if ($activity === null) { throw new \DomainException('An announcement or Labs worker is using these settings. Wait for delivery to finish and stop configured watch supervisors before saving.'); }
        $lock = null;
        try {
            $lock = $this->acquireSettingsLock(false);
            $active = $this->loadSettingsFile(self::SETTINGS_JSON);
            foreach ($expected as $key=>$revision) {
                if (!array_key_exists($key,$changes)||!is_string($revision)) { throw new \InvalidArgumentException('Invalid Enterprise configuration revision guard.'); }
                $latest=$this->normalizeSettings($active)[$key]??($this->getDefaultSettings()[$key]??[]);
                if (!hash_equals(hash('sha256',json_encode($latest,JSON_THROW_ON_ERROR)),$revision)) { throw new \DomainException('These Enterprise Labs settings changed in another request. Reload before saving.'); }
            }
            $active = array_replace($active, $changes);
            $active = $this->normalizeSettings($active);
            $this->assertEnterpriseActivationSafe($active);
            $pending = null;
            if ($this->configurationPathMetadata(self::PENDING_SETTINGS_JSON) !== null) {
                $pending = $this->loadSettingsFile(self::PENDING_SETTINGS_JSON);
                $pending = array_replace($pending, $changes);
                $pending = $this->normalizeSettings($pending);
                $this->assertEnterpriseActivationSafe($pending);
            }
            // Keep pending edits from silently reverting a security decision.
            // Active is written first; if the second write fails, reload before
            // retrying. The protected writer preserves a configuration backup.
            $this->writeSettingsFileUnlocked(self::SETTINGS_JSON, $active, true);
            $this->rememberSettingsFingerprint(self::SETTINGS_JSON);
            if ($pending !== null) {
                $this->writeSettingsFileUnlocked(self::PENDING_SETTINGS_JSON, $pending, false);
                $this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON);
            }
        } finally {
            if ($lock !== null) { $this->releaseSettingsLock($lock); }
            $this->releaseNativeBackupFileLock($activity);
        }
    }

    protected function assertEnterpriseActivationSafe(array $settings): void
    {
        $cluster = $settings['enterprise_cluster'] ?? [];
        if (!empty($cluster['enabled']) && (!empty($cluster['mirroring_enabled'])
            || ($cluster['mode'] ?? '') === 'notification_ha' || ($cluster['role'] ?? '') === 'witness')) { LabsSafety::requireReceipt($settings, 'enterprise_cluster'); }
        if (!empty($settings['enterprise_identity']['enabled'])) { LabsSafety::requireReceipt($settings, 'enterprise_identity'); }
        $integrations = $settings['enterprise_integrations'] ?? [];
        if (!empty($integrations['enabled']) && !empty($integrations['access_control']['enabled'])) {
            foreach ($integrations['access_control']['doors'] ?? [] as $door) {
                if (!empty($door['allow_lock']) || !empty($door['allow_unlock'])) { LabsSafety::requireReceipt($settings, 'access_control_actuation'); break; }
            }
        }
        if (!empty($integrations['enabled']) && !empty($integrations['public_warning']['enabled']) && !empty($integrations['public_warning']['ipaws_enabled'])) {
            LabsSafety::requireReceipt($settings, 'public_warning_origination');
        }
    }

    public function beginEnterpriseDangerWarning(string $feature): array
    {
        $principal = $this->assertEnterpriseAdministrator();
        $this->getCsrfToken();
        if (LabsSafety::hasReceipt($this->getActiveSettings(), $feature)) { return ['success'=>true, 'acknowledged'=>true]; }
        return ['success'=>true, 'acknowledged'=>false] + LabsSafety::begin($_SESSION, $feature,
            (string)$principal['username'], session_id());
    }

    public function acceptEnterpriseDangerWarning(array $input): array
    {
        $principal = $this->assertEnterpriseAdministrator();
        $this->getCsrfToken();
        $result = LabsSafety::accept($_SESSION, $input, (string)$principal['username'], session_id());
        $config = LabsSafety::normalize($this->getActiveSettings()['labs_safety'] ?? []);
        $config['receipts'][$result['feature']] = $result['receipt'];
        $this->saveEnterpriseNamespace('labs_safety', $config);
        return ['success'=>true, 'message'=>'Warning acknowledged. The feature remains disabled until you explicitly enable and save it.'];
    }

    public function enterpriseAdministratorAction(string $action,array $input): array
    {
        $this->assertEnterpriseAdministrator();
        if ($action==='danger_begin') { return $this->beginEnterpriseDangerWarning($input['feature']??''); }
        if ($action==='danger_accept') { return $this->acceptEnterpriseDangerWarning($input); }
        if (in_array($action,['identity_save','directory_save','directory_preview','directory_apply','subscriber_save','subscriber_invite','subscriber_revoke'],true)) {
            return $this->enterpriseLabsAction($action,$input);
        }
        if (in_array($action,['operations_save','approval_list','approval_approve','approval_submit','approval_reject','floorplan_overlay','drill_start','drill_review','drill_report'],true)) {
            return $this->enterpriseOperationsAction($action,$input);
        }
        switch ($action) {
            case 'cluster_save': return $this->configureEnterpriseCluster($input['config']??[],$input['revision']??'');
            case 'cluster_status': return ['success'=>true,'status'=>$this->getEnterpriseClusterStatus()];
            case 'cluster_prerequisites': return ['success'=>true,'prerequisites'=>$this->enterprisePbxReadiness()];
            case 'cluster_initialize': return $this->initializeEnterpriseClusterJournal();
            case 'cluster_control': return $this->enterpriseClusterControl($input);
            case 'cluster_mirror': return $this->enterpriseMirrorConfiguration();
            case 'cluster_handoff': return $this->enterpriseClusterHandoff($input['node_id']??'');
            case 'cluster_distribute': return $this->enterpriseDistributeJob($input);
            case 'cluster_receipt': return $this->enterpriseSiteReceipt($input);
            case 'cluster_recovery': return $this->enterpriseClusterRecoveryState();
            case 'cluster_reconcile': return $this->enterpriseClusterReconcile($input);
            case 'integrations_save': return $this->saveEnterpriseIntegrations($input);
            case 'integrations_meeting':
                $id=$input['incident_id']??'';unset($input['incident_id']);return $this->createIncidentMeeting($id,$input['input']??$input);
            case 'integrations_door': return $this->controlIntegrationDoor($input);
            case 'integrations_speaker_telemetry': return $this->readIntegrationSpeakerTelemetry($input['speaker_id']??'');
            case 'integrations_speaker_send': return $this->sendIntegrationSpeakerAnnouncement($input);
            case 'integrations_cap_export': return $this->exportPublicWarning($input);
            case 'integrations_cap_submit': return $this->submitPublicWarning($input);
            case 'integrations_ipaws': return $this->submitPublicWarning($input);
        }
        throw new \InvalidArgumentException('Unsupported Enterprise Labs action.');
    }

    public function renderEnterpriseLabsDashboard(): string
    {
        $this->assertEnterpriseAdministrator();
        return load_view(__DIR__.'/views/enterprise.php',['module'=>$this,'csrf_token'=>$this->getCsrfToken(),
            'cluster'=>$this->getEnterpriseClusterConfiguration(),'operations'=>$this->enterpriseOperationsState(),
            'safety'=>LabsSafety::normalize($this->getActiveSettings()['labs_safety']??[])]);
    }

    /** A recovered PBX cannot resume peer authority or experimental automation. */
    protected function disableEnterpriseAfterRecovery(array &$settings): bool
    {
        $changed=false;
        foreach (['enterprise_cluster','enterprise_identity','directory_sync','subscriber_browser','enterprise_operations'] as $key) {
            if(isset($settings[$key])&&!empty($settings[$key]['enabled'])){$settings[$key]['enabled']=false;$changed=true;}
        }
        if(isset($settings['enterprise_integrations'])&&!empty($settings['enterprise_integrations']['enabled'])){$settings['enterprise_integrations']['enabled']='0';$changed=true;}
        if(!empty($settings['labs_safety']['receipts'])){$settings['labs_safety']=LabsSafety::defaults();$changed=true;}
        if(!empty($settings['enterprise_cluster']['witness_epoch'])){$settings['enterprise_cluster']['witness_epoch']='';$changed=true;}
        return $changed;
    }
}
