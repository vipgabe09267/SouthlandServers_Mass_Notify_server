<?php
declare(strict_types=1);
if(!interface_exists('BMO')){interface BMO{}}
require dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
use SLS\MassNotify\{RadioGateway as Radio,EnterpriseIntegrationsConfig as Config};
function radioCheck(bool $value,string $message):void{if(!$value){throw new RuntimeException($message);}}
function radioReject(callable $work):void{try{$work();}catch(DomainException|InvalidArgumentException $error){return;}throw new RuntimeException('Unsafe radio request accepted.');}
$config=Config::normalize([]);radioCheck($config['radios']===['enabled'=>'0','devices'=>[]],'Radio gateways enabled by default.');
$first=['id'=>'rad_'.str_repeat('a',24),'name'=>'North radio','extension'=>'1000','enabled'=>'1','maximum_audio_seconds'=>5];
$second=['id'=>'rad_'.str_repeat('b',24),'name'=>'South radio','extension'=>'1001','enabled'=>'1','maximum_audio_seconds'=>30];
$config['enabled']='1';$config['radios']=['enabled'=>'1','devices'=>[$first,$second]];
$settings=['enterprise_integrations'=>Config::normalize($config)];
$context=Radio::select($settings,[$first['id'],$second['id']]);
radioCheck(Radio::current($context,$settings)===5&&count($context['bindings'])===2,'Strictest selected gateway limit was lost.');
radioCheck(Radio::forExtensions($settings,['1000'])['maximum_audio_seconds']===5&&Radio::forExtensions($settings,['9999'])===null,'Normal announcement recipients bypass radio classification.');
foreach([0,4,121,'30',true] as $limit){radioReject(fn()=>Radio::normalize(['devices'=>[array_replace($first,['maximum_audio_seconds'=>$limit])]]));}
radioReject(fn()=>Radio::select($settings,[$first['id'],$first['id']]));
$changed=$settings;$changed['enterprise_integrations']['radios']['devices'][0]['extension']='1002';radioReject(fn()=>Radio::current($context,$changed));
$changed=$settings;$changed['enterprise_integrations']['radios']['enabled']='0';radioReject(fn()=>Radio::current($context,$changed));
$changed=$settings;$changed['enterprise_integrations']['radios']['devices'][0]['enabled']='0';radioReject(fn()=>Radio::current($context,$changed));
$reflection=new ReflectionMethod(\FreePBX\modules\Slsmassnotifyserver::class,'sendAnnouncementTtsAudio');
$source=file($reflection->getFileName());$method=implode('',array_slice($source,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
$body=<<<'PHP'
class RadioAudioFixture {
 const PIPER_BIN='/usr/bin/true';const PIPER_VOICE='fixture';
 public array $settings=[];public int $queued=0;public float $duration=6;private array $lastPhoneAdmission=[];
 private function getActiveSettings(){return $this->settings+['piper_bin'=>'/usr/bin/true'];}
 private function normalizeAnnouncementAudioMode($value){return $value;}
 private function getAvailablePiperVoices(){return [['path'=>'fixture']];}
 private function isValidPiperVoiceFile($path){return $path==='fixture';}
 private function normalizeToneName($value){return $value;}
 private function pruneTtsCache(){}
 private function generateAnnouncementTtsFile($message,$settings){return 'fixture';}
 private function buildAnnouncementAudioSequence($base,$settings){return 'fixture';}
 private function getAnnouncementSequenceDuration($base){return $this->duration;}
 private function guardPreparedApiAudio($context,$extensions,$outbound){return ['success'=>true,'extensions'=>$extensions,'outbound_targets'=>$outbound,'permission_denied_targets'=>['phones'=>[],'voice_recipient_ids'=>[]]];}
 private function queueAnnouncementAudioCalls($extensions,...$unused){$this->queued+=count($extensions);return count($extensions);}
 private function updateStatusData($value){}
 private function appendAnnouncementAudioLog(...$unused){}
 public function send($context){return $this->sendAnnouncementTtsAudio(['1000'],'Fixture message',$context);}
PHP;
eval($body.'const TTS_DIR='.var_export(sys_get_temp_dir(),true).';'.$method.'}');
$fixture=new RadioAudioFixture();$fixture->settings=$settings;
$audio=['audio_mode'=>'tts','piper_voice'=>'fixture','maximum_audio_seconds'=>5,'radio_context'=>Radio::select($settings,[$first['id']])];
$result=$fixture->send($audio);radioCheck(!$result['success']&&$result['error']==='radio_audio_duration_rejected'&&$fixture->queued===0,'An overlong radio sequence reached the phone queue.');
$fixture->duration=4;$result=$fixture->send($audio);radioCheck($result['success']&&$fixture->queued===1,'A bounded radio sequence did not use normal phone admission.');
$fixture->settings=$changed;$result=$fixture->send($audio);radioCheck(!$result['success']&&$fixture->queued===1,'A revoked radio profile was used after audio preparation.');
echo "Radio gateways: opt-in configuration, frozen identities, revocation and measured no-truncation audio limits passed. RF transmission was not attempted.\n";
