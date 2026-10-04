<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/AutomationPhone.php';
use SLS\MassNotify\{AutomationConfig,AutomationPhone,AutomationStore,LivePagingAgi,LivePagingState,LivePagingConfig};
function panicCheck(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
class PanicAgiFixture extends LivePagingAgi {
    public string $endpoint='1000'; public string $key='1'; public array $calls=[]; public $onDigits=null;
    public function __construct() {}
    public function variable(string $expression):string {$this->calls[]=['identity',$expression];return $this->endpoint;}
    public function answer():void {$this->calls[]=['answer'];}
    public function autoHangup(int $seconds):void {$this->calls[]=['deadline',$seconds];}
    public function play(string $file):void {$this->calls[]=['play',$file];}
    public function digits(string $file,int $maximum):string {$this->calls[]=['digits',$maximum];if ($this->onDigits) {($this->onDigits)();}return $this->key;}
}
$root=sys_get_temp_dir().'/sls-panic-'.bin2hex(random_bytes(8));mkdir($root,0700);
$action=AutomationConfig::action(['id'=>'act_'.str_repeat('a',24),'name'=>'Fixture screen','kind'=>'brightsign_udp','enabled'=>true,'host'=>'192.168.20.2','port'=>5000,'message'=>'emergency']);
$rule=AutomationConfig::rule(['id'=>'trg_'.str_repeat('b',24),'name'=>'Desk shortcut','enabled'=>true,'kind'=>'panic','secret'=>str_repeat('c',64),
    'location_id'=>'loc_'.str_repeat('d',24),'dial_extension'=>'8998','callers'=>['1000'],'action_ids'=>[$action['id']]]);
$settings=['enabled'=>'1','automations'=>['schema'=>1,'rules'=>[$rule],'actions'=>[$action]],'location_directory'=>['nodes'=>[['id'=>$rule['location_id'],'name'=>'Office','type'=>'site']]]];
$env=['agi_extension'=>'8998','agi_context'=>'sls-trigger-panic','agi_channel'=>'PJSIP/1000-0000001a','agi_uniqueid'=>'fixture.1'];
$workers=[];
$run=static function(PanicAgiFixture $agi,array $environment,string $name)use(&$settings,$root,&$workers):array {
    $directory=$root.'/'.$name;mkdir($directory,0700);
    $session=new AutomationPhone($agi,new LivePagingState($directory),static function()use(&$settings){return $settings;},static fn()=>new AutomationStore($directory.'/events'),
        static function($id)use(&$workers){$workers[]=$id;},static fn()=>true);
    return $session->run($environment);
};
$agi=new PanicAgiFixture();$result=$run($agi,$env,'confirmed');
panicCheck($result['state']==='queued' && count($workers)===1,'Confirmed internal panic did not queue exactly one event.');
$kinds=array_column($agi->calls,0);panicCheck(array_search('play',$kinds)<array_search('digits',$kinds),'Confirmation was read before the entire spoken purpose.');
$journal=(new AutomationStore($root.'/confirmed/events'))->read();$event=array_values($journal['events'])[0];
panicCheck($event['caller']==='1000' && $event['source']==='panic_phone' && $event['is_test']===false,'Phone identity or typed real-alert evidence missing.');
panicCheck(count($workers)===1 && $event['state']==='queued','AGI executed a delivery instead of durable admission.');
$agi=new PanicAgiFixture();$agi->key='2';panicCheck($run($agi,$env,'declined')['state']==='not_confirmed','Non-confirming DTMF activated a panic.');
$agi=new PanicAgiFixture();$agi->endpoint='1001';panicCheck($run($agi,$env,'unauthorized')['state']==='unauthorized_caller','Caller ID spoof or another endpoint activated panic.');
$agi=new PanicAgiFixture();panicCheck($run($agi,array_replace($env,['agi_channel'=>'PJSIP/trunk-0000001a']),'trunk')['state']==='unauthorized_caller','Trunk call activated panic.');
$agi=new PanicAgiFixture();panicCheck($run($agi,array_replace($env,['agi_context'=>'from-trunk']),'context')['state']==='disabled','Wrong dialplan context activated panic.');
$agi=new PanicAgiFixture();$agi->onDigits=static function()use(&$settings){$settings['automations']['rules'][0]['enabled']=false;};
panicCheck($run($agi,$env,'revoked')['state']==='unconfirmed' && count($workers)===1,'Revocation during the confirmation prompt still activated panic.');
$settings['automations']['rules'][0]['enabled']=true;
$prompts=LivePagingConfig::promptFiles($settings);
panicCheck(isset($prompts['panic_'.$rule['id']],$prompts['panic_queued'],$prompts['panic_failed']),'Panic confirmation/outcome prompts were not included in the protected prompt manifest.');
$settings['automations']['rules'][0]['confirmation_prompt']='Different approved wording. Press one to activate.';
panicCheck($prompts['panic_'.$rule['id']]!==LivePagingConfig::promptFiles($settings)['panic_'.$rule['id']],'Changing prompt wording reused an old sound asset.');
echo "Phone panic: endpoint/context authorization, deliberate confirmation, durable admission, revocation and prompt versioning passed.\n";
