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
        return new \SLS\MassNotify\EnterpriseClusterRuntime($settings,\SLS\MassNotify\EnterpriseClusterStore::DIRECTORY,null,null,
            null,
            fn(array $candidate,array $shared): array=>$this->validateEnterpriseMirrorCandidate($candidate,$shared));
    }
    public function validateEnterpriseMirrorCandidate(array $candidate,array $shared): array
    {
        $config=\SLS\MassNotify\EnterpriseClusterConfig::normalize($candidate['enterprise_cluster']??[]);
        if (array_diff(array_keys($shared),$config['mirror_fields'])) { throw new \DomainException('Mirror contains an unapproved shared setting.'); }
        $errors=$this->validateConfigSchema($candidate);
        if ($errors) { throw new \DomainException('Mirrored settings conflict with this PBX: '.implode(' ',array_slice($errors,0,3))); }
        $normalized=$this->normalizeSettings($candidate);
        foreach ($shared as $key=>$value) {
            if (!array_key_exists($key,$normalized) || $normalized[$key]!==$value) {
                throw new \DomainException('Mirrored setting '.$key.' is not canonical or valid for this PBX.');
            }
        }
        return $normalized;
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
        if (!is_array($value['peers']) || !array_is_list($value['peers'])) { throw new \DomainException('Cluster peers must be a list of structured records.'); }
        foreach ($value['peers'] as &$peer) {
            if (!is_array($peer) || array_is_list($peer)) { throw new \DomainException('Each cluster peer must be a structured record.'); }
            if (($peer['hmac_secret']??'')==='[redacted]') {
                if (!is_string($peer['node_id']??null)) { throw new \DomainException('Cluster peer identifiers must be text.'); }
                $old=\SLS\MassNotify\EnterpriseClusterConfig::peer(\SLS\MassNotify\EnterpriseClusterConfig::normalize($active['enterprise_cluster']??[]),$peer['node_id']);
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
        if (!is_string($input['peer_id'])) { throw new \DomainException('Cluster control peer identifier must be text.'); }
        return $r->request($input['peer_id'],'control.set',['stopped'=>$input['stopped'],'spend_limit_cents'=>$input['spend_limit_cents']]);
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
    protected function enterpriseRecoveryCollections(array $recovery): array
    {
        $keys=['replica','replica_provenance','effects','effect_history','control','revision'];
        $replica=$recovery['replica']??null;
        if (count($recovery)!==count($keys) || array_diff(array_keys($recovery),$keys)
            || !is_array($replica) || count($replica)!==5
            || array_diff(array_keys($replica),['jobs','schedules','incidents','events','receipts'])
            || count(array_filter($replica,'is_array'))!==5
            || !is_array($recovery['replica_provenance']??null) || !is_array($recovery['effects']??null)
            || !is_array($recovery['effect_history']??null) || !is_array($recovery['control']??null)
            || !is_int($recovery['revision']??null) || $recovery['revision']<0) {
            throw new \RuntimeException('Witness recovery response schema is invalid; no local job was restored.');
        }
        return [$replica['jobs'],$recovery['replica_provenance'],$recovery['effects']];
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
        [$replicaJobs,$provenance,$effects]=$this->enterpriseRecoveryCollections($recovery);
        $files=new \SlsAnnouncementJobStore($this->announcementJobDirectory());
        foreach ($replicaJobs as $id=>$record) {
            if (!is_string($id) || !is_array($record) || ($record['state']??null)!=='queued' || ($record['uncertain']??null)!==false
                || !is_array($record['intent']??null) || ($record['intent']['id']??null)!==$id || !\SlsAnnouncementJobStore::validId($id)) { continue; }
            $intent=$record['intent'];
            try { \SLS\MassNotify\EnterpriseClusterRuntime::record($record); \SLS\MassNotify\EnterpriseClusterRuntime::jobReplicaIntent($intent,$id); }
            catch (\Throwable $error) { continue; }
            $proof=$provenance['jobs:'.$id]??null;
            if (!is_array($proof) || ($proof['record_sha256']??'')!==hash('sha256',\SLS\MassNotify\EnterpriseClusterProtocol::canonical($record))) { continue; }
            $request=$intent['request'];
            try { $created=\SLS\MassNotify\EnterpriseClusterRuntime::timestamp((string)$intent['created_at']); }
            catch (\Throwable $error) { continue; }
            if (time()-$created>=900 || $created>time()+30) { continue; }
            $deadline=$this->scheduledAnnouncementDeadline($request);
            if ($deadline!==null && time()>=$deadline) { continue; }
            $delivery=$request['delivery_id']??''; $started=false;
            foreach ($effects as $effect) {
                if (is_array($effect) && is_array($effect['intent']??null)
                    && is_string($effect['intent']['delivery_id']??null) && $effect['intent']['delivery_id']===$delivery) { $started=true; break; }
            }
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
        $components=['mirrors'=>fn()=>$this->enterpriseApplyPendingMirrors(),
            'replication_outbox'=>fn()=>$this->enterpriseFlushReplicationOutbox(),
            'recovery'=>fn()=>$this->enterpriseRecoverPendingJobs(),
            'site_jobs'=>fn()=>$this->enterpriseProcessRemoteJobs(null)];
        $result=[];
        foreach ($components as $name=>$work) {
            try { $result[$name]=['ok'=>true,'result'=>$work()]; }
            catch (\Throwable $error) { $result[$name]=['ok'=>false,'error_code'=>'cluster_'.$name.'_unavailable']; }
        }
        return $result;
    }
    public function enterpriseFlushReplicationOutbox(): array
    {
        if (PHP_SAPI!=='cli') { throw new \DomainException('Replication outbox processing requires the local worker.'); }
        $settings=$this->getActiveSettings(); if (!\SLS\MassNotify\EnterpriseClusterConfig::enabled($settings)) { return []; }
        $runtime=$this->enterpriseClusterRuntime($settings); if ($runtime->config()['mode']!=='notification_ha') { return []; }
        $incidents=new \SLS\MassNotify\IncidentStore(self::PLUGIN_DATA_DIR.'/incidents');
        $schedules=null;
        return $runtime->flushReplicaOutbox(function(string $kind,string $id) use($incidents,&$schedules): ?string {
            if ($kind==='incidents') {
                $record=$incidents->read($id);
            } elseif ($kind==='schedules') {
                $schedules ??= $this->loadScheduleExecutionStore(true);
                $record=$schedules['occurrences'][$id]??null;
            } else { return null; }
            return is_array($record)?hash('sha256',\SLS\MassNotify\EnterpriseClusterProtocol::canonical($record)):null;
        });
    }
    public function enterpriseMirrorConfiguration(): array
    {
        $this->assertEnterpriseAdministrator(); $s=$this->getActiveSettings(); $r=$this->enterpriseClusterRuntime($s);
        if (!$r->config()['mirroring_enabled']) { throw new \DomainException('Configuration mirroring is disabled.'); }
        if ($r->config()['mirror_authority']!==$r->config()['node_id']) { throw new \DomainException('Only the configured shared-policy authority may publish mirror revisions.'); }
        $revision=$r->store()->transaction(static function(array &$state): int { return $state['revision']+1; });
        return $r->mirror($s,$revision);
    }
    protected function finalizeEnterpriseMirror(\SLS\MassNotify\EnterpriseClusterRuntime $runtime,string $node,array $mirror,callable $commit): array
    {
        // Runtime admission takes settings before the cluster journal. Keep the
        // same global lock order here so remote-site admission cannot deadlock
        // against a mirror settings commit.
        $settingsLock=$this->acquireSettingsLock(false);
        try {
            return $runtime->store()->transaction(static function(array &$state) use($node,$mirror,$commit): array {
                $current=$state['mirrors'][$node]??null;
                if ($current!==$mirror || ($current['state']??'')!=='pending') { return ['state'=>'superseded','reason'=>'newer_or_terminal_revision']; }
                $outcome=$commit();
                if (!is_array($outcome) || array_diff(array_keys($outcome),['state','reason']) || count($outcome)!==2
                    || !in_array($outcome['state']??null,['applied','rejected'],true) || !is_string($outcome['reason']??null)
                    || strlen($outcome['reason'])>256 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$outcome['reason'])) {
                    throw new \RuntimeException('Mirror commit returned an invalid terminal outcome.');
                }
                $state['mirrors'][$node]=array_replace($mirror,$outcome);
                return $outcome;
            });
        } finally { $this->releaseSettingsLock($settingsLock); }
    }
    protected function rejectLegacyEnterpriseMirror(\SLS\MassNotify\EnterpriseClusterRuntime $runtime,string $node,array $mirror): bool
    {
        return $runtime->store()->transaction(static function(array &$state) use($runtime,$node,$mirror): bool {
            if (($state['mirrors'][$node]??null)!==$mirror) { return false; }
            $state['mirrors'][$node]=['schema'=>1,'authority'=>$node,'epoch'=>$runtime->config()['mirror_epoch'],
                'revision'=>$mirror['revision'],'shared'=>$mirror['shared'],
                'content_sha256'=>hash('sha256',\SLS\MassNotify\EnterpriseClusterProtocol::canonical($mirror['shared'])),
                'state'=>'rejected','reason'=>'legacy_mirror_requires_restage'];
            return true;
        });
    }
    public function enterpriseApplyPendingMirrors(): array
    {
        if (PHP_SAPI!=='cli') { throw new \DomainException('Mirror application requires the bounded local worker.'); }
        $s=$this->getActiveSettings(); if (!\SLS\MassNotify\EnterpriseClusterConfig::enabled($s)) { return []; }
        $r=$this->enterpriseClusterRuntime($s); if (!$r->config()['mirroring_enabled']) { return []; }
        $mirrors=$r->store()->read()['mirrors']; $results=[];
        foreach ($mirrors as $node=>$mirror) {
          try {
            if (array_keys($mirror)===['revision','shared','applied'] || (count($mirror)===3 && array_diff(array_keys($mirror),['revision','shared','applied'])===[])) {
                if ($mirror['applied']) { continue; }
                $changed=$this->rejectLegacyEnterpriseMirror($r,(string)$node,$mirror);
                $results[$node]=['applied'=>false,'state'=>$changed?'rejected':'superseded','reason'=>'legacy_mirror_requires_restage'];
                continue;
            }
            $state=$mirror['state']??(!empty($mirror['applied'])?'applied':'pending');
            if ($state!=='pending') { continue; }
            $authority=$mirror['authority']??$node; $epoch=$mirror['epoch']??$r->config()['mirror_epoch'];
            $content=hash('sha256',\SLS\MassNotify\EnterpriseClusterProtocol::canonical($mirror['shared']??[]));
            if ($authority!==$r->config()['mirror_authority'] || $epoch!==$r->config()['mirror_epoch']) {
                $r->store()->transaction(static function(array &$journal) use($node,$mirror,$authority,$epoch,$content): void {
                    if (($journal['mirrors'][$node]??null)===$mirror) {
                        $journal['mirrors'][$node]=['schema'=>1,'authority'=>$authority,'epoch'=>$epoch,'revision'=>$mirror['revision'],
                            'shared'=>$mirror['shared'],'content_sha256'=>$content,'state'=>'rejected','reason'=>'authority_or_epoch_changed'];
                    }
                });
                $results[$node]=['applied'=>false,'state'=>'rejected','reason'=>'authority_or_epoch_changed'];
                continue;
            }
            $activity=$this->acquireAnnouncementActivityLock(true,5);
            if ($activity===null) { $results[$node]=['applied'=>false,'state'=>'deferred','reason'=>'delivery_active']; continue; }
            try {
                $outcome=$this->finalizeEnterpriseMirror($r,(string)$node,$mirror,function() use($mirror,$r): array {
                    $local=$this->getActiveSettings();
                    try {
                        $merged=\SLS\MassNotify\EnterpriseClusterConfig::applyMirror($local,$mirror['shared'],$r->config());
                        $normalized=$this->validateEnterpriseMirrorCandidate($merged,$mirror['shared']);
                        $pending=null;
                        if ($this->configurationPathMetadata(self::PENDING_SETTINGS_JSON)!==null) {
                            $pending=$this->loadSettingsFile(self::PENDING_SETTINGS_JSON);
                            $pending=$this->validateEnterpriseMirrorCandidate(
                                \SLS\MassNotify\EnterpriseClusterConfig::applyMirror($pending,$mirror['shared'],$r->config()),$mirror['shared']);
                        }
                        [$normalized,$pending]=\SLS\MassNotify\ImmediateSettings::prepare($normalized,$pending,array_keys($mirror['shared']));
                    } catch (\DomainException|\InvalidArgumentException $error) {
                        return ['state'=>'rejected','reason'=>'configuration_conflict'];
                    }
                    // Existing protected writer encrypts with this node's keyring;
                    // the sender's AES envelope/key material never crosses nodes.
                    \SLS\MassNotify\ImmediateSettings::commitFiles(self::SETTINGS_JSON,self::PENDING_SETTINGS_JSON,
                        $normalized,$pending,function(string $path,array $value,bool $backup): void {
                            $this->writeSettingsFileUnlocked($path,$value,$backup);
                        });
                    $this->rememberSettingsFingerprint(self::SETTINGS_JSON);
                    if ($pending!==null) { $this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON); }
                    return ['state'=>'applied','reason'=>''];
                });
            } finally { $this->releaseNativeBackupFileLock($activity); }
            if ($outcome['state']==='applied') { $results[$node]=['applied'=>true,'revision'=>$mirror['revision']]; }
            else { $results[$node]=['applied'=>false,'state'=>$outcome['state'],'reason'=>$outcome['reason']]; }
          } catch (\Throwable $error) {
            $results[$node]=['applied'=>false,'state'=>'pending','reason'=>'local_storage_or_configuration_unavailable'];
          }
        }
        return $results;
    }
    protected function buildEnterpriseDistributedJob(array $input): array
    {
        $job=null;
        if (!array_diff(array_keys($input),['peer_id','job']) && count($input)===2
            && is_string($input['peer_id']) && is_array($input['job'])) {
            // Preserve the documented full-job administrator contract.
            $job=$input['job'];
        } elseif (!array_diff(array_keys($input),['peer_id','site_id','ttl','recipients','channels','intent','offline_approved'])
            && count($input)===7 && is_string($input['peer_id']) && is_string($input['site_id'])
            && is_int($input['ttl']) && $input['ttl']>=1 && $input['ttl']<=900
            && is_array($input['recipients']) && is_array($input['channels']) && is_array($input['intent'])
            && is_bool($input['offline_approved'])) {
            // The PBX supplies the identity, trusted clock and canonical digest.
            // This keeps the form usable when an administrator browser does not
            // expose Web Crypto and prevents a client clock from extending TTL.
            $suffix=bin2hex(random_bytes(16));$created=time();
            $job=['id'=>'site-'.$suffix,'delivery_id'=>'remote-'.$suffix,'site_id'=>$input['site_id'],
                'created_at'=>$created,'expires_at'=>$created+$input['ttl'],'recipients'=>$input['recipients'],
                'channels'=>$input['channels'],'intent'=>$input['intent'],
                'content_sha256'=>hash('sha256',\SLS\MassNotify\EnterpriseClusterProtocol::canonical($input['intent'])),
                'offline_approved'=>$input['offline_approved']];
        }
        if ($job===null) {
            throw new \DomainException('Invalid distributed job schema.');
        }
        return [$input['peer_id'],$job];
    }
    public function enterpriseDistributeJob(array $input): array
    {
        $this->assertEnterpriseAdministrator();
        [$peer,$job]=$this->buildEnterpriseDistributedJob($input);
        $r=$this->enterpriseClusterRuntime($this->getActiveSettings());
        if (!$r->config()['remote_enabled'] || $r->config()['role']!=='coordinator') { throw new \DomainException('Distributed dispatch requires an enabled coordinator.'); }
        foreach (['recipients','channels'] as $field) {
            if (!is_array($job[$field]??null) || !array_is_list($job[$field])
                || count(array_filter($job[$field],'is_string'))!==count($job[$field])
                || count(array_unique($job[$field],SORT_STRING))!==count($job[$field])) {
                throw new \DomainException('Distributed recipient and channel lists must be unique arrays.');
            }
            sort($job[$field],SORT_STRING);
        }
        \SLS\MassNotify\EnterpriseClusterRuntime::job($job);
        return $r->request($peer,'site.enqueue',$job);
    }
    public function enterpriseSiteReceipt(array $input): array
    {
        $this->assertEnterpriseAdministrator(); $r=$this->enterpriseClusterRuntime($this->getActiveSettings());
        if (array_diff(array_keys($input),['peer_id','id']) || count($input)!==2) { throw new \DomainException('Invalid site receipt request.'); }
        if (!is_string($input['peer_id']) || !is_string($input['id'])) { throw new \DomainException('Site receipt peer and job identifiers must be text.'); }
        return $r->request($input['peer_id'],'site.receipt',['id'=>$input['id']]);
    }
    public function enterpriseProcessRemoteJobs(?bool $connected=null): array
    {
        if (PHP_SAPI!=='cli') { throw new \DomainException('Remote-site dispatch requires the local worker.'); }
        $settings=$this->getActiveSettings(); if (!\SLS\MassNotify\EnterpriseClusterConfig::enabled($settings)) { return []; }
        $r=$this->enterpriseClusterRuntime($settings);
        if (!$r->config()['remote_enabled']) { return []; }
        if ($connected!==false) {
            $connected=false;
            $deadline=hrtime(true)+8000000000;
            foreach ($r->config()['peers'] as $peer) {
                if ($peer['role']!=='coordinator') { continue; }
                if (hrtime(true)>=$deadline) { break; }
                try { $r->request($peer['node_id'],'status',[],$deadline); $connected=true; break; }
                catch (\Throwable $ignored) {}
            }
        }
        if ($r->config()['mode']==='edge') { return (new \SLS\MassNotify\EnterpriseClusterEdge($r))->drain($connected); }
        return $r->drain(function(array $job) use($settings,$r): array {
            $payload=$job['intent'];
            // A site owns explicit recipient IDs. Broadcast/group expansion and
            // destination credentials supplied over the peer protocol are forbidden.
            $declared=$job['channels']; sort($declared,SORT_STRING);
            if ($declared!==\SLS\MassNotify\EnterpriseClusterRuntime::pbxJobChannels($payload)) {
                throw new \DomainException('Remote-site channel manifest changed or does not match its immutable selectors.');
            }
            if (\SLS\MassNotify\EnterpriseClusterRuntime::pbxJobRecipients($payload)!==$job['recipients']) {
                throw new \DomainException('Payload audience does not equal the immutable owned-site recipients.');
            }
            $options=['trigger_source'=>'Enterprise remote site','title'=>$payload['title']??'Announcement',
                'audio_mode'=>$payload['audio_mode']??'none','desktop_clients'=>$payload['desktop_clients']??[],
                'email_recipient_ids'=>$payload['email_recipient_ids']??[],'sms_recipient_ids'=>$payload['sms_recipient_ids']??[],
                'voice_recipient_ids'=>$payload['voice_recipient_ids']??[],'webhook_ids'=>$payload['webhook_ids']??[]];
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
