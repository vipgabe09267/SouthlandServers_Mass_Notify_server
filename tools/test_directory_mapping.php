<?php
declare(strict_types=1);
require __DIR__.'/../slsmassnotifyserver/DirectorySync.php';
use SLS\MassNotify\{DirectoryConfig,DirectorySync};
function check(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);}
function rejects(callable $work):void{try{$work();}catch(Throwable $error){return;}throw new RuntimeException('Expected directory safeguard rejection');}
$ldap=['ldap_attributes'=>['active'=>'userAccountControl'],'ldap_inactive_mask'=>2,'ldap_inactive_values'=>['false','0']];
check(DirectorySync::ldapActive($ldap,'512')&&!DirectorySync::ldapActive($ldap,'514'),'AD account-disabled flag mapping incorrect');
foreach(['','false','9999999999','-1'] as $invalid)rejects(fn()=>DirectorySync::ldapActive($ldap,$invalid));
$ldap['ldap_inactive_mask']=0;check(!DirectorySync::ldapActive($ldap,'false')&&DirectorySync::ldapActive($ldap,'true'),'LDAP string activity mapping incorrect');
$source=DirectoryConfig::normalize(['sources'=>[['id'=>'dir_'.str_repeat('a',24),'name'=>'SCIM','type'=>'scim','scim_base_url'=>'https://scim.example.test/v2','scim_token'=>'fixture','scim_attributes'=>['external_id'=>'/externalId','name'=>'/name/formatted','location'=>'/urn:ietf:params:scim:schemas:extension:enterprise:2.0:User/department']]]])['sources'][0];
$resource=['id'=>'internal-id','externalId'=>'immutable-id','name'=>['formatted'=>'Fixture Person'],'active'=>true,'emails'=>[['value'=>'person@example.test','primary'=>true]],'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User'=>['department'=>'Operations']];
$reply=static fn($user)=>['schemas'=>['urn:ietf:params:scim:api:messages:2.0:ListResponse'],'startIndex'=>1,'totalResults'=>1,'Resources'=>[$user]];
$records=DirectorySync::scim($source,fn()=>$reply($resource));check($records[0]['external_id']==='immutable-id'&&$records[0]['name']==='Fixture Person'&&$records[0]['location']==='Operations','Curated SCIM mapping failed');
foreach([['active'=>'true'],['emails'=>'malformed'],['emails'=>[['value'=>false]]],['emails'=>[['value'=>'a@example.test','primary'=>true],['value'=>'b@example.test','primary'=>true]]],['name'=>['givenName'=>[]]]] as $change)rejects(fn()=>DirectorySync::scim($source,fn()=>$reply(array_replace($resource,$change))));
$invalid=$source;$invalid['scim_attributes']=['external_id'=>'/password'];rejects(fn()=>DirectoryConfig::normalize(['sources'=>[$invalid]]));$invalid['scim_attributes']='string';rejects(fn()=>DirectoryConfig::normalize(['sources'=>[$invalid]]));
$old=['external_id'=>'immutable-id','name'=>'Fixture Person','email'=>'person@example.test','location'=>'Operations','location_id'=>'','desktop_username'=>'','active'=>true];$source['records']=[$old];$source['template_ids']=[];$settings=['directory_sync'=>['sources'=>[$source]],'incident_workflows'=>['schema'=>1,'templates'=>[]]];$plan=DirectorySync::plan($settings,$source,[]);check($plan['deactivation_count']===1&&$plan['deactivation_percent']===100&&$plan['requires_deactivation_confirmation'],'Large directory loss omitted confirmation');
echo "Directory mapping: AD bitmask, malformed activity/email rejection, curated SCIM pointers, ambiguity rejection and mass-deactivation safeguard passed.\n";
