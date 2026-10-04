<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__.'/EnterpriseClusterIntegration.php';
require_once __DIR__.'/EnterpriseClusterEdge.php';
require_once __DIR__.'/EnterpriseClusterIncidentStore.php';

/** FreePBX administration and explicit local dispatch of site-owned jobs. */
trait SlsEnterpriseCluster
{
    protected $enterpriseClusterRequest=null;
    protected function enterpriseClusterRuntime(array $settings): \SLS\MassNotify\EnterpriseClusterRuntime
    {
        return new \SLS\MassNotify\EnterpriseClusterRuntime($settings);
    }
    public function enterpriseClusterWorkerState(): array
    {
        if (PHP_SAPI!=='cli') { throw new \DomainException('Worker settings require CLI.'); }
        $s=$this->getActiveSettings(); $c=\SLS\MassNotify\EnterpriseClusterConfig::normalize($s['enterprise_cluster']??[]);
        return ['enabled'=>$c['enabled'],'mode'=>$c['mode'],'lease_seconds'=>$c['lease_seconds']];
    }
    public function renderClusterPage(string $endpoint,string $csrf): string
    {
        $this->assertEnterpriseAdministrator();
        $config=\SLS\MassNotify\EnterpriseClusterConfig::normalize($this->getActiveSettings()['enterprise_cluster']??[]);
        $revision=hash('sha256',json_encode($config,JSON_THROW_ON_ERROR));
        foreach ($config['peers'] as &$peer) { $peer['hmac_secret']='[redacted]'; } unset($peer);
        ob_start(); include __DIR__.'/views/cluster.php'; return (string)ob_get_clean();
    }
    public function enterprisePbxReadiness(): array
    {
        $this->assertEnterpriseAdministrator();
        return \SLS\MassNotify\EnterpriseClusterPbxContract::measure();
    }
    public function getEnterpriseClusterConfiguration(): array
    {
        $this->assertEnterpriseAdministrator();
        $c=\SLS\MassNotify\EnterpriseClusterConfig::normalize($this->getActiveSettings()['enterprise_cluster']??[]);
        foreach ($c['peers'] as &$p) { $p['hmac_secret']='[redacted]'; } unset($p);
        return $c;
    }
    public function configureEnterpriseCluster(array $value,string $expectedRevision): array
    {
        $this->assertEnterpriseAdministrator(); $active=$this->getActiveSettings();
        $current=\SLS\MassNotify\EnterpriseClusterConfig::normalize($active['enterprise_cluster']??[]);
        if (!preg_match('/^[a-f0-9]{64}$/D',$expectedRevision) || !hash_equals(hash('sha256',json_encode($current,JSON_THROW_ON_ERROR)),$expectedRevision)) {
            throw new \DomainException('Cluster settings changed. Reload before saving.');
        }
        if (!isset($value['peers'])) { $value['peers']=[]; }
        foreach ($value['peers'] as &$peer) {
            if (($peer['hmac_secret']??'')==='[redacted]') {
                $old=\SLS\MassNotify\EnterpriseClusterConfig::peer(\SLS\MassNotify\EnterpriseClusterConfig::normalize($active['enterprise_cluster']??[]),(string)($peer['node_id']??''));
                if (!$old) { throw new \DomainException('A new peer requires its own shared authentication secret.'); }
                $peer['hmac_secret']=$old['hmac_secret'];
            }
        } unset($peer);
        $c=\SLS\MassNotify\EnterpriseClusterConfig::normalize($value);
        if ($c['enabled'] && ($c['mode']==='notification_ha' || $c['role']==='witness' || $c['mirroring_enabled'])) { \SLS\MassNotify\LabsSafety::requireReceipt($active,'enterprise_cluster'); }
        $this->saveEnterpriseNamespace('enterprise_cluster',$c,$expectedRevision);
        return ['success'=>true,'enabled'=>$c['enabled'],'revision'=>hash('sha256',json_encode($c,JSON_THROW_ON_ERROR))];
    }
    public function getEnterpriseClusterStatus(): array
    {
        $this->assertEnterpriseAdministrator(); $s=$this->getActiveSettings();
        if (!\SLS\MassNotify\EnterpriseClusterConfig::enabled($s)) { return ['enabled'=>false,'automatic_delivery'=>false,'qualification'=>'disabled']; }
        $r=$this->enterpriseClusterRuntime($s); $state=$r->store()->read();
        return ['enabled'=>true,'node_id'=>$r->config()['node_id'],'mode'=>$r->config()['mode'],
            'lease'=>$state['lease'],'control'=>$state['control'],'queued'=>count($state['queue']),
            'uncertain_effects'=>count(array_filter($state['effects'],static fn($e)=>$e['state']==='uncertain')),
            'hardware_qualified'=>false,'pbx_ha'=>false,'qualification'=>'Labs protocol only; phones, registrar and trunks remain operator managed.'];
    }
    public function initializeEnterpriseClusterJournal(): array
    {
        $this->assertEnterpriseAdministrator(); $r=$this->enterpriseClusterRuntime($this->getActiveSettings());
        $r->initialize(); return ['success'=>true,'initialized'=>true];
    }
    public function enterpriseClusterControl(array $input): array
    {
        $this->assertEnterpriseAdministrator(); $r=$this->enterpriseClusterRuntime($this->getActiveSettings());
        if (array_diff(array_keys($input),['peer_id','stopped','spend_limit_cents']) || count($input)!==3) { throw new \DomainException('Invalid cluster control schema.'); }
        return $r->request((string)$input['peer_id'],'control.set',['stopped'=>$input['stopped'],'spend_limit_cents'=>$input['spend_limit_cents']]);
    }
    public function enterpriseClusterHandoff(string $node): array
    {
        $this->assertEnterpriseAdministrator(); $r=$this->enterpriseClusterRuntime($this->getActiveSettings());
        return $r->request($r->config()['witness_id'],'lease.handoff',['target'=>$node]);
    }
    public function enterpriseClusterReconcile(array $input): array
    {
        $this->assertEnterpriseAdministrator(); $r=$this->enterpriseClusterRuntime($this->getActiveSettings());
        return $r->request($r->config()['witness_id'],'effect.reconcile',$input);
    }
    public function enterpriseClusterRecoveryState(): array
    {
        $this->assertEnterpriseAdministrator();
        return $this->enterpriseClusterRuntime($this->getActiveSettings())->recover();
    }
    public function enterpriseRecoverPendingJobs(): array
    {
        if (PHP_SAPI!=='cli') { throw new \DomainException('Replicated job recovery requires the local worker.'); }
        $settings=$this->getActiveSettings(); if (!\SLS\MassNotify\EnterpriseClusterConfig::enabled($settings)) { return []; }
        $r=$this->enterpriseClusterRuntime($settings);
        if ($r->config()['mode']!=='notification_ha') { return []; }
        // This is election by durable witness authority. No heartbeat result
        // or native backup/recovery file can grant an exclusive send lease.
        $r->acquire(); $recovery=$r->recover(); $results=[];
        $files=new \SlsAnnouncementJobStore($this->announcementJobDirectory());
        foreach ($recovery['replica']['jobs'] as $id=>$record) {
            if ($record['state']!=='queued' || $record['uncertain'] || ($record['intent']['id']??null)!==$id || !\SlsAnnouncementJobStore::validId($id)) { continue; }
            $intent=$record['intent']; $request=$intent['request'];
            $created=strtotime((string)$intent['created_at']);
            if (!$created || time()-$created>=900 || $created>time()+30) { continue; }
            $deadline=$this->scheduledAnnouncementDeadline($request);
            if ($deadline!==null && time()>=$deadline) { continue; }
            $delivery=$request['delivery_id']??''; $started=false;
            foreach ($recovery['effects'] as $effect) { if ($effect['intent']['delivery_id']===$delivery) { $started=true; break; } }
            if ($started) { continue; }
            $lock=$files->lock($id); if (!$lock) { continue; }
            try {
                // Never replace an existing local job, even if it is failed or
                // uncertain. Only provably untouched queued intent is eligible.
                if ($files->read($id)!==null) { continue; }
                $job=['id'=>$id,'state'=>'queued','created_at'=>$intent['created_at'],'request'=>$request,
                    'receipts'=>[],'message'=>'Unexpired untouched job recovered under exclusive witness authority.'];
                if (isset($request['schedule_context'])) { $job['request_fingerprint']=\SlsAnnouncementJobStore::requestFingerprint($request); }
                $files->write($job);
                $r->store()->transaction(static function(array &$s) use($id,$record): void {
                    $s['snapshot_revisions']['job:'.$id]=max($s['snapshot_revisions']['job:'.$id]??0,$record['revision']);
                });
            } finally { \SlsAnnouncementJobStore::unlock($lock); }
            $results[$id]=['success'=>$this->processAnnouncementJobs($id)];
        }
        return $results;
    }
    public function enterpriseClusterCycle(): array
    {
        $settings=$this->getActiveSettings(); if (!\SLS\MassNotify\EnterpriseClusterConfig::enabled($settings)) { return []; }
        return ['mirrors'=>$this->enterpriseApplyPendingMirrors(),'recovery'=>$this->enterpriseRecoverPendingJobs(),
            'site_jobs'=>$this->enterpriseProcessRemoteJobs(null)];
    }
    public function enterpriseMirrorConfiguration(): array
    {
        $this->assertEnterpriseAdministrator(); $s=$this->getActiveSettings(); $r=$this->enterpriseClusterRuntime($s);
        $revision=$r->store()->transaction(static function(array &$state): int { return $state['revision']+1; });
        return $r->mirror($s,$revision);
    }
    public function enterpriseApplyPendingMirrors(): array
    {
        if (PHP_SAPI!=='cli') { throw new \DomainException('Mirror application requires the bounded local worker.'); }
        $s=$this->getActiveSettings(); if (!\SLS\MassNotify\EnterpriseClusterConfig::enabled($s)) { return []; }
        $r=$this->enterpriseClusterRuntime($s); if (!$r->config()['mirroring_enabled']) { return []; }
        $mirrors=$r->store()->read()['mirrors']; $results=[];
        foreach ($mirrors as $node=>$mirror) {
            if ($mirror['applied']) { continue; }
            $activity=$this->acquireAnnouncementActivityLock(true,5);
            if ($activity===null) { throw new \DomainException('A delivery is using protected settings; mirror application is deferred.'); }
            $lock=null;
            try {
                // The explicit exclusive activity descriptor already protects
                // this replacement. Opening it again would self-deadlock.
                $lock=$this->acquireSettingsLock(false);
                $local=$this->getActiveSettings(); $merged=\SLS\MassNotify\EnterpriseClusterConfig::applyMirror($local,$mirror['shared'],$r->config());
                $normalized=$this->normalizeSettings($merged);
                // Existing protected writer encrypts with this node's keyring;
                // the sender's AES envelope/key material never crosses nodes.
                $this->writeSettingsFileUnlocked(self::SETTINGS_JSON,$normalized,false);
                $this->rememberSettingsFingerprint(self::SETTINGS_JSON);
                if ($this->configurationPathMetadata(self::PENDING_SETTINGS_JSON)!==null) {
                    $pending=$this->loadSettingsFile(self::PENDING_SETTINGS_JSON);
                    $pending=$this->normalizeSettings(\SLS\MassNotify\EnterpriseClusterConfig::applyMirror($pending,$mirror['shared'],$r->config()));
                    $this->writeSettingsFileUnlocked(self::PENDING_SETTINGS_JSON,$pending,false);
                    $this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON);
                }
            } finally { $this->releaseSettingsLock($lock); $this->releaseNativeBackupFileLock($activity); }
            $r->store()->transaction(static function(array &$state) use($node,$mirror): void {
                if ($state['mirrors'][$node]['revision']===$mirror['revision']) { $state['mirrors'][$node]['applied']=true; }
            });
            $results[$node]=['applied'=>true,'revision'=>$mirror['revision']];
        }
        return $results;
    }
    public function enterpriseDistributeJob(array $input): array
    {
        $this->assertEnterpriseAdministrator();
        if (array_diff(array_keys($input),['peer_id','job']) || count($input)!==2 || !is_array($input['job'])) { throw new \DomainException('Invalid distributed job schema.'); }
        $r=$this->enterpriseClusterRuntime($this->getActiveSettings());
        if (!$r->config()['remote_enabled'] || $r->config()['role']!=='coordinator') { throw new \DomainException('Distributed dispatch requires an enabled coordinator.'); }
        \SLS\MassNotify\EnterpriseClusterRuntime::job($input['job']);
        return $r->request((string)$input['peer_id'],'site.enqueue',$input['job']);
    }
    public function enterpriseSiteReceipt(array $input): array
    {
        $this->assertEnterpriseAdministrator(); $r=$this->enterpriseClusterRuntime($this->getActiveSettings());
        if (array_diff(array_keys($input),['peer_id','id']) || count($input)!==2) { throw new \DomainException('Invalid site receipt request.'); }
        return $r->request((string)$input['peer_id'],'site.receipt',['id'=>$input['id']]);
    }
    public function enterpriseProcessRemoteJobs(?bool $connected=null): array
    {
        if (PHP_SAPI!=='cli') { throw new \DomainException('Remote-site dispatch requires the local worker.'); }
        $settings=$this->getActiveSettings(); if (!\SLS\MassNotify\EnterpriseClusterConfig::enabled($settings)) { return []; }
        $r=$this->enterpriseClusterRuntime($settings);
        if (!$r->config()['remote_enabled']) { return []; }
        if ($connected!==false) {
            $connected=false;
            foreach ($r->config()['peers'] as $peer) {
                if ($peer['role']!=='coordinator') { continue; }
                try { $r->request($peer['node_id'],'status',[]); $connected=true; break; }
                catch (\Throwable $ignored) {}
            }
        }
        if ($r->config()['mode']==='edge') { return (new \SLS\MassNotify\EnterpriseClusterEdge($r))->drain($connected); }
        return $r->drain(function(array $job) use($settings,$r): array {
            $payload=$job['intent'];
            // A site owns explicit recipient IDs. Broadcast/group expansion and
            // destination credentials supplied over the peer protocol are forbidden.
            $allowed=['message','title','targets','desktop_clients','audio_mode','email_recipient_ids','sms_recipient_ids','voice_recipient_ids','webhook_ids'];
            if (array_diff(array_keys($payload),$allowed)) { throw new \DomainException('Remote-site payload contains unapproved selectors.'); }
            $targets=[];
            foreach (['targets','desktop_clients','email_recipient_ids','sms_recipient_ids','voice_recipient_ids','webhook_ids'] as $key) {
                if (isset($payload[$key])) {
                    if (!is_array($payload[$key]) || !array_is_list($payload[$key])) { throw new \DomainException('Site selectors must be explicit arrays.'); }
                    $targets=array_merge($targets,$payload[$key]);
                }
            }
            sort($targets,SORT_STRING); $owned=$job['recipients']; sort($owned,SORT_STRING);
            if (array_values(array_unique($targets))!==array_values(array_unique($owned))) { throw new \DomainException('Payload audience does not equal the immutable owned-site recipients.'); }
            $options=['trigger_source'=>'Enterprise remote site','title'=>$payload['title']??'Announcement',
                'audio_mode'=>$payload['audio_mode']??'none','desktop_clients'=>$payload['desktop_clients']??[],
                'email_recipient_ids'=>$payload['email_recipient_ids']??[],'sms_recipient_ids'=>$payload['sms_recipient_ids']??[],
                'voice_recipient_ids'=>$payload['voice_recipient_ids']??[],'webhook_destination_ids'=>$payload['webhook_ids']??[]];
            $resolved=$this->resolveAnnouncementRequest($payload['targets']??[],(string)($payload['message']??''),false,false,[],$options,$settings);
            if (empty($resolved['success'])) { return ['uncertain'=>false,'result'=>$resolved]; }
            $request=$resolved['request']; $request['delivery_id']=$job['delivery_id'];
            $request['delivery_timestamp']=gmdate('c',$job['created_at']); $request['schedule_deadline_at']=$job['expires_at'];
            $attempt='job_'.substr(hash('sha256',$job['id']),0,32);
            $request['email_attempt_id']=$attempt; $request['sms_attempt_id']=$attempt;
            $request['email_expires_at']=$job['expires_at']; $request['sms_expires_at']=$job['expires_at'];
            foreach ($request['email_targets'] as $id=>&$target) {
                $domain=strtolower(substr(strrchr($target['sender']['address'],'@'),1));
                $target['message_id']='<sls-'.substr(hash('sha256',$job['delivery_id'].'|'.$id),0,48).'@'.$domain.'>';
            } unset($target);
            \SLS\MassNotify\EnterpriseClusterIntegration::prepare($settings,$request);
            $result=$this->executeResolvedAnnouncement($request,function(array $receipts,string $message) use($r,$job): void {
                $r->store()->transaction(static function(array &$state) use($receipts,$job): void {
                    $state['queue'][$job['id']]['receipt']=['uncertain'=>true,'receipts'=>$receipts];
                });
            });
            return ['uncertain'=>!empty($result['submission_uncertain']),'result'=>$result];
        },$connected);
    }
}
