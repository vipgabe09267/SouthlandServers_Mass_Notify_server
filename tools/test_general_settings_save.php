<?php
/** Execute the actual General Settings save method with private storage and inert host adapters. */
namespace FreePBX\modules {
    function exec($command, &$output = null, &$status = null) {
        if (!preg_match('#^/usr/bin/timeout --kill-after=1 2 /usr/sbin/postconf -h (myorigin|myhostname|mydomain) 2>/dev/null$#D', $command)) {
            throw new \RuntimeException('Unexpected host command in settings fixture.');
        }
        $output = ['mail.example.test'];
        $status = 0;
        return 'mail.example.test';
    }
}
namespace {
interface BMO {}
$_SERVER['HTTP_HOST']='pbx.example.test';
require dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
class GeneralReflectionFixture extends \FreePBX\modules\Slsmassnotifyserver {
    public function getConfiguredPjsipExtensionNumbers() { return ['1000']; }
    public function getAllPjsipExtensions() { return [['extension'=>'1000','name'=>'Fixture']]; }
    public function getAvailableTones() { return []; }
    public function getAvailablePiperVoices() { return []; }
    public function getOutboundVoiceTrunks() { return []; }
}
$reflection=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module=(new ReflectionClass(GeneralReflectionFixture::class))->newInstanceWithoutConstructor();
$invoke=static function($name,...$args)use($reflection,$module){$method=$reflection->getMethod($name);$method->setAccessible(true);return $method->invoke($module,...$args);};
$method=$reflection->getMethod('saveOtherSettings');$lines=file($method->getFileName());
$body=implode('',array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));
$constants='';foreach($reflection->getConstants() as $name=>$value){$constants.='const '.$name.'='.var_export($value,true).';';}
eval('namespace FreePBX\\modules; class GeneralSaveFixture {
 public $settings; public $pending=null; public $call; public $writes=0;
 private function getActiveSettings(){return $this->settings;}
 private function getPendingSettings(){return $this->pending;}
 private function isSetupComplete($settings){return true;}
 private function persistPendingSettings($settings){$this->pending=$settings;++$this->writes;}
 public function getAvailableTones(){return [];}
 public function getAvailablePiperVoices(){return [];}
 public function __call($name,$args){return ($this->call)($name,...$args);}
 '.$constants.$body.'}');
$fixture=new \FreePBX\modules\GeneralSaveFixture();$fixture->call=$invoke;
$settings=$invoke('getDefaultSettings');$settings['desktop_clients']=[];
$settings['public_pbx_host']='old.example.test';$settings['sipnotify']['pbx_host']='old.example.test';
$settings['sipnotify']['base_url']='https://old.example.test:8443/api/sipnotify';
$settings['sipnotify']['media_scheme']='https';$settings['sipnotify']['media_base_url']='https://old.example.test:8443/sls_mass_notify';
$settings['control_api']['base_url']='https://old.example.test:8443/api/sls-mass-notify';
$settings['control_api']['api_key']=str_repeat('s',32);$settings['desktop_auth_key']=base64_encode(str_repeat('d',32));
$settings['opening_tone']='';$settings['closing_tone']='';$settings['announcement_groups']=[];
$fixture->settings=$settings;
$input=['sls_address_change'=>'1','advertised_pbx_host'=>'new.example.test','advertised_api_port'=>'8443',
 'advertised_control_port'=>'9443','advertised_media_port'=>'7443','sipnotify_media_scheme'=>'https',
 'mail_from_domain'=>'mail.example.test','mail_from_local_part'=>'notify'];
if(isset($argv[1])){$pairs=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);$encoded=http_build_query([]);$parts=[];foreach($pairs as [$key,$value]){$parts[]=urlencode($key).'='.urlencode($value);}parse_str(implode('&',$parts),$input);}
$result=$fixture->saveOtherSettings($input);
if(!$result['success'])throw new RuntimeException('Full General Settings save failed: '.json_encode($result));
$expected=\FreePBX\modules\SlsAdvertisedAddress::migrate($settings,$input);
foreach(['public_pbx_host'] as $key){if($fixture->pending[$key]!==$expected[$key])throw new RuntimeException('Advertised hostname lost.');}
foreach(['base_url','media_base_url','media_scheme'] as $key){if($fixture->pending['sipnotify'][$key]!==$expected['sipnotify'][$key])throw new RuntimeException('Advertised desktop/image field lost: '.$key);}
if($fixture->pending['control_api']['base_url']!==$expected['control_api']['base_url'])throw new RuntimeException('Advertised Control API port lost.');
if($fixture->settings!==$settings || $fixture->pending['control_api']['api_key']!==$settings['control_api']['api_key'])throw new RuntimeException('Address save modified active settings or credentials.');
if(!isset($argv[1])) {
 $before=$fixture->pending;$writes=$fixture->writes;$input['advertised_media_port']='65536';$bad=$fixture->saveOtherSettings($input);
 if($bad['success']||$fixture->writes!==$writes||$fixture->pending!==$before)throw new RuntimeException('Invalid advertised address partially saved settings.');
}
echo "Actual General Settings save preserves advertised HTTPS host/ports and credentials; invalid addresses do not partially save.\n";
}
