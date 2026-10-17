<?php
declare(strict_types=1);
namespace SLS\MassNotify {
    // Exercise the real response parser and TLS options without a network call.
    function curl_init(string $url){return \curl_init($url);}
    function curl_setopt_array($handle,array $options): bool{$GLOBALS['fixture_https_options']=$options;return true;}
    function curl_exec($handle): bool{
        $body=$GLOBALS['fixture_provider_body'];
        return ($GLOBALS['fixture_https_options'][CURLOPT_WRITEFUNCTION])($handle,$body)===strlen($body);
    }
    function curl_getinfo($handle,int $option){
        if($option===CURLINFO_RESPONSE_CODE){return 200;}
        if($option===CURLINFO_PRIMARY_IP){return '192.168.1.10';}
        throw new \RuntimeException('Unexpected cURL information request.');
    }
    function curl_close($handle): void{\curl_close($handle);}
}
namespace {
    require_once __DIR__.'/../slsmassnotifyserver/EnterpriseIdentityHttp.php';
    $checks=0;
    $GLOBALS['fixture_provider_body']='{"issuer":"https://identity.example.test","keys":[]}';
    $policy=['hosts'=>['identity.example.test'],'private_pins'=>[['host'=>'identity.example.test','ip'=>'192.168.1.10']]];
    $value=\SLS\MassNotify\EnterpriseIdentityHttp::request('https://identity.example.test/metadata',$policy);
    if($value!==['issuer'=>'https://identity.example.test','keys'=>[]]){throw new RuntimeException('Normal provider JSON was changed.');}$checks++;
    foreach(['{"issuer":"first","issuer":"second"}','{"keys":[{"kid":"first","kid":"second"}]}','{"expires":1e9999}','[]'] as $body){
        $GLOBALS['fixture_provider_body']=$body;
        try{\SLS\MassNotify\EnterpriseIdentityHttp::request('https://identity.example.test/metadata',$policy);throw new RuntimeException('Ambiguous provider response was accepted.');}
        catch(DomainException $error){if(!str_contains($error->getMessage(),'invalid or ambiguous JSON')){throw $error;}}$checks++;
    }
    $options=$GLOBALS['fixture_https_options'];
    if($options[CURLOPT_SSL_VERIFYPEER]!==true||$options[CURLOPT_SSL_VERIFYHOST]!==2
        ||$options[CURLOPT_FOLLOWLOCATION]!==false||$options[CURLOPT_PROXY]!==''
        ||$options[CURLOPT_RESOLVE]!==['identity.example.test:443:192.168.1.10']){
        throw new RuntimeException('Provider parsing changed HTTPS verification policy.');
    }$checks++;
    echo $checks." provider response JSON and unchanged HTTPS-option checks passed; no network request was executed.\n";
}
