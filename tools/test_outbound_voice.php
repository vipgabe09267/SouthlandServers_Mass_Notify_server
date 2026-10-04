<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/OutboundVoice.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/AnnouncementDelivery.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/AnnouncementEmail.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/AnnouncementSms.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/RecipientSelection.php';

class VoiceFixture {
    use \FreePBX\modules\SlsOutboundVoice;
    use \FreePBX\modules\SlsAnnouncementDelivery;
    use \FreePBX\modules\SlsAnnouncementEmail; use \FreePBX\modules\SlsAnnouncementSms;
    public $settings = [];
    public $queued = [];
    public $queueState = 'queued';
    public $audioRequests = [];
    public $failVoice = '';
    public function getAvailablePiperVoices() { return [['path'=>'internal-voice'], ['path'=>'external-voice']]; }
    public function normalize($value, $create = false) { return $this->normalizeOutboundVoice($value, $create); }
    public function validate($value) { return $this->validateOutboundVoice($value); }
    public function form($input, $current = []) { return $this->readOutboundVoiceForm($input, $current); }
    public function snapshot($request) { return $this->snapshotAnnouncementRequest($request, $this->settings); }
    public function dispatch($request) { return $this->executeResolvedAnnouncementWithActivity($request); }
    private function getActiveSettings() { return $this->settings; }
    private function getDesktopClients($settings) { return $settings['desktop_clients'] ?? []; }
    private function normalizeWebhookDestinations($rows, $kind) { return $rows; }
    private function currentAnnouncementDestinationIds() { return ['phones'=>['1000'], 'desktops'=>[], 'webhooks'=>[]]; }
    private function sanitizeScheduleText($value, $limit, $single) { return substr($value, 0, $limit); }
    private function setAnnouncementCooldown() {}
    private function getAnnouncementCooldownState() { return ['remaining'=>0]; }
    private function appendAnnouncementNotifyLog(...$args) {}
    private function dispatchAnnouncementWebhooks(...$args) { return ['accepted'=>[], 'failed'=>[]]; }
    private function sendAnnouncementTtsAudio($phones, $message, $options) {
        $this->audioRequests[] = ['phones'=>$phones] + $options;
        if ($this->failVoice !== '' && $options['piper_voice'] === $this->failVoice) {
            return ['success'=>false, 'message'=>'Selected voice could not be prepared.', 'retryable'=>false];
        }
        foreach ($phones as $phone) { $this->lastAudioQueueResults[$phone] = true; }
        $this->queued[] = $options['outbound_targets'];
        foreach ($options['outbound_targets'] as $target) {
            $this->lastOutboundQueueResults[$target['id']] = ['state'=>$this->queueState, 'retryable'=>false, 'detail'=>'fixture'];
        }
        return ['success'=>$this->queueState === 'queued', 'audio_duration_seconds'=>12, 'delivery_started'=>false];
    }
}
function voice_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$fixture = new VoiceFixture();
$config = $fixture->normalize(['enabled'=>'1', 'recipients'=>[['name'=>'Fixture recipient', 'number'=>'+15551234567', 'enabled'=>'1']]], true);
$id = $config['recipients'][0]['id'];
voice_assert((bool)preg_match('/^voice_[a-f0-9]{24}$/D', $id), 'Missing stable random recipient identity');
voice_assert($fixture->normalize($config) === $config, 'Normalization changed persisted recipient identity');
voice_assert($config['route_mode'] === 'pbx_routes' && $config['trunk_id'] === '' && $config['caller_id'] === '', 'Default routing overrides existing PBX policy');
voice_assert($fixture->normalize([])['enabled'] === '0', 'Outbound voice enabled by default');
voice_assert($config['acknowledgement_required'] === false && $config['ack_timeout_seconds'] === 10 && $config['daily_call_limit'] === 0, 'New call policy changed legacy behavior');
foreach ([['acknowledgement_required'=>'1'], ['ack_timeout_seconds'=>4], ['ack_timeout_seconds'=>31], ['daily_call_limit'=>-1], ['daily_call_limit'=>10001], ['daily_call_limit'=>true]] as $patch) {
    voice_assert((bool)$fixture->validate(array_replace($config,$patch)), 'Invalid external call policy accepted');
}
$policyForm=['outbound_voice_present'=>'1','outbound_voice_complete'=>'1','outbound_voice_recipients_json'=>'[]',
    'outbound_voice_policy_present'=>'1','outbound_voice_acknowledgement_required'=>'1','outbound_voice_ack_timeout_seconds'=>'15','outbound_voice_daily_call_limit'=>'25'];
$policy=$fixture->form($policyForm);
voice_assert($policy['acknowledgement_required'] === true && $policy['ack_timeout_seconds'] === 15 && $policy['daily_call_limit'] === 25, 'Policy form lost types or choices');
$olderForm=array_diff_key($policyForm,array_flip(['outbound_voice_policy_present','outbound_voice_acknowledgement_required','outbound_voice_ack_timeout_seconds','outbound_voice_daily_call_limit']));
voice_assert($fixture->form($olderForm,$policy)['acknowledgement_required'] === true, 'Older form silently reset newer call policy');
foreach (['outbound_voice_ack_timeout_seconds','outbound_voice_daily_call_limit'] as $key) {
    $incomplete=$policyForm;unset($incomplete[$key]);
    try { $fixture->form($incomplete);throw new RuntimeException('Incomplete policy form accepted'); } catch (DomainException $expected) {}
}
foreach ([null, 'disabled', true, [1], ['recipients'=>[['name'=>'Missing ID', 'number'=>'+15551234567']]]] as $invalidCanonical) {
    try { $fixture->normalize($invalidCanonical); throw new RuntimeException('Invalid canonical voice config generated defaults or new identities.'); }
    catch (DomainException $expected) {}
}
$createdViaForm = $fixture->form(['outbound_voice_present'=>'1','outbound_voice_complete'=>'1',
    'outbound_voice_recipients_json'=>json_encode([['name'=>'New recipient','number'=>'+15551234569','enabled'=>'1']])]);
voice_assert((bool)preg_match('/^voice_[a-f0-9]{24}$/D', $createdViaForm['recipients'][0]['id']), 'Explicit form creation did not allocate a recipient identity');
voice_assert($fixture->normalize($createdViaForm) === $createdViaForm, 'Persisted form identity changed on canonical read');

foreach ([['route_mode'=>'unknown'], ['route_mode'=>'trunk'], ['trunk_id'=>"1\nChannel:evil"], ['caller_id'=>'${SHELL(id)}'], ['enabled'=>'yes'], ['extra'=>'bad']] as $patch) {
    voice_assert((bool)$fixture->validate(array_replace($config, $patch)), 'Unsafe outbound config accepted');
}
foreach (['1000', '+123&Local/911@from-internal', '+123,argument', "+123\r\n", '+0001', '+1234567890123456'] as $number) {
    $invalid = $config; $invalid['recipients'][0]['number'] = $number;
    voice_assert((bool)$fixture->validate($invalid), 'Unsafe or malformed telephone number accepted');
}
foreach (['enabled', 'route_mode', 'trunk_id', 'caller_id', 'piper_voice', 'recipients'] as $field) {
    voice_assert((bool)$fixture->validate(array_replace($config, [$field=>null])), 'Explicit null bypassed config validation for ' . $field);
}
$nullRow = $config; $nullRow['recipients'][0]['enabled']=null;
voice_assert((bool)$fixture->validate($nullRow), 'Null recipient enabled state was accepted');

$duplicate = $config; $duplicate['recipients'][] = $duplicate['recipients'][0];
voice_assert((bool)$fixture->validate($duplicate), 'Duplicate recipient identity/number accepted');
foreach (['0', '2', ''] as $complete) {
    try { $fixture->form(['outbound_voice_present'=>'1', 'outbound_voice_complete'=>$complete, 'outbound_voice_recipients_json'=>'[]']); throw new RuntimeException('Incomplete form accepted'); }
    catch (DomainException $expected) {}
}
$fixture->settings = ['outbound_voice'=>$config];
$request = ['phones'=>[], 'desktops'=>[], 'webhooks'=>[], 'voice_recipient_ids'=>[$id],
    'message'=>'Fixture only', 'sender'=>'Fixture', 'audio_mode'=>'tones', 'voice'=>'',
    'opening_tone'=>'fixture', 'closing_tone'=>'', 'volume'=>25, 'trigger_source'=>'fixture',
    'timeout_mode'=>'audio', 'display_timeout'=>0, 'image'=>false, 'title'=>'Fixture', 'background_color'=>'#000000'];
$frozen = $fixture->snapshot($request);
voice_assert($frozen['outbound_targets'][0]['number'] === '+15551234567', 'Snapshot did not freeze the phone number');
$result = $fixture->dispatch($frozen);
voice_assert($result['success'] && $result['receipts'][0]['channel'] === 'external_voice'
    && $result['receipts'][0]['state'] === 'queued' && !$result['receipts'][0]['retryable'], 'External-only audio was not queued with conservative receipt');
voice_assert($result['display_timeout_seconds'] === 12, 'External-only audio duration was lost');

// Separate selections must survive settings edits and independent preparation
// failures without changing another channel's receipt or playing the wrong voice.
$fixture->settings['outbound_voice']['piper_voice'] = 'external-voice';
$splitRequest = array_replace($request, ['audio_mode'=>'tts', 'phones'=>['1000'], 'voice'=>'internal-voice',
    'only_channels'=>['audio'=>['1000'], 'external_voice'=>[$id]]]);
$split = $fixture->snapshot($splitRequest);
voice_assert($split['voice'] === 'internal-voice' && $split['external_voice'] === 'external-voice', 'Both voice choices were not frozen');
$fixture->settings['outbound_voice']['piper_voice'] = '';
$fixture->audioRequests = [];
$splitResult = $fixture->dispatch($split);
voice_assert($splitResult['success'] && count($fixture->audioRequests) === 2, 'Distinct speech selections were not dispatched independently');
voice_assert($fixture->audioRequests[0]['phones'] === ['1000'] && $fixture->audioRequests[0]['outbound_targets'] === []
    && $fixture->audioRequests[0]['piper_voice'] === 'internal-voice', 'Internal recipients used external speech');
voice_assert($fixture->audioRequests[1]['phones'] === [] && $fixture->audioRequests[1]['piper_voice'] === 'external-voice', 'External voice snapshot followed edited configuration');
foreach (['external-voice'=>'external_voice', 'internal-voice'=>'audio'] as $failedVoice=>$channel) {
    $fixture->failVoice = $failedVoice;
    $splitResult = $fixture->dispatch($split);
    $states = array_column($splitResult['receipts'], 'state', 'channel');
    voice_assert($states[$channel] === 'failed' && $states[$channel === 'audio' ? 'external_voice' : 'audio'] === 'queued', 'One failed voice contaminated another channel');
}
$fixture->failVoice = '';
$shared = $split; unset($shared['external_voice']); $fixture->audioRequests = [];
voice_assert($fixture->dispatch($shared)['success'] && count($fixture->audioRequests) === 1
    && $fixture->audioRequests[0]['piper_voice'] === 'internal-voice', 'Legacy shared-voice jobs changed behavior');
$fixture->settings['outbound_voice'] = $config;
voice_assert((bool)$fixture->validate(array_replace($config, ['piper_voice'=>'/etc/passwd'])), 'Unlisted external voice was accepted');
foreach (['number'=>'+15557654321', 'route_mode'=>'trunk', 'caller_id'=>'+15550000001'] as $field=>$value) {
    $fixture->settings['outbound_voice'] = $config;
    if ($field === 'number') { $fixture->settings['outbound_voice']['recipients'][0]['number'] = $value; }
    else { $fixture->settings['outbound_voice'][$field] = $value; }
    if ($field === 'route_mode') { $fixture->settings['outbound_voice']['trunk_id'] = '2'; }
    $before = count($fixture->queued); $result = $fixture->dispatch($frozen);
    voice_assert(!$result['success'] && $result['receipts'][0]['state'] === 'cancelled' && count($fixture->queued) === $before, 'Changed route retargeted a queued alert');
}
$fixture->settings['outbound_voice'] = $config;
$fixture->settings['outbound_voice']['enabled'] = '0';
voice_assert($fixture->dispatch($frozen)['receipts'][0]['state'] === 'cancelled', 'Disabled outbound voice still dispatched');
$fixture->settings['outbound_voice'] = $config;
$legacy = $frozen; unset($legacy['outbound_targets']);
voice_assert($fixture->dispatch($legacy)['receipts'][0]['state'] === 'failed', 'Legacy request invented a route snapshot');
$fixture->queueState = 'submission_uncertain';
$result = $fixture->dispatch($frozen);
voice_assert($result['submission_uncertain'] && !$result['receipts'][0]['retryable'], 'Uncertain call submission allowed automatic retry');
$retry = $frozen; $retry['only_channels'] = ['external_voice'=>[]];
$before = count($fixture->queued); $fixture->dispatch($retry);
voice_assert(count($fixture->queued) === $before, 'Retry replayed an unselected external target');

// The API creation boundary assigns IDs once without changing partial-patch semantics.
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = $reflection->newInstanceWithoutConstructor();
$patchMethod = $reflection->getMethod('validateAndNormalizeControlConfigPatch'); $patchMethod->setAccessible(true);
$created = $patchMethod->invoke($module, ['outbound_voice'=>['recipients'=>[['name'=>'API recipient','number'=>'+15551234569']]]]);
voice_assert(empty($created['errors']) && preg_match('/^voice_[a-f0-9]{24}$/D', $created['patch']['outbound_voice']['recipients'][0]['id']), 'API creation did not allocate an identity');
voice_assert(array_keys($created['patch']['outbound_voice']) === ['recipients'], 'API recipient patch unexpectedly changed routing or Enabled');
$toggled = $patchMethod->invoke($module, ['outbound_voice'=>['enabled'=>false]]);
voice_assert(empty($toggled['errors']) && $toggled['patch']['outbound_voice'] === ['enabled'=>'0'], 'API Enabled patch erased saved recipients or routing');
$mergeMethod = $reflection->getMethod('mergeControlConfigPatch');
$merged = $mergeMethod->invoke($module, ['outbound_voice'=>$config], $toggled['patch']);
voice_assert($merged['outbound_voice'] === array_replace($config, ['enabled'=>'0']), 'Merging an API toggle erased existing routing or recipients');

// Exercise decoding an audience too large for repeated PHP form variables.
$fields = \SLS\MassNotify\RecipientSelection::FIELDS['send_announcement'];
$selection = array_fill_keys($fields, []);
$selection['announcement_desktop_clients'] = array_map(static function ($i) { return 'desktop' . $i; }, range(1, 1000));
$selection['voice_recipient_ids'] = array_map(static function ($i) { return 'voice_' . str_pad(dechex($i), 24, '0', STR_PAD_LEFT); }, range(1, 1000));
$form = ['slsmassnotifyserver_action'=>'send_announcement', 'sls_recipient_selection_present'=>'1',
    'sls_recipient_selection_complete'=>'1', 'sls_recipient_selection_json'=>json_encode($selection), 'announcement_extensions'=>['9999']];
$decoded = \SLS\MassNotify\RecipientSelection::decode($form);
voice_assert(count($decoded['voice_recipient_ids']) === 1000 && count($decoded['announcement_desktop_clients']) === 1000
    && $decoded['announcement_extensions'] === [], 'Large recipient form was truncated or accepted conflicting ordinary fields');
foreach (['{}', '{"unknown":[]}', '[]', str_repeat('x', 512*1024+1)] as $bad) {
    try { \SLS\MassNotify\RecipientSelection::decode(array_replace($form, ['sls_recipient_selection_json'=>$bad])); throw new RuntimeException('Malformed recipient form accepted'); }
    catch (DomainException $expected) {}
}
try { \SLS\MassNotify\RecipientSelection::decode(array_replace($form, ['sls_recipient_selection_complete'=>'0'])); throw new RuntimeException('Incomplete recipient form accepted'); }
catch (DomainException $expected) {}
echo "Outbound voice validation, immutable routes, revocation, external-only audio, uncertain receipts, retry scope and 2000-recipient form decoding passed.\n";
