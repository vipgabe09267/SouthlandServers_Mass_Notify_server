<?php
declare(strict_types=1);
namespace FreePBX\modules {
    function is_executable($path) { return false; }
    function is_readable($path) { return false; }
    function file_get_contents(...$args) { throw new \RuntimeException('Unexpected filesystem read'); }
    function exec(...$args) { throw new \RuntimeException('Unexpected command'); }
}
namespace {
    if (!interface_exists('BMO')) { interface BMO {} }
    require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
    use SLS\MassNotify\UpdatePolicy;
    final class UpdatePolicyFixture extends \FreePBX\modules\Slsmassnotifyserver {
        public function getConfiguredPjsipExtensionNumbers() { return []; }
        public function getAllPjsipExtensions() { return []; }
        public function getAvailableTones() { return []; }
        public function getAvailablePiperVoices() { return []; }
    }
    $checks = 0;
    $check = static function ($ok, $message) use (&$checks) { $checks++; if (!$ok) { throw new \RuntimeException($message); } };
    $class = new \ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
    $module = (new \ReflectionClass(UpdatePolicyFixture::class))->newInstanceWithoutConstructor();
    $call = static function ($name, ...$args) use ($class, $module) { $m=$class->getMethod($name); $m->setAccessible(true); return $m->invokeArgs($module,$args); };
    $settings=$call('getDefaultSettings');
    $check($settings['updates'] === UpdatePolicy::defaults(), 'Default update policy differs');
    $check($settings['updates']['github_enabled'] === '0', 'Automatic installation enabled by default');
    $settings['desktop_clients']=[];
    $settings['updates']=UpdatePolicy::form(['channel'=>'stable','pinned_version'=>'1.2.3','window_start'=>'22:00','window_end'=>'04:00','rollout_delay_hours'=>'48'],[]);
    $check($call('validateConfigSchema',$settings) === [], 'Valid update policy rejected by config schema');
    $restored=$call('validateNativeBackupConfig',json_encode($settings, JSON_THROW_ON_ERROR));
    $check($restored['updates'] === $settings['updates'], 'Backup import lost update policy');
    $check(UpdatePolicy::form([], $settings['updates']) === $settings['updates'], 'Omitted form fields reset policy');
    foreach ([['channel'=>'nightly'],['pinned_version'=>'../file'],['channel'=>'stable','pinned_version'=>'1.2.3-beta'],
        ['rollout_delay_hours'=>'1'],['rollout_delay_hours'=>true],['rollout_delay_hours'=>169],
        ['window_start'=>'01:45','window_end'=>'02:15'],['window_start'=>'24:00'],['window_end'=>'']] as $bad) {
        $invalid=$settings; $invalid['updates']=array_replace($settings['updates'],$bad);
        $check($call('validateConfigSchema',$invalid)!==[], 'Invalid imported policy accepted');
    }
    foreach ([true,1.5,'-1','01','169',[]] as $bad) {
        $failed=false;
        try { UpdatePolicy::form(['rollout_delay_hours'=>$bad],[]); } catch (\DomainException $e) { $failed=true; }
        $check($failed,'Invalid form delay accepted');
    }
    $patch=$call('validateAndNormalizeControlConfigPatch',['updates'=>['channel'=>'stable','pinned_version'=>'1.2.3','rollout_delay_hours'=>24,'window_start'=>'22:00','window_end'=>'04:00']]);
    $check($patch['errors']===[], 'API does not accept typed update fields');
    $check($call('validateAndNormalizeControlConfigPatch',['updates'=>['rollout_delay_hours'=>'24']])['errors']!==[], 'API coerced delay');
    echo "Update policy form, API and backup validation: $checks checks passed.\n";
}
