<?php
declare(strict_types=1);
// Reuse the actual main-class send/worker extraction and inert final adapters.
require __DIR__.'/test_incident_integration.php';
require_once __DIR__.'/../slsmassnotifyserver/Automations.php';
$automationDirectory=$directory.'/automation-fixture';mkdir($automationDirectory,0700);
$automationFixture=str_replace('class IncidentIntegratedFixture','class AutomationIntegratedFixture',$fixture);
$automationFixture=str_replace('    use \\FreePBX\\modules\\SlsAnnouncementDelivery;', '    use \\FreePBX\\modules\\SlsAutomations;'."\n".'    use \\FreePBX\\modules\\SlsAnnouncementDelivery;',$automationFixture);
$extra=<<<'PHP'
    public array $automationWorkers=[];
    protected function automationStoreDirectory(): string { return self::PLUGIN_DATA_DIR.'/automations'; }
    private function startAutomationWorker(string $id): void { $this->automationWorkers[]=$id; }
    protected function automationStore(): \SLS\MassNotify\AutomationStore { return new \SLS\MassNotify\AutomationStore($this->automationStoreDirectory()); }
PHP;
eval($automationFixture . ' const PLUGIN_DATA_DIR = ' . var_export($automationDirectory,true)
    . '; const ANNOUNCEMENT_LOCK_FILE = ' . var_export($automationDirectory.'/announcement.lock',true)
    . '; const RUNTIME_DIR = ' . var_export($automationDirectory,true) . ';' . $extra . $methods . '}');
$module=new AutomationIntegratedFixture();
$template=\SLS\MassNotify\IncidentConfig::template(['id'=>'tpl_'.str_repeat('e',24),'name'=>'Sensor responders','title'=>'Sensor report','message'=>'{{event}} at {{location}}',
    'fields'=>[['key'=>'event','label'=>'Event'],['key'=>'location','label'=>'Location']], 'delivery'=>['desktop_clients'=>['alice']],
    'roster'=>[['id'=>'alice_person','name'=>'Alice','location'=>'Office','desktop_username'=>'alice']]]);
$rule=\SLS\MassNotify\AutomationConfig::rule(['id'=>'trg_'.str_repeat('f',24),'name'=>'Enrolled fixture sensor','kind'=>'sensor','enabled'=>true,'secret'=>str_repeat('f',64),
    'event'=>'smoke','allow_tests'=>true,'location_id'=>'loc_'.str_repeat('a',24),'template_id'=>$template['id'],'fields'=>['event'=>'@event','location'=>'@location']]);
$module->settings=['enabled'=>'1','desktop_clients'=>[['enabled'=>'1','username'=>'alice','client_id'=>'cli_alice']],
    'announcement_webhooks'=>[],'announcement_groups'=>[], 'announcement_timeout_mode'=>'custom','announcement_timeout_seconds'=>45,
    'incident_workflows'=>['schema'=>1,'templates'=>[$template]],'automations'=>['schema'=>1,'rules'=>[$rule],'actions'=>[]],
    'location_directory'=>['nodes'=>[['id'=>$rule['location_id'],'name'=>'Main office','type'=>'site']]]];
$payload=['operation'=>'activate','request_id'=>'fixture-activation','sent_at'=>time(),'expires_at'=>time()+300,'event'=>'smoke','message'=>'','is_test'=>true];
$queued=$module->automationRequest($rule['id'],$payload);
incident_integration_check($queued['ok'] && $queued['state']==='queued' && count($module->automationWorkers)===1,'Trigger did not durably queue before launch.');
$processed=$module->processAutomationEvents($queued['event_id']);
incident_integration_check($processed['success'] && count($module->workers)===1,'Real trigger/incident facade failed: '.json_encode($processed));
$jobs=new SlsAnnouncementJobStore($automationDirectory.'/announcement-jobs'); $jobId=$module->workers[0];$job=$jobs->read($jobId);
incident_integration_check(($job['request']['automation_context']['event_id'] ?? '')===$queued['event_id'],'Trigger authorization lost at the real durable announcement boundary.');
incident_integration_check($job['request']['message']==='DRILL / TEST. smoke at Main office' && $job['request']['is_test']===true,'Trigger bindings/drill labeling lost.');
$incident=$processed['event']['results']['announcement'];
incident_integration_check(($incident['created_by']['automation_context']['event_id'] ?? '')===$queued['event_id'],'Automatic follow-up lost originating trigger provenance.');
$module->settings['automations']['rules'][0]['enabled']=false;
$module->processAnnouncementJobs($jobId);$job=$jobs->read($jobId);
incident_integration_check(count($module->commands)===0,'Revoked trigger reached the final desktop publisher.');
incident_integration_check(($job['receipts'][0]['state'] ?? '')==='cancelled','Revoked trigger has no explicit cancelled destination evidence.');
incident_integration_check($job['request']['automation_context']['event_id']===$queued['event_id'],'Completed/cancelled job erased provenance.');
echo "Integrated trigger → incident → durable announcement → current-source revocation checks passed.\n";
