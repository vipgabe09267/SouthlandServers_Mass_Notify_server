<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/contract.php';
use SLS\MassNotify\{ApiSecurity, ControlContract};
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    $checks++; if (!$ok) { throw new RuntimeException($message); }
};
$principal = ApiSecurity::issue(['name' => 'Fixture', 'scopes' => ['read', 'send'],
    'audience' => ['unrestricted' => false, 'announcement_group_ids' => ['group_a']]])['credential'];
unset($principal['secret_hash']);
$settings = ['desktop_clients' => [['client_id' => 'desktop_a', 'username' => 'alice', 'name' => 'Alice', 'enabled' => true]],
    'announcement_groups' => [['id' => 'group_a', 'name' => str_repeat('é', 150), 'extensions' => ['1000'], 'desktop_clients' => ['alice'],
        'location_snapshot' => ['schema' => 1, 'desktop_bindings' => [['client_id' => 'desktop_a', 'username' => 'alice']]]]],
    'announcement_webhooks' => [['id' => 'hook_a', 'name' => 'Hook', 'enabled' => true, 'url' => 'https://private.example.com/secret']]];
$audience = ControlContract::audiences($principal, $settings);
$check($audience['phone_targets'] === ['1000'] && count($audience['desktop_clients']) === 1, 'Permitted saved audiences were not expanded.');
$check(preg_match('//u', $audience['announcement_groups'][0]['name']) === 1, 'A projected label was cut inside a UTF-8 character.');
$check($audience['webhooks'] === [] && !str_contains(json_encode($audience), 'https://'), 'Discovery disclosed an unpermitted webhook or address.');
$settings['desktop_clients'][0]['client_id'] = 'replacement';
$changed = ControlContract::audiences($principal, $settings);
$check($changed['announcement_groups'] === [] && $changed['desktop_clients'] === [] && $changed['phone_targets'] === [], 'A stale desktop binding authorized discovery of a changed saved audience.');
$fleetReport=['schema'=>1,'private_configuration'=>'SECRET','clients'=>[['client_id'=>'desktop_a','username'=>'alice','password_enc'=>'SECRET'],['client_id'=>'outsider','username'=>'other']]];
$settings['desktop_clients'][0]['client_id']='desktop_a';
$projected=ControlContract::fleet($principal,$settings,$fleetReport);
$check(count($projected['clients'])===1&&$projected['clients'][0]['client_id']==='desktop_a'&&!str_contains(json_encode($projected),'SECRET'),'Saved-group fleet permission leaked a secret or excluded its reviewed desktop.');
$settings['desktop_clients'][0]['client_id']='replacement';
$check(ControlContract::fleet($principal,$settings,$fleetReport)['clients']===[],'A reassigned desktop username inherited fleet access.');
$restricted = ControlContract::capabilities($principal);
$check(!in_array('events', $restricted['resources'], true), 'Restricted discovery offered unrestricted audit history.');
$check(!in_array('start_incident', array_column($restricted['actions'], 'action'), true), 'Restricted discovery offered incident mutation.');
$principal['audience']['unrestricted'] = true;
$check(!in_array('recordings', ControlContract::capabilities($principal)['resources'], true), 'Read-only discovery offered the config-protected recording catalogue.');
$principal['scopes'][] = 'config';
$check(in_array('recordings', ControlContract::capabilities($principal)['resources'], true), 'Read-and-config discovery omitted the recording catalogue.');
$check(in_array('start_incident', array_column(ControlContract::capabilities($principal)['actions'], 'action'), true), 'Named unrestricted incident sender is missing discovery.');
$labsConfig=['enterprise_identity'=>['enabled'=>true],'enterprise_integrations'=>['enabled'=>'1'],
    'enterprise_operations'=>['enabled'=>true,'drills'=>['enabled'=>true]],'live_paging'=>['enabled'=>'1']];
foreach ([null, ['enabled'=>false], ['enabled'=>'1'], ['enabled'=>true,'unknown'=>true]] as $master) {
    $candidate=$labsConfig;if($master!==null){$candidate['enterprise_labs']=$master;}
    $capabilities=ControlContract::enterpriseCapabilities($principal,$candidate);
    $check(!$capabilities['features']['enterprise_labs']&&!$capabilities['features']['integrations']&&!$capabilities['features']['drills'], 'API advertised disabled Labs capabilities.');
    $check($capabilities['features']['identity']&&ControlContract::effectiveLabs($candidate)['live_paging']['enabled']==='1', 'Labs master disabled independent operator SSO or paging.');
}
$labsConfig['enterprise_labs']=['enabled'=>true];
$check(ControlContract::enterpriseCapabilities($principal,$labsConfig)['features']['drills'], 'Enabled Labs feature was missing from discovery.');
$principal['id'] = 'legacy';
$check(!in_array('start_incident', array_column(ControlContract::capabilities($principal)['actions'], 'action'), true), 'Discovery offered incident mutation to a legacy key.');
foreach(['monitor'=>['status','capabilities','readiness','metrics'], 'fleet_read'=>['capabilities','desktop_fleet'], 'incident_read'=>['capabilities','incidents','incident','incident_report']] as $scope=>$expected){
    $reader=ApiSecurity::issue(['name'=>'Narrow reader','scopes'=>[$scope],'audience'=>['unrestricted'=>true]])['credential'];
    $discovered=ControlContract::capabilities($reader);
    $actual=$discovered['resources'];sort($actual);sort($expected);
    $check($actual===$expected&&$discovered['actions']===[], 'A narrow reader gained a mutation or an unrelated resource: '.$scope);
    $check(!ApiSecurity::permits($reader,'read')&&!ApiSecurity::permits($reader,'send')&&!ApiSecurity::permits($reader,'config'),'Narrow scope implied a broad privilege.');
}
$restrictedFleet=ApiSecurity::issue(['name'=>'Fleet reader','scopes'=>['fleet_read'],'audience'=>['unrestricted'=>false,'desktop_client_ids'=>['desktop_a']]])['credential'];
$check(in_array('desktop_fleet',ControlContract::capabilities($restrictedFleet)['resources'],true),'A recipient-restricted fleet reader cannot discover permitted inventory.');
$input = ControlContract::announcementInput(['action' => 'preview_announcement', 'message' => 'Fixture', 'is_test' => true,
    'webhook_ids' => ['hook_a'], 'tts_volume' => 100, 'options' => ['preview' => false]]);
$check($input['options']['preview'] === true && $input['options']['_is_test'] === true, 'Preview or explicit test metadata was not normalized.');
$check($input['options']['webhook_ids'] === ['hook_a'] && !isset($input['webhook_ids']), 'Top-level webhook selection was not routed to the resolver.');
$rehearsal=ControlContract::announcementInput(['action'=>'rehearse_announcement','message'=>'Fixture','is_test'=>false,'preview'=>false,'options'=>['preview'=>false]]);
$check($rehearsal['options']['preview']===true&&$rehearsal['options']['_is_test']===false,'Rehearsal can be changed into a real send or requires test alert marking.');
$input = ControlContract::announcementInput(['action' => 'send_announcement', 'message' => 'Fixture', 'is_test' => false, 'options' => ['is_test' => true]]);
$check($input['options']['_is_test'] === false, 'Top-level explicit false did not take precedence over an option.');
foreach ([['options' => ['_test_channels' => ['sms']]], ['options' => ['_is_test' => true]], ['is_test' => 'true'],
    ['preview' => 1], ['webhook_ids' => [false]], ['webhook_ids' => array_fill(0, 11, 'hook')],
    ['tts_volume' => 0], ['tts_volume' => 201], ['tts_volume' => '100'], ['piper_voice' => []],
    ['style' => 'bogus'], ['audio_mode' => 'bogus'], ['background_color' => 'red'],
    ['options' => ['style' => 'bogus']], ['options' => ['audio_mode' => 'bogus']],
    ['options' => ['background_color' => '#12345g']], ['undocumented' => true]] as $invalid) {
    try { ControlContract::announcementInput(['action' => 'send_announcement', 'message' => 'Fixture'] + $invalid); }
    catch (InvalidArgumentException $expected) { $checks++; continue; }
    throw new RuntimeException('An internal option or malformed announcement field was accepted.');
}
$named=ApiSecurity::issue(['name'=>'Labs fixture','scopes'=>['send'],'audience'=>['unrestricted'=>true]])['credential'];
$restricted=$named;$restricted['audience']['unrestricted']=false;
foreach(['browser_recipient_ids'=>['sub_'.str_repeat('a',24)],'connector_ids'=>['conn_'.str_repeat('b',24)]] as $field=>$ids){
 $check(!ApiSecurity::permitsResolvedAnnouncement($restricted,[$field=>$ids],[]),'Restricted API key obtained Labs recipient access');
 $check(ApiSecurity::permitsResolvedAnnouncement($named,[$field=>$ids],[]),'Named unrestricted API sender rejected Labs recipient selection');
 try{ControlContract::announcementInput(['action'=>'send_announcement',$field=>[false]]);throw new RuntimeException('Malformed Labs identifier accepted');}catch(InvalidArgumentException $expected){$checks++;}
}
echo "Control API discovery, recipient isolation, stale identities, preview and explicit test metadata: $checks checks passed.\n";
