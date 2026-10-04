<?php
declare(strict_types=1);

// Render the real view and its static partials without FreePBX bootstrap.
if (!function_exists('load_view')) {
    function load_view(string $path, array $variables = []): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();
        try { include $path; return (string)ob_get_contents(); }
        finally { ob_end_clean(); }
    }
}

require_once dirname(__DIR__) . '/slsmassnotifyserver/Paging.php';
use SLS\MassNotify\LivePagingConfig;
use SLS\MassNotify\LivePagingAgi;
use SLS\MassNotify\LivePagingState;
use SLS\MassNotify\LivePagingSession;

function paging_check($condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function paging_rejects(callable $operation, string $message): void
{
    try { $operation(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException($message);
}

class PagingFixtureAgi extends LivePagingAgi
{
    public $variables;
    public $entered;
    public $calls = [];
    public $failPage = false;
    public $failAdmission = false;
    public $admissionFailureCode = '';
    public $notification = null;
    public $throwNotification = false;
    public function __construct(array $variables, array $entered) { $this->variables = $variables; $this->entered = $entered; }
    public function variable(string $expression): string { $this->calls[] = ['variable', $expression]; return $this->variables[$expression] ?? ''; }
    public function answer(): void { $this->calls[] = ['answer']; }
    public function hangup(): void { $this->calls[] = ['hangup']; }
    protected function command(string $command, int $timeout = 5): string { $this->calls[] = ['command', $command]; return '200 result=0'; }
    public function autoHangup(int $seconds): void { $this->calls[] = ['deadline', $seconds]; }
    public function admitPhones(array $recipients, string $caller, string $groupId = ''): array
    {
        $this->calls[] = ['admission', $recipients, $caller, $groupId];
        if ($this->failAdmission) { return $this->rejectAdmission(['failure_code' => $this->admissionFailureCode]); }
        $dials = []; $unavailable = [];
        foreach ($recipients as $recipient) {
            $value = $this->variables['${PJSIP_DIAL_CONTACTS(' . $recipient . ')}'] ?? '';
            if ($value === '') { $unavailable[] = $recipient; }
            else { $dials[] = $value; }
        }
        return ['live_dial' => implode('&', $dials), 'unavailable' => $unavailable];
    }
    public function notifyGroup(array $group, string $caller, array $settings): array
    {
        $this->calls[] = ['notify', $group['group_id'], $caller, $group['notify_extensions']];
        if ($this->throwNotification) { throw new RuntimeException('fixture_text_failure'); }
        return $this->notification ?? parent::notifyGroup($group, $caller, $settings);
    }
    public function play(string $file): void { $this->calls[] = ['play', $file]; }
    public function finishGroupNotification(): array { $this->calls[] = ['finish_notify']; return parent::finishGroupNotification(); }
    public function digits(string $file, int $maximum): string
    {
        $this->calls[] = ['digits', $maximum];
        if (!$this->entered) { throw new RuntimeException('Fixture ran out of DTMF input'); }
        return array_shift($this->entered);
    }
    public function menu(array $files): string
    {
        $this->calls[] = ['menu', $files];
        if (!$this->entered) { throw new RuntimeException('Fixture ran out of menu input'); }
        return array_shift($this->entered);
    }
    public function page(string $dial, int $answerTimeout, int $duration): void
    {
        $this->calls[] = ['page', $dial, $answerTimeout, $duration];
        if ($this->failPage) { throw new RuntimeException('fixture_hangup'); }
    }
}

class PagingFixtureModule
{
    use \FreePBX\modules\SlsLivePaging;
    public $settings;
    public $saved;
    public $FreePBX;
    public $dialplan = "There is no existence of '700' in context 'from-internal'";
    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->FreePBX = (object)['Extensions' => new class { public $usage = []; public function checkUsage($extensions, $report) { return $this->usage; } }];
    }
    private function getActiveSettings() { return $this->settings; }
    private function getPendingSettings() { return null; }
    public function getLivePagingIvrs(): array { return [['id'=>'1','name'=>'Office IVR','entries'=>[]]]; }
    private function isSetupComplete($settings) { return true; }
    private function normalizeSettings($settings) { return $settings; }
    private function persistPendingSettings($settings) { $this->saved = $settings; }
    public function getConfiguredPjsipExtensionNumbers() { return ['1000', '1001', '1002']; }
    protected function queryLivePagingDialplan($extension) { return $this->dialplan; }
}

$root = sys_get_temp_dir() . '/sls-live-paging-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
register_shutdown_function(static function () use ($root): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
    rmdir($root);
});

$settings = ['live_paging' => ['enabled' => '1', 'extension' => '700', 'allowed_callers' => ['1000'],
    'groups' => [['group_id' => 'grp_office', 'menu_number' => 1, 'require_pin' => '1', 'pin_length' => 4, 'pin_hash' => password_hash('0123', PASSWORD_DEFAULT)]]],
    'announcement_groups' => [['id' => 'grp_office', 'name' => 'Office', 'extensions' => ['1000', '1001', '1002'], 'desktop_clients' => ['desktop']]],
    'paging_answer_timeout' => 5];
$settings['live_paging'] = LivePagingConfig::normalize($settings['live_paging']);
$variables = ['${CHANNEL(channeltype)}' => 'PJSIP', '${CHANNEL(endpoint)}' => '1000',
    '${DB(DEVICE/1000/dial)}' => 'PJSIP/1000', '${DB(DEVICE/1000/user)}' => '1000',
    '${PJSIP_DIAL_CONTACTS(1001)}' => 'PJSIP/1001/sip:1001@192.0.2.1:5060&PJSIP/1001/sip:1001@192.0.2.2:5060;transport=TCP',
    '${PJSIP_DIAL_CONTACTS(1002)}' => 'PJSIP/1002/sips:1002@[2001:db8::2]:5061;transport=TLS'];

paging_check(LivePagingConfig::defaults()['enabled'] === '0', 'New installations enabled paging');
paging_check(LivePagingConfig::defaults()['allowed_callers'] === [], 'New installations authorized callers');
foreach (range(4, 8) as $length) {
    $pin = LivePagingConfig::generatePin($length);
    paging_check(strlen($pin) === $length && ctype_digit($pin), 'Generated PIN length or alphabet changed');
}
paging_rejects(static function () { LivePagingConfig::generatePin(3); }, 'Short PIN accepted');
paging_rejects(static function () { LivePagingConfig::normalize(['enabled' => '1']); }, 'Enabled paging accepted no callers/groups/extension');
paging_rejects(static function () { LivePagingConfig::normalize(['extension' => '700,Hangup()']); }, 'Dialplan injection accepted');
paging_rejects(static function () { LivePagingConfig::normalize(['max_duration_seconds' => 1801]); }, 'Unbounded page accepted');
foreach ([['menu_prompt' => []], ['allowed_callers' => ['1000', []]], ['groups' => 'bad'], ['groups' => [['group_id' => []]]],
    ['groups' => [['group_id' => 'grp_office', 'menu_number' => 1, 'pin_hash' => []]]]] as $malformed) {
    paging_rejects(static function () use ($malformed) { LivePagingConfig::normalize($malformed); }, 'Malformed nested paging input accepted');
}
$duplicate = $settings['live_paging']; $duplicate['groups'][] = $duplicate['groups'][0];
paging_rejects(static function () use ($duplicate) { LivePagingConfig::normalize($duplicate); }, 'Duplicate group accepted');
$desktopOnly = $settings['announcement_groups']; $desktopOnly[0]['extensions'] = [];
paging_rejects(static function () use ($settings, $desktopOnly) { LivePagingConfig::resolvedGroups($settings['live_paging'], $desktopOnly); }, 'Desktop-only group accepted');
$texts = LivePagingConfig::promptTexts($settings['live_paging'], $settings['announcement_groups']);
paging_check(strpos($texts['menu'], 'For Office, press 1, then pound.') !== false, 'Menu lost configured group/selection');
paging_check(strpos(json_encode($texts), '0123') === false && strpos(json_encode($texts), '$2y$') === false, 'Prompts exposed PIN material');

$newSession = static function (PagingFixtureAgi $agi, array $config, string $name, ?callable $loader = null) use ($root): array {
    $directory = $root . '/' . $name; mkdir($directory, 0700);
    $state = new LivePagingState($directory);
    return [new LivePagingSession($agi, $state, $loader ?? static function () use ($config) { return $config; }, static function () { return true; }), $state, $directory];
};
$agi = new PagingFixtureAgi($variables, ['1', '0123']);
[$session, $state, $directory] = $newSession($agi, $settings, 'valid');
$result = $session->run();
paging_check($result['status'] === 'page_ended' && $result['requested_recipients'] === 2 && $result['dialed_contacts'] === 3, 'Valid page lost a contact or paged its origin');
$page = array_values(array_filter($agi->calls, static function ($call) { return $call[0] === 'page'; }))[0];
paging_check(strpos($page[1], 'transport=TCP') !== false && strpos($page[1], 'transport=TLS') !== false && strpos($page[1], 'PJSIP/1000/') === false, 'Live paging changed contact transport or included origin');
paging_check(json_decode(file_get_contents($directory . '/audio-reservations.json'), true)['recipients'] === [], 'Page completion retained recipient leases');
paging_check(strpos(json_encode($agi->calls), '0123') === false, 'Entered PIN leaked into AGI commands');

$capacityVariables = $variables;
$capacitySettings = $settings;
$capacitySettings['announcement_groups'][0]['extensions'][] = '1003';
foreach (['1001', '1002', '1003'] as $extension) {
    $contacts = [];
    foreach (range(1, 120) as $contact) { $contacts[] = 'PJSIP/' . $extension . '/sip:' . $extension . '@192.0.2.1:5060;transport=TCP;fixture=' . $contact . str_repeat('a', 45); }
    $capacityVariables['${PJSIP_DIAL_CONTACTS(' . $extension . ')}'] = implode('&', $contacts);
}
$agi = new PagingFixtureAgi($capacityVariables, ['1', '0123']);
[$session] = $newSession($agi, $capacitySettings, 'dial-capacity');
paging_check($session->run()['status'] === 'dial_string_capacity_exceeded', 'Oversized reachable group was misreported as unreachable');
paging_check(!array_filter($agi->calls, static function ($call) { return $call[0] === 'page'; }), 'Oversized dial string reached Page');

$trunkVariables = $variables; $trunkVariables['${CHANNEL(endpoint)}'] = 'provider-trunk';
$agi = new PagingFixtureAgi($trunkVariables, []);
[$session] = $newSession($agi, $settings, 'trunk');
paging_check($session->run()['status'] === 'unauthorized_caller', 'An incoming trunk obtained paging access');
paging_check(count(array_filter($agi->calls, static function ($call) { return $call[0] === 'play'; })) === 1
    && !array_filter($agi->calls, static function ($call) { return in_array($call[0], ['digits','menu','notify','page','admission','command'], true); }), 'Unauthorized trunk reached paging instead of the denial message');
$localVariables = $variables; $localVariables['${CHANNEL(channeltype)}'] = 'Local';
[$session] = $newSession(new PagingFixtureAgi($localVariables, []), $settings, 'local');
paging_check($session->run()['status'] === 'unauthorized_caller', 'Local channel bypassed internal endpoint authentication');
$spoofVariables = $variables; $spoofVariables['${DB(DEVICE/1000/dial)}'] = '';
[$session] = $newSession(new PagingFixtureAgi($spoofVariables, []), $settings, 'spoof');
paging_check($session->run()['status'] === 'unauthorized_caller', 'Caller endpoint without internal device identity was accepted');

$agi = new PagingFixtureAgi($variables, ['1', '123', '01230', '0000']);
[$session] = $newSession($agi, $settings, 'bad-pin');
paging_check($session->run()['status'] === 'invalid_pin', 'Wrong/overlong PIN accepted or attempts unbounded');
paging_check(!array_filter($agi->calls, static function ($call) { return $call[0] === 'page'; }), 'Invalid PIN paged phones');
$agi = new PagingFixtureAgi($variables, ['21', '', '01']);
[$session] = $newSession($agi, $settings, 'bad-menu');
paging_check($session->run()['status'] === 'invalid_menu_selection', 'Unknown/ambiguous menu number was accepted');

$noPin = $settings; $noPin['live_paging']['groups'][0]['require_pin'] = '0';
$agi = new PagingFixtureAgi($variables, ['1']);
[$session] = $newSession($agi, $noPin, 'no-pin');
paging_check($session->run()['status'] === 'page_ended', 'Explicit no-PIN group still required a PIN');

$agi = new PagingFixtureAgi($variables, ['1', '0123']);
[$session, $state, $directory] = $newSession($agi, $settings, 'busy');
$existing = $state->claim(['1001'], 300);
paging_check($session->run()['status'] === 'group_busy', 'Live page interrupted an existing recipient reservation');
$state->release($existing);
paging_check($state->claim(['1002'], 30) !== [], 'Disjoint group was blocked by unrelated reservation');

$queueDirectory = $root . '/queued'; mkdir($queueDirectory, 0700);
$queuedState = ['recipients' => (object)[], 'media' => (object)[], 'waiting' => [str_repeat('a', 32) => ['recipients' => ['1001'], 'priority' => 0, 'created' => 1800, 'expires' => 1900, 'heartbeat' => 1815, 'duration' => 30, 'media_name' => 'fixture.wav']]];
file_put_contents($queueDirectory . '/audio-reservations.json', json_encode($queuedState));
$queued = new LivePagingState($queueDirectory);
paging_check($queued->claim(['1001'], 300, 1800.0) === [], 'Live page jumped ahead of waiting recorded audio');
$lease = $queued->claim(['1002'], 300, 1800.0);
paging_check($lease !== [], 'Unrelated queued audio blocked a disjoint live group');
$later = json_decode(file_get_contents($queueDirectory . '/audio-reservations.json'), true);
$later['recipients']['1002'] = 2500;
file_put_contents($queueDirectory . '/audio-reservations.json', json_encode($later));
$queued->release($lease);
paging_check(json_decode(file_get_contents($queueDirectory . '/audio-reservations.json'), true)['recipients']['1002'] === 2500, 'Live completion deleted a later recorded-page reservation');

$unsafeDirectory = $root . '/unsafe'; mkdir($unsafeDirectory, 0700);
$victim = $unsafeDirectory . '/victim'; file_put_contents($victim, 'preserve');
symlink($victim, $unsafeDirectory . '/audio-reservations.json');
try { (new LivePagingState($unsafeDirectory))->claim(['1001'], 30); throw new LogicException('Symlink was accepted'); }
catch (RuntimeException $expected) { paging_check(file_get_contents($victim) === 'preserve', 'Reservation symlink modified its target'); }

$configPath = $root . '/protected.config'; file_put_contents($configPath, json_encode($settings)); chmod($configPath, 0640);
paging_check(LivePagingSession::loadSettings($configPath)['live_paging']['extension'] === '700', 'Protected config fixture failed');
chmod($configPath, 0644);
try { LivePagingSession::loadSettings($configPath); throw new LogicException('Public config accepted'); }
catch (RuntimeException $expected) { paging_check($expected->getMessage() === 'paging_config_unsafe', 'Wrong protected config failure'); }
if (function_exists('posix_mkfifo')) {
    $fifo = $root . '/config-fifo'; posix_mkfifo($fifo, 0600);
    $started = microtime(true);
    try { LivePagingSession::loadSettings($fifo); throw new LogicException('Config FIFO accepted'); }
    catch (RuntimeException $expected) { paging_check($expected->getMessage() === 'paging_config_unsafe', 'Wrong FIFO failure'); }
    paging_check(microtime(true) - $started < 0.5, 'Config FIFO blocked before validation');
}
$lockDirectory = $root . '/locked'; mkdir($lockDirectory, 0700);
$lockHandle = fopen($lockDirectory . '/audio-reservations.lock', 'c+'); flock($lockHandle, LOCK_EX);
$started = microtime(true);
try { (new LivePagingState($lockDirectory))->claim(['1001'], 30); throw new LogicException('Held reservation lock was ignored'); }
catch (RuntimeException $expected) { paging_check($expected->getMessage() === 'paging_lock_timeout', 'Wrong held-lock failure'); }
paging_check(microtime(true) - $started < 3.0, 'Live paging blocked indefinitely on another worker lock');
flock($lockHandle, LOCK_UN); fclose($lockHandle);

$agi = new PagingFixtureAgi($variables, ['1', '0123']); $agi->failPage = true;
[$session, $state, $directory] = $newSession($agi, $settings, 'hangup');
try { $session->run(); throw new LogicException('Expected fixture hangup'); } catch (RuntimeException $expected) { paging_check($expected->getMessage() === 'fixture_hangup', 'Unexpected hangup error'); }
paging_check(json_decode(file_get_contents($directory . '/audio-reservations.json'), true)['recipients'] === [], 'Hangup left live reservation held');
paging_check(in_array(['finish_notify'],$agi->calls,true), 'Hangup skipped page-popup completion');

$changed = $settings; $changed['live_paging']['enabled'] = '0'; $loads = 0;
[$session] = $newSession(new PagingFixtureAgi($variables, ['1', '0123']), $settings, 'changed', static function () use (&$loads, $settings, $changed) { return $loads++ === 0 ? $settings : $changed; });
paging_check($session->run()['status'] === 'configuration_changed', 'Changed configuration was not rechecked before paging');

$rateDirectory = $root . '/rate'; mkdir($rateDirectory, 0700); $state = new LivePagingState($rateDirectory);
for ($i = 0; $i < 10; $i++) { $state->authenticationAllowed('1000', true, 1800000000); }
paging_check(!$state->authenticationAllowed('1000', false, 1800000000), 'Caller PIN failure budget did not apply');
paging_check($state->authenticationAllowed('1001', false, 1800000000), 'One caller exhausted another caller budget');
paging_check($state->authenticationAllowed('1000', false, 1800000060), 'PIN failure budget did not expire');
$firstSlot = new LivePagingState($rateDirectory); $secondSlot = new LivePagingState($rateDirectory); $thirdSlot = new LivePagingState($rateDirectory);
paging_check($firstSlot->acquireCallSlot('1000') && $secondSlot->acquireCallSlot('1000') && !$thirdSlot->acquireCallSlot('1000'), 'Caller session bound failed');
$firstSlot->releaseCallSlot(); $secondSlot->releaseCallSlot();
for ($i = 0; $i < 60; $i++) { $state->authenticationAllowed('source-' . $i, true, 1800000120); }
paging_check(!$state->authenticationAllowed('fresh-source', false, 1800000120), 'Global failed PIN budget was not bounded');

$module = new PagingFixtureModule($settings);
$post = $settings['live_paging'];
$post['allowed_callers'] = json_encode($post['allowed_callers']);
unset($post['groups'][0]['pin_hash']); $post['groups'][0]['pin'] = '0042';
$saved = $module->saveLivePagingSettings($post);
paging_check($saved['success'] && password_verify('0042', $module->saved['live_paging']['groups'][0]['pin_hash']), 'Form lost leading zero or did not hash the PIN');
paging_check(strpos(json_encode($module->saved), '0042') === false, 'Persisted configuration contains plain PIN');
$post['groups'][0]['pin'] = ''; $post['groups'][0]['pin_length'] = '8';
paging_check(!$module->saveLivePagingSettings($post)['success'], 'Changed PIN length without replacing its hash');
$post['groups'][0]['pin_length'] = '4';
$module->FreePBX->Extensions->usage = ['core' => ['700' => ['status' => 'INUSE']]];
paging_check(!$module->saveLivePagingSettings($post)['success'], 'Existing PBX extension conflict was ignored');
paging_check(PagingFixtureModule::livePagingDialplanHasConflict("[ Included context 'outbound-emergency' created by 'pbx_config' ]\n '_X.' => 1. NoOp(existing)"), 'Conflicting wildcard route was ignored');
paging_check(!PagingFixtureModule::livePagingDialplanHasConflict("[ Included context 'sls-live-paging' created by 'pbx_config' ]\n '700' => 1. AGI(owned)"), 'Owned paging entry conflicted with itself');
$invalidNumber = "[ Included context 'bad-number' created by 'pbx_config' ]\n"
    . " '_X.' => 1. ResetCDR() [extensions_additional.conf:1]\n"
    . " 2. Set(CDR_PROP(disable)=true) [extensions_additional.conf:2]\n"
    . " 3. Progress() [extensions_additional.conf:3]\n 4. Wait(1) [extensions_additional.conf:4]\n"
    . " 5. Playback(silence/1&cannot-complete-as-dialed&check-number-dial-again,noanswer) [extensions_additional.conf:5]\n"
    . " 6. Wait(1) [extensions_additional.conf:6]\n 7. Congestion(20) [extensions_additional.conf:7]\n"
    . " 8. Hangup() [extensions_additional.conf:8]\n";
paging_check(!PagingFixtureModule::livePagingDialplanHasConflict($invalidNumber), 'Standard FreePBX invalid-number fallback occupied every unused extension');
paging_check(!PagingFixtureModule::livePagingDialplanHasConflict(str_replace('Set(CDR_PROP(disable)=true)', 'NoCDR()', $invalidNumber)), 'Legacy invalid-number fallback occupied unused extensions');
foreach ([str_replace("'_X.'", "'799'", $invalidNumber), str_replace("'pbx_config'", "'custom'", $invalidNumber),
    str_replace('Congestion(20)', 'Dial(PJSIP/fixture)', $invalidNumber), str_replace(' 8. Hangup() [extensions_additional.conf:8]', '', $invalidNumber),
    $invalidNumber . "[ Included context 'outbound-test' created by 'pbx_config' ]\n '_X.' => 1. Dial(PJSIP/fixture) [extensions_additional.conf:1]\n",
    "[ Included context 'sls-live-paging' created by 'pbx_config' ]\n '700' => 1. AGI(owned)\n" . $invalidNumber
        . "[ Included context 'custom-paging' created by 'pbx_config' ]\n '799' => 1. NoOp(used)\n"] as $conflict) {
    paging_check(PagingFixtureModule::livePagingDialplanHasConflict($conflict), 'A real or modified route was exempted as an invalid-number fallback');
}
$module->FreePBX->Extensions->usage = []; $module->dialplan = $invalidNumber;
foreach (['799', '7999'] as $unused) {
    $post['extension'] = $unused;
    paging_check($module->saveLivePagingSettings($post)['success'], 'Save rejected an unused extension matching only the stock invalid-number fallback');
}

[$input, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$output = fopen('php://temp', 'w+'); fwrite($peer, "200 result=0123\n");
$protocol = new LivePagingAgi($input, $output);
paging_check($protocol->digits('SLS_Mass_Notifications_Plugin/paging/fixture', 9) === '0123', 'AGI parser stripped leading zeros');
rewind($output); paging_check(strpos(stream_get_contents($output), '0123') === false, 'AGI command included entered PIN');
fclose($input); fclose($peer); fclose($output);

$paging = $settings['live_paging'];
$announcement_groups = $settings['announcement_groups'];
$announcement_groups[0]['name'] = '</script><script>alert(1)</script>';
$available_extensions = [['extension' => '1000', 'name' => '<img src=x onerror=alert(1)>']];
ob_start(); include dirname(__DIR__) . '/slsmassnotifyserver/views/paging.php'; $view = ob_get_clean();
paging_check(strpos($view, $settings['live_paging']['groups'][0]['pin_hash']) === false, 'Paging editor rendered a saved PIN hash');
paging_check(strpos($view, '</script><script>alert(1)</script>') === false && strpos($view, '<img src=x onerror=alert(1)>') === false, 'Paging editor allowed script injection');
paging_check(strpos($view, 'sls_paging_form_complete') !== false && strpos($view, 'sls-paging-callers-json') !== false, 'Paging form lost truncation protections');

// Independent groups never require or rewrite announcement groups.
$independent = $settings;
$independent['announcement_groups'] = [];
$independent['live_paging'] = LivePagingConfig::normalize(['enabled' => '1', 'extension' => '700', 'allowed_callers' => [],
    'groups' => [['group_id' => 'paging_staff', 'menu_number' => 1, 'name' => 'Staff', 'extensions' => ['1001', '1002'],
        'allowed_callers' => ['1000'], 'pin_hash' => $settings['live_paging']['groups'][0]['pin_hash']]]]);
$independentGroup = $independent['live_paging']['groups'][0];
paging_check($independentGroup['require_pin'] === '1' && $independentGroup['pin_length'] === 4
    && $independentGroup['text_message'] === 'Live page in progress.' && $independentGroup['notify_extensions'] === [], 'Independent group defaults changed');
paging_check(!LivePagingConfig::isStandalone($settings['live_paging']['groups'][0]), 'Legacy normalization silently migrated an announcement reference');
paging_check(LivePagingConfig::resolvedGroups($independent['live_paging'], [])[1]['name'] === 'Staff', 'Independent group depended on announcement groups');
foreach ([['name' => ''], ['name' => "Bad\0name"], ['extensions' => []], ['allowed_callers' => []],
    ['extensions' => ['PJSIP/1001']], ['notify_extensions' => ['1002;evil']], ['allowed_callers' => [1000]],
    ['text_message' => str_repeat('a', 1001)], ['text_message' => "bad\0text"], ['menu_number' => 11],
    ['notify_extensions' => array_fill(0, 1001, '1001')]] as $invalidFields) {
    $bad = $independent['live_paging']; $bad['groups'][0] = array_replace($independentGroup, $invalidFields);
    paging_rejects(static function () use ($bad) { LivePagingConfig::normalize($bad); }, 'Malformed independent group accepted');
}
$ten = $independent['live_paging']; $ten['groups'] = [];
foreach (range(1, 10) as $number) { $ten['groups'][] = array_replace($independentGroup, ['group_id' => 'paging_' . $number, 'menu_number' => $number]); }
paging_check(count(LivePagingConfig::normalize($ten)['groups']) === 10, 'Ten independent paging groups rejected');
$eleven = $ten; $eleven['groups'][] = array_replace($independentGroup, ['group_id' => 'paging_11', 'menu_number' => 11]);
paging_rejects(static function () use ($eleven) { LivePagingConfig::normalize($eleven); }, 'Eleventh independent paging group accepted');

$agi = new PagingFixtureAgi($variables, ['1', '0123']);
[$session] = $newSession($agi, $independent, 'independent-pin');
$result = $session->run();
paging_check($result['status'] === 'page_ended' && $result['text_notification'] === ['status' => 'not_requested', 'requested' => 0], 'Independent PIN group failed or empty text targets expanded');
$admissions = array_values(array_filter($agi->calls, static function ($call) { return $call[0] === 'admission'; }));
paging_check($admissions[0][3] === 'paging_staff', 'Admission lost the exact selected group identity');
$agi = new PagingFixtureAgi($variables, ['10', '0123']);
$tenSettings = $independent; $tenSettings['live_paging'] = $ten;
[$session] = $newSession($agi, $tenSettings, 'menu-ten');
paging_check($session->run()['group_id'] === 'paging_10', 'Menu entry ten did not select its independent group');

$isolated = $independent;
$isolated['live_paging']['groups'][] = array_replace($independentGroup, ['group_id' => 'paging_private', 'menu_number' => 2, 'allowed_callers' => ['1002']]);
$agi = new PagingFixtureAgi($variables, ['2', '0123']);
[$session] = $newSession($agi, $isolated, 'group-caller-denied');
paging_check($session->run()['status'] === 'unauthorized_group' && $agi->entered === ['0123'], 'A caller to one group reached another group PIN');
paging_check(!array_filter($agi->calls, static function ($call) { return in_array($call[0], ['notify', 'admission', 'page'], true); }), 'Unauthorized group caused recipients to be contacted');
$globalOnly = $variables; $globalOnly['${CHANNEL(endpoint)}'] = '1002';
$globalOnly['${DB(DEVICE/1002/dial)}'] = 'PJSIP/1002'; $globalOnly['${DB(DEVICE/1002/user)}'] = '1002';
$staleGlobals = $independent; $staleGlobals['live_paging']['allowed_callers'] = ['1002'];
[$session] = $newSession(new PagingFixtureAgi($globalOnly, []), $staleGlobals, 'legacy-global-no-grant');
paging_check($session->run()['status'] === 'unauthorized_caller', 'Legacy global caller list authorized an independent group');
$mixed = $settings; $mixed['live_paging']['groups'][] = array_replace($independentGroup, ['group_id' => 'paging_private', 'menu_number' => 2, 'allowed_callers' => ['1002']]);
$agi = new PagingFixtureAgi($globalOnly, ['1', '0123']);
[$session] = $newSession($agi, $mixed, 'independent-no-legacy-grant');
paging_check($session->run()['status'] === 'unauthorized_group' && $agi->entered === ['0123'], 'Independent group caller bypassed legacy caller selection');

$withText = $independent;
$withText['live_paging']['groups'][0]['notify_extensions'] = ['1002'];
$withText['live_paging']['groups'][0]['text_message'] = 'Staff page in progress.';
foreach (['failed', 'uncertain', 'submitted'] as $textState) {
    $agi = new PagingFixtureAgi($variables, ['1', '0123']);
    $agi->notification = ['status' => $textState, 'requested' => 1];
    [$session] = $newSession($agi, $withText, 'text-' . $textState);
    $result = $session->run();
    paging_check($result['status'] === 'page_ended' && $result['text_notification']['status'] === $textState, 'Text outcome did not preserve audio paging and accurate status');
    $events = array_column($agi->calls, 0);
    paging_check(array_search('admission', $events, true) < array_search('notify', $events, true)
        && array_search('notify', $events, true) < array_search('page', $events, true), 'Text submission occurred before admission or after audio');
    $warning = ['play', LivePagingConfig::promptFiles($withText)['text_failure']];
    paging_check(in_array($warning, $agi->calls, true) === ($textState !== 'submitted'), 'Caller did not receive the correct text failure warning');
}
$agi = new PagingFixtureAgi($variables, ['1', '0123']); $agi->throwNotification = true;
[$session] = $newSession($agi, $withText, 'text-exception');
$result = $session->run();
paging_check($result['status'] === 'page_ended' && $result['text_notification']['status'] === 'failed', 'Text dispatcher exception blocked audio');
$agi = new PagingFixtureAgi($variables, ['1', '0000', '0000', '0000']);
[$session] = $newSession($agi, $withText, 'text-pin-denied');
paging_check($session->run()['status'] === 'invalid_pin'
    && !array_filter($agi->calls, static function ($call) { return $call[0] === 'notify'; }), 'Failed PIN sent a text notification');
$agi = new PagingFixtureAgi($variables, ['1', '0123']); $agi->failAdmission = true;
[$session] = $newSession($agi, $withText, 'text-admission-denied');
paging_check($session->run()['status'] === 'phone_admission_rejected'
    && !array_filter($agi->calls, static function ($call) { return $call[0] === 'notify'; }), 'Failed phone admission sent a text notification');
foreach (['phone_live_audience_changed', 'phone_event_collector_unavailable', "unexpected\nsecret"] as $index => $failureCode) {
    $agi = new PagingFixtureAgi($variables, ['1', '0123']); $agi->failAdmission = true; $agi->admissionFailureCode = $failureCode;
    [$session] = $newSession($agi, $withText, 'admission-error-' . $index);
    $result = $session->run();
    paging_check($result['status'] === 'phone_admission_rejected', 'A rejected admission started paging');
    paging_check(($result['phone_admission_error'] ?? '') === ($index < 2 ? $failureCode : ''), 'Admission logs lost an allowed diagnostic or exposed arbitrary helper output');
}
$agi = new PagingFixtureAgi($variables, ['1', '0123']);
$agi->notification = ['status' => 'configuration_changed', 'requested' => 1];
[$session, $state, $directory] = $newSession($agi, $withText, 'text-revoked');
paging_check($session->run()['status'] === 'configuration_changed'
    && !array_filter($agi->calls, static function ($call) { return $call[0] === 'page'; }), 'Text configuration revocation still started audio');
paging_check(json_decode(file_get_contents($directory . '/audio-reservations.json'), true)['recipients'] === [], 'Text revocation retained audio leases');
$revoked = $withText; $revoked['live_paging']['groups'][0]['allowed_callers'] = ['1002']; $loads = 0;
$agi = new PagingFixtureAgi($variables, ['1', '0123']);
[$session, $state, $directory] = $newSession($agi, $withText, 'pre-admission-revoked', static function () use (&$loads, $withText, $revoked) { return $loads++ < 2 ? $withText : $revoked; });
paging_check($session->run()['status'] === 'configuration_changed'
    && !array_filter($agi->calls, static function ($call) { return in_array($call[0], ['admission', 'notify', 'page'], true); }), 'Caller revoked while resolving contacts still reached admission');
paging_check(json_decode(file_get_contents($directory . '/audio-reservations.json'), true)['recipients'] === [], 'Pre-admission revocation retained audio leases');

$module = new PagingFixtureModule($settings);
$independentPost = $independent['live_paging'];
$newRow = $independentGroup; unset($newRow['pin_hash']);
$newRow['group_id'] = ''; $newRow['pin'] = '0042';
$independentPost['groups'] = json_encode([$newRow]);
$independentPost['allowed_callers'] = '[]';
$saved = $module->saveLivePagingSettings($independentPost);
paging_check($saved['success'], 'Bounded JSON independent group save failed');
$savedRow = $module->saved['live_paging']['groups'][0];
paging_check(preg_match('/^paging_[0-9a-f]{24}$/D', $savedRow['group_id']) === 1
    && password_verify('0042', $savedRow['pin_hash']) && !isset($savedRow['pin']), 'New group lost its server ID or protected PIN');
paging_check($module->saved['announcement_groups'] === $settings['announcement_groups'], 'Saving an independent group changed announcement groups');
$module->settings = $module->saved;
$newRow['group_id'] = $savedRow['group_id']; $newRow['pin'] = '';
$independentPost['groups'] = json_encode([$newRow]);
paging_check($module->saveLivePagingSettings($independentPost)['success']
    && $module->saved['live_paging']['groups'][0]['group_id'] === $savedRow['group_id']
    && $module->saved['live_paging']['groups'][0]['pin_hash'] === $savedRow['pin_hash'], 'Editing independent group changed stable identity or saved PIN');
foreach (['{}', 'null', '[[]]', '[{"group_id": []}]', str_repeat(' ', 800001),
    json_encode([array_replace($newRow, ['notify_extensions' => (object)[]])]),
    json_encode([array_replace($newRow, ['allowed_callers' => ['9999']])]),
    json_encode([array_replace($newRow, ['extensions' => ['9999']])]),
    json_encode([array_replace($newRow, ['notify_extensions' => ['9999']])])] as $invalidJson) {
    $badPost = $independentPost; $badPost['groups'] = $invalidJson;
    paging_check(!$module->saveLivePagingSettings($badPost)['success'], 'Malformed JSON or stale extension saved an independent group');
}
$module = new PagingFixtureModule($settings);
$legacyPost = $settings['live_paging']; unset($legacyPost['groups'][0]['pin_hash']);
$legacyPost['groups'][0]['pin'] = '';
paging_check($module->saveLivePagingSettings($legacyPost)['success']
    && !LivePagingConfig::isStandalone($module->saved['live_paging']['groups'][0]), 'Saving legacy menu silently converted its group');
$conversion = array_replace($legacyPost['groups'][0], ['name' => 'Independent Office', 'extensions' => ['1001'], 'allowed_callers' => ['1000']]);
$legacyPost['groups'] = json_encode([$conversion]);
paging_check($module->saveLivePagingSettings($legacyPost)['success']
    && LivePagingConfig::isStandalone($module->saved['live_paging']['groups'][0])
    && $module->saved['live_paging']['groups'][0]['group_id'] === 'grp_office'
    && $module->saved['live_paging']['groups'][0]['pin_hash'] === $settings['live_paging']['groups'][0]['pin_hash'], 'Explicit conversion lost legacy identity or protected PIN');

// A retired, hidden legacy caller list must not prevent editing independent
// groups after an old extension is deleted. Only an explicit save clears it.
$unusedLegacy = $independent; $unusedLegacy['live_paging']['allowed_callers'] = ['9999'];
paging_check(LivePagingConfig::normalize($unusedLegacy['live_paging'])['allowed_callers'] === ['9999'],
    'Loading independent settings silently rewrote a retained legacy list');
$module = new PagingFixtureModule($unusedLegacy);
$unusedPost = $unusedLegacy['live_paging'];
unset($unusedPost['groups'][0]['pin_hash']); $unusedPost['groups'][0]['pin'] = '';
paging_check($module->saveLivePagingSettings($unusedPost)['success'] && $module->saved['live_paging']['allowed_callers'] === [],
    'An unused stale global caller blocked an independent-only save');
$module = new PagingFixtureModule($settings);
$legacyPost = $settings['live_paging']; unset($legacyPost['groups'][0]['pin_hash']); $legacyPost['groups'][0]['pin'] = '';
paging_check($module->saveLivePagingSettings($legacyPost)['success'] && $module->saved['live_paging']['allowed_callers'] === ['1000'],
    'Saving a legacy menu cleared its shared callers');
$legacyPost['allowed_callers'] = ['9999'];
paging_check(!$module->saveLivePagingSettings($legacyPost)['success'], 'A stale caller remained valid for a legacy menu');

echo "Independent paging groups: strict JSON and recipient validation, ten-group limit, legacy conversion, caller isolation, PIN protection, revocation and text failure behavior passed.\n";

echo "Live paging validation, PINs, endpoint authorization, all-contact routing, busy reservations, hangup cleanup, rate limits, configuration revocation, and extension conflict fixtures passed.\n";

$external = $independent;
$external['live_paging']['external_access'] = '1';
$external['live_paging']['external_ivr_ids'] = ['1'];
$external['live_paging']['groups'][0]['allow_external'] = '1';
$external['live_paging']['groups'][0]['external_callers'] = ['+15125550123'];
$external['live_paging']['groups'][0]['require_pin'] = '0'; // Internal opt-out never bypasses external PIN.
$ingress = ['agi_context' => 'sls-live-paging-external', 'agi_extension' => 's'];
$externalRun = static function(array $config, array $digits, string $case, ?array $environment = null, ?callable $loader = null, array $callerVariables = []) use ($root, $trunkVariables, $ingress): array {
    $directory = $root . '/external-' . $case; mkdir($directory, 0700);
    $agi = new PagingFixtureAgi(array_replace($trunkVariables, ['${IVR_CONTEXT}'=>'ivr-1', '${DIALPLAN_EXISTS(ivr-1,s,1)}'=>'1', '${CALLERID(num)}'=>'+15125550123', '${CALLERID(num-valid)}'=>'1', '${CALLERID(num-pres)}'=>'allowed'], $callerVariables), $digits);
    $session = new LivePagingSession($agi, new LivePagingState($directory), $loader ?? static fn() => $config, static fn() => true, $environment ?? $ingress);
    return [$session->run(), $agi];
};
[$result, $agi] = $externalRun($external, ['1', '0123'], 'accepted');
paging_check($externalRun($external, [], 'wrong-ivr', null, null, ['${IVR_CONTEXT}'=>'ivr-2'])[0]['status'] === 'external_ivr_not_approved', 'Unselected IVR reached the paging menu');
paging_check($externalRun($external, [], 'no-ivr', null, null, ['${IVR_CONTEXT}'=>''])[0]['status'] === 'external_ivr_not_approved', 'Direct inbound destination bypassed IVR selection');
paging_check($result['status'] === 'page_ended', 'Opted-in external caller with correct PIN was refused');
$admitted = array_values(array_filter($agi->calls, static fn($r) => $r[0] === 'admission'));
paging_check($admitted[0][2] === '700' && $admitted[0][1] === ['1001', '1002'], 'External admission used caller ID or changed the audience');
[$result, $agi] = $externalRun($external, ['1', '0000', '0000', '0000'], 'bad-pin');
paging_check($result['status'] === 'invalid_pin' && !array_filter($agi->calls, static fn($r) => in_array($r[0], ['admission','notify','page'], true)), 'External wrong PIN reached delivery');
[$result] = $externalRun($external, [], 'direct', ['agi_context' => 'sls-live-paging', 'agi_extension' => '700']);
paging_check($result['status'] === 'unauthorized_caller', 'Trunk bypassed the explicit IVR ingress');
$disabled = $external; $disabled['live_paging']['external_access'] = '0';
paging_check($externalRun($disabled, [], 'disabled')[0]['status'] === 'external_access_disabled', 'Disabled external route accepted a caller');
$private = $external; $private['live_paging']['groups'][0]['allow_external'] = '0';
paging_check($externalRun($private, [], 'private')[0]['status'] === 'external_caller_not_approved', 'Internal-only group leaked to external callers');
$private['live_paging']['groups'][] = array_replace($external['live_paging']['groups'][0], ['group_id'=>'paging_external', 'menu_number'=>2, 'name'=>'Outside staff']);
$prompts = LivePagingConfig::promptTexts($private['live_paging'], []);
paging_check(!isset($prompts['external_group_1']) && str_contains($prompts['external_group_2'], 'Outside staff'), 'External menu exposed internal-only names');
$bad = $external['live_paging']; $bad['groups'][0]['pin_hash'] = '';
paging_rejects(static fn() => LivePagingConfig::normalize($bad), 'External group accepted an absent PIN');
$loads = 0;
[$result, $agi] = $externalRun($external, ['1','0123'], 'revoked', null, static function() use (&$loads, $external, $disabled) { return ++$loads === 1 ? $external : $disabled; });
paging_check($result['status'] === 'configuration_changed' && !array_filter($agi->calls, static fn($r) => $r[0] === 'admission'), 'External revocation after PIN did not stop delivery');
$module = new PagingFixtureModule($external);
paging_check($module->getLivePagingExternalDestinations()[0]['destination'] === 'sls-live-paging-external,s,1', 'IVR destination missing');
$post = $external['live_paging']; unset($post['groups'][0]['pin_hash']); $post['groups'][0]['pin'] = '';
paging_check(!$module->saveLivePagingSettings($post, str_repeat('0',64))['success'], 'Stale paging editor overwrote settings');
$revision = hash('sha256', json_encode([LivePagingConfig::normalize($external['live_paging']), []], JSON_THROW_ON_ERROR));
paging_check($module->saveLivePagingSettings($post, $revision)['success'], 'Current paging editor with external access failed to save');
echo "External IVR paging: explicit ingress, per-group opt-in, mandatory PIN, exact recipients, private menu, revision checks and revocation passed.\n";

foreach (['+15125550123','15125550123','5125550123','+1 (512) 555-0123','0015125550123'] as $number) {
    paging_check(LivePagingConfig::matchExternalCaller($number,['+15125550123']) === '+15125550123', 'Complete approved caller presentation was not normalized');
}
foreach (['123-456-7890','','anonymous','+9915125550123','5550123','5125550123;1','+15125550123@evil',"+15125550123\n",'++15125550123','+00015125550123'] as $number) {
    paging_check(LivePagingConfig::matchExternalCaller($number,['+15125550123']) === '', 'Unapproved/suffix/injected caller presentation matched');
}
paging_check(LivePagingConfig::matchExternalCaller('442079460123',['+442079460123']) === '+442079460123', 'International caller without + was not accepted');
paging_check(LivePagingConfig::matchExternalCaller('02079460123',['+442079460123']) === '', 'Ambiguous national number acquired an assumed country code');
paging_check(LivePagingConfig::externalCallers(['+1 (512) 555-0123']) === ['+15125550123'], 'Approved caller normalization changed');
foreach ([['5125550123'], ['*'], ['+15125550123','+1 512 555 0123'], [15125550123], array_fill(0,101,'+15125550123')] as $numbers) {
    paging_rejects(static fn() => LivePagingConfig::externalCallers($numbers), 'Invalid approved caller list was accepted');
}
$emptyList = $external['live_paging']; $emptyList['groups'][0]['external_callers'] = [];
paging_rejects(static fn() => LivePagingConfig::normalize($emptyList), 'An empty external list admitted everybody');
foreach ([['${CALLERID(num)}'=>'123-456-7890'], ['${CALLERID(num)}'=>''], ['${CALLERID(num-pres)}'=>'prohib'],
    ['${CALLERID(num-pres)}'=>'allowed_failed_screen'], ['${CALLERID(num-pres)}'=>'unavailable'], ['${CALLERID(num-valid)}'=>'0']] as $index => $values) {
    [$result, $agi] = $externalRun($external, ['1','0123'], 'denied-'.$index, null, null, $values);
    paging_check($result['status'] === 'external_caller_not_approved', 'Unlisted/withheld caller was not rejected');
    paging_check(!array_filter($agi->calls, static fn($r) => in_array($r[0], ['menu','digits','notify','admission','page'], true)), 'Unapproved caller reached a menu, PIN, or delivery');
    paging_check($result['returned_to_ivr'] === true && $agi->hasReturnedToIvr()
        && in_array(['command','EXEC Goto "ivr-1,s,1"'], $agi->calls, true), 'Unapproved caller did not return to the selected originating IVR');
}
[$missingResult, $missingAgi] = $externalRun($external, [], 'deleted-return-ivr', null, null,
    ['${CALLERID(num)}'=>'+15125550124', '${DIALPLAN_EXISTS(ivr-1,s,1)}'=>'0']);
paging_check($missingResult['returned_to_ivr'] === false && !$missingAgi->hasReturnedToIvr()
    && !array_filter($missingAgi->calls, static fn($r) => $r[0] === 'command'), 'Missing IVR accepted an unsafe redirect');
paging_check(LivePagingConfig::promptTexts($external['live_paging'], [])['denied'] === 'You are not authorized to perform that function.', 'Denial wording changed');
foreach (['ivr-1,s,1', 'ivr-0', 'from-internal', "ivr-1\nEXEC Dial"] as $context) {
    $unsafeAgi = new PagingFixtureAgi([], []);
    paging_check(!$unsafeAgi->returnToIvr($context) && !$unsafeAgi->calls, 'Unsafe IVR redirect reached the AGI pipe');
}
$twoGroups = $external;
$twoGroups['live_paging']['groups'][] = array_replace($external['live_paging']['groups'][0], ['group_id'=>'paging_other','menu_number'=>2,'name'=>'Other staff','external_callers'=>['+15125550124']]);
[$result, $agi] = $externalRun($twoGroups, ['2','2','2'], 'cross-group');
paging_check($result['status'] === 'invalid_menu_selection' && !array_filter($agi->calls, static fn($r) => $r[0] === 'digits'), 'Approved caller reached another group’s PIN');
$menus = array_values(array_filter($agi->calls, static fn($r) => $r[0] === 'menu'));
$files = LivePagingConfig::promptFiles($twoGroups);
paging_check($menus[0][1] === [$files['external_menu'],$files['external_group_1']], 'Caller heard a group outside its allowlist');
$revokedNumber = $external; $revokedNumber['live_paging']['groups'][0]['external_callers'] = ['+15125550124']; $loads = 0;
[$result,$agi] = $externalRun($external, ['1','0123'], 'number-revoked', null, static function() use (&$loads,$external,$revokedNumber) { return ++$loads === 1 ? $external : $revokedNumber; });
paging_check($result['status'] === 'configuration_changed' && !array_filter($agi->calls, static fn($r) => $r[0] === 'admission'), 'Caller-number revocation during PIN exchange reached delivery');
echo "External caller allowlists: number formats, strict full-number matching, withheld/invalid identities, per-group menus and current authorization passed.\n";
