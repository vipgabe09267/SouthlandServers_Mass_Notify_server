<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/AutomationService.php';
require_once __DIR__.'/../slsmassnotifyserver/AutomationInputs.php';
require_once __DIR__.'/../slsmassnotifyserver/DeliveryAuthorization.php';
use SLS\MassNotify\{AutomationConfig,AutomationService,AutomationStore,AutomationInputs};
$checks=0;
function check(bool $value,string $label): void { global $checks; $checks++; if (!$value) { throw new RuntimeException($label); } }
function rejects(callable $work,string $label): void { try { $work(); } catch (InvalidArgumentException|DomainException|RuntimeException $e) { check($e->getMessage()!=='',$label); return; } throw new RuntimeException('Expected rejection: '.$label); }
function automationFixtureCleanup(string $root,bool $strict): void {
    if(!is_dir($root)||is_link($root)){return;}
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry){$directory=$entry->isDir()&&!$entry->isLink();$removed=$strict?($directory?rmdir($entry->getPathname()):unlink($entry->getPathname())):($directory?@rmdir($entry->getPathname()):@unlink($entry->getPathname()));if(!$removed&&$strict){throw new RuntimeException('Unable to remove an automation fixture entry.');}}
    $removed=$strict?rmdir($root):@rmdir($root);if(!$removed&&$strict){throw new RuntimeException('Unable to remove the automation fixture directory.');}
}
$parent=sys_get_temp_dir().'/sls-automations-'.bin2hex(random_bytes(8));$passed=false;
try {
check(mkdir($parent,0700)&&chmod($parent,0700),'Unable to create the private automation fixture.');
$root=$parent.'/automations';check(mkdir($root,0700),'Unable to create the automation journal fixture.');
$action=AutomationConfig::action(['id'=>'act_'.str_repeat('a',24),'name'=>'Fixture screen','enabled'=>true,'kind'=>'brightsign_udp','host'=>'192.168.1.20','port'=>5000,'message'=>'evacuation']);
$rule=AutomationConfig::rule(['id'=>'trg_'.str_repeat('b',24),'name'=>'Fixture sensor','enabled'=>true,'kind'=>'sensor','secret'=>str_repeat('c',64),'event'=>'smoke','action_ids'=>[$action['id']],'allow_tests'=>true,'cooldown_seconds'=>10]);
$settings=['enabled'=>'0','runtime_enabled'=>true,'automations'=>['schema'=>1,'rules'=>[$rule],'actions'=>[$action]]];
$time=time(); $calls=[]; $store=new AutomationStore($root);
$service=new AutomationService($store,static function()use(&$settings){return $settings;},static function(){throw new RuntimeException('Unexpected notification');},static function($action,$row)use(&$calls){$calls[]=$row['id'];return ['state'=>'submitted'];},static function()use(&$time){return $time;});
$input=['request_id'=>'event-1','sent_at'=>$time,'expires_at'=>$time+300,'event'=>'smoke','message'=>'Smoke sensor active','is_test'=>true];
$event=$service->activate($rule['id'],$input,'sensor'); check($event['state']==='queued','durable queue');
$settings['runtime_enabled']=false;
rejects(fn()=>$service->activate($rule['id'],array_replace($input,['request_id'=>'paused']),'sensor'),'paused activation');
check($service->process($event['id'])['deferred']===true&&$calls===[],'Paused runtime executed an enrolled trigger action');
check(AutomationConfig::resolve($settings,$rule['id'])['rule']['id']===$rule['id']&&isset($store->read()['events'][$event['id']]),'Paused runtime or disabled Weather hid authorized trigger status');
$settings['runtime_enabled']=true;
check($service->activate($rule['id'],$input,'sensor')['id']===$event['id'],'duplicate idempotent before execution');
$reordered=['message'=>$input['message'],'event'=>$input['event'],'expires_at'=>$input['expires_at'],'sent_at'=>$input['sent_at'],'request_id'=>$input['request_id'],'is_test'=>$input['is_test'],'operation'=>'alert','references'=>[],'invalidate'=>[]];
check($service->activate($rule['id'],$reordered,'sensor')['id']===$event['id'],'Equivalent reordered/defaulted event was treated as different content');
rejects(fn()=>$service->activate($rule['id'],array_replace($input,['message'=>'changed']),'sensor'),'same identifier different content');
rejects(fn()=>$service->activate($rule['id'],array_replace($input,['request_id'=>'event-2']),'sensor'),'cooldown');
check($service->process($event['id'])['state']==='complete','action completes');
$service->process($event['id']); check(count($calls)===1,'duplicate execution never replays action');
$partialRoot=$parent.'/partial';check(mkdir($partialRoot,0700),'Unable to create the partial-action journal fixture.');
$secondAction=AutomationConfig::action(array_replace($action,['id'=>'act_'.str_repeat('d',24),'name'=>'Second fixture screen']));
$partialRule=AutomationConfig::rule(array_replace($rule,['id'=>'trg_'.str_repeat('e',24),'action_ids'=>[$action['id'],$secondAction['id']]]));
$partialSettings=['enabled'=>'0','runtime_enabled'=>true,'automations'=>['schema'=>1,'rules'=>[$partialRule],'actions'=>[$action,$secondAction]]];
$partialCalls=0;$partialStore=new AutomationStore($partialRoot);
$partialService=new AutomationService($partialStore,static fn()=>$partialSettings,static function(){throw new RuntimeException('Unexpected notification');},
    static function()use(&$partialCalls){if(++$partialCalls===1){return ['state'=>'submitted'];}throw new DomainException('Second action authorization changed.');},static fn()=>$time);
$partialEvent=$partialService->activate($partialRule['id'],array_replace($input,['request_id'=>'partial-action']),'sensor');
$partialResult=$partialService->process($partialEvent['id']);
check($partialResult['state']==='failed'&&$partialCalls===2&&($partialResult['results'][$action['id']]['state']??'')==='submitted'
    &&($partialResult['results']['guard']['state']??'')==='cancelled','Partial trigger execution was mislabeled as wholly cancelled or lost its first result');
$settingsReads=0;$snapshotService=new AutomationService($store,static function()use(&$settingsReads,&$settings){$settingsReads++;return $settings;},static function(){},static function(){},static function()use(&$time){return $time;});
check($snapshotService->permits($event['context'])&&$settingsReads===1,'Authorization combined different configuration reads');
$time+=11;$snapshotInput=array_replace($input,['request_id'=>'settings-snapshot','sent_at'=>$time,'expires_at'=>$time+300]);
$snapshotEvent=$service->activate($rule['id'],$snapshotInput,'sensor');$changedSettings=$settings;$changedSettings['automations']['actions'][0]['message']='changed-event';
$processReads=0;$snapshotActions=[];$snapshotWorker=new AutomationService($store,static function()use(&$processReads,&$settings,&$changedSettings){$processReads++;return $processReads<=2?$settings:$changedSettings;},static function(){throw new RuntimeException('Unexpected notification');},static function($action)use(&$snapshotActions){$snapshotActions[]=$action['message'];return ['state'=>'submitted'];},static function()use(&$time){return $time;});
check($snapshotWorker->process($snapshotEvent['id'])['state']==='failed'&&$snapshotActions===[]&&$processReads===3,'Changed settings were not rechecked before a later action');
$time+=11; $input['request_id']='event-3';$input['sent_at']=$time;$input['expires_at']=$time+300;
$event=$service->activate($rule['id'],$input,'sensor');$settings['automations']['rules'][0]['enabled']=false;
check($service->process($event['id'])['state']==='cancelled' && count($calls)===1,'revocation before worker prevents submission');
$settings['automations']['rules'][0]['enabled']=true;$time+=11;
$input['request_id']='event-4';$input['sent_at']=$time;$input['expires_at']=$time+30;$event=$service->activate($rule['id'],$input,'sensor');$time+=31;
check($service->process($event['id'])['state']==='expired' && count($calls)===1,'expiry prevents late action');
$time+=11;$input['request_id']='reconcile-expired';$input['sent_at']=$time;$input['expires_at']=$time+30;
$pending=$service->activate($rule['id'],$input,'sensor');$time+=31;$service->reconcile();
check($store->read()['events'][$pending['id']]['state']==='expired' && count($calls)===1,'reconciliation expires queued work without submitting it');
$input['request_id']='reconcile-disabled';$input['sent_at']=$time;$input['expires_at']=$time+300;
$pending=$service->activate($rule['id'],$input,'sensor');$settings['automations']['rules'][0]['enabled']=false;$service->reconcile();
check($store->read()['events'][$pending['id']]['state']==='cancelled' && count($calls)===1,'reconciliation cancels disabled queued work without submitting it');
$settings['automations']['rules'][0]['enabled']=true;
foreach ([['sent_at'=>$time+61],['sent_at'=>$time-601],['expires_at'=>$time],['event'=>'wrong'],['is_test'=>'false'],['is_test'=>null],['references'=>[str_repeat('a',64)]],['operation'=>'cancel']] as $bad) {
    rejects(fn()=>$service->activate($rule['id'],array_replace($input,['sent_at'=>$time,'expires_at'=>$time+300,'request_id'=>bin2hex(random_bytes(8))],$bad),'sensor'),'invalid source or payload');
}
$raw='{"operation":"activate"}';$stamp=(string)$time;$sig=hash_hmac('sha256',$rule['id'].'.'.$stamp.'.'.$raw,$rule['secret']);
check(AutomationInputs::authenticate($rule,$raw,['timestamp'=>$stamp,'signature'=>$sig],$time),'signed bytes authenticate');
check(!AutomationInputs::authenticate($rule,$raw.' ',['timestamp'=>$stamp,'signature'=>$sig],$time),'whitespace tamper');
check(!AutomationInputs::authenticate($rule,$raw,['timestamp'=>$stamp,'signature'=>$sig],$time+61),'stale signature');
check(!AutomationInputs::authenticate(array_replace($rule,['id'=>'trg_'.str_repeat('d',24)]),$raw,['timestamp'=>$stamp,'signature'=>$sig],$time),'cross identity signature');
foreach ([[],array_replace($rule,['enabled'=>1]),array_replace($rule,['id'=>'invalid']),array_replace($rule,['secret'=>[]])] as $malformedRule) {
    check(!AutomationInputs::authenticate($malformedRule,$raw,['timestamp'=>$stamp,'signature'=>$sig],$time),'Malformed enrolled rule reached HMAC verification');
}
foreach (['127.0.0.1','169.254.169.254','224.0.0.1','192.168.1.255','0.0.0.0','8.8.8.8','localhost'] as $host) { rejects(fn()=>AutomationConfig::action(array_replace($action,['host'=>$host])),'unsafe device host'); }
rejects(fn()=>AutomationConfig::action(array_replace($action,['path'=>'/tmp/file.sh'])),'mixed action fields');
rejects(fn()=>AutomationConfig::rule(array_replace($rule,['secret'=>'weak'])),'weak enrollment');
rejects(fn()=>AutomationConfig::rule(array_replace($rule,['allow_tests'=>'1'])),'typed flags');
rejects(fn()=>AutomationConfig::normalize(['rules'=>[$rule,$rule],'actions'=>[$action]]),'duplicate identities');

$panic=AutomationConfig::rule(['id'=>'trg_'.str_repeat('d',24),'name'=>'Desk panic','enabled'=>true,'kind'=>'panic','secret'=>str_repeat('e',64),'location_id'=>'loc_'.str_repeat('f',24),'action_ids'=>[$action['id']],'allow_tests'=>true]);
$settings['automations']['rules'][]=$panic;$settings['location_directory']['nodes']=[['id'=>$panic['location_id'],'name'=>'Office','type'=>'site']];
$implicit=$settings;unset($implicit['location_directory']);$implicit['automations']['rules'][1]['location_id']=\SLS\MassNotify\LocationDirectory::DEFAULT_SITE_ID;
check(AutomationConfig::resolve($implicit,$panic['id'])['location']['name']==='Default site','implicit default site is usable without a config rewrite');
$request=str_repeat('1',32);$challenge=$service->challenge($panic['id'],$request);
check($service->challenge($panic['id'],$request)===$challenge,'confirmation retry stable');
$panicInput=['request_id'=>$request,'sent_at'=>$time,'expires_at'=>$time+300,'event'=>'panic','message'=>'','is_test'=>true,'confirmation'=>$challenge['confirmation']];
rejects(fn()=>$service->activate($panic['id'],$panicInput,'panic'),'immediate accidental confirmation');
$time+=2;$event=$service->activate($panic['id'],$panicInput,'panic');check($event['state']==='queued','deliberate confirmed activation');
check(!isset($store->read()['challenges'][$event['id']]),'confirmation consumed');
check($service->activate($panic['id'],$panicInput,'panic')['id']===$event['id'],'confirmed duplicate does not need new challenge');
$settings['location_directory']['nodes'][0]['name']='Different room';check($service->process($event['id'])['state']==='cancelled','location re-enrollment change revokes queued action');

$cap=AutomationConfig::rule(['id'=>'trg_'.str_repeat('2',24),'name'=>'CAP feed','enabled'=>true,'kind'=>'cap','feed_url'=>'https://alerts.example.org/cap.xml','sender'=>'alerts@example.org','event'=>'Test event','action_ids'=>[$action['id']],'allow_tests'=>true]);
$settings['automations']['rules'][]=$cap;$sent=gmdate('Y-m-d\TH:i:s\Z',$time);$expires=gmdate('Y-m-d\TH:i:s\Z',$time+300);
$xml='<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2"><identifier>a1</identifier><sender>alerts@example.org</sender><sent>'.$sent.'</sent><status>Test</status><msgType>Alert</msgType><scope>Public</scope><info><event>Test event</event><headline>Reviewed headline</headline><expires>'.$expires.'</expires></info></alert>';
$messages=AutomationInputs::cap($xml,$cap);check(count($messages)===1 && $messages[0]['is_test']===true,'CAP typed test and absolute expiry');
$full=str_replace('<headline>Reviewed headline</headline>','<language>es-MX</language><headline>Reviewed headline</headline><description>Smoke near the east entrance.</description><instruction>Use the west exit.</instruction><area><areaDesc>Building A</areaDesc></area>',$xml);
check(AutomationInputs::cap($full,$cap)===[],'CAP language selection excludes unmatched text');
$spanish=array_replace($cap,['language'=>'es']);$mapped=AutomationInputs::cap($full,$spanish);
check(count($mapped)===1 && str_contains($mapped[0]['message'],'Use the west exit.') && str_contains($mapped[0]['message'],'Building A'),'CAP includes source instructions and affected area in selected language');
rejects(fn()=>AutomationInputs::cap(str_replace('Use the west exit.',str_repeat('x',3001),$full),$spanish),'oversized CAP instructions reject rather than silently truncate');
$event=$service->activate($cap['id'],$messages[0],'cap');
$cancelSent=gmdate('Y-m-d\TH:i:s\Z',$time+1);
$cancel=str_replace(['<identifier>a1</identifier>','<sent>'.$sent.'</sent>','<msgType>Alert</msgType>','<info>'],['<identifier>a2</identifier>','<sent>'.$cancelSent.'</sent>','<msgType>Cancel</msgType><references>alerts@example.org,a1,'.$sent.'</references>','<info>'],$xml);
$cancelEvent=AutomationInputs::cap($cancel,$cap)[0];
check($service->activate($cap['id'],$cancelEvent,'cap')['state']==='cancelled','CAP cancellation bypasses cooldown without notification');
check($service->process($event['id'])['state']==='cancelled','CAP cancellation suppresses original queued event');
$duplicateReferences=str_replace('</references>',' alerts@example.org,a1,'.$sent.'</references>',$cancel);
rejects(fn()=>AutomationInputs::cap($duplicateReferences,$cap),'duplicate CAP reference accepted');
$futureReference=str_replace('alerts@example.org,a1,'.$sent,'alerts@example.org,a1,'.$cancelSent,$cancel);
rejects(fn()=>AutomationInputs::cap($futureReference,$cap),'noncausal CAP reference accepted');
$beforeCalls=count($calls);$origin=str_repeat('4',64);$cancelOwn=str_repeat('5',64);$updateOwn=str_repeat('6',64);
$causalBase=['sent_at'=>$time,'expires_at'=>$time+300,'event'=>'Test event','message'=>'','caller'=>'','is_test'=>true];
$firstCancel=$service->activate($cap['id'],$causalBase+['request_id'=>'cancel-first','references'=>[$cancelOwn,$origin],'invalidate'=>[$origin],'operation'=>'cancel'],'cap');
$lateUpdate=$service->activate($cap['id'],array_replace($causalBase,['request_id'=>'late-update','message'=>'Late stale update','references'=>[$updateOwn,$origin],'invalidate'=>[$origin],'operation'=>'update']),'cap');
check($firstCancel['state']==='cancelled'&&$lateUpdate['state']==='cancelled'&&$lateUpdate['blocked']===true,'Update executed after its referenced alert was cancelled');
check($service->process($lateUpdate['id'])['state']==='cancelled'&&count($calls)===$beforeCalls,'Blocked out-of-order CAP update reached a physical action');
$cancelledUpdateIdentity=str_repeat('a',64);$cancelBeforeUpdate=str_repeat('b',64);$cancelledUpdateOrigin=str_repeat('c',64);
$service->activate($cap['id'],array_replace($causalBase,['request_id'=>'cancel-update-before-arrival','references'=>[$cancelBeforeUpdate,$cancelledUpdateIdentity],'invalidate'=>[$cancelledUpdateIdentity],'operation'=>'cancel']),'cap');
$updateAfterOwnCancel=$service->activate($cap['id'],array_replace($causalBase,['request_id'=>'update-after-own-cancel','message'=>'Cancelled update','references'=>[$cancelledUpdateIdentity,$cancelledUpdateOrigin],'invalidate'=>[$cancelledUpdateOrigin],'operation'=>'update']),'cap');
check($updateAfterOwnCancel['state']==='cancelled'&&$updateAfterOwnCancel['blocked']===true,'Update arriving after a cancellation of its own identity became active');
check($service->process($updateAfterOwnCancel['id'])['state']==='cancelled'&&count($calls)===$beforeCalls,'Update cancelled before arrival reached a physical action');
$time+=61;$lateAlert=$service->activate($cap['id'],array_replace($causalBase,['request_id'=>'late-alert','sent_at'=>$time,'expires_at'=>$time+300,'message'=>'Late alert','references'=>[$origin],'invalidate'=>[],'operation'=>'alert']),'cap');
check($lateAlert['state']==='cancelled'&&$lateAlert['blocked']===true,'Alert arriving after its cancellation became active');
$directRequest='direct-late-alert';$directIdentity=hash('sha256',$cap['id'].'|'.$directRequest);
$service->activate($cap['id'],array_replace($causalBase,['request_id'=>'direct-cancel-first','sent_at'=>$time,'expires_at'=>$time+300,'references'=>[str_repeat('d',64)],'invalidate'=>[$directIdentity],'operation'=>'cancel']),'cap');
$time+=61;
$directLate=$service->activate($cap['id'],array_replace($causalBase,['request_id'=>$directRequest,'sent_at'=>$time,'expires_at'=>$time+300,'message'=>'Direct late alert','references'=>[],'invalidate'=>[],'operation'=>'alert']),'cap');
check($directLate['state']==='cancelled'&&$directLate['blocked']===true,'Cancellation tombstone did not block the event identity itself');
$time+=61;$head=str_repeat('7',64);$next=str_repeat('8',64);$stop=str_repeat('9',64);
$headEvent=$service->activate($cap['id'],array_replace($causalBase,['request_id'=>'causal-head','sent_at'=>$time,'expires_at'=>$time+300,'message'=>'Head','references'=>[$head],'invalidate'=>[],'operation'=>'alert']),'cap');
$nextEvent=$service->activate($cap['id'],array_replace($causalBase,['request_id'=>'causal-update','sent_at'=>$time,'expires_at'=>$time+300,'message'=>'Current update','references'=>[$next,$head],'invalidate'=>[$head],'operation'=>'update']),'cap');
check($nextEvent['state']==='queued'&&!$nextEvent['blocked'],'A causal CAP update was rejected');
$service->activate($cap['id'],array_replace($causalBase,['request_id'=>'causal-cancel','sent_at'=>$time,'expires_at'=>$time+300,'references'=>[$stop,$head],'invalidate'=>[$head],'operation'=>'cancel']),'cap');
check($service->process($nextEvent['id'])['state']==='cancelled'&&$service->process($headEvent['id'])['state']==='cancelled','Cancellation did not propagate through CAP update lineage');
$descendant=str_repeat('d',64);
$lateDescendant=$service->activate($cap['id'],array_replace($causalBase,['request_id'=>'late-causal-descendant','sent_at'=>$time,'expires_at'=>$time+300,'message'=>'Late descendant','references'=>[$descendant,$next],'invalidate'=>[$next],'operation'=>'update']),'cap');
check($lateDescendant['state']==='cancelled'&&$lateDescendant['blocked']===true,'Late CAP descendant ignored a cancelled ancestor');
check(AutomationInputs::cap(str_replace('alerts@example.org','wrong@example.org',$xml),$cap)===[],'CAP sender isolation');
check(AutomationInputs::cap(str_replace('<scope>Public','<scope>Private',$xml),$cap)===[],'CAP scope');
rejects(fn()=>AutomationInputs::cap('<!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]>'.$xml,$cap),'XXE declaration');
rejects(fn()=>AutomationInputs::cap(str_replace($sent,substr($sent,0,-1),$xml),$cap),'timezone-less CAP');
rejects(fn()=>AutomationInputs::cap(str_replace('<sender>alerts@example.org</sender>','<sender>alerts@example.org</sender><sender>alerts@example.org</sender>',$xml),$cap),'CAP duplicate critical field');
foreach (['http://example.org/cap','https://127.0.0.1/cap','https://8.8.8.8/cap','https://localhost/cap','https://bad..example.org/cap','https://user:secret@example.org/cap','https://example.org:8443/cap',"https://example.org/cap\nInjected: yes",'https://example.org/'.str_repeat('a',2049)] as $url) { rejects(fn()=>AutomationInputs::fetch($url),'CAP unsafe fetch URL'); }
// Uncertain claims are terminal: never replay a script after a killed worker.
$store->transaction(static function(array &$state) use($event,$time): void { $state['events'][$event['id']]['state']='running';$state['events'][$event['id']]['claimed_at']=$time-241; });
check($service->process($event['id'])['state']==='uncertain','interrupted claimed action is uncertain');
check(count($calls)===1,'no unintended test notifications or extra actions');
foreach (['127.0.0.1','10.0.0.1','169.254.1.1','100.64.0.1','100.127.255.254','192.0.2.8','198.18.2.5','198.51.100.8','203.0.113.4','224.0.0.1','240.0.0.1','255.255.255.255','::1','8.8.8.8.evil'] as $ip) {
    check(!AutomationInputs::publicAddress($ip), 'Reserved CAP target was accepted.');
}
check(AutomationInputs::publicAddress('8.8.8.8') && AutomationInputs::publicAddress('1.1.1.1'), 'Public IPv4 target was rejected.');
$time+=20; $input=array_replace($input,['request_id'=>'transmission-check','sent_at'=>$time,'expires_at'=>$time+300]);
$event=$service->activate($rule['id'],$input,'sensor');
$gate=['automation_context'=>$event['context'],'webhook_ids'=>['fixture_hook']];
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,['webhook_ids'=>['fixture_hook']],$root,$time), 'Identity-free webhook authorization was accepted');
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,['automation_context'=>null,'webhook_ids'=>['fixture_hook']],$root,$time), 'Null automation identity was accepted');
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,['api_credential_id'=>null,'webhook_ids'=>['fixture_hook']],$root,$time), 'Null API identity was accepted');
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,['automation_context'=>$event['context'],'webhook_ids'=>['fixture_hook','fixture_hook']],$root,$time), 'Repeated webhook authorization target was accepted');
rejects(fn()=>\SLS\MassNotify\DeliveryAuthorization::parseInput('{"webhook_ids":["first"],"webhook_ids":["second"]}'),'Duplicate authorization JSON field was accepted');
rejects(fn()=>\SLS\MassNotify\DeliveryAuthorization::parseInput('[]'),'Authorization JSON array was accepted');
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,$gate,$root.'/missing',$time), 'A missing journal allowed transmission');
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,$gate,$root,$time), 'An action-only trigger authorized an unrelated webhook transmission');
$authRoot=$parent.'/authorized-webhooks';check(mkdir($authRoot,0700),'Unable to create the authorization journal fixture.');
$template=\SLS\MassNotify\IncidentConfig::template(['id'=>'tpl_'.str_repeat('a',24),'name'=>'Webhook fixture','title'=>'Fixture','message'=>'Fixture',
    'delivery'=>['webhook_ids'=>['fixture_hook'],'group_ids'=>['grp_aaaaaaaaaaaa','grp_bbbbbbbbbbbb']]]);
$authRule=\SLS\MassNotify\AutomationConfig::rule(array_replace($rule,['id'=>'trg_'.str_repeat('a',24),'template_id'=>$template['id']]));
$authSettings=$settings;$authSettings['incident_workflows']=['schema'=>1,'templates'=>[$template]];
$authSettings['announcement_groups']=[['id'=>'grp_aaaaaaaaaaaa','webhook_ids'=>['group_hook']],['id'=>'grp_bbbbbbbbbbbb']];
$authSettings['automations']=['schema'=>1,'rules'=>[$authRule],'actions'=>[$action]];
$authStore=new AutomationStore($authRoot);$authService=new AutomationService($authStore,static fn()=>$authSettings,static fn()=>[],static fn()=>[],static fn()=>$time);
$authInput=array_replace($input,['request_id'=>'authorized-webhook','sent_at'=>$time,'expires_at'=>$time+300]);
$authEvent=$authService->activate($authRule['id'],$authInput,'sensor');
$changedAuthSettings=$authSettings;$changedAuthSettings['incident_workflows']['templates'][0]['message']='Changed after authorization';
$authProcessReads=0;$resolvedMessages=[];$snapshotAuthService=new AutomationService($authStore,
    static function()use(&$authProcessReads,&$authSettings,&$changedAuthSettings){$authProcessReads++;return $authProcessReads<=2?$authSettings:$changedAuthSettings;},
    static function($resolved)use(&$resolvedMessages){$resolvedMessages[]=$resolved['template']['message'];return ['success'=>true];},
    static function(){return ['state'=>'submitted'];},static function()use(&$time){return $time;});
$snapshotAuthResult=$snapshotAuthService->process($authEvent['id']);
check($resolvedMessages===['Fixture']&&$authProcessReads===3&&$snapshotAuthResult['state']==='failed','Announcement resolved a template from a different settings snapshot than its authorization');
$authGate=['automation_context'=>$authEvent['context'],'webhook_ids'=>['fixture_hook']];
check(AutomationConfig::resolvePermitted($authSettings,$authEvent['context'],$time)['rule']['id']===$authRule['id'],'Current trigger context did not return its reviewed resolution');
check(AutomationConfig::resolvePermitted($authSettings,$authEvent['context'],$time+301)===null,'Expired trigger context returned a resolution');
check(\SLS\MassNotify\DeliveryAuthorization::permits($authSettings,$authGate,$authRoot,$time), 'Current template webhook was rejected at transmission');
check(\SLS\MassNotify\DeliveryAuthorization::permits($authSettings,array_replace($authGate,['webhook_ids'=>['group_hook']]),$authRoot,$time), 'Current template group webhook was rejected at transmission');
check(!\SLS\MassNotify\DeliveryAuthorization::permits($authSettings,array_replace($authGate,['webhook_ids'=>['other_hook']]),$authRoot,$time), 'Automation authorized a webhook outside its incident template');
$changedBrowser=$authSettings;$changedBrowser['subscriber_browser']=['enabled'=>true];
check(AutomationConfig::resolvePermitted($changedBrowser,$authEvent['context'],$time)===null,'Browser-subscriber changes did not revoke a queued trigger');
$changedConnector=$authSettings;$changedConnector['enterprise_integrations']=['enabled'=>'1'];
check(AutomationConfig::resolvePermitted($changedConnector,$authEvent['context'],$time)===null,'Enterprise-connector changes did not revoke a queued trigger');
$settings['runtime_enabled']=false;check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,$gate,$root,$time),'Stopped runtime allowed webhook transmission');$settings['runtime_enabled']=true;
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,$gate,$root,$time+301), 'Expired trigger reached transmission');
$settings['automations']['rules'][0]['enabled']=false;
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,$gate,$root,$time), 'Disabled trigger reached transmission');
$settings['automations']['rules'][0]['enabled']=true;
$store->transaction(static function(array &$state)use($event):void{$state['events'][$event['id']]['blocked']=true;});
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,$gate,$root,$time), 'Blocked durable trigger reached transmission');
$path=$root.'/private.config';$configJson=json_encode($settings,JSON_THROW_ON_ERROR);check(file_put_contents($path,$configJson,LOCK_EX)===strlen($configJson)&&chmod($path,0600),'Unable to create the protected authorization configuration fixture.');
check(\SLS\MassNotify\DeliveryAuthorization::settings($path)===$settings, 'Read-only authorization changed config');
check(link($path,$root.'/linked.config'),'Unable to create the authorization hard-link fixture.');rejects(fn()=>\SLS\MassNotify\DeliveryAuthorization::settings($path),'Hard-linked config reached transmission');check(unlink($root.'/linked.config'),'Unable to remove the authorization hard-link fixture.');
check(chmod($path,0666),'Unable to expose the configuration-mode fixture.');rejects(fn()=>\SLS\MassNotify\DeliveryAuthorization::settings($path),'Writable config reached transmission');
$protectedBytes=file_get_contents($path);check(is_string($protectedBytes),'Unable to read protected configuration evidence.');
foreach([0644,0641,0660] as $mode){
    check(chmod($path,$mode),'Unable to set an unsafe configuration fixture mode.');
    rejects(fn()=>\SLS\MassNotify\DeliveryAuthorization::settings($path),'Public-readable, executable or group-writable config reached transmission');
    check(file_get_contents($path)===$protectedBytes,'Rejected delivery authorization modified configuration bytes.');
}
check(chmod($path,0640),'Unable to restore the private configuration fixture mode.');
check(\SLS\MassNotify\DeliveryAuthorization::settings($path)===$settings,'Private group-readable configuration was rejected at transmission');
// Persisted corruption must fail closed before prune(), HMAC comparisons, or
// worker reconciliation can interpret attacker-controlled PHP value types.
$validJournal=$store->read();$journalFiles=new \SlsAnnouncementJobStore($root);
$corruptions=[];
$bad=$validJournal;$bad['challenges'][str_repeat('1',64)]='not-an-object';$corruptions[]=$bad;
$bad=$validJournal;$bad['cooldowns']['bad-rule']='tomorrow';$corruptions[]=$bad;
$bad=$validJournal;$bad['invalidated'][str_repeat('2',64)]='tomorrow';$corruptions[]=$bad;
$eventKey=array_key_first($validJournal['events']);check(is_string($eventKey),'Automation journal fixture has no event to corrupt.');
$bad=$validJournal;$bad['events'][$eventKey]['fingerprint']=[];$corruptions[]=$bad;
$bad=$validJournal;$bad['events'][$eventKey]['context']['unexpected']=true;$corruptions[]=$bad;
$bad=$validJournal;$bad['events'][$eventKey]['blocked']='false';$corruptions[]=$bad;
$bad=$validJournal;$bad['events'][$eventKey]['results']['worker']='invalid';$corruptions[]=$bad;
$bad=$validJournal;$bad['unexpected']=[];$corruptions[]=$bad;
foreach($corruptions as $index=>$bad){
    $journalFiles->atomic('worker-state.json',$bad);
    rejects(fn()=>$store->read(),'Malformed automation journal case '.($index+1).' was accepted');
}
$journalFiles->atomic('worker-state.json',$validJournal);check($store->read()===$validJournal,'Valid automation history was not restored after corruption tests.');
check(!$store->permits(['event_id'=>[]],$time),'Malformed event identity reached the trigger authorization journal.');
// Administration must be checked before reading private trigger state or inspecting a script.
require_once __DIR__.'/../slsmassnotifyserver/Automations.php';
class AutomationAccessFixture {
    use \FreePBX\modules\SlsAutomations;
    const PLUGIN_DATA_DIR='/nonexistent-sls-automation-access-fixture';
    public string $role='viewer'; public int $reads=0;
    public function currentOperator():array{return ['operator_role'=>$this->role];}
    public function getActiveSettings():array{$this->reads++;return ['enabled'=>'1'];}
    public function getPendingSettings():?array{$this->reads++;return null;}
    protected function automationStoreDirectory():string{return self::PLUGIN_DATA_DIR;}
}
$adminFixture=new AutomationAccessFixture;
foreach(['viewer','sender','scheduler','warden']as$role){
    $adminFixture->role=$role;
    rejects(fn()=>$adminFixture->getAutomationState(),'Limited role read private trigger configuration');
    rejects(fn()=>$adminFixture->saveAutomationItem([]),'Limited role changed trigger configuration');
    rejects(fn()=>$adminFixture->inspectAutomationScript('/tmp/forbidden.sh'),'Limited role inspected a script');
}
check($adminFixture->reads===0,'Denied role performed configuration discovery before authorization.');
$adminFixture->role='administrator';
check($adminFixture->getAutomationState()['config']===['schema'=>1,'rules'=>[],'actions'=>[]],'Administrator could not review trigger configuration.');
// The PHP-to-worker handoff must tolerate legitimate short pipe writes.
$runnerDirectory=$parent.'/runner';check(mkdir($runnerDirectory,0700),'Unable to create the action-runner fixture.');
$runnerHelper=$runnerDirectory.'/sls_trigger_actions.py';
$runnerSource="import json,sys\nvalue=json.load(sys.stdin)\nassert value == {'fixture':'x'*4096}\nprint(json.dumps({'state':'completed'}))\n";
check(file_put_contents($runnerHelper,$runnerSource,LOCK_EX)===strlen($runnerSource)&&chmod($runnerHelper,0600),'Unable to create the action-runner helper.');
$runnerCode='declare(strict_types=1); namespace FreePBX\\modules {'
    .'function fwrite($stream,$data){return \\fwrite($stream,substr($data,0,7));}'
    .'require_once '.var_export(__DIR__.'/../slsmassnotifyserver/Automations.php',true).';'
    .'class ShortWriteAutomationRunner {use SlsAutomations; const RUNTIME_DIR='.var_export($runnerDirectory,true).';'
    .'public function run(array $input):array{return $this->automationRunner([],$input);}}'
    .'$result=(new ShortWriteAutomationRunner)->run(["fixture"=>str_repeat("x",4096)]);'
    .'if(($result["state"]??"")!=="completed"){throw new \\RuntimeException("Short write handoff failed");}'
    .'chmod('.var_export($runnerHelper,true).',0666);$rejected=false;try{(new ShortWriteAutomationRunner)->run([]);}catch(\\RuntimeException $error){$rejected=true;}'
    .'if(!$rejected){throw new \\RuntimeException("Writable automation helper executed");}}';
$runnerProcess=proc_open(['/usr/bin/timeout','--signal=TERM','--kill-after=2','10',PHP_BINARY,'-r',$runnerCode],
    [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$runnerPipes,'/',
    ['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'],['bypass_shell'=>true]);
check(is_resource($runnerProcess),'Unable to start the short-write action-runner fixture.');
$runnerOut=stream_get_contents($runnerPipes[1],1025);$runnerError=stream_get_contents($runnerPipes[2],4097);
fclose($runnerPipes[1]);fclose($runnerPipes[2]);$runnerExit=proc_close($runnerProcess);
check(is_string($runnerOut)&&strlen($runnerOut)<=1024&&is_string($runnerError)&&strlen($runnerError)<=4096&&$runnerExit===0,
    'Action input short writes were not completed: '.substr((string)$runnerError,0,300));
// Read the real directory scanner: ordinary retained history fits, while a
// crowded or unsafe journal stays bounded before any record is consumed.
$scanMethod=new ReflectionMethod(AutomationAccessFixture::class,'automationObservationPaths');
$scanDirectory=$root.'/observations';check(mkdir($scanDirectory,0700),'Unable to create the observation fixture directory.');
for($i=0;$i<550;$i++){check(file_put_contents($scanDirectory.'/job_'.sprintf('%032x',$i).'.json','{}',LOCK_EX)===2,'Unable to create an observation fixture.');}
$scan=$scanMethod->invoke($adminFixture,$scanDirectory);
check(!$scan['limited']&&$scan['inspected']===550&&count($scan['paths'])===550,'Supported emergency history was truncated.');
for($i=0;$i<700;$i++){check(file_put_contents($scanDirectory.'/unrelated-'.$i,'ignored',LOCK_EX)===7,'Unable to create a bounded-scan fixture.');}
$started=hrtime(true);$scan=$scanMethod->invoke($adminFixture,$scanDirectory);
check($scan['limited']&&$scan['inspected']<=1024&&count($scan['paths'])<=1024,'Crowded emergency history was fully enumerated.');
check(hrtime(true)-$started<2000000000,'Emergency history scanner exceeded its bounded deadline.');
$unsafeDirectory=$root.'/unsafe-observations';check(mkdir($unsafeDirectory,0700),'Unable to create the unsafe-observation fixture directory.');
$unsafe=$unsafeDirectory.'/job_'.str_repeat('a',32).'.json';
check(symlink($scanDirectory.'/job_'.sprintf('%032x',0).'.json',$unsafe),'Unable to create the observation symlink fixture.');
rejects(fn()=>$scanMethod->invoke($adminFixture,$unsafeDirectory),'Emergency observation symlink');check(unlink($unsafe),'Unable to remove the observation symlink fixture.');
check(link($scanDirectory.'/job_'.sprintf('%032x',0).'.json',$unsafe),'Unable to create the observation hard-link fixture.');
rejects(fn()=>$scanMethod->invoke($adminFixture,$unsafeDirectory),'Emergency observation shared inode');check(unlink($unsafe),'Unable to remove the observation hard-link fixture.');
check(posix_mkfifo($unsafe,0600),'Emergency FIFO fixture could not be created');
$started=hrtime(true);rejects(fn()=>$scanMethod->invoke($adminFixture,$unsafeDirectory),'Emergency observation FIFO');
check(hrtime(true)-$started<500000000,'Emergency observation FIFO blocked its scanner');check(unlink($unsafe),'Unable to remove the observation FIFO fixture.');
rejects(fn()=>$scanMethod->invoke($adminFixture,$root.'/missing-observations'),'Missing emergency observation storage');
// The actual PBX page must invoke its role gate before either GET or POST work.
foreach(['GET','POST']as$method){
    $code='require_once '.var_export(__DIR__.'/../slsmassnotifyserver/Operators.php',true).';'
        .'class AutomationPageFixture {use \\FreePBX\\modules\\SlsOperators;'
        .'function getActiveSettings(){return ["operator_access"=>["enabled"=>true]];}'
        .'function currentOperator(){return ["operator_role"=>"sender"];}'
        .'function __call($name,$args){throw new RuntimeException("Unauthorized controller dispatched ".$name);}}'
        .'class FreePBX{static function create(){return (object)["Slsmassnotifyserver"=>new AutomationPageFixture];}}'
        .'$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';$_POST=[];'
        .'register_shutdown_function(function(){fwrite(STDERR,(string)(http_response_code()?:200));});'
        .'require '.var_export(__DIR__.'/../slsmassnotifyserver/page.slsmassnotifyserver_automations.php',true).';';
    $environment=['PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'];
    $process=proc_open(['/usr/bin/timeout','--signal=TERM','--kill-after=2s','10s',PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$environment,['bypass_shell'=>true]);
    check(is_resource($process),'Role controller fixture could not start');check(fclose($pipes[0]),'Role controller input pipe could not be closed.');
    $body=stream_get_contents($pipes[1],8193);$status=stream_get_contents($pipes[2],1025);fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($process);
    check(is_string($body)&&strlen($body)<=8192&&is_string($status)&&strlen($status)<=1024&&$exit===0&&$status==='403'&&str_contains($body,'current SLS role'),'Trigger page bypassed its SLS role gate.');
}
$passed=true;
} finally { automationFixtureCleanup($parent,$passed); }
echo "Automation configuration/auth/journal/CAP/transmission: $checks checks passed\n";
