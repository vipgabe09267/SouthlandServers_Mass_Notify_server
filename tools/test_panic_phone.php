<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/AutomationPhone.php';
use SLS\MassNotify\{AutomationConfig,AutomationPhone,AutomationStore,LivePagingAgi,LivePagingState,LivePagingConfig};
function panicCheck(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function createPanicFixtureRoot(string $prefix): array {
    $root=sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8));
    panicCheck(mkdir($root,0700),'Unable to create the private panic fixture root.');
    $stat=lstat($root);
    if (!is_array($stat)) { @rmdir($root);throw new RuntimeException('Unable to inspect the private panic fixture root.'); }
    if (($stat['mode']&0170000)!==0040000||($stat['mode']&0077)||$stat['nlink']<1) {
        @rmdir($root);throw new RuntimeException('Private panic fixture root metadata is unsafe.');
    }
    return [$root,$stat];
}
function removePanicFixture(string $root, int $device, int $inode): void {
    $rootStat = @lstat($root);
    if ($rootStat === false) {
        return;
    }
    panicCheck(($rootStat['mode']&0170000)===0040000 && !is_link($root), 'Panic fixture root was replaced before cleanup.');
    panicCheck((int)$rootStat['dev'] === $device && (int)$rootStat['ino'] === $inode, 'Panic fixture identity changed before cleanup.');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        panicCheck(str_starts_with($path, $root.DIRECTORY_SEPARATOR), 'Panic fixture cleanup escaped its private root.');
        if ($entry->isLink() || $entry->isFile()) {
            panicCheck(unlink($path), 'Unable to remove a panic fixture file.');
        } else {
            panicCheck(rmdir($path), 'Unable to remove a panic fixture directory.');
        }
    }
    panicCheck(rmdir($root), 'Unable to remove the panic fixture root.');
}
class PanicAgiFixture extends LivePagingAgi {
    public string $endpoint='1000'; public string $key='1'; public array $calls=[]; public ?Closure $onDigits=null;
    public function __construct() {}
    public function variable(string $expression):string {$this->calls[]=['identity',$expression];return $this->endpoint;}
    public function answer():void {$this->calls[]=['answer'];}
    public function autoHangup(int $seconds):void {$this->calls[]=['deadline',$seconds];}
    public function play(string $file):void {$this->calls[]=['play',$file];}
    public function digits(string $file,int $maximum):string {$this->calls[]=['digits',$maximum];if ($this->onDigits!==null) {($this->onDigits)();}return $this->key;}
}
[$faultRoot,$faultStat]=createPanicFixtureRoot('sls-panic-cleanup');
try {
    panicCheck(mkdir($faultRoot.'/nested',0700),'Unable to create the cleanup fault subtree.');
    panicCheck(file_put_contents($faultRoot.'/nested/event.json','{}',LOCK_EX)===2,'Unable to create cleanup fault evidence.');
    throw new RuntimeException('expected cleanup fault');
} catch (RuntimeException $error) {
    panicCheck($error->getMessage()==='expected cleanup fault','Unexpected cleanup fault escaped the fixture.');
} finally {
    removePanicFixture($faultRoot,(int)$faultStat['dev'],(int)$faultStat['ino']);
}
panicCheck(!file_exists($faultRoot),'Exception-path cleanup left its exact private fixture behind.');
[$root,$rootStat]=createPanicFixtureRoot('sls-panic');
$rootDevice=(int)$rootStat['dev'];$rootInode=(int)$rootStat['ino'];
$cleanup=static function()use($root,$rootDevice,$rootInode):void{removePanicFixture($root,$rootDevice,$rootInode);};
register_shutdown_function($cleanup);
$action=AutomationConfig::action(['id'=>'act_'.str_repeat('a',24),'name'=>'Fixture screen','kind'=>'brightsign_udp','enabled'=>true,'host'=>'192.168.20.2','port'=>5000,'message'=>'emergency']);
$rule=AutomationConfig::rule(['id'=>'trg_'.str_repeat('b',24),'name'=>'Desk shortcut','enabled'=>true,'kind'=>'panic','secret'=>str_repeat('c',64),
    'location_id'=>'loc_'.str_repeat('d',24),'dial_extension'=>'8998','callers'=>['1000'],'action_ids'=>[$action['id']]]);
$settings=['enabled'=>'0','runtime_enabled'=>true,'automations'=>['schema'=>1,'rules'=>[$rule],'actions'=>[$action]],'location_directory'=>['nodes'=>[['id'=>$rule['location_id'],'name'=>'Office','type'=>'site']]]];
$env=['agi_extension'=>'8998','agi_context'=>'sls-trigger-panic','agi_channel'=>'PJSIP/1000-0000001a','agi_uniqueid'=>'fixture.1'];
$workers=[];
$run=static function(PanicAgiFixture $agi,array $environment,string $name)use(&$settings,$root,&$workers):array {
    $directory=$root.'/'.$name;panicCheck(mkdir($directory,0700),'Unable to create a panic scenario fixture.');
    $session=new AutomationPhone($agi,new LivePagingState($directory),static function()use(&$settings){return $settings;},static fn()=>new AutomationStore($directory.'/events'),
        static function($id)use(&$workers){$workers[]=$id;},static fn()=>true);
    return $session->run($environment);
};
$agi=new PanicAgiFixture();$result=$run($agi,$env,'confirmed');
panicCheck(($result['state']??null)==='queued' && count($workers)===1,'Confirmed internal panic did not queue exactly one event.');
$kinds=array_column($agi->calls,0);$playIndex=array_search('play',$kinds,true);$digitsIndex=array_search('digits',$kinds,true);
panicCheck(is_int($playIndex)&&is_int($digitsIndex)&&$playIndex<$digitsIndex
    && $kinds[0]==='identity'&&in_array('answer',$kinds,true)&&in_array('deadline',$kinds,true),
    'Confirmation call setup or spoken-purpose ordering was incomplete.');
$journal=(new AutomationStore($root.'/confirmed/events'))->read();
panicCheck(is_array($journal['events']??null)&&count($journal['events'])===1,'Confirmed panic journal shape changed.');
$event=array_values($journal['events'])[0];
panicCheck(($event['caller']??null)==='1000' && ($event['source']??null)==='panic_phone' && ($event['is_test']??null)===false,'Phone identity or typed real-alert evidence missing.');
panicCheck(count($workers)===1 && is_string($workers[0]) && ($event['id']??null)===$workers[0]
    && ($event['state']??null)==='queued','AGI executed a delivery or queued the wrong durable event.');
$agi=new PanicAgiFixture();$agi->key='2';panicCheck(($run($agi,$env,'declined')['state']??null)==='not_confirmed','Non-confirming DTMF activated a panic.');
$agi=new PanicAgiFixture();$agi->endpoint='1001';$unauthorized=$run($agi,$env,'unauthorized');
panicCheck(($unauthorized['state']??null)==='unauthorized_caller'&&!in_array('answer',array_column($agi->calls,0),true),'Caller ID spoof or another endpoint activated or answered panic.');
$agi=new PanicAgiFixture();$trunk=$run($agi,array_replace($env,['agi_channel'=>'PJSIP/trunk-0000001a']),'trunk');
panicCheck(($trunk['state']??null)==='unauthorized_caller'&&!in_array('answer',array_column($agi->calls,0),true),'Trunk call activated or answered panic.');
$agi=new PanicAgiFixture();$wrongContext=$run($agi,array_replace($env,['agi_context'=>'from-trunk']),'context');
panicCheck(($wrongContext['state']??null)==='disabled'&&!in_array('answer',array_column($agi->calls,0),true),'Wrong dialplan context activated or answered panic.');
$agi=new PanicAgiFixture();$agi->onDigits=static function()use(&$settings){$settings['automations']['rules'][0]['enabled']=false;};
panicCheck(($run($agi,$env,'revoked')['state']??null)==='unconfirmed' && count($workers)===1,'Revocation during the confirmation prompt still activated panic.');
$settings['automations']['rules'][0]['enabled']=true;
$settings['runtime_enabled']=false;$agi=new PanicAgiFixture();panicCheck(($run($agi,$env,'paused')['state']??null)==='disabled'&&count($workers)===1&&$agi->calls===[],'Stopped runtime admitted a phone panic or answered its call.');$settings['runtime_enabled']=true;
$prompts=LivePagingConfig::promptFiles($settings);
panicCheck(isset($prompts['panic_'.$rule['id']],$prompts['panic_queued'],$prompts['panic_failed']),'Panic confirmation/outcome prompts were not included in the protected prompt manifest.');
$settings['automations']['rules'][0]['confirmation_prompt']='Different approved wording. Press one to activate.';
$changedPrompts=LivePagingConfig::promptFiles($settings);
panicCheck(isset($changedPrompts['panic_'.$rule['id']])&&$prompts['panic_'.$rule['id']]!==$changedPrompts['panic_'.$rule['id']],'Changing prompt wording reused or removed its sound asset.');
$cleanup();panicCheck(!file_exists($root),'Panic fixture cleanup left its private root behind.');
echo "Phone panic: endpoint/context authorization, deliberate confirmation, durable admission, revocation and prompt versioning passed.\n";
