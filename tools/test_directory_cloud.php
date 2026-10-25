<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/DirectoryCloud.php';
use SLS\MassNotify\{DirectoryCloud,DirectoryConfig,DirectorySync};
$checks=0;
function cloudCheck(bool $ok,string $message): void { $GLOBALS['checks']++; if(!$ok) throw new RuntimeException($message); }
function cloudReject(callable $work): void { try { $work(); } catch (DomainException $e) { cloudCheck($e->getMessage()!=='','Missing actionable error.');return; } throw new RuntimeException('Unsafe cloud snapshot accepted.'); }
$entra=['id'=>'dir_'.str_repeat('a',24),'name'=>'Fixture cloud','type'=>'entra','enabled'=>true,'template_ids'=>[],
    'tenant_id'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa','client_id'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb','cloud_client_secret'=>'fixture-secret'];
$user=static fn(string $id): array=>['id'=>$id,'displayName'=>'Fixture '.$id,'mail'=>$id.'@example.test','accountEnabled'=>true,
    'officeLocation'=>'Fixture room','businessPhones'=>['+15125550100'],'mobilePhone'=>'+15125550101'];
$calls=[];
$http=static function($url,$hosts,$method,$form,$headers,$timeout)use(&$calls,$user):array {
    $calls[]=compact('url','hosts','method','form','headers','timeout');
    if($method==='POST') return ['access_token'=>'fixture-token','token_type'=>'Bearer'];
    if(str_contains($url,'skiptoken')) return ['value'=>[$user('two')]];
    return ['value'=>[$user('one')],'@odata.nextLink'=>'https://graph.microsoft.com/v1.0/users?%24skiptoken=opaque'];
};
$rows=DirectoryCloud::fetch($entra,$http);
cloudCheck(count($rows)===2&&$rows[0]['phone_candidates']===['+15125550100','+15125550101'],'Complete pages or candidate phones lost.');
cloudCheck($calls[0]['form']['scope']==='https://graph.microsoft.com/.default'&&$calls[0]['method']==='POST','OAuth request changed its read-only directory contract.');
cloudCheck(count($calls)===3&&$calls[1]['hosts']===['graph.microsoft.com']&&$calls[1]['timeout']<=12000,'Collection escaped its network/deadline policy.');
cloudCheck(!isset($rows[0]['sms_consent'])&&!isset($rows[0]['operator_account_id']),'Imported contact silently granted authority.');
$public=DirectoryConfig::publicConfig(['sources'=>[$entra]]);
cloudCheck($public['sources'][0]['cloud_client_secret']===''&&$public['sources'][0]['cloud_client_secret_configured'],'Cloud credentials leaked in UI state.');
foreach(['https://other.example/users?%24skiptoken=x','http://graph.microsoft.com/v1.0/users?%24skiptoken=x',
    'https://graph.microsoft.com:444/v1.0/users?%24skiptoken=x','https://graph.microsoft.com/v1.0/users/one?%24skiptoken=x',
    'https://graph.microsoft.com/v1.0/users?%24skiptoken=x&%24skiptoken=y','https://graph.microsoft.com/v1.0/users?%24filter=anything',
    'https://graph.microsoft.com/v1.0/users?%24top=999&%24skiptoken=x','https://graph.microsoft.com/v1.0/users?%24skiptoken=%0aHeader',
    'https://graph.microsoft.com@other.example/v1.0/users?%24skiptoken=x','https://graph.microsoft.com/v1.0/users?%24skiptoken=x#fragment'] as $url) cloudReject(static fn()=>DirectoryCloud::requestUrl($url));
foreach([
    ['value'=>[$user('one')],'@odata.nextLink'=>'https://other.example/token'],
    ['value'=>[$user('one')],'@odata.nextLink'=>'https://login.microsoftonline.com/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/oauth2/v2.0/token'],
    ['value'=>[$user('one')],'@odata.nextLink'=>'https://graph.microsoft.com/v1.0/users?%24skiptoken=repeat'],
    ['value'=>[array_diff_key($user('one'),['accountEnabled'=>true])]], ['value'=>[],'@odata.nextLink'=>'https://graph.microsoft.com/v1.0/users?%24skiptoken=x'],
    ['error'=>['message'=>'Fixture denial']], ['value'=>[$user('same'),$user('same')]], ['value'=>array_fill(0,201,$user('one'))]
] as $page) {
    cloudReject(static fn()=>DirectoryCloud::fetch($entra,static fn($url,$hosts,$method)=>$method==='POST'?['access_token'=>'token','token_type'=>'Bearer']:$page));
}
$elapsed=0.;cloudReject(static fn()=>DirectoryCloud::fetch($entra,static fn()=>['access_token'=>'x','token_type'=>'Bearer'],static function()use(&$elapsed){return $elapsed+=30.;}));
$disabled=$entra;$disabled['enabled']=false;cloudReject(static fn()=>DirectoryCloud::fetch($disabled,$http));
$bad=$entra;$bad['tenant_id']='common';cloudReject(static fn()=>DirectoryCloud::normalize($bad));
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);openssl_pkey_export($key,$pem);
$google=['id'=>'dir_'.str_repeat('b',24),'name'=>'Fixture Google','type'=>'google','enabled'=>true,'template_ids'=>[],
    'google_client_email'=>'service@fixture.iam.gserviceaccount.com','google_admin_subject'=>'admin@example.test','google_customer'=>'my_customer','google_private_key'=>$pem];
$calls=[];
$googleHttp=static function($url,$hosts,$method,$form,$headers)use(&$calls,$key):array {
    $calls[]=compact('url','hosts','method','form','headers');
    if($method==='POST') {
        $jwt=\Firebase\JWT\JWT::decode($form['assertion'],new \Firebase\JWT\Key(openssl_pkey_get_details($key)['key'],'RS256'));
        cloudCheck($jwt->scope===DirectoryCloud::GOOGLE_SCOPE&&$jwt->sub==='admin@example.test'&&$jwt->aud==='https://oauth2.googleapis.com/token','Google assertion has excessive scopes or wrong delegation.');
        return ['access_token'=>'google-token','token_type'=>'Bearer'];
    }
    $id=str_contains($url,'pageToken=')?'two':'one';
    return ['users'=>[['id'=>$id,'name'=>['fullName'=>'Fixture '.$id],'primaryEmail'=>$id.'@example.test','suspended'=>$id==='two','phones'=>[['value'=>'+15125550102']]]]]
        +($id==='one'?['nextPageToken'=>'opaque+/=']:[]);
};
$rows=DirectoryCloud::fetch($google,$googleHttp);
cloudCheck(count($rows)===2&&!$rows[1]['active']&&$rows[1]['phone_candidates']===['+15125550102'],'Google status or phone mapping is incorrect.');
cloudCheck(str_contains($calls[2]['url'],'pageToken=opaque%2B%2F%3D'),'Continuation token was not encoded.');
$public=DirectoryConfig::publicConfig(['sources'=>[$google]]);
cloudCheck($public['sources'][0]['google_private_key']===''&&$public['sources'][0]['google_private_key_configured'],'Google key leaked.');
cloudCheck(DirectoryConfig::normalize(['sources'=>[$google]])['sources'][0]['google_customer']==='my_customer','Saved Google source changed customer.');
echo "Cloud directory: $checks read-only scope, complete pagination, status, credential isolation, deadline and candidate-contact checks passed.\n";
