<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once __DIR__.'/../slsmassnotifyserver/Slsmassnotifyserver.class.php';
use SLS\MassNotify\{IncidentStore,LocationDirectory};

function load_view(string $path,array $parameters):string
{
    return json_encode($parameters,JSON_THROW_ON_ERROR);
}
final class IncidentEditorDirectoryFixture
{
    use \FreePBX\modules\SlsIncidents;
    public const HERO_IMAGE='';
    public array $active=[];
    public ?array $pending=null;
    public string $storage;
    private function getActiveSettings():array{return $this->active;}
    private function getPendingSettings():?array{return $this->pending;}
    protected function incidentStore():IncidentStore{return new IncidentStore($this->storage);}
    private function getExtensionNameMap():array{return [];}
    private function getDesktopClients($settings):array{return [];}
    private function getAnnouncementGroups():array{return [];}
    private function outboundVoiceTargets($settings):array{return [];}
    private function normalizeWebhookDestinations($rows,$kind):array{return [];}
    private function announcementEmailTargets($settings):array{return [];}
    private function getAvailableTones():array{return [];}
    private function getCsrfToken():string{return 'private-token';}
}
$root=sys_get_temp_dir().'/sls-incident-editor-'.bin2hex(random_bytes(8));mkdir($root,0700);
register_shutdown_function(static function()use($root):void{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){
        $file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());
    }rmdir($root);
});
$fixture=new IncidentEditorDirectoryFixture;$fixture->storage=$root.'/incidents';
$site=['id'=>LocationDirectory::DEFAULT_SITE_ID,'type'=>'site','name'=>'Private site'];
$nodes=[$site];
for($index=1;$index<500;$index++){
    $nodes[]=['id'=>'loc_'.sprintf('%024x',$index+1000),'type'=>'building','name'=>'Building '.$index,'parent_id'=>$site['id']];
}
$fixture->active=['location_directory'=>['nodes'=>$nodes],'announcement_sms'=>[]];
$original=$fixture->active;
$report=json_decode($fixture->renderIncidentsPage(),true,64,JSON_THROW_ON_ERROR);
if(count($report['choices']['locations'])!==500||$fixture->active!==$original||!$report['listing']['success']){
    throw new RuntimeException('Large incident directory lost nodes, mutated configuration or failed rendering.');
}
$locations=array_column($report['choices']['locations'],'name','id');
if($locations[$site['id']]!=='Private site'||$locations['loc_'.sprintf('%024x',1499)]!=='Private site / Building 499'){
    throw new RuntimeException('Incident directory labels lost their hierarchy.');
}
$nodes[0]['name']='Pending site';$fixture->pending=['location_directory'=>['nodes'=>$nodes]];
$report=json_decode($fixture->renderIncidentsPage(),true,64,JSON_THROW_ON_ERROR);
$locations=array_column($report['choices']['locations'],'name','id');
if($locations['loc_'.sprintf('%024x',1499)]!=='Pending site / Building 499'||$fixture->active!==$original){
    throw new RuntimeException('Pending incident directory adopted stale active labels or altered active settings.');
}
echo "Actual incident editor:500 hierarchical locations, pending labels, empty history and unchanged active configuration passed; no transport used.\n";
