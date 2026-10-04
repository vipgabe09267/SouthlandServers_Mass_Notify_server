<?php
/** Setup parsing and actual view rendering without a PBX bootstrap or transport. */
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
if (!function_exists('load_view')) { function load_view($path, $data) { return ''; } }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$class=new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class);
$module=$class->newInstanceWithoutConstructor();
$method=$class->getMethod('readSetupConnectionPreferences');$method->setAccessible(true);
$count=0;
$check=static function($value,$message)use(&$count){$count++;if(!$value){throw new RuntimeException($message);}};
$saved=['public_pbx_host'=>'pbx.example.test','desktop_client_limit'=>25,'phone_device_limit'=>25,
    'sipnotify'=>['pbx_host'=>'pbx.example.test','base_url'=>'https://pbx.example.test:9443/api/sipnotify','media_scheme'=>'https','media_base_url'=>'https://pbx.example.test:9443/sls_mass_notify'],
    'control_api'=>['base_url'=>'https://pbx.example.test:9443/api/sls-mass-notify','api_key'=>'fixture-secret'],
    'outbound_voice'=>['enabled'=>'0','recipients'=>[]],'live_paging'=>['enabled'=>'0','groups'=>[]]];
$check($method->invoke($module,$saved,[])===$saved,'Older setup form reset saved configuration');
$timezone=$method->invoke($module,$saved,['pbx_timezone'=>'America/Chicago']);
$check($timezone['pbx_timezone']==='America/Chicago' && $timezone['sipnotify']===$saved['sipnotify'],'Timezone selection changed unrelated connection settings');
foreach (['','../etc/passwd','America/Chicago;bad',null,[]] as $zone) {
    $failed=false;try{$method->invoke($module,$saved,['pbx_timezone'=>$zone]);}catch(DomainException $error){$failed=true;}
    $check($failed,'Invalid setup timezone accepted');
}
$check($method->invoke($module,$saved,['advertised_api_port'=>'443'])===$saved,'Unchecked address option reset a forwarded port');
$input=['sls_address_change'=>'1','advertised_pbx_host'=>'new.example.test','advertised_api_port'=>'7443','advertised_control_port'=>'443',
    'advertised_media_port'=>'9443','sipnotify_media_scheme'=>'https','desktop_client_limit'=>'50','phone_device_limit'=>'25'];
$expected=FreePBX\modules\SlsAdvertisedAddress::migrate($saved,$input);$expected['desktop_client_limit']=50;
$check($method->invoke($module,$saved,$input)===$expected,'Setup changed unrelated settings or lost forwarded ports');
foreach (['desktop_client_limit','phone_device_limit'] as $key) {
    foreach (['0','1001','25.0','025','-1',true,[],null] as $value) {
        $failed=false;try{$method->invoke($module,$saved,[$key=>$value]);}catch(DomainException $error){$failed=true;}
        $check($failed,'Malformed setup capacity accepted');
    }
}
$settings=$saved;$csrf_token='fixture';$available_voices=[];
ob_start();include dirname(__DIR__).'/slsmassnotifyserver/views/setup.php';$html=ob_get_clean();
$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML($html);libxml_clear_errors();
$xpath=new DOMXPath($dom);
$check($xpath->query('//*[@id="sls-setup-address-change" and not(@checked)]')->length===1,'Address override enabled by default');
$check($xpath->query('//*[@id="sls-setup-ports" and @disabled and @hidden]')->length===1,'Custom ports active before opt-in');
$check($xpath->query('//input[@name="advertised_api_port" and @value="9443"]')->length===1,'Wizard did not retain the saved external port');
$check($xpath->query('//input[@name="sls_setup_form_present"]')->length===1 && $xpath->query('//input[@name="sls_setup_form_complete"]')->length===1,'Setup truncation sentinels missing');
$scripts=[];foreach($xpath->query('//script')as $script){$scripts[]=$script->textContent;}
$pipes=[];$process=proc_open(['node','--check'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
fwrite($pipes[0],implode("\n",$scripts));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
$check(proc_close($process)===0,'Rendered wizard JavaScript is invalid: '.$error);
echo "Setup preferences: $count parsing, preservation and rendered view checks passed.\n";
