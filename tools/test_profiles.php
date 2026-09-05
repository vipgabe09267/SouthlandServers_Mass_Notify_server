<?php
require (getenv('SLS_CANDIDATE_CLASS') ? dirname(getenv('SLS_CANDIDATE_CLASS')) : dirname(__DIR__) . '/slsmassnotifyserver') . '/TestProfiles.php';
class ProfileFixture {
    use \FreePBX\modules\SlsTestProfiles;
    private function sanitizeScheduleText($text, $limit, $singleLine) { return substr(trim($text), 0, $limit); }
    public $profiles = [];
    public function getActiveSettings() { return ['test_profiles' => $this->profiles]; }
    public function sendSipNotifyAnnouncement($extensions, $message, $massNotify=true, $ttsAudio=false, $groups=[], array $options=[]) {
        return compact('extensions','message','ttsAudio','options');
    }
}
$class = new ReflectionClass(ProfileFixture::class);
$fixture = $class->newInstanceWithoutConstructor();
$normalize = $class->getMethod('normalizeTestProfiles'); $normalize->setAccessible(true);
$rows = $normalize->invoke($fixture, [['id'=>'chosen','name'=>'Only my desk','extensions'=>['1000','../1001',['invalid']],
    'desktop_clients'=>['gabe','bad client'], 'channels'=>'audio']]);
if (count($rows)!==1 || $rows[0]['extensions']!==['1000'] || $rows[0]['desktop_clients']!==['gabe']) throw new RuntimeException('Profile scope normalization failed');
$fixture->profiles = $rows;
$audio = $fixture->runTestProfile('chosen');
if ($audio['options']['_test_channels']['sip_notify'] !== [] || $audio['options']['desktop_clients']!==[] || !$audio['ttsAudio']) throw new RuntimeException('Audio-only profile leaked another channel');
$fixture->profiles[0]['channels']='visual';
$visual=$fixture->runTestProfile('chosen');
if ($visual['ttsAudio'] || $visual['options']['_test_channels']['audio']!==[] || $visual['options']['_test_channels']['webhook']!==[]) throw new RuntimeException('Visual-only profile leaked audio/webhooks');
if (strpos($visual['message'],'SYSTEM TEST')===false) throw new RuntimeException('Missing test label');
if (!empty($fixture->runTestProfile('missing')['success'])) throw new RuntimeException('Missing profile accepted');
$bound=$class->getMethod('normalizePagingAnswerTimeout'); $bound->setAccessible(true);
foreach ([-100=>1,0=>1,1=>1,3=>3,5=>5,999=>5] as $input=>$expected) if ($bound->invoke($fixture,$input)!==$expected) throw new RuntimeException('Paging answer bound failed');
if (count($normalize->invoke($fixture, array_fill(0,20,['name'=>'duplicate'])))!==1) throw new RuntimeException('Profile duplicate limit failed');
echo "Scoped test-profile and bounded answer-window checks passed.\n";
