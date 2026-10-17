<?php
declare(strict_types=1);
require __DIR__.'/../slsmassnotifyserver/DirectorySync.php';
use SLS\MassNotify\{DirectoryConfig,DirectorySync};
function check(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);}
function rejects(callable $work):void
{
    try{$work();}
    catch(DomainException $error){if($error->getMessage()!==''){return;}throw new RuntimeException('Directory rejection had no diagnostic.',0,$error);}
    catch(Throwable $error){throw new RuntimeException('Directory safeguard raised an unexpected exception.',0,$error);}
    throw new RuntimeException('Expected directory safeguard rejection');
}
$ldap=['ldap_attributes'=>['active'=>'userAccountControl'],'ldap_inactive_mask'=>2,'ldap_inactive_values'=>['false','0']];
check(DirectorySync::ldapActive($ldap,'512')&&!DirectorySync::ldapActive($ldap,'514'),'AD account-disabled flag mapping incorrect');
foreach(['','false','9999999999','-1'] as $invalid)rejects(fn()=>DirectorySync::ldapActive($ldap,$invalid));
$ldap['ldap_inactive_mask']=0;check(!DirectorySync::ldapActive($ldap,'false')&&DirectorySync::ldapActive($ldap,'true'),'LDAP string activity mapping incorrect');
$ldapSource=['id'=>'dir_'.str_repeat('b',24),'name'=>'LDAP','type'=>'ldap','ldap_url'=>'ldaps://directory.example.test','ldap_bind_dn'=>'cn=fixture','ldap_password'=>'fixture','ldap_base_dn'=>'dc=example,dc=test','ldap_attributes'=>['external_id'=>'entryUUID','name'=>'displayName','email'=>'mail','location'=>'l','desktop_username'=>'uid','active'=>'accountStatus'],'ldap_inactive_values'=>[' FALSE ','Disabled']];
$normalizedLdap=DirectoryConfig::normalize(['sources'=>[$ldapSource]])['sources'][0];
check($normalizedLdap['ldap_inactive_values']===['false','disabled']&&!DirectorySync::ldapActive($normalizedLdap,' false '),'LDAP inactive values were not normalized consistently');
$redacted=DirectoryConfig::publicConfig(['sources'=>[$ldapSource]]);
check($redacted['sources'][0]['ldap_password']===''&&$redacted['sources'][0]['ldap_password_configured']===true,
    'Directory public configuration exposed a saved secret or lost its configured state.');
rejects(fn()=>DirectoryConfig::publicConfig(['sources'=>['not-a-source']]));
rejects(fn()=>DirectoryConfig::publicConfig(['sources'=>[],'unexpected_secret'=>'must-not-leak']));
$startTlsSource=$ldapSource;$startTlsSource['ldap_url']='ldap://directory.internal:389';$startTlsSource['ldap_starttls']=true;
check(DirectoryConfig::normalize(['sources'=>[$startTlsSource]])['sources'][0]['ldap_starttls']===true,'LDAP StartTLS source was rejected.');
foreach([
    ['ldap_url'=>'ldaps://directory.example.test','ldap_starttls'=>true],
    ['ldap_url'=>'ldap://directory.internal','ldap_starttls'=>false],
    ['ldap_url'=>'ldap://directory.internal:0','ldap_starttls'=>true],
    ['ldap_url'=>'ldaps://bad..example.test'],
    ['ldap_url'=>'ldaps://-bad.example.test'],
] as $changes){rejects(fn()=>DirectoryConfig::normalize(['sources'=>[array_replace($ldapSource,$changes)]]));}
rejects(fn()=>DirectoryConfig::normalize(['sources'=>[['id'=>'dir_'.str_repeat('c',24),'name'=>'LDAP','type'=>'ldap','ldap_url'=>'ldaps://directory.example.test','ldap_bind_dn'=>'cn=fixture','ldap_password'=>'fixture','ldap_base_dn'=>'dc=example,dc=test','ldap_inactive_values'=>['FALSE',' false ']]]]));
rejects(fn()=>DirectoryConfig::normalize(['sources'=>[['id'=>'dir_'.str_repeat('d',24),'name'=>'CSV','type'=>'csv','ldap_password'=>'must-not-disappear']]]));
rejects(fn()=>DirectoryConfig::normalize(['sources'=>[['id'=>'dir_'.str_repeat('e',24),'name'=>'SCIM','type'=>'scim','scim_base_url'=>'https://scim.example.test/v2','scim_token'=>'fixture','ldap_url'=>'ldaps://wrong.example.test']]]));
$source=DirectoryConfig::normalize(['sources'=>[['id'=>'dir_'.str_repeat('a',24),'name'=>'SCIM','type'=>'scim','scim_base_url'=>'https://scim.example.test/v2','scim_token'=>'fixture','scim_attributes'=>['external_id'=>'/externalId','name'=>'/name/formatted','location'=>'/urn:ietf:params:scim:schemas:extension:enterprise:2.0:User/department']]]])['sources'][0];
$resource=['id'=>'internal-id','externalId'=>'immutable-id','name'=>['formatted'=>'Fixture Person'],'active'=>true,'emails'=>[['value'=>'person@example.test','primary'=>true]],'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User'=>['department'=>'Operations']];
$reply=static fn($user)=>['schemas'=>['urn:ietf:params:scim:api:messages:2.0:ListResponse'],'startIndex'=>1,'totalResults'=>1,'Resources'=>[$user]];
$records=DirectorySync::scim($source,fn()=>$reply($resource));check($records[0]['external_id']==='immutable-id'&&$records[0]['name']==='Fixture Person'&&$records[0]['location']==='Operations','Curated SCIM mapping failed');
foreach([['schemas'=>'not-a-list'],['schemas'=>array_fill(0,17,'urn:ietf:params:scim:api:messages:2.0:ListResponse')]] as $change)rejects(fn()=>DirectorySync::scim($source,fn()=>array_replace($reply($resource),$change)));
foreach([['active'=>'true'],['emails'=>'malformed'],['emails'=>[['value'=>false]]],['emails'=>[['value'=>'a@example.test','primary'=>true],['value'=>'b@example.test','primary'=>true]]],['name'=>['givenName'=>[]]]] as $change)rejects(fn()=>DirectorySync::scim($source,fn()=>$reply(array_replace($resource,$change))));
$invalid=$source;$invalid['scim_attributes']=['external_id'=>'/password'];rejects(fn()=>DirectoryConfig::normalize(['sources'=>[$invalid]]));$invalid['scim_attributes']='string';rejects(fn()=>DirectoryConfig::normalize(['sources'=>[$invalid]]));
$old=['external_id'=>'immutable-id','name'=>'Fixture Person','email'=>'person@example.test','location'=>'Operations','location_id'=>'','desktop_username'=>'','active'=>true];$source['records']=[$old];$source['template_ids']=[];$settings=['directory_sync'=>['sources'=>[$source]],'incident_workflows'=>['schema'=>1,'templates'=>[]]];$plan=DirectorySync::plan($settings,$source,[]);check($plan['deactivation_count']===1&&$plan['deactivation_percent']===100&&$plan['requires_deactivation_confirmation'],'Large directory loss omitted confirmation');
$unknown=$old;$unknown['location_id']='loc_'.str_repeat('f',24);rejects(fn()=>DirectorySync::plan($settings,$source,[$unknown]));
echo "Directory mapping: AD bitmask, malformed activity/email rejection, curated SCIM pointers, ambiguity rejection and mass-deactivation safeguard passed.\n";
