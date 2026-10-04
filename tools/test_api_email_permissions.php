<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/security.php';
use SLS\MassNotify\ApiSecurity as Security;
$count = 0;
function checkEmailPermission($condition, string $message): void {
    global $count; ++$count;
    if (!$condition) { throw new RuntimeException($message); }
}
set_error_handler(static function ($level, $message, $file, $line) { throw new ErrorException($message, 0, $level, $file, $line); });
$a = 'email_'.str_repeat('a',24); $b = 'email_'.str_repeat('b',24); $c = 'email_'.str_repeat('c',24);
$settings = ['announcement_email' => ['enabled'=>'1', 'recipients'=>[
    ['id'=>$a,'name'=>'Allowed <script>alert(1)</script>','address'=>'allowed@example.com','enabled'=>'1'],
    ['id'=>$b,'name'=>'Disabled recipient','address'=>'disabled@example.com','enabled'=>'0'],
    ['id'=>$c,'name'=>'Group recipient','address'=>'group@example.com','enabled'=>'1'],
    ['id'=>'email_bad','name'=>'Invalid identity','address'=>'valid@example.com','enabled'=>'1'],
    ['id'=>'email_'.str_repeat('d',24),'name'=>'Invalid address','address'=>"bad\r\nBcc: victim@example.com",'enabled'=>'1'],
]], 'announcement_groups'=>[['id'=>'approved','email_recipient_ids'=>[$c,'outside@example.com','email_missing']],
                            ['id'=>'other','email_recipient_ids'=>[$a]]]];
$settingsBytes = json_encode($settings);
$issued = Security::issue(['name'=>'Old sender','scopes'=>['send'],'audience'=>['unrestricted'=>false,'extensions'=>['1000']]]);
// Simulate a credential actually persisted before this field existed.
$old = $issued['credential']; unset($old['audience']['email_recipient_ids']);
$oldBytes = json_encode($old);
$p = Security::authenticate(['credentials'=>[$old]],$issued['secret']);
checkEmailPermission($p !== null && $p['audience']['email_recipient_ids'] === [], 'Old credential gained implicit email access.');
checkEmailPermission(json_encode($old) === $oldBytes, 'Reading credential mutated its existing configuration.');
checkEmailPermission(Security::permitsResolvedAnnouncement($p,['phones'=>['1000']],$settings),'Old phone permission changed.');
checkEmailPermission(!Security::permitsResolvedAnnouncement($p,['email_recipient_ids'=>[$a]],$settings),'Old credential can email without grant.');
checkEmailPermission(!isset($p['secret_hash']),'Authentication projected a credential hash.');
foreach ([[$a],[],[$a,$a]] as $ids) {
    $audience = Security::audience(['unrestricted'=>false,'email_recipient_ids'=>$ids]);
    checkEmailPermission($audience['email_recipient_ids'] === array_values(array_unique($ids)), 'Valid ID normalization failed.');
}
foreach ([['email_short'],['outside@example.com'],[strtoupper($a)],[123],['nested'=>[$a]],[$a."\n"],null,'all'] as $ids) {
    $rejected=false;
    try { Security::audience(['unrestricted'=>false,'email_recipient_ids'=>$ids]); }
    catch (DomainException $error) { $rejected=true; }
    checkEmailPermission($rejected,'Malformed email permission was accepted.');
}
$p['audience']['email_recipient_ids']=[$a,$b];
$p['audience']['announcement_group_ids']=['approved'];
$allowed = Security::allowedAudience($p,$settings);
checkEmailPermission($allowed['email_recipient_ids'] === [$a,$c],'Explicit/group permissions did not filter disabled or arbitrary recipients.');
checkEmailPermission(Security::permitsResolvedAnnouncement($p,['email_recipient_ids'=>[$a,$c]],$settings),'Authorized email IDs refused.');
foreach ([[$b],['outside@example.com'],['email_missing'],[$a,$b],[[$a]],['key'=>$a],null,'all'] as $ids) {
    // Explicit null must not be mistaken for an absent list.
    checkEmailPermission(!Security::permitsResolvedAnnouncement($p,['email_recipient_ids'=>$ids],$settings),'Malformed/unauthorized resolved email audience allowed.');
}
$legacy = Security::authenticate(['api_key'=>'legacy-secret'],'legacy-secret');
$unrestricted = Security::issue(['name'=>'Full sender','scopes'=>['send'],'audience'=>['unrestricted'=>true]])['credential'];
foreach ([$legacy,$unrestricted] as $principal) {
    checkEmailPermission(Security::permitsResolvedAnnouncement($principal,['email_recipient_ids'=>[$a,$c]],$settings),'Unrestricted saved-ID behavior changed.');
    foreach (['emails','email_recipients'] as $key) {
        checkEmailPermission(!Security::permitsResolvedAnnouncement($principal,[$key=>['outside@example.com']],$settings),'Raw email addresses bypassed ID-only authority.');
    }
    checkEmailPermission(!Security::permitsResolvedAnnouncement($principal,['email_recipient_ids'=>['outside@example.com']],$settings),'Unrestricted accepted malformed email IDs.');
}
$settings['announcement_groups'][0]['email_recipient_ids']=[];
checkEmailPermission(!Security::permitsResolvedAnnouncement($p,['email_recipient_ids'=>[$c]],$settings),'Group removal retained email permission.');
$settings['announcement_email']['recipients'][0]['enabled']='0';
checkEmailPermission(!Security::permitsResolvedAnnouncement($p,['email_recipient_ids'=>[$a]],$settings),'Disabled saved recipient stayed authorized.');
$settings = json_decode($settingsBytes,true);
$duplicate = $settings; $duplicate['announcement_email']['recipients'][]=$settings['announcement_email']['recipients'][0];
checkEmailPermission(!in_array($a,Security::enabledEmailRecipientIds($duplicate),true),'Ambiguous duplicate ID stayed authorized.');
$revoked = Security::revoke([$issued['credential']],$issued['credential']['id']);
checkEmailPermission(Security::authenticate(['credentials'=>$revoked],$issued['secret']) === null,'Revocation behavior changed.');
$api_credentials=[$issued['credential']]; $csrf_token='fixture-only';
ob_start(); include dirname(__DIR__).'/slsmassnotifyserver/views/api_credentials.php'; $html=ob_get_clean();
checkEmailPermission(strpos($html,'data-credential-audience="email_recipient_ids"') !== false,'Email permission picker is missing.');
checkEmailPermission(strpos($html,'value="'.$a.'"') !== false && strpos($html,'value="'.$c.'"') !== false,'Valid email choices missing.');
checkEmailPermission(strpos($html,'value="'.$b.'"') === false && strpos($html,'Invalid address') === false,'Disabled/invalid choice rendered.');
checkEmailPermission(strpos($html,'<script>alert(1)</script>') === false && strpos($html,'&lt;script&gt;alert(1)&lt;/script&gt;') !== false,'Email label was not escaped.');
foreach (['allowed@example.com','disabled@example.com','group@example.com',$issued['credential']['secret_hash'],$issued['secret']] as $private) {
    checkEmailPermission(strpos($html,$private) === false,'Email permission editor leaked address or credential material.');
}
checkEmailPermission(json_encode($settings) === $settingsBytes,'Permission/UI projection mutated settings.');
echo "Email permission and editor: $count checks passed.\n";
