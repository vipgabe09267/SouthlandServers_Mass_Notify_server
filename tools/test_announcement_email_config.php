<?php
declare(strict_types=1);
require dirname(__DIR__) . '/slsmassnotifyserver/AnnouncementEmail.php';
// Load the actual pure sender normalizers without PBX bootstrap or settings I/O.
$source = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php');
$methods = '';
foreach (['normalizeEmailSenderDomain', 'normalizeEmailSenderLocalPart'] as $name) {
    if (!preg_match('/\tprivate function ' . $name . '\([^\n]*\)\n\t\{.*?\n\t\}/s', $source, $match)) throw new RuntimeException('Missing sender normalizer');
    $methods .= $match[0] . "\n";
}
eval('class AnnouncementEmailFixture { use \\FreePBX\\modules\\SlsAnnouncementEmail; public $settings=[]; private function getActiveSettings(){return $this->settings;} public function norm($v,$create=false){return $this->normalizeAnnouncementEmail($v,$create);} public function valid($v){return $this->validateAnnouncementEmail($v);} public function form($v,$current=[]){return $this->readAnnouncementEmailForm($v,$current);} public function targets($v){return $this->announcementEmailTargets($v);} public function fingerprint($v){return $this->announcementEmailFingerprint($v);} public function ids($v){return $this->validateAnnouncementEmailRecipientIds($v);} ' . $methods . '}');
$f = new AnnouncementEmailFixture(); $checks=0;
$check = static function ($ok, $message) use (&$checks) { $checks++; if (!$ok) throw new RuntimeException($message); };
$reject = static function ($fn, $message) use ($check) { try { $fn(); } catch (DomainException $e) { $check(true,$message); return; } $check(false,$message); };
$id = 'email_' . str_repeat('a',24);
$row = ['id'=>$id, 'name'=>'Front office', 'address'=>'office@example.com', 'enabled'=>'1'];
$config = ['enabled'=>'1','recipients'=>[$row]];
$check($f->norm([]) === ['enabled'=>'0','recipients'=>[]], 'Unsafe defaults');
$check($f->norm($config) === $config, 'Normalization changed stable IDs or address');
foreach ([null, false, 'bad', 1, [['enabled'=>'1']], ['unknown'=>true], ['enabled'=>null], ['enabled'=>'yes'], ['recipients'=>null], ['recipients'=>['not'=>'list']]] as $bad) $reject(fn()=>$f->norm($bad),'Invalid config accepted');
foreach ([['id'=>null], ['id'=>12], ['id'=>''], ['id'=>'email_bad'], ['name'=>"injected\r\n"], ['name'=>[]], ['name'=>str_repeat('é',121)], ['name'=>"\xff"], ['address'=>"office@example.com\r\nBcc:bad@example.com"], ['address'=>' office@example.com'], ['address'=>'user@-bad.example.com'], ['address'=>'a..b@example.com'], ['address'=>'.a@example.com'], ['address'=>'a.@example.com'], ['address'=>'Name <a@example.com>'], ['address'=>['a@example.com']], ['enabled'=>null], ['enabled'=>'yes'], ['surprise'=>'x']] as $patch) $reject(fn()=>$f->norm(['recipients'=>[array_replace($row,$patch)]]),'Invalid recipient accepted');
$reject(fn()=>$f->norm(['recipients'=>[$row,$row]]),'Duplicate IDs/addresses accepted');
$reject(fn()=>$f->norm(['recipients'=>[$row,array_replace($row,['id'=>'email_'.str_repeat('b',24),'address'=>'OFFICE@EXAMPLE.COM'])]]),'Case duplicate accepted');
$reject(fn()=>$f->norm(['recipients'=>array_fill(0,51,$row)]),'Capacity accepted');
$new = array_replace($row,['id'=>'']);
$created = $f->norm(['enabled'=>true,'recipients'=>[$new]],true);
$check((bool)preg_match('/^email_[a-f0-9]{24}$/D',$created['recipients'][0]['id']), 'Explicit UI new ID missing');
$check($f->norm($created)===$created, 'Existing recipient ID regenerated');
$check($f->form([], $config)===$config, 'Absent editor destroyed config');
$form=['announcement_email_present'=>'1','announcement_email_complete'=>'1','announcement_email_recipients_json'=>json_encode([$row]),'announcement_email_enabled'=>'1'];
$check($f->form($form)===$config,'Form did not preserve recipient');
foreach ([['announcement_email_complete'=>'0'],['announcement_email_complete'=>1],['announcement_email_recipients_json'=>'{'],['announcement_email_recipients_json'=>str_repeat(' ',65537)],['announcement_email_enabled'=>'yes']] as $patch) $reject(fn()=>$f->form(array_replace($form,$patch)),'Malformed form accepted');
$f->settings=['announcement_email'=>$config];
$check($f->getAnnouncementEmailRecipients()===[$row],'Active recipients unavailable');
$check($f->getAnnouncementEmailRecipients(['announcement_email'=>array_replace($config,['enabled'=>'0'])])===[],'Disabled channel exposed recipients');
$check($f->getAnnouncementEmailRecipients(['announcement_email'=>['enabled'=>'1','recipients'=>[array_replace($row,['enabled'=>'0'])]]])===[],'Disabled recipient exposed');
$settings=['announcement_email'=>$config,'mail_from_name'=>'SLS Team','mail_from_local_part'=>'notify','mail_from_domain'=>'example.com'];
$target=$f->targets($settings)[$id];
$check($target===['id'=>$id,'address'=>'office@example.com','sender'=>['name'=>'SLS Team','address'=>'notify@example.com']],'Target snapshot is not exact');
$hash=$f->fingerprint($target);
foreach ([array_replace($target,['id'=>'email_'.str_repeat('b',24)]),array_replace($target,['address'=>'changed@example.com']),array_replace($target,['sender'=>['name'=>'Other','address'=>'notify@example.com']]),array_replace($target,['sender'=>['name'=>'SLS Team','address'=>'other@example.com']])] as $changed) $check($f->fingerprint($changed)!==$hash,'Identity change did not revoke fingerprint');
$check($f->targets(['announcement_email'=>['enabled'=>'0']])===[],'Disabled channel requires sender');
$reject(fn()=>$f->targets(['announcement_email'=>$config]),'Invented missing sender');
foreach (['mail_from_name'=>"bad\nname",'mail_from_domain'=>'','mail_from_local_part'=>'..'] as $key=>$value) $reject(fn()=>$f->targets(array_replace($settings,[$key=>$value])),'Invalid sender accepted');
$check($f->ids([$id])===[],'Saved ID selector rejected');
foreach ([[$id,$id],['office@example.com'],[123],null] as $bad) $check((bool)$f->ids($bad),'Invalid ID selector accepted');
echo "Announcement email configuration: $checks assertions passed; no config or mail I/O.\n";
