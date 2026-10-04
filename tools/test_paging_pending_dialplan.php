<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/Paging.php';
class extension { public $arguments; public function __construct(...$arguments){$this->arguments=$arguments;} }
class ext_setvar extends extension {} class ext_hangup extends extension {} class ext_gosub extends extension {} class ext_return extends extension {}
class PagingGeneration {
    use \FreePBX\modules\SlsLivePaging;
    const RUNTIME_DIR='/fixture/runtime';
    public $active,$pending,$prepared;
    private function getActiveSettings(){return $this->active;}
    private function getPendingSettings(){return $this->pending;}
    private function validateLivePagingSelection($paging,$settings){}
    private function assertLivePagingExtensionAvailable($extension){}
    private function ensureLivePagingPrompts($settings){$this->prepared=$settings;}
}
class DialplanGeneration {
    public $entries=[],$includes=[];
    public function add($context,$extension,$label,$application){$this->entries[] = [$context,$extension,$application];}
    public function addInclude($context,$include){$this->includes[]=[$context,$include];}
}
$module=new PagingGeneration();
$module->active=['live_paging'=>array_replace(\SLS\MassNotify\LivePagingConfig::defaults(),['enabled'=>'0','extension'=>'799'])];
$module->pending=['live_paging'=>array_replace(\SLS\MassNotify\LivePagingConfig::defaults(),['enabled'=>'1','extension'=>'799','external_access'=>'1','external_ivr_ids'=>['2'], 'allowed_callers'=>['1000'], 'groups'=>[['group_id'=>'fixture_group','menu_number'=>1,'require_pin'=>'1','pin_length'=>4,'pin_hash'=>password_hash('1234',PASSWORD_DEFAULT)]]])];
$dialplan=new DialplanGeneration();$module->doDialplanHook($dialplan,'asterisk',500);
$pairs=array_map(static fn($row)=>array_slice($row,0,2),$dialplan->entries);
if (!in_array(['sls-live-paging','799'],$pairs,true) || !in_array(['ivr-2','799'],$pairs,true) || in_array(['ivr-1','799'],$pairs,true)
    || !in_array(['from-internal-additional','sls-live-paging'],$dialplan->includes,true) || $module->prepared!==$module->pending) { throw new RuntimeException('Pending paging settings did not generate the selected internal/IVR routes.'); }
$module->active=$module->pending;$module->pending=['live_paging'=>array_replace(\SLS\MassNotify\LivePagingConfig::defaults(),['enabled'=>'0'])];
$dialplan=new DialplanGeneration();$module->doDialplanHook($dialplan,'asterisk',500);
if ($dialplan->entries) { throw new RuntimeException('Pending disable kept the old active paging route.'); }
echo "Paging Apply Config: pending enable/disable, prompt revision and exact selected-IVR routing passed.\n";
