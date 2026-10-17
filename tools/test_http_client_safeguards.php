<?php
declare(strict_types=1);

namespace SLS\MassNotify {
    function curl_init($url) { $state=&$GLOBALS['sls_http_fixture'];$state['urls'][]=$url;return ($state['mode']??'')==='init_false'?false:1; }
    function curl_setopt_array($handle,$options) { $state=&$GLOBALS['sls_http_fixture'];$state['options'][]=$options;return ($state['mode']??'')!=='options_false'; }
    function curl_exec($handle) { $GLOBALS['sls_http_fixture']['executed']++;return true; }
    function curl_getinfo($handle,$option) { return 200; }
    function curl_errno($handle) { return 0; }
    function curl_close($handle) { $GLOBALS['sls_http_fixture']['closed']++; }
    function gethostbynamel($host) { return ['8.8.8.8']; }
}

namespace SLS\MassNotify\Sms {
    function curl_init($url) { $state=&$GLOBALS['sms_http_fixture'];$state['urls'][]=$url;return ($state['mode']??'')==='init_false'?false:1; }
    function curl_setopt_array($handle,$options) { $state=&$GLOBALS['sms_http_fixture'];$state['options'][]=$options;return ($state['mode']??'')!=='options_false'; }
    function curl_exec($handle) { $GLOBALS['sms_http_fixture']['executed']++;return true; }
    function curl_getinfo($handle,$option) { return 200; }
    function curl_errno($handle) { return 0; }
    function curl_close($handle) { $GLOBALS['sms_http_fixture']['closed']++; }
}

namespace {
    require dirname(__DIR__).'/slsmassnotifyserver/AutomationInputs.php';
    require dirname(__DIR__).'/slsmassnotifyserver/BrowserPush.php';
    require dirname(__DIR__).'/slsmassnotifyserver/EnterpriseDeliveryConnector.php';
    require dirname(__DIR__).'/slsmassnotifyserver/EnterpriseIntegrationsProvider.php';
    require dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/sms/Provider.php';
    require dirname(__DIR__).'/slsmassnotifyserver/identity-libs/vendor/autoload.php';

    use SLS\MassNotify\{AutomationInputs,BrowserPush,EnterpriseDeliveryConnector,EnterpriseIntegrationsProvider};
    use SLS\MassNotify\Sms\Provider as SmsProvider;

    function httpCheck($condition,string $message): void { if(!$condition){throw new RuntimeException($message);} }
    function httpReject(callable $call,string $fragment,string $message): void {
        try{$call();}catch(Throwable $error){httpCheck(str_contains($error->getMessage(),$fragment),$message.': '.$error->getMessage());return;}
        throw new RuntimeException($message.' was accepted.');
    }
    function resetHttpFixture(string $mode): void {$GLOBALS['sls_http_fixture']=['mode'=>$mode,'urls'=>[],'options'=>[],'executed'=>0,'closed'=>0];}
    function resetSmsHttpFixture(string $mode): void {$GLOBALS['sms_http_fixture']=['mode'=>$mode,'urls'=>[],'options'=>[],'executed'=>0,'closed'=>0];}

    final class ProviderHttpFixture extends EnterpriseIntegrationsProvider {
        public function probe(): array {return $this->request('https://provider.example.test/v1/action','{}',['Content-Type: application/json']);}
    }
    final class SmsHttpFixture extends SmsProvider {
        public function probe(): array {return $this->request('https://provider.example.test/v1/messages',['Content-Type: application/json'],'{}','user:secret');}
    }

    $connector=['id'=>'conn_'.str_repeat('a',24),'name'=>'Fixture','enabled'=>'1','provider'=>'ntfy','origin'=>'https://notify.example.test','pinned_ipv4'=>'192.168.1.20','token'=>'fixture-token','topic'=>'fixture'];
    $event=['title'=>'Fixture','message'=>'Fixture body','is_test'=>true];
    $clients=[
        'CAP feed'=>static fn()=>AutomationInputs::fetch('https://alerts.example.test/cap.xml'),
        'browser push'=>static fn()=>BrowserPush::transmit('https://push.example.test/send','fixture',['Content-Type: application/octet-stream']),
        'notification connector'=>static fn()=>EnterpriseDeliveryConnector::send($connector,$event),
        'integration provider'=>static fn()=>(new ProviderHttpFixture())->probe(),
    ];
    foreach($clients as $label=>$call){
        resetHttpFixture('init_false');httpReject($call,'initialized',$label.' initialization failure');
        httpCheck($GLOBALS['sls_http_fixture']['executed']===0&&$GLOBALS['sls_http_fixture']['closed']===0,$label.' initialization failure reached transport or closed a nonexistent handle');
        resetHttpFixture('options_false');httpReject($call,'safeguards',$label.' option failure');
        httpCheck($GLOBALS['sls_http_fixture']['executed']===0&&$GLOBALS['sls_http_fixture']['closed']===1,$label.' option failure reached transport or leaked its handle');
    }
    $options=$GLOBALS['sls_http_fixture']['options'][0];
    httpCheck($options[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS&&$options[CURLOPT_FOLLOWLOCATION]===false&&$options[CURLOPT_SSL_VERIFYPEER]===true&&$options[CURLOPT_SSL_VERIFYHOST]===2&&$options[CURLOPT_PROXY]==='','Integration provider safeguards changed');

    $sms=new SmsHttpFixture();
    resetSmsHttpFixture('init_false');httpReject(static fn()=>$sms->probe(),'initialized','SMS initialization failure');
    httpCheck($GLOBALS['sms_http_fixture']['executed']===0&&$GLOBALS['sms_http_fixture']['closed']===0,'SMS initialization failure reached transport or closed a nonexistent handle');
    resetSmsHttpFixture('options_false');httpReject(static fn()=>$sms->probe(),'safeguards','SMS option failure');
    httpCheck($GLOBALS['sms_http_fixture']['executed']===0&&$GLOBALS['sms_http_fixture']['closed']===1,'SMS option failure reached transport or leaked its handle');
    $smsOptions=$GLOBALS['sms_http_fixture']['options'][0];
    httpCheck($smsOptions[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS&&$smsOptions[CURLOPT_FOLLOWLOCATION]===false&&$smsOptions[CURLOPT_SSL_VERIFYPEER]===true&&$smsOptions[CURLOPT_SSL_VERIFYHOST]===2&&$smsOptions[CURLOPT_SSLVERSION]===CURL_SSLVERSION_TLSv1_2&&$smsOptions[CURLOPT_PROXY]===''&&$smsOptions[CURLOPT_NOPROXY]==='*','SMS transport safeguards changed');
    httpCheck($smsOptions[CURLOPT_POST]===true&&$smsOptions[CURLOPT_HTTPAUTH]===CURLAUTH_BASIC,'SMS request method or authentication was not installed atomically');

    $curlFactory=new \GuzzleHttp\Handler\CurlFactory(1);
    try{
        $curlFactory->create(new \GuzzleHttp\Psr7\Request('GET','https://example.test'),['curl'=>'invalid']);
        throw new RuntimeException('A non-array Guzzle curl option was accepted.');
    }catch(\GuzzleHttp\Exception\InvalidArgumentException $error){
        httpCheck(str_contains($error->getMessage(),'must be an array'),'Guzzle curl option validation returned an unclear error.');
    }

    echo "HTTP clients: initialization and mandatory cURL safeguard failures remain pre-transport and release every acquired handle.\n";
}
