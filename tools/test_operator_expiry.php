<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
require_once dirname(__DIR__).'/slsmassnotifyserver/OperatorPortal.php';
use SLS\MassNotify\{OperatorAccess as Access, OperatorAuth as Auth, OperatorPortal as Portal, ApiSecurity, EnterpriseIdentity};
$checks = 0;
function expiryCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) { throw new RuntimeException($message); } }
function expiryReject(callable $work): void { try { $work(); } catch (DomainException $expected) { expiryCheck(true, 'Rejected'); return; } throw new RuntimeException('Invalid expiry accepted'); }
$root = sys_get_temp_dir().'/sls-operator-expiry-'.bin2hex(random_bytes(8)); mkdir($root,0700); define('SLS_EXPIRY_FIXTURE',$root);
class ExpiryFixture {
    private function normalizeSettings(array $settings): array { return $settings; }
    use \FreePBX\modules\SlsOperators;
    const SETTINGS_JSON = SLS_EXPIRY_FIXTURE.'/settings.config';
    const PENDING_SETTINGS_JSON = SLS_EXPIRY_FIXTURE.'/pending.config';
    public array $settings = [];
    public function getActiveSettings(): array { return $this->settings; }
    public function currentOperator(): array { return ['id'=>'recovery_admin','username'=>'owner','operator_role'=>'administrator']; }
    public function operatorDirectory(): array { return ['accounts'=>[],'warnings'=>[]]; }
    public function getPbxDateTimeZone(): DateTimeZone { return new DateTimeZone('America/Chicago'); }
    public function locationCatalog($settings): array { return array_fill_keys(array_keys(\SLS\MassNotify\LocationDirectory::MEMBERS), []); }
    private function acquireSettingsLock(...$args) { return null; }
    private function releaseSettingsLock($lock): void {}
    private function loadSettingsFile($path): array { return $this->settings; }
    private function writeSettingsFileUnlocked($path,$settings,$active): void { $this->settings=$settings; }
    protected function commitOperatorConfigFiles(array $active, ?array $pending): void { $this->writeSettingsFileUnlocked(self::SETTINGS_JSON,$active,true); }
    private function resolveScheduleLocalDateTime($value, DateTimeZone $zone): array {
        $class=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
        return $class->getMethod('resolveScheduleLocalDateTime')->invoke($class->newInstanceWithoutConstructor(),$value,$zone);
    }
    public function save(array $account): array {
        return $this->saveOperatorAccess(['revision'=>$this->operatorRevision($this->settings),'enabled'=>false,'portal_enabled'=>true,'accounts'=>[$account]]);
    }
}
try {
    $settings=['enabled'=>'1','desktop_auth_key'=>base64_encode(str_repeat('k',32)),
        'public_pbx_host'=>'pbx.example.test','sipnotify'=>['base_url'=>'https://pbx.example.test/api/sipnotify'],
        'control_api'=>['base_url'=>'https://pbx.example.test/api/sls-mass-notify']];
    $id='api_'.str_repeat('a',24);$secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $auth=Auth::create('dedicated fixture password 248');$auth['force_password_change']=false;
    $auth['totp_secret_enc']=Auth::seal($secret,$id,$settings);
    $account=['id'=>$id,'username'=>'fixture','name'=>'Fixture','email'=>'','source'=>'portal','role'=>'administrator','enabled'=>true,
        'site_ids'=>[],'group_ids'=>[],'auth'=>$auth];$account['identity']=Auth::identity($account);
    $access=Access::normalize(['enabled'=>false,'portal_enabled'=>true,'accounts'=>[$account]]);
    expiryCheck($access['accounts'][0]['expires_at']===0,'Existing accounts did not default to no expiry');
    $settings['operator_access']=$access;$now=time();
    foreach ([0,$now+1,253402300799] as $expiry) {
        $copy=$settings;$copy['operator_access']['accounts'][0]['expires_at']=$expiry;
        expiryCheck(Access::principal($copy,$id,$now)!==null,'Unexpired account lost permissions');
    }
    foreach ([1,$now-1,$now] as $expiry) {
        $copy=$settings;$copy['operator_access']['accounts'][0]['expires_at']=$expiry;
        expiryCheck(Access::normalize($copy['operator_access'])['accounts'][0]['expires_at']===$expiry,'Past expiry was removed or rejected');
        expiryCheck(Access::principal($copy,$id,$now)===null,'Expired account retained central authority');
        expiryCheck(ApiSecurity::currentCredential($copy,$id)===null,'Expired operator retained queued delivery authority');
    }
    foreach ([-1,253402300800,1.5,'123',true,null,[]] as $bad) {
        $copy=$access;$copy['accounts'][0]['expires_at']=$bad;expiryReject(fn()=>Access::normalize($copy));
    }
    $provider=['id'=>'idp_'.str_repeat('b',24),'vendor'=>'okta','protocol'=>'oidc','name'=>'Expiry fixture','enabled'=>true,
        'issuer'=>'https://idp.example.test','callback_url'=>'https://pbx.example.test/mass-notify/sso.php',
        'discovery_url'=>'https://idp.example.test/.well-known/openid-configuration','client_id'=>'inert-client','client_secret'=>'inert-secret',
        'endpoint_hosts'=>['idp.example.test']];
    $sso=$settings;$sso['enterprise_identity']=['enabled'=>true,'providers'=>[$provider],
        'grants'=>[['enabled'=>true,'provider_id'=>$provider['id'],'subject'=>'immutable-fixture','operator_account_id'=>$id]]];
    $proof=['provider_id'=>$provider['id'],'subject'=>'immutable-fixture','authenticated_at'=>$now,'expires_at'=>$now+300];
    $grant=EnterpriseIdentity::grant($sso,$proof);
    expiryCheck($grant['account']['id']===$id,'Fixture SSO grant did not resolve');
    $sso['operator_access']['accounts'][0]['expires_at']=$now-1;
    expiryReject(fn()=>EnterpriseIdentity::grant($sso,$proof));
    expiryCheck(!EnterpriseIdentity::current($sso,$grant['binding'],$id,$now),'Federated session survived local account expiry');
    $principal=Access::principal($settings,$id);$principal['expires_at']=$now-1;
    expiryCheck(!Access::may($principal,'send'),'A stale administrator principal could send after expiry');
    expiryCheck(!Access::delivery($principal,[], $settings),'A stale administrator principal retained unrestricted recipients');
    expiryCheck(!Access::person($principal,[], $settings,[]),'A stale administrator principal retained human-response permissions');
    $expired=$access['accounts'][0];$expired['expires_at']=$now;
    expiryCheck(!Portal::current(Portal::binding($expired,$now-1),$expired,1800,$now),'Existing session survived exact account expiry');
    $fixture=new ExpiryFixture();$fixture->settings=$settings;
    $editor=Auth::publicAccount($access['accounts'][0]);foreach (['totp_enrolled','recovery_remaining','password_change_required','password_recovery'] as $key) { unset($editor[$key]); }
    $editor['expires_local']='2030-06-01T09:15';
    $result=$fixture->save($editor);expiryCheck($result['success'],'Valid PBX-local expiry did not save: '.($result['message']??''));
    $saved=$fixture->settings['operator_access']['accounts'][0];
    expiryCheck($saved['expires_at']===strtotime('2030-06-01T14:15:00Z'),'PBX-local expiry was interpreted in browser or UTC timezone');
    expiryCheck(!isset($saved['expires_local'])&&!isset($saved['access_expired']),'Presentation values entered configuration');
    expiryCheck(!hash_equals($saved['identity'],$account['identity']),'Changing expiry revived existing sessions');
    $state=$fixture->operatorAccessState();
    expiryCheck($state['timezone']==='America/Chicago'&&$state['access']['accounts'][0]['expires_local']==='2030-06-01T09:15','Editor did not project the PBX timezone');
    $before=$fixture->settings;
    foreach (['2026-03-08T02:30','2026-11-01T01:30','2030-02-30T09:15','2030-06-01T24:15','2030-06-01T09:15Z','2030-06-01T09:15:30'] as $bad) {
        $editor['expires_local']=$bad;$result=$fixture->save($editor);
        expiryCheck(!$result['success'],'Invalid or ambiguous local expiry accepted: '.$bad);
        expiryCheck($fixture->settings===$before,'Rejected expiry mutated settings');
    }
    $editor['expires_local']=[];expiryCheck(!$fixture->save($editor)['success'],'Array local expiry accepted');
    $editor['expires_local']='2001-01-01T00:00';expiryCheck($fixture->save($editor)['success'],'Administrator could not retain an already expired account');
    $expired=$fixture->settings['operator_access']['accounts'][0];
    expiryCheck($fixture->portalAccount('fixture')===null,'Expired account could authenticate its password');
    expiryReject(fn()=>$fixture->operatorAuthUpdate($id,$expired['identity'],'totp',Auth::code($secret,intdiv(time(),30))));
    expiryCheck($fixture->currentOperator()['id']==='recovery_admin','PBX administrator lost recovery access');
    $editor['expires_local']='';expiryCheck($fixture->save($editor)['success'],'Administrator could not clear expiry');
    expiryCheck($fixture->settings['operator_access']['accounts'][0]['expires_at']===0&&$fixture->portalAccount('fixture')!==null,'Clearing expiry failed to restore a ready account');
    expiryCheck(!hash_equals($expired['identity'],$fixture->portalAccount('fixture')['identity']),'Clearing expiry revived a pre-expiry session');
    echo "$checks operator expiry checks passed.\n";
} finally { rmdir($root); }
