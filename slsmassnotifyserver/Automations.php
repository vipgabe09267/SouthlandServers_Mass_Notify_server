<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__ . '/AutomationService.php';
require_once __DIR__ . '/AutomationInputs.php';
use SLS\MassNotify\{AutomationConfig,AutomationStore,AutomationService,AutomationInputs,IncidentConfig};

trait SlsAutomations
{
    private ?array $automationSubmissionContext=null;
    private function requireAutomationAdministrator(): void
    {
        if (($this->currentOperator()['operator_role'] ?? '') !== 'administrator') {
            throw new \DomainException('Only an SLS administrator can manage triggers and actions.');
        }
    }
    protected function automationStoreDirectory(): string { return AutomationStore::DIRECTORY; }
    protected function automationStore(): AutomationStore { return new AutomationStore($this->automationStoreDirectory()); }
    private function automationService(): AutomationService
    {
        return new AutomationService($this->automationStore(),fn()=> $this->getActiveSettings(),
            function(array $resolved,array $event): array {
                $previous=$this->automationSubmissionContext; $this->automationSubmissionContext=$event['context'];
                try { return $this->startIncident(['template_id'=>$resolved['template']['id'], 'request_id'=>substr($event['id'],0,32),
                    'fields'=>$event['fields'],'is_test'=>$event['is_test']], ['identity'=>$resolved['rule']['name'], 'source'=>'automation', 'automation_context'=>$event['context']]); }
                finally { $this->automationSubmissionContext=$previous; }
            }, fn(array $action,array $event)=>$this->runAutomationAction($action,$event));
    }
    private function automationRunner(array $arguments, ?array $input=null): array
    {
        $pipes=[];
        $process=proc_open(array_merge(['/usr/bin/timeout','--kill-after=2','15','/usr/bin/python3',self::RUNTIME_DIR.'/sls_trigger_actions.py'],$arguments),
            [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,'/',['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','PYTHONDONTWRITEBYTECODE'=>'1'],['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new \RuntimeException('Trigger action worker could not start. Check the installed Python runtime and action helper.'); }
        try {
            $encoded=$input===null ? '' : json_encode($input,JSON_THROW_ON_ERROR);
            if (strlen($encoded)>16384) { throw new \InvalidArgumentException('Action input exceeds its bounded handoff size.'); }
            if ($encoded!=='' && fwrite($pipes[0],$encoded)!==strlen($encoded)) { throw new \RuntimeException('Action input could not be delivered completely.'); }
            fclose($pipes[0]); $pipes[0]=null;
            $output=stream_get_contents($pipes[1],8193); fclose($pipes[1]); $pipes[1]=null;
            $exit=proc_close($process); $process=null;
            $result=is_string($output) && strlen($output)<=8192 ? json_decode($output,true) : null;
            if (!is_array($result)) { return ['state'=>'uncertain','detail'=>'Action worker did not return a bounded result. Review the script/device before any manual resend.']; }
            if ($exit!==0 && !isset($result['state'])) { $result=['state'=>'failed','detail'=>'Action inspection failed.']; }
            return $result;
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_terminate($process,9); proc_close($process); }
        }
    }
    private function runAutomationAction(array $action,array $event): array
    {
        return $this->automationRunner([],['action'=>$action,'event'=>['schema'=>1,'event_id'=>$event['id'],
            'rule_id'=>$event['rule_id'],'event'=>$event['event'],'message'=>$event['message'],'caller'=>$event['caller'],
            'is_test'=>$event['is_test'],'created_at'=>$event['created_at'],'expires_at'=>$event['context']['expires_at']]]);
    }
    public function inspectAutomationScript(string $path): array
    {
        $this->requireAutomationAdministrator();
        return $this->automationRunner(['--inspect',$path]);
    }
    private function validatePanicExtensions(array $settings): void
    {
        $rules=AutomationConfig::normalize($settings['automations'] ?? [])['rules'];
        $phones=array_map('strval',array_column($this->getAllPjsipExtensions(),'extension'));
        foreach ($rules as $rule) {
            if ($rule['kind']!=='panic' || !$rule['enabled'] || $rule['dial_extension']==='') { continue; }
            if (array_diff($rule['callers'],$phones)) { throw new \DomainException('Panic shortcut callers must be existing PJSIP extensions.'); }
            if (($settings['live_paging']['enabled'] ?? '0')==='1' && ($settings['live_paging']['extension'] ?? '')===$rule['dial_extension']) { throw new \DomainException('The panic shortcut conflicts with the dial-in paging extension. Choose another unused number.'); }
            $usage=$this->FreePBX->Extensions->checkUsage([$rule['dial_extension']],false); unset($usage['slsmassnotifyserver']);
            if ($usage || self::livePagingDialplanHasConflict($this->queryLivePagingDialplan($rule['dial_extension']),'sls-trigger-panic')) { throw new \DomainException('The panic shortcut conflicts with an existing PBX number or dial pattern. Choose an unused internal number.'); }
        }
    }
    public function getPanicExtensionUsage($extensions,array $settings): array
    {
        $out=[];
        foreach (AutomationConfig::normalize($settings['automations'] ?? [])['rules'] as $rule) {
            if ($rule['kind']==='panic' && $rule['enabled'] && $rule['dial_extension']!=='' && ($extensions===true || in_array($rule['dial_extension'],array_map('strval',(array)$extensions),true))) {
                $out[$rule['dial_extension']]=['description'=>'SLS panic: '.$rule['name'],'status'=>'INUSE','edit_url'=>'config.php?display=slsmassnotifyserver_automations'];
            }
        }
        return $out;
    }
    private function addPanicDialplan(&$ext,array $settings): void
    {
        $usage=$this->getPanicExtensionUsage(true,$settings); if (!$usage) { return; }
        $this->validatePanicExtensions($settings); $this->ensureLivePagingPrompts($settings);
        foreach ($usage as $number=>$row) {
            $ext->add('sls-trigger-panic',(string)$number,'',new \ext_setvar('AGISIGHUP','no'));
            $ext->add('sls-trigger-panic',(string)$number,'',new \extension('AGI('.self::RUNTIME_DIR.'/sls_mass_notify_panic.php)'));
            $ext->add('sls-trigger-panic',(string)$number,'',new \ext_hangup());
        }
        $ext->addInclude('from-internal-additional','sls-trigger-panic');
    }
    public function getAutomationState(): array
    {
        $this->requireAutomationAdministrator();
        $settings=$this->getPendingSettings() ?? $this->getActiveSettings();
        $config=AutomationConfig::normalize($settings['automations'] ?? []);
        $revision=hash('sha256',json_encode([$config,$settings['incident_workflows'] ?? [],$settings['location_directory'] ?? []],JSON_THROW_ON_ERROR));
        foreach ($config['rules'] as &$rule) { if (isset($rule['secret'])) { unset($rule['secret']); $rule['enrolled']=true; } } unset($rule);
        $history=[]; $storageError='';
        // Opening an empty editor does not create operational state.
        if (is_dir($this->automationStoreDirectory())) {
            try {
                foreach (array_reverse($this->automationStore()->read()['events']) as $row) {
                    $history[]=array_intersect_key($row,array_flip(['id','rule_id','rule_name','source','created_at','state','results']));
                    if (count($history)>=50) { break; }
                }
            } catch (\Throwable $error) { $storageError='Trigger history is unavailable. Check protected journal permissions and free storage before activating triggers.'; }
        }
        $sourceHealth=[];
        $enabledSources=[];
        foreach ($config['rules'] as $rule) {
            if ($rule['enabled'] && in_array($rule['kind'], ['cap', 'emergency_call'], true)) {
                $enabledSources[$rule['kind']==='cap' ? $rule['id'] : 'emergency_call']=true;
            }
        }
        foreach (['automation-sources','automation-observer'] as $name) {
            if (is_dir(self::PLUGIN_DATA_DIR.'/'.$name)) {
                try { $journal=(new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR.'/'.$name))->state(); $sourceHealth=array_merge($sourceHealth,array_intersect_key($journal['sources'] ?? [],$enabledSources)); }
                catch (\Throwable $error) { if ($enabledSources) { $sourceHealth[]=['ok'=>false,'detail'=>'Source health journal is unavailable. Check protected storage.']; } }
            }
        }
        return ['config'=>$config,'revision'=>$revision,'templates'=>array_map(static fn($row)=>array_intersect_key($row,array_flip(['id','name','fields','delivery'])), $settings['incident_workflows']['templates'] ?? []),
            'locations'=>array_map(static fn($row)=>['id'=>$row['id'],'name'=>$row['name']],\SLS\MassNotify\LocationDirectory::effective($settings['location_directory'] ?? [])['nodes']),
            'source_health'=>$sourceHealth,'history'=>$history,'storage_error'=>$storageError,'pending'=>$this->getPendingSettings()!==null];
    }
    public function renderAutomationsPage(): string
    {
        return load_view(__DIR__.'/views/automations.php',['state'=>$this->getAutomationState(),'csrf_token'=>$this->getCsrfToken(),'hero_image'=>self::HERO_IMAGE]);
    }
    public function saveAutomationItem(array $input): array
    {
        $this->requireAutomationAdministrator();
        IncidentConfig::object($input,['revision','kind','item','remove','rotate_secret','approve_script'],'Trigger configuration request');
        $state=$this->getAutomationState();
        if (!is_string($input['revision'] ?? null) || !hash_equals($state['revision'],$input['revision'])) { throw new \DomainException('Trigger settings, templates or locations changed. Reload before saving.'); }
        $kind=$input['kind'] ?? ''; if (!in_array($kind,['rules','actions'],true)) { throw new \InvalidArgumentException('Choose a trigger or action.'); }
        $settings=$this->getPendingSettings() ?? $this->getActiveSettings();
        if (!$this->isSetupComplete($settings)) { throw new \DomainException($this->getSetupRequiredMessage()); }
        $config=AutomationConfig::normalize($settings['automations'] ?? []);
        $item=$input['item'] ?? null; if (!is_array($item)) { throw new \InvalidArgumentException('Trigger/action details are missing.'); }
        $id=$item['id'] ?? ''; $new=$id===''; $prefix=$kind==='rules'?'trg':'act';
        if ($new) { $id=$prefix.'_'.bin2hex(random_bytes(12)); } else { AutomationConfig::id($id,$prefix); }
        $rows=array_column($config[$kind],null,'id'); $old=$rows[$id] ?? null;
        if (!$new && !$old) { throw new \DomainException('This item was removed. Reload the editor.'); }
        $secret='';
        if (($input['remove'] ?? false)===true) { unset($rows[$id]); }
        else {
            $item['id']=$id; unset($item['enrolled']);
            if ($kind==='rules' && in_array($item['kind'] ?? '',['panic','sensor'],true)) {
                if (array_key_exists('secret',$item)) { throw new \InvalidArgumentException('Enrollment secrets are generated by SLS. Use Rotate enrollment to replace one.'); }
                $rotate=IncidentConfig::flag($input['rotate_secret'] ?? false,'Rotate enrollment');
                if ($new || !isset($old['secret']) || $rotate) { $secret=bin2hex(random_bytes(32)); $item['secret']=$secret; }
                else { $item['secret']=$old['secret']; }
            }
            if ($kind==='actions' && ($item['kind'] ?? '')==='script') {
                if (($input['approve_script'] ?? false)!==true && (($old['path'] ?? '')!==($item['path'] ?? '') || ($old['sha256'] ?? '')!==($item['sha256'] ?? ''))) { throw new \DomainException('Review the script and explicitly approve its current file before saving.'); }
                if (($input['approve_script'] ?? false)===true) {
                    $inspection=$this->inspectAutomationScript((string)($item['path'] ?? ''));
                    if (empty($inspection['ok'])) { throw new \DomainException($inspection['detail'] ?? 'Script inspection failed.'); }
                    $item['sha256']=$inspection['sha256'];
                }
            }
            $rows[$id]=$kind==='rules' ? AutomationConfig::rule($item) : AutomationConfig::action($item);
        }
        $config[$kind]=array_values($rows); $settings['automations']=AutomationConfig::normalize($config);
        foreach ($this->automationDependencyChecks($settings) as $dependency) {
            if ($dependency['state'] !== 'ok') { throw new \DomainException($dependency['detail']); }
        }
        foreach ($settings['automations']['rules'] as $rule) {
            if ($rule['enabled']) {
                $resolved=AutomationConfig::resolve($settings,$rule['id']);
                if ($resolved['template']) {
                    if (array_diff(array_keys($rule['fields']),array_column($resolved['template']['fields'],'key')) || array_diff(array_column($resolved['template']['fields'],'key'),array_keys($rule['fields']))) { throw new \DomainException('Bind every required field in the selected incident template.'); }
                    $this->freezeIncidentDelivery($resolved['template']['delivery']);
                }
                if ($rule['kind']==='emergency_call') {
                    $phones=array_map('strval',array_column($this->getAllPjsipExtensions(),'extension'));
                    if (array_diff($rule['callers'],$phones)) { throw new \DomainException('Emergency-call callers must be existing PJSIP extensions.'); }
                }
            }
        }
        if ($this->getPanicExtensionUsage(true,$settings)) { $this->validatePanicExtensions($settings); }
        $this->persistPendingSettings($settings);
        return ['success'=>true,'id'=>$id,'enrollment_secret'=>$secret,'state'=>$this->getAutomationState(),
            'message'=>'Saved to pending .config settings. Apply Config to activate. No action or notification was sent.'];
    }
    public function automationDependencyChecks(array $settings): array
    {
        $checks = []; $cap = false; $javascript = false;
        foreach (AutomationConfig::normalize($settings['automations'] ?? [])['rules'] as $rule) {
            if ($rule['enabled'] && $rule['kind'] === 'cap') { $cap = true; }
        }
        foreach ($settings['automations']['actions'] ?? [] as $action) {
            if (!empty($action['enabled']) && ($action['kind'] ?? '') === 'script' && str_ends_with($action['path'] ?? '', '.js')) { $javascript = true; }
        }
        if ($cap) {
            $ok = class_exists('\\DOMDocument') && function_exists('curl_init');
            $checks[] = ['id'=>'cap_runtime', 'label'=>'CAP feed runtime', 'state'=>$ok ? 'ok' : 'warning',
                'detail'=>$ok ? 'PHP XML and cURL are available.' : 'CAP feeds require XML and cURL in both the PBX web PHP and CLI runtimes. Run Repair Installation to install matching PHP-version extensions before enabling this feed.'];
        }
        if ($javascript) {
            $ok = is_executable('/usr/bin/node');
            $checks[] = ['id'=>'script_runtime', 'label'=>'JavaScript action runtime', 'state'=>$ok ? 'ok' : 'warning',
                'detail'=>$ok ? 'The Node.js interpreter is available.' : 'JavaScript actions require /usr/bin/node. Run Repair Installation before enabling this action.'];
        }
        return $checks;
    }
    public function automationRequest(string $ruleId,array $input): array
    {
        $current=AutomationConfig::resolve($this->getActiveSettings(),$ruleId);
        $operation=$input['operation'] ?? ''; unset($input['operation']);
        if ($operation==='status') {
            IncidentConfig::object($input,['event_id'],'Trigger status');
            $id=IncidentConfig::identifier($input['event_id'] ?? '', '/^[a-f0-9]{64}$/D','Trigger event identifier');
            $event=$this->automationStore()->read()['events'][$id] ?? null;
            if (!$event || $event['rule_id']!==$ruleId) { throw new \DomainException('Event is unavailable for this enrolled identity.'); }
            return ['ok'=>true,'event_id'=>$id,'state'=>$event['state']];
        }
        if ($operation==='challenge' && $current['rule']['kind']==='panic') {
            IncidentConfig::object($input,['request_id'],'Panic challenge');
            return ['ok'=>true,'challenge'=>$this->automationService()->challenge($ruleId,(string)($input['request_id'] ?? ''))];
        }
        if ($operation!=='activate') { throw new \InvalidArgumentException('Choose challenge or activate.'); }
        $event=$this->automationService()->activate($ruleId,$input,$current['rule']['kind']);
        if ($event['state']==='queued') { $this->startAutomationWorker($event['id']); }
        return ['ok'=>true,'event_id'=>$event['id'],'state'=>$event['state']];
    }
    private function startAutomationWorker(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$id)) { throw new \InvalidArgumentException('Invalid trigger event identifier.'); }
        // A durable queued record precedes this best-effort start; the minute
        // worker recovers missed starts using the same claim, never a new event.
        exec('/usr/bin/timeout --kill-after=5 180 /usr/bin/php '.escapeshellarg(self::RUNTIME_DIR.'/sls_mass_notify_automation_worker.php').' '.escapeshellarg($id).' >/dev/null 2>&1 &');
    }
    private function automationObservationPaths(string $directory): array
    {
        $handle=@opendir($directory);
        if (!$handle) { throw new \RuntimeException('Emergency observation storage could not be scanned.'); }
        $paths=[]; $inspected=0; $limited=false; $deadline=hrtime(true)+1000000000;
        try {
            while (($name=readdir($handle))!==false) {
                if ($name==='.' || $name==='..') { continue; }
                if ($inspected>=1024 || hrtime(true)>=$deadline) { $limited=true; break; }
                $inspected++;
                if (!str_ends_with($name,'.json') || !\SlsAnnouncementJobStore::validId(substr($name,0,-5))) { continue; }
                $path=$directory.'/'.$name; $meta=@lstat($path);
                if (!$meta || ($meta['mode'] & 0170000)!==0100000 || $meta['nlink']!==1) {
                    throw new \RuntimeException('Emergency observation storage contains an unsafe record.');
                }
                $paths[]=$path;
            }
        } finally { closedir($handle); }
        return ['paths'=>$paths,'inspected'=>$inspected,'limited'=>$limited];
    }
    public function processAutomationSources(bool $feeds=true): array
    {
        if (PHP_SAPI!=='cli') { throw new \DomainException('Source observation requires the local CLI worker.'); }
        $files=new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR.($feeds ? '/automation-sources' : '/automation-observer')); $lock=$files->previewLock();
        if (!$lock) { return ['success'=>true,'busy'=>true]; }
        $lease=null;
        try {
            $lease=$this->acquireAnnouncementActivityLock(false,5); $settings=$this->getActiveSettings();
            $config=AutomationConfig::normalize($settings['automations'] ?? []); $service=$this->automationService(); $health=[];
            $directory=self::PLUGIN_DATA_DIR.'/emergency-observations';
            if (!$feeds && is_dir($directory)) {
                $observations=new \SlsAnnouncementJobStore($directory); $processed=0;
                $scan=$this->automationObservationPaths($directory); $deadline=hrtime(true)+1000000000;
                foreach ($scan['paths'] as $path) {
                    if (hrtime(true)>=$deadline) { $scan['limited']=true; break; }
                    $id=basename($path,'.json'); if (!\SlsAnnouncementJobStore::validId($id)) { continue; }
                    $record=$observations->read($id);
                    if (!$record || ($record['schema'] ?? null)!==1) { throw new \RuntimeException('Emergency observation journal has an unsupported record.'); }
                    if (($record['state'] ?? '')!=='observed') {
                        if (is_int($record['sent_at'] ?? null) && $record['sent_at']<time()-86400) {
                            // read() checked regular-file identity and private parent;
                            // the collector never replaces an existing record.
                            if (is_file($path) && !is_link($path)) { unlink($path); }
                        }
                        continue;
                    }
                    if ($processed>=32) { break; }
                    $processed++;
                    try {
                        if (!is_string($record['settings_sha256'] ?? null) || !hash_equals(hash_file('sha256',self::SETTINGS_JSON),$record['settings_sha256'])) {
                            throw new \DomainException('Configuration changed after the dial attempt. No new recipient selection was inferred.');
                        }
                        $input=array_intersect_key($record,array_flip(['request_id','sent_at','expires_at','event','caller','message','is_test']));
                        $event=$service->activate((string)$record['rule_id'],$input,'emergency_call');
                        $record['event_id']=$event['id']; $record['state']='accepted'; $this->startAutomationWorker($event['id']);
                    } catch (\InvalidArgumentException | \DomainException $error) { $record['state']='rejected'; $record['reason']=$error->getMessage(); }
                    $observations->atomic($id.'.json',$record);
                }
                $health['emergency_call']=['checked_at'=>time(),'ok'=>!$scan['limited'],'processed'=>$processed,'inspected'=>$scan['inspected']];
                if ($scan['limited']) { $health['emergency_call']['detail']='Emergency observation scanning reached its bounded work limit. Review the protected journal for excess retained files before retrying.'; }
            }
            foreach ($config['rules'] as $rule) {
                if (!$feeds || !$rule['enabled'] || $rule['kind']!=='cap') { continue; }
                $accepted=0; $ignored=0;
                try {
                    $inputs=AutomationInputs::cap(AutomationInputs::fetch($rule['feed_url']),$rule);
                    // Old/expired feed entries never replay after enabling a feed
                    // or reconnecting. Cancellation precedes other source entries.
                    usort($inputs,static fn($a,$b)=>($a['operation']==='cancel' ? 0 : 1)<=>($b['operation']==='cancel' ? 0 : 1));
                    foreach ($inputs as $input) {
                        if ($input['sent_at']<time()-$rule['max_age_seconds'] || $input['expires_at']<=time() || ($input['is_test'] && !$rule['allow_tests'])) { $ignored++; continue; }
                        try {
                            $event=$service->activate($rule['id'],$input,'cap'); if ($event['state']==='queued') { $this->startAutomationWorker($event['id']); } $accepted++;
                        } catch (\DomainException $error) { $ignored++; }
                    }
                    $health[$rule['id']]=['checked_at'=>time(),'ok'=>true,'accepted'=>$accepted,'ignored'=>$ignored];
                } catch (\Throwable $error) { $health[$rule['id']]=['checked_at'=>time(),'ok'=>false,'detail'=>in_array(get_class($error),[\InvalidArgumentException::class,\DomainException::class,\RuntimeException::class],true) ? substr($error->getMessage(),0,400) : 'CAP source could not be processed. Check the installed PHP XML/cURL extensions and protected trigger journal.']; }
            }
            $files->atomic('worker-state.json',['schema'=>1,'checked_at'=>time(),'sources'=>$health]);
            return ['success'=>!array_filter($health,static fn($row)=>!$row['ok'])];
        } finally { if ($lease!==null) { $this->releaseNativeBackupFileLock($lease); } \SlsAnnouncementJobStore::unlock($lock); }
    }
    public function processAutomationEvents(string $id=''): array
    {
        if (PHP_SAPI!=='cli') { throw new \DomainException('Trigger processing requires the local CLI worker.'); }
        $settings=$this->getActiveSettings(); $config=AutomationConfig::normalize($settings['automations'] ?? []);
        if (!$config['rules'] && !is_dir($this->automationStoreDirectory())) { return ['success'=>true,'processed'=>0]; }
        $lease=$this->acquireAnnouncementActivityLock(false,5);
        try {
            $service=$this->automationService(); $store=$this->automationStore(); $failures=[];
            if ($id!=='') { $row=$service->process($id); return ['success'=>in_array($row['state'],['complete','cancelled','expired'],true),'event'=>$row]; }
            foreach (['cap'=>'--sources','emergency_call'=>'--observe'] as $kind=>$argument) {
                if (array_filter($config['rules'],static fn($row)=>$row['enabled'] && $row['kind']===$kind)) {
                    exec('/usr/bin/timeout --kill-after=5 120 /usr/bin/php '.escapeshellarg(self::RUNTIME_DIR.'/sls_mass_notify_automation_worker.php').' '.$argument.' >/dev/null 2>&1 &');
                }
            }
            // Reconciliation stays bounded; each event is executed by its own
            // supervised worker, so a slow script cannot stall schedules.
            foreach ($store->read()['events'] as $event) {
                if ($event['state']==='queued' || ($event['state']==='running' && ($event['claimed_at'] ?? 0)<time()-240)) { $this->startAutomationWorker($event['id']); }
            }
            return ['success'=>!$failures,'processed'=>0];
        } finally { $this->releaseNativeBackupFileLock($lease); }
    }
    protected function automationContextPermitted(array $context): bool
    {
        try { return $this->automationService()->permits($context); } catch (\Throwable $error) { return false; }
    }
}
