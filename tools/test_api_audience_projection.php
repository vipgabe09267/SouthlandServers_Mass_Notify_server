<?php
declare(strict_types=1);
namespace SLS\MassNotify {
    function hash($algorithm, $data, ...$options) { $GLOBALS['projection_hashes']++; return \hash($algorithm, $data, ...$options); }
}
namespace {
    require dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/security.php';
    use SLS\MassNotify\ApiSecurity;
    $GLOBALS['projection_hashes'] = 0;
    $settings = ['subscriber_browser'=>['enabled'=>true,'push'=>['enabled'=>true],'people'=>[]], 'announcement_groups'=>[]];
    for ($i=0;$i<1000;$i++) {
        $settings['subscriber_browser']['people'][] = ['id'=>'sub_'.sprintf('%024x',$i+1),'person_id'=>'person-'.$i,
            'name'=>'Private fixture '.$i,'email'=>'fixture'.$i.'@example.test','version'=>'1','registered_at'=>1700000000,'enabled'=>true];
    }
    $ids = [];
    for ($i=0;$i<100;$i++) {
        $group = ['id'=>'group-'.$i,'browser_recipient_ids'=>[$settings['subscriber_browser']['people'][$i]['id']]];
        $group['labs_bindings'] = ApiSecurity::labsBindings($group,$settings);
        $settings['announcement_groups'][] = $group; $ids[] = $group['id'];
    }
    $principal = ['scopes'=>['send'],'audience'=>ApiSecurity::audience(['unrestricted'=>false,'announcement_group_ids'=>$ids])];
    $GLOBALS['projection_hashes'] = 0; $start = microtime(true);
    $allowed = ApiSecurity::allowedAudience($principal,$settings);
    $duration = microtime(true)-$start; $hashes = $GLOBALS['projection_hashes'];
    echo json_encode(['projection_sha256'=>\hash('sha256',json_encode($allowed)), 'catalog_hashes'=>$hashes,
        'elapsed_ms'=>round($duration*1000,3),'browser_count'=>count($allowed['browser_recipient_ids'])]),"\n";
    if ($hashes>1100 || count($allowed['browser_recipient_ids'])!==100) throw new RuntimeException('One request recomputed the entire Labs enrollment catalog.');
    $settings['subscriber_browser']['people'][0]['enabled'] = false;
    $revoked = ApiSecurity::allowedAudience($principal,$settings);
    if (count($revoked['browser_recipient_ids'])!==99) throw new RuntimeException('A revoked enrollment remained authorized.');
    $settings['subscriber_browser']['people'][0]['enabled'] = true;
    $settings['subscriber_browser']['people'][0]['version'] = '2';
    if (count(ApiSecurity::allowedAudience($principal,$settings)['browser_recipient_ids'])!==99) throw new RuntimeException('A changed enrollment revived an old saved binding.');
    $settings['announcement_groups'][0]['browser_recipient_ids'] = false;
    if (ApiSecurity::groupLabsBindingsValid($settings['announcement_groups'][0],$settings)) throw new RuntimeException('Malformed channel membership became valid.');
    echo "API audience projection: one current catalog, unchanged recipients, revocation and stale/malformed binding fences passed.\n";
}
