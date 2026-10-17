<?php
declare(strict_types=1);
namespace SLS\MassNotify {
    // The real LDAP driver runs against inert local responses, never a server.
    function extension_loaded(string $name): bool { return $name==='ldap'||\extension_loaded($name); }
    function ldap_connect($url){return 'private-fixture';}
    function ldap_set_option(...$arguments): bool{return true;}
    function ldap_start_tls(...$arguments): bool{return true;}
    function ldap_bind(...$arguments): bool{return true;}
    function ldap_search(...$arguments){return 'private-page';}
    function ldap_parse_result($connection,$result,&$code,&$matched,&$error,&$referrals,&$controls): bool {
        $code=0;$controls=$GLOBALS['sls_ldap_fixture_controls']??[\LDAP_CONTROL_PAGEDRESULTS=>['value'=>['cookie'=>'']]];return true;
    }
    function ldap_get_entries(...$arguments): array{return $GLOBALS['sls_ldap_fixture_entries'];}
    function ldap_free_result(...$arguments): void{}
    function ldap_unbind(...$arguments): bool{return true;}
}
namespace {
    require_once __DIR__.'/../slsmassnotifyserver/DirectorySync.php';
    use SLS\MassNotify\{DirectoryConfig,DirectorySync};
    foreach(['LDAP_OPT_PROTOCOL_VERSION','LDAP_OPT_REFERRALS','LDAP_OPT_NETWORK_TIMEOUT','LDAP_OPT_TIMELIMIT',
        'LDAP_OPT_X_TLS_REQUIRE_CERT','LDAP_OPT_X_TLS_DEMAND','LDAP_CONTROL_PAGEDRESULTS','LDAP_DEREF_NEVER'] as $index=>$name){
        if(!defined($name)){define($name,$index+1);}
    }
    function check(bool $ok,string $message): void{if(!$ok){throw new RuntimeException($message);}}
    function rejects(callable $work): void{try{$work();}catch(DomainException $error){return;}throw new RuntimeException('Missing stable LDAP identity was admitted.');}
    $source=DirectoryConfig::normalize(['sources'=>[['id'=>'dir_'.str_repeat('a',24),'name'=>'Private directory','type'=>'ldap',
        'ldap_url'=>'ldaps://directory.example.test','ldap_bind_dn'=>'cn=fixture','ldap_password'=>'fixture-only','ldap_base_dn'=>'dc=example,dc=test',
        'ldap_attributes'=>['external_id'=>'objectGUID','name'=>'displayName','email'=>'mail','location'=>'l','desktop_username'=>'uid','active'=>'']]]])['sources'][0];
    $entry=['displayname'=>['count'=>1,0=>'Fixture Person']];$GLOBALS['sls_ldap_fixture_entries']=['count'=>1,0=>$entry];
    rejects(static fn()=>DirectorySync::ldap($source));
    foreach(['',null] as $empty){$entry['objectguid']=['count'=>1,0=>$empty];$GLOBALS['sls_ldap_fixture_entries']=['count'=>1,0=>$entry];rejects(static fn()=>DirectorySync::ldap($source));}
    $entry['objectguid']=['count'=>1,0=>str_repeat("\0",16)];$GLOBALS['sls_ldap_fixture_entries']=['count'=>1,0=>$entry];
    $records=DirectorySync::ldap($source);check($records[0]['external_id']==='binary:'.base64_encode(str_repeat("\0",16)),'A present binary LDAP identity was changed.');
    $source['ldap_attributes']['external_id']='entryUUID';$entry['entryuuid']=['count'=>1,0=>'unique-fixture'];$GLOBALS['sls_ldap_fixture_entries']=['count'=>1,0=>$entry];
    check(DirectorySync::ldap($source)[0]['external_id']==='unique-fixture','A present textual LDAP identity was changed.');
    $entry['entryuuid']=['count'=>2,0=>'identity-one',1=>'identity-two'];$GLOBALS['sls_ldap_fixture_entries']=['count'=>1,0=>$entry];rejects(static fn()=>DirectorySync::ldap($source));
    $entry['entryuuid']=['count'=>1,0=>'unique-fixture'];$GLOBALS['sls_ldap_fixture_entries']=['count'=>1,0=>$entry];
    foreach([[\LDAP_CONTROL_PAGEDRESULTS=>['value'=>['cookie'=>[]]]],[\LDAP_CONTROL_PAGEDRESULTS=>['value'=>['cookie'=>str_repeat('x',4097)]]]] as $controls){$GLOBALS['sls_ldap_fixture_controls']=$controls;rejects(static fn()=>DirectorySync::ldap($source));}unset($GLOBALS['sls_ldap_fixture_controls']);
    unset($entry['entryuuid']);$GLOBALS['sls_ldap_fixture_entries']=['count'=>1,0=>$entry];rejects(static fn()=>DirectorySync::ldap($source));
    echo "Real LDAP driver with inert transport rejects missing stable IDs and preserves valid binary/text identities. Live LDAP/TLS is not qualified.\n";
}
