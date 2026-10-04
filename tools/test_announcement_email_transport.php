<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/AnnouncementDelivery.php';
class EmailTransportFixture {
    use \FreePBX\modules\SlsAnnouncementDelivery;
    public $helper, $mode;
    protected function startAnnouncementEmailProcess(&$pipes) {
        $pipes=[];
        if($this->mode==='not_started')return false;
        return proc_open(['/usr/bin/timeout','--kill-after=0.1', $this->mode==='timeout'?'0.15':'2', '/usr/bin/php',$this->helper,$this->mode],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,null,[],['bypass_shell'=>true]);
    }
    public function send($envelope) { return $this->submitAnnouncementEmail($envelope); }
}
$root=sys_get_temp_dir().'/sls-email-cli-'.bin2hex(random_bytes(8));mkdir($root,0700);
$helper=$root.'/fake.php';file_put_contents($helper, <<<'STUB'
<?php
$e=json_decode(stream_get_contents(STDIN),true);$mode=$argv[1];
if($mode==='timeout'){sleep(20);exit(0);}
if($mode==='invalid'){echo json_encode(['ok'=>false,'state'=>'rejected','error_code'=>'invalid_envelope','retryable'=>false]);exit(2);}
if($mode==='badjson'){echo 'bad';exit(0);}
if($mode==='oversized'){echo str_repeat('x',10000);sleep(20);exit(0);}
if($mode==='nonzero'){exit(1);}
$state=$mode==='temporary'?'rejected':($mode==='uncertain'?'uncertain':'accepted');
echo json_encode(['ok'=>$state==='accepted','state'=>$state,'retryable'=>$mode==='temporary',
 'recipient_id'=>$e['recipient_id'],'message_id'=>$mode==='wrong_id'?'wrong':$e['message_id'],
 'error_code'=>$mode==='temporary'?'sendmail_temporary_failure':'']);
STUB
);
try {
    $fixture=new EmailTransportFixture();$fixture->helper=$helper;
    $envelope=['recipient_id'=>'email_'.str_repeat('a',24),'address'=>'fixture@example.com','sender'=>['name'=>'Fixture','address'=>'notify@example.com'],
        'title'=>'Test','message'=>str_repeat('Body line\n',6000),'is_test'=>true,'severity'=>'info','message_id'=>'<sls-'.str_repeat('b',32).'-'.str_repeat('a',24).'@example.com>','created_at'=>gmdate('c')];
    foreach(['accepted'=>'accepted','temporary'=>'failed','uncertain'=>'uncertain','wrong_id'=>'uncertain','badjson'=>'uncertain','nonzero'=>'uncertain','timeout'=>'uncertain','oversized'=>'uncertain','not_started'=>'failed','invalid'=>'failed']as $mode=>$expected){
        $fixture->mode=$mode;$start=microtime(true);$result=$fixture->send($envelope);
        if($result['state']!==$expected || $result['retryable']!==in_array($mode,['temporary','not_started'],true))throw new RuntimeException('Sender protocol mismatch: '.$mode.' '.json_encode($result));
        if(microtime(true)-$start>3)throw new RuntimeException('Private sender deadline/cleanup exceeded fixture bound');
    }
    echo "Actual private CLI stdin/protocol/timeout/oversized-output handling passed; no real mailer invoked.\n";
}finally{unlink($helper);rmdir($root);}
