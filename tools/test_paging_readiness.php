<?php
declare(strict_types=1);

// Render and execute the real view's display-only readiness calculations.
function load_view(string $path, array $variables = []): string { return ''; }
$paging = ['groups'=>[['group_id'=>'paging_fixture','name'=>'Staff','menu_number'=>1,'extensions'=>['1000','1001','1002'],
    'notify_extensions'=>[],'allowed_callers'=>['1000'],'require_pin'=>'0']], 'allowed_callers'=>[]];
$available_extensions = [['extension'=>'1000','name'=>'Office']]; $announcement_groups=[];
$phone_inventory = ['available'=>true,'observed_at'=>gmdate('c'),'expires_at'=>time()+90,'endpoints'=>[
    '1000'=>['contacts'=>[['format'=>'yealink','contact_uri'=>'sip:private@example.invalid','device_key'=>'private-key'],['format'=>'cisco']]],
    '1001'=>['contacts'=>[['format'=>'unknown','user_agent'=>'<script>private</script>']]],
    '1002'=>['contacts'=>[]]]];
ob_start(); include dirname(__DIR__).'/slsmassnotifyserver/views/paging.php'; $view=(string)ob_get_clean();
if (str_contains($view,'private@example.invalid') || str_contains($view,'private-key') || str_contains($view,'<script>private</script>')) {
    throw new RuntimeException('Paging readiness exposed raw contact identity or device metadata.');
}
if (!preg_match('/var readinessInventory = (.*?);\s*var labels/s', $view, $match)) { throw new RuntimeException('Cached readiness projection is missing.'); }
$inventory = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR);
if ($inventory['endpoints']['1000']['contacts'] !== 2 || count($inventory['endpoints']['1000']['formats']) !== 2) {
    throw new RuntimeException('Mixed-device readiness lost registered contacts.');
}
$start=strpos($view,'function readinessForRecipients(values) {'); $end=strpos($view,'function renderReadiness(', $start);
if ($start===false || $end===false) { throw new RuntimeException('Paging readiness calculation is missing.'); }
$program='const assert=require("assert"); const vm=require("vm"); let readinessInventory='.json_encode($inventory, JSON_THROW_ON_ERROR).';'
    .'let now=Date.now(); Date.now=()=>now;'.substr($view,$start,$end-$start).<<<'JS'
const check=readinessForRecipients(['1000','1001','1002','1000']);
assert.deepStrictEqual(check,{available:true,configured:3,registered:2,contacts:3,formats:['Cisco','Yealink'],unknown:1,unavailable:['1002']});
assert.equal(readinessForRecipients([]).contacts,0);
now+=91000;
let expired=readinessForRecipients(['1000']);
assert.equal(expired.available,false); assert.equal(expired.registered,0); assert.deepStrictEqual(expired.unavailable,[]);
readinessInventory.available=false; readinessInventory.expires_at=now/1000+90;
assert.equal(readinessForRecipients(['1000']).available,false);
readinessInventory.available=true; readinessInventory.endpoints['1000'].contacts=0;
assert.deepStrictEqual(readinessForRecipients(['1000']).unavailable,['1000']);
JS;
preg_match_all('#<script[^>]*>(.*?)</script>#s', $view, $scripts);
foreach ($scripts[1] as $script) { if (trim($script)!=='') { $program.='new vm.Script('.json_encode($script, JSON_THROW_ON_ERROR).');'; } }
$process=proc_open(['/usr/bin/node','-e',$program],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if (!is_resource($process)) { throw new RuntimeException('Node fixture could not start.'); }
$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
if (proc_close($process)!==0) { throw new RuntimeException($output); }
echo "Paging readiness: cached mixed contacts, scope counts, stale/empty evidence, private projection and actual view JavaScript passed.\n";
