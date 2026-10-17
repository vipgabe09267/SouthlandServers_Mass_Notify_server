<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/slsmassnotifyserver/EnterpriseIdentity.php';
require_once dirname(__DIR__).'/slsmassnotifyserver/ImmediateSettings.php';
use SLS\MassNotify\{EnterpriseIdentity,EnterpriseIdentityConfig,OperatorAuth,OperatorAccess};
function confirmed(bool $condition,string $message): void { if(!$condition){throw new RuntimeException($message);} }
$id='api_'.str_repeat('a',24);$now=time();
$settings=['desktop_auth_key'=>base64_encode(str_repeat('k',32)),'control_api'=>['base_url'=>'https://pbx.example.test/api/sls-mass-notify']];
$auth=['password_hash'=>OperatorAuth::dummyHash(),'version'=>str_repeat('b',64),'totp_secret_enc'=>OperatorAuth::seal(OperatorAuth::secret(),$id,$settings),'totp_last_counter'=>-1,'recovery_hashes'=>[],'force_password_change'=>false];
$account=['id'=>$id,'source'=>'portal','username'=>'audit-user','name'=>'Audit user','enabled'=>true,'role'=>'administrator','auth'=>$auth];$account['identity']=OperatorAuth::identity($account);
$settings['operator_access']=OperatorAccess::normalize(['portal_enabled'=>true,'accounts'=>[$account]]);
$provider=['id'=>'idp_'.str_repeat('c',24),'vendor'=>'okta','protocol'=>'oidc','name'=>'Synthetic provider','enabled'=>true,'issuer'=>'https://idp.example.test','callback_url'=>'https://pbx.example.test/mass-notify/sso.php','discovery_url'=>'https://idp.example.test/.well-known/openid-configuration','client_id'=>'old-client','client_secret'=>'old-synthetic-secret','endpoint_hosts'=>['idp.example.test']];
$settings['enterprise_identity']=EnterpriseIdentityConfig::normalize(['enabled'=>true,'providers'=>[$provider],'grants'=>[['enabled'=>true,'provider_id'=>$provider['id'],'subject'=>'stable-subject','operator_account_id'=>$id]]]);
$binding=EnterpriseIdentity::grant($settings,['provider_id'=>$provider['id'],'subject'=>'stable-subject','protocol'=>'oidc','authenticated_at'=>$now,'expires_at'=>$now+300])['binding'];
confirmed(EnterpriseIdentity::current($settings,$binding,$id,$now),'Unchanged session fixture invalid.');
$settings['enterprise_identity']['providers'][0]['client_id']='replacement-client';
$settings['enterprise_identity']['providers'][0]['client_secret']='replacement-synthetic-secret';
$settings['enterprise_identity']['providers'][0]['issuer']='https://replacement-idp.example.test';
confirmed($binding['provider_revision']!==EnterpriseIdentityConfig::revision($settings['enterprise_identity']['providers'][0]),'Provider fixture revision unchanged.');
confirmed(!EnterpriseIdentity::current($settings,$binding,$id,$now),'Replaced provider retained an old session.');
$forged=$binding;$forged['provider_revision']=str_repeat('f',64);$forged['grant_revision']=str_repeat('f',64);
confirmed(!EnterpriseIdentity::current($settings,$forged,$id,$now),'Mismatched provider/grant revision retained a session.');
$settings['enterprise_identity']['providers'][0]['enabled']=false;
confirmed(!EnterpriseIdentity::current($settings,$binding,$id,$now),'Explicit provider disable must still revoke session.');
foreach (['active-first','pending-first'] as $order) {
    $active=['enterprise_identity'=>['enabled'=>true],'immediate_config_revisions'=>[]];
    $pending=$active+['pending_other'=>'retained'];
    [$nextActive,$nextPending]=\SLS\MassNotify\ImmediateSettings::prepare($active,$pending,['enterprise_identity']);
    $nextActive['enterprise_identity']['enabled']=false;$nextPending['enterprise_identity']['enabled']=false;
    $committedActive=$order==='active-first' ? $nextActive : $active;
    $committedPending=$order==='pending-first' ? $nextPending : $pending;
    $reviewed=\SLS\MassNotify\ImmediateSettings::reconcile($committedActive,$committedPending);
    confirmed($reviewed['enterprise_identity']===$committedActive['enterprise_identity'],'A partial immediate save reverted the active decision: '.$order);
    confirmed($reviewed['pending_other']==='retained','Unrelated staged settings were lost: '.$order);
    $fresh=$reviewed;$fresh['enterprise_identity']['enabled']=!$committedActive['enterprise_identity']['enabled'];
    confirmed(\SLS\MassNotify\ImmediateSettings::reconcile($committedActive,$fresh)['enterprise_identity']===$fresh['enterprise_identity'],'A fresh reviewed edit was overwritten: '.$order);
}
echo "SSO sessions: unchanged provider, provider replacement, mismatched revisions and explicit revocation passed.\n";
