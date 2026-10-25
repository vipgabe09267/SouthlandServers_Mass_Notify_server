<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/AudienceLimits.php';
use SLS\MassNotify\AudienceLimits;
function audienceLimitCheck(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
AudienceLimits::assertGrowth(array_fill(0,200,['name'=>'Bounded audience']));
try{AudienceLimits::assertGrowth(array_fill(0,201,[]));throw new RuntimeException('Unbounded audience count accepted.');}catch(DomainException $expected){}
$large=[['name'=>str_repeat('x',AudienceLimits::CONFIG_BYTES+1)]];
try{AudienceLimits::assertGrowth($large);throw new RuntimeException('Unbounded audience growth accepted.');}catch(DomainException $expected){}
AudienceLimits::assertGrowth($large,$large);
$reduced=[['name'=>substr($large[0]['name'],0,-1)]];AudienceLimits::assertGrowth($reduced,$large);
audienceLimitCheck(AudienceLimits::bytes([['name'=>'é']])===strlen('[{"name":"\u00e9"}]'),'Unicode byte budgeting does not match encrypted configuration encoding.');
$settings=['announcement_groups'=>[['name'=>'é']],'unrelated_settings'=>str_repeat('x',1024)];
$budget=AudienceLimits::budget($settings);
audienceLimitCheck($budget['used_bytes']===strlen(json_encode($settings,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))&&$budget['remaining_bytes']===$budget['maximum_bytes']-$budget['used_bytes'],'Whole-config budget omitted unrelated settings.');
audienceLimitCheck($budget['audience_bytes']===AudienceLimits::bytes($settings['announcement_groups'])&&$budget['within_limit'],'Audience budget differs from whole-config accounting.');
$settings['unrelated_settings']=str_repeat('x',AudienceLimits::CONFIG_BYTES);
audienceLimitCheck(!AudienceLimits::budget($settings)['within_limit'],'A whole-config shortage was hidden by a smaller audience.');
require dirname(__DIR__).'/slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging.php';
audienceLimitCheck(str_contains(file_get_contents(dirname(__DIR__).'/slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging.php'),"count(\$config['groups']) > 10"),'The separate live paging group ceiling changed.');
echo "Saved audiences: 200-entry ceiling, UTF-8 byte growth budget, safe legacy reductions and unchanged paging limit passed.\n";
