<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/AnnouncementDelivery.php';
require dirname(__DIR__).'/slsmassnotifyserver/TestProfiles.php';
use SLS\MassNotify\TestDeliveryReport as Report;
$root=sys_get_temp_dir().'/sls-test-report-'.bin2hex(random_bytes(8)); mkdir($root,0750);
define('REPORT_ROOT',$root);
class ReceiptReportFixture {
    use \FreePBX\modules\SlsAnnouncementDelivery, \FreePBX\modules\SlsTestProfiles;
    const PLUGIN_DATA_DIR=REPORT_ROOT;
    public array $settings; public array $evidence=[];
    public function getActiveSettings(){return $this->settings;}
    public function getDesktopClients($settings){return [['username'=>'desktop_one','client_id'=>'cli_fixture']];}
    protected function readAnnouncementPhoneOutcomes($correlation){return $this->evidence;}
    public function markers($lines,$targets){return $this->testDesktopPublications($lines,$targets,$this->settings);}
}
$checks=0;
function reportCheck(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function reportReject(callable $work):void{try{$work();}catch(DomainException|JsonException $e){reportCheck(true,'Rejected');return;}throw new RuntimeException('Unsafe report input was accepted.');}
try {
    $settings=['desktop_auth_key'=>base64_encode(random_bytes(32))];$m=new ReceiptReportFixture;$m->settings=$settings;
    $context=['correlation'=>'test_'.str_repeat('a',32),'created_at'=>time(),'phones'=>['1000','1001'],
        'desktops'=>[['event_id'=>'test_event_a','target'=>'desktop_one','client_id'=>'cli_fixture'],['event_id'=>'test_event_b','target'=>'desktop_one','client_id'=>'cli_fixture']]];
    $ticket=Report::ticket($context,$settings);
    reportCheck(Report::context($ticket,$settings,time())===$context,'Signed report changed its recipients.');
    reportReject(fn()=>Report::context(substr($ticket,0,-1).(str_ends_with($ticket,'0')?'1':'0'),$settings,time()));
    reportReject(fn()=>Report::context($ticket,['desktop_auth_key'=>base64_encode(random_bytes(32))],time()));
    foreach ([$context['created_at']-1,$context['created_at']+1800] as $now) reportReject(fn()=>Report::context($ticket,$settings,$now));
    foreach ([['correlation'=>'../../secret'],['phones'=>['../1000']],['phones'=>['1000',1001]],['desktops'=>[['event_id'=>'event','target'=>'bad<script>','client_id'=>'id']]]] as $patch) {
        $bad=Report::ticket(array_replace($context,$patch),$settings);reportReject(fn()=>Report::context($bad,$settings,time()));
    }
    $first=$m->getTestDeliveryStatus($ticket)['delivery'];
    reportCheck($first['pending'] && $first['receipts'][0]['state']==='published' && $first['receipts'][2]['state']==='pending','Queued delivery was presented as received.');
    mkdir($root.'/sipnotify',0750);mkdir($root.'/sipnotify/acknowledgements',0750);
    $identity=hash('sha256','id:cli_fixture');$path=$root.'/sipnotify/acknowledgements/'.$identity.'.json';
    $receipt=['event_id'=>'test_event_b','username'=>'desktop_one','client_id'=>'cli_fixture','receipt_type'=>'client_acknowledgement','ack_at'=>gmdate('c')];
    $ledger=['schema'=>1,'username'=>'desktop_one','client_id'=>'cli_fixture','acknowledgements'=>[hash('sha256','test_event_b')=>$receipt]];
    $write=static function()use($path,&$ledger){file_put_contents($path,json_encode($ledger,JSON_THROW_ON_ERROR));chmod($path,0640);};$write();
    $m->evidence=['available'=>true,'active'=>false,'uncertain'=>false,'targets'=>[
        ['recipient_id'=>'1000','answered'=>true,'joined'=>true,'ended'=>true,'uncertain'=>false,'dial_status'=>'ANSWER'],
        ['recipient_id'=>'1001','answered'=>false,'joined'=>false,'ended'=>true,'uncertain'=>false,'dial_status'=>'NOANSWER'],
        ['recipient_id'=>'2000','answered'=>true,'joined'=>true,'ended'=>true,'uncertain'=>false,'dial_status'=>'ANSWER']]];
    $before=hash_file('sha256',$path);$result=$m->getTestDeliveryStatus($ticket)['delivery'];
    reportCheck($result['receipts'][0]['state']==='published' && $result['receipts'][1]['state']==='received','Latest desktop receipt was attributed to another test.');
    reportCheck($result['receipts'][1]['detail']==='Received by desktop app','Desktop receipt label is incorrect.');
    reportCheck($result['receipts'][2]['state']==='answered' && $result['receipts'][2]['joined'] && !$result['receipts'][2]['playback_confirmed'],'Phone answer/conference evidence lost or full playback was invented.');
    reportCheck($result['receipts'][3]['state']==='failed' && count($result['receipts'])===4,'Failed/noanswer evidence or recipient isolation failed.');
    reportCheck(hash_file('sha256',$path)===$before,'Refreshing a test rewrote receipt storage.');
    $ledger['acknowledgements'][hash('sha256','test_event_a')]=array_replace($receipt,['event_id'=>'test_event_a']);$write();
    reportCheck(!$m->getTestDeliveryStatus($ticket)['delivery']['pending'],'Exact receipts did not finish the report.');
    $ledger['acknowledgements'][hash('sha256','test_event_a')]['username']='another_user';$write();
    reportCheck($m->getTestDeliveryStatus($ticket)['delivery']['receipts'][0]['state']==='published','A different username confirmed this receipt.');
    $markers=['SLS_TEST_DESKTOP_PUBLICATION '.json_encode(['event_id'=>'test_event_c','targets'=>['desktop_one','other']]),'SLS_TEST_DESKTOP_PUBLICATION {bad}'];
    reportCheck($m->markers($markers,['desktop_one'])===[['event_id'=>'test_event_c','target'=>'desktop_one','client_id'=>'cli_fixture']],'Worker publication markers leaked or lost exact event identities.');
    echo "$checks signed test reporting, receipt isolation, phone evidence and read-only refresh checks passed.\n";
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
