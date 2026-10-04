<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging.php';
$source = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify_live_paging.php');
$start = strpos($source, 'class SlsLivePagingNotifyAgi ');
$end = strpos($source, '$agi = new SlsLivePagingNotifyAgi', $start);
if ($start === false || $end === false) { throw new RuntimeException('Missing production visual dispatcher.'); }
eval(substr($source, $start, $end - $start));

class PagingNotifyFixture extends SlsLivePagingNotifyAgi
{
    public $settings;
    public $commands = [];
    public $exitCode = 0;
    public function __construct(array $settings) { $this->settings = $settings; }
    protected function currentPagingSettings(): array { return $this->settings; }
    protected function runVisualCommand(array $command): int { $this->commands[] = $command; return $this->exitCode; }
}
function visual_check($condition, $message): void { if (!$condition) { throw new RuntimeException($message); } }

$settings = ['announcement_timeout_mode' => 'custom', 'announcement_timeout_seconds' => 45,
    'announcement_groups' => [], 'live_paging' => ['enabled' => '1', 'extension' => '700', 'allowed_callers' => [],
    'groups' => [['group_id' => 'paging_staff', 'name' => 'Staff', 'menu_number' => 1, 'require_pin' => '0',
        'extensions' => ['1000', '1001'], 'allowed_callers' => ['1000'], 'notify_extensions' => ['1000', '1002'],
        'text_message' => 'Live staff page: "quoted", $literal and `text`.']]]];
$settings['live_paging'] = \SLS\MassNotify\LivePagingConfig::normalize($settings['live_paging']);
$group = array_values(\SLS\MassNotify\LivePagingConfig::resolvedGroups($settings['live_paging'], []))[0];
$fixture = new PagingNotifyFixture($settings);
$result = $fixture->notifyGroup($group, '1000', $settings);
visual_check($result === ['status' => 'submitted', 'requested' => 1], 'Visual submission lost scope or claimed screen receipt.');
$command = $fixture->commands[0];
visual_check(array_slice($command, 0, 8) === ['/usr/bin/timeout', '--signal=TERM', '--kill-after=1', '12', '/usr/bin/python3', '-I',
    '/usr/local/bin/sls_mass_notify/sls_notify.py', '--targets'], 'Visual execution is not fixed and bounded.');
visual_check($command[8] === '1002' && $command[9] === '--announcement=' . $group['text_message'], 'Text or exact visual audience changed.');
visual_check(in_array('--no-api', $command, true) && in_array('--no-retry', $command, true)
    && !in_array('--desktop-all', $command, true) && !in_array('--api-only', $command, true), 'Paging leaked to desktops or automatic retries.');
visual_check($command[array_search('--announcement-timeout-seconds', $command, true) + 1] === '45', 'General display timeout was lost.');
foreach ([1 => 'failed', 124 => 'uncertain', 137 => 'uncertain', -1 => 'failed'] as $exit => $state) {
    $fixture->exitCode = $exit;
    visual_check($fixture->notifyGroup($group, '1000', $settings)['status'] === $state, 'Visual failure was reported as success.');
}
// Argument-shaped text must remain the value of --announcement, including
// names of existing flags that argparse would otherwise consume as options.
foreach (['--help', '--no-api', '-urgent', '--announcement=literal'] as $literalMessage) {
    $literalSettings = $settings;
    $literalSettings['live_paging']['groups'][0]['text_message'] = $literalMessage;
    $literalGroup = array_values(\SLS\MassNotify\LivePagingConfig::resolvedGroups($literalSettings['live_paging'], []))[0];
    $literalFixture = new PagingNotifyFixture($literalSettings);
    visual_check($literalFixture->notifyGroup($literalGroup, '1000', $literalSettings)['status'] === 'submitted', 'Argument-shaped text was rejected.');
    visual_check($literalFixture->commands[0][9] === '--announcement=' . $literalMessage,
        'Argument-shaped text escaped its announcement option value.');
}
$before = count($fixture->commands);
$fixture->settings['live_paging']['groups'][0]['text_message'] = 'Changed after PIN';
visual_check($fixture->notifyGroup($group, '1000', $settings)['status'] === 'configuration_changed', 'Changed configuration still dispatched.');
$fixture->settings = $settings;
visual_check($fixture->notifyGroup($group, '9999', $settings)['status'] === 'configuration_changed', 'Unauthorized caller dispatched visual text.');
$bad = $group; $bad['notify_extensions'] = ['1002;evil'];
visual_check($fixture->notifyGroup($bad, '1000', $settings)['status'] === 'failed', 'Malformed target reached dispatcher.');
$empty = $group; $empty['notify_extensions'] = ['1000'];
visual_check($fixture->notifyGroup($empty, '1000', $settings) === ['status' => 'not_requested', 'requested' => 0], 'Empty audience expanded to a broadcast.');
visual_check(count($fixture->commands) === $before, 'Rejected request executed a command.');
echo "Live paging SIP text: exact recipients, caller exclusion, immutable settings, authorization, literal argv, display timeout and honest bounded outcomes passed.\n";

$external = $settings; $external['live_paging']['external_access'] = '1';
$external['live_paging']['groups'][0] += ['allow_external'=>'1','external_callers'=>['+15125550123']];
$external['live_paging']['groups'][0]['pin_hash'] = password_hash('0123',PASSWORD_DEFAULT);
$externalGroup = \SLS\MassNotify\LivePagingConfig::resolvedGroups($external['live_paging'],[])[1];
$externalFixture = new PagingNotifyFixture($external); $externalFixture->setExternalIngress(true, '+15125550123');
visual_check($externalFixture->notifyGroup($externalGroup,'700',$external)['status'] === 'submitted', 'Approved external caller lost its phone text');
visual_check($externalFixture->commands[0][8] === '1000,1002', 'External caller ID removed an internal recipient');
$before = count($externalFixture->commands);
$externalFixture->setExternalIngress(true,'+15125550124');
visual_check($externalFixture->notifyGroup($externalGroup,'700',$external)['status'] === 'configuration_changed', 'Unlisted caller reached text dispatcher');
$externalFixture->setExternalIngress(true,'+15125550123');
$externalFixture->settings['live_paging']['groups'][0]['external_callers'] = ['+15125550124'];
visual_check($externalFixture->notifyGroup($externalGroup,'700',$external)['status'] === 'configuration_changed', 'Revoked caller reached text dispatcher');
visual_check(count($externalFixture->commands) === $before, 'Rejected external caller executed a visual command');
echo "External caller allowlist is rechecked before exact SIP text submission.\n";
$ending = new PagingNotifyFixture($settings);
visual_check($ending->notifyGroup($group,'1000',$settings)['status']==='submitted','Page start failed');
$token=$ending->commands[0][array_search('--visual-owner',$ending->commands[0],true)+1];
visual_check(preg_match('/^[a-f0-9]{32}$/D',$token)===1,'Page popup has no unique owner');
visual_check($ending->finishGroupNotification()['status']==='submitted','Page popup completion failed');
$clear=$ending->commands[1];
visual_check($clear[8]==='1002' && in_array('--announcement=Paging ended.',$clear,true)
    && $clear[array_search('--announcement-timeout-seconds',$clear,true)+1]==='1'
    && $clear[array_search('--clear-visual-owner',$clear,true)+1]===$token
    && in_array('--no-api',$clear,true) && in_array('--no-retry',$clear,true),'Page completion lost ownership, scope or short expiry');
visual_check($ending->finishGroupNotification()['status']==='not_requested' && count($ending->commands)===2,'Page completion was replayed');
$fallback=$settings; $fallback['announcement_timeout_mode']='none';
$fallbackGroup=\SLS\MassNotify\LivePagingConfig::resolvedGroups($fallback['live_paging'],[])[1];
$fallbackAgi=new PagingNotifyFixture($fallback);$fallbackAgi->notifyGroup($fallbackGroup,'1000',$fallback);
visual_check($fallbackAgi->commands[0][array_search('--announcement-timeout-seconds',$fallbackAgi->commands[0],true)+1]===(string)($fallback['live_paging']['max_duration_seconds']+5),'Unbounded page popup survived a lost cleanup process');
echo "Page completion: bounded fallback, exact original targets, unique ownership and one quiet cleanup attempt passed.\n";
