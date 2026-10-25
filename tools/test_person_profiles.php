<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/person-profiles.php';
require dirname(__DIR__).'/slsmassnotifyserver/DirectoryConfig.php';
require dirname(__DIR__).'/slsmassnotifyserver/DirectorySync.php';
require dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/security.php';
use SLS\MassNotify\{PersonProfiles,DirectoryConfig,ApiSecurity};
function checkProfile(bool $ok,string $why):void{if(!$ok){throw new RuntimeException($why);}}
$source=['id'=>'dir_'.str_repeat('a',24),'enabled'=>true,'records'=>[['external_id'=>'employee-a','name'=>'Person A','active'=>true,'phone_candidates'=>['+15551234567']]]];
$id=DirectoryConfig::personId($source['id'],'employee-a');$settings=['directory_sync'=>['sources'=>[$source]]];
$profile=['directory_person_id'=>$id,'language'=>'es','ownership_reviewed'=>true,'reviewed_at'=>1800000000];
$group=['id'=>'grp_'.str_repeat('b',24),'name'=>'Person A devices','person_profile'=>$profile,'extensions'=>['1000'],'sms_recipient_ids'=>['sms_'.str_repeat('c',24)]];
checkProfile(PersonProfiles::normalize($profile)===$profile,'Profile did not round-trip.');
checkProfile(PersonProfiles::active($group,$settings)&&ApiSecurity::groupDesktopBindingsValid($group,$settings),'Reviewed active identity was denied.');
checkProfile(PersonProfiles::people($settings)[$id]['phone_candidates']===['+15551234567'],'Candidate review context was lost.');
$affected=PersonProfiles::affected(['announcement_groups'=>[$group]],[$id]);
checkProfile($affected[0]['members']['extensions']===['1000']&&$affected[0]['members']['sms_recipient_ids']===$group['sms_recipient_ids'],'Deactivation preview omitted owned device identities.');
$settings['directory_sync']['sources'][0]['records'][0]['active']=false;
checkProfile(!PersonProfiles::active($group,$settings)&&!ApiSecurity::groupDesktopBindingsValid($group,$settings),'Deactivated directory person retained audience authorization.');
$group['person_profile']['directory_person_id']='';checkProfile(PersonProfiles::active($group,$settings),'Manual profiles require a directory.');
foreach([['language'=>'xx'],['ownership_reviewed'=>false],['directory_person_id'=>'../bad'],['reviewed_at'=>true],['sms_consent'=>true]] as $bad){
    try{PersonProfiles::normalize(array_replace($profile,$bad));throw new RuntimeException('Invalid profile accepted.');}catch(DomainException $expected){}
}
checkProfile(!isset(PersonProfiles::people(['directory_sync'=>['sources'=>[$source+['other'=>'ignored']]]])[$id]['sms_consent']),'Import granted consent.');
if(!interface_exists('BMO')){interface BMO{}}
require_once dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$class=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);$module=$class->newInstanceWithoutConstructor();$validate=$class->getMethod('validateAnnouncementGroupFields');
$group['person_profile']=$profile;
checkProfile($validate->invoke($module,[$group])===[],'Central configuration rejects the saved person-profile field.');
$group['person_profile']['ownership_reviewed']=false;
checkProfile($validate->invoke($module,[$group])!==[],'Central configuration accepts unreviewed profile ownership.');
echo "Person profiles: ownership, language, candidate-only imports, deactivation preview and revoked authorization passed.\n";
