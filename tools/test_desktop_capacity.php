<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/DesktopCapacity.php';
class CapacityFixture {
    use \FreePBX\modules\SlsDesktopCapacity;
    public $calls = 0;
    public $result = ['eligible_limit' => 100, 'eligible_phone_limit' => 50, 'errors' => []];
    public $lastLimits = [];
    protected function checkDesktopCapacityResources($limit, $phoneLimit = 25) { $this->calls++; $this->lastLimits = [$limit, $phoneLimit]; return $this->result; }
    public function validate(array $settings) { return $this->validateDesktopCapacityConfig($settings); }
    public function save($limit, $count, $old = 50, $phones = 25, $oldPhones = 25) {
        $this->assertDesktopCapacityChange(['desktop_client_limit' => $limit, 'desktop_clients' => array_fill(0, $count, []), 'phone_device_limit' => $phones], ['desktop_client_limit' => $old, 'phone_device_limit' => $oldPhones]);
    }
}
$fixture = new CapacityFixture();
if ($fixture->validate(['desktop_clients' => array_fill(0, 25, [])])
    || !$fixture->validate(['desktop_clients' => array_fill(0, 26, [])])
    || $fixture->validate(['desktop_client_limit' => 50, 'desktop_clients' => array_fill(0, 50, [])])) {
    throw new RuntimeException('Default25 admission or explicit existing capacity was not preserved.');
}
$fixture->save(50, 50); $fixture->save(25, 25);
if ($fixture->calls !== 0) { throw new RuntimeException('Unchanged or reduced capacity probed resources.'); }
$fixture->save(100, 60);
if ($fixture->calls !== 1) { throw new RuntimeException('Increased capacity bypassed resource check.'); }
foreach ([[50, 51], [1001, 0], [0, 0], ['100', 0], [250, 60]] as [$limit, $count]) {
    try { $fixture->save($limit, $count); throw new RuntimeException('Invalid desktop capacity was accepted.'); }
    catch (DomainException $expected) {}
}
$fixture->save(50, 50, 50, 50);
if ($fixture->lastLimits !== [50, 50]) { throw new RuntimeException('Phone increase did not check combined resources.'); }
$before = $fixture->calls;
$fixture->save(50, 50, 50, 25, 50);
if ($fixture->calls !== $before) { throw new RuntimeException('Phone reduction probed resources.'); }
foreach ([0, 1001, '25', true] as $invalid) {
    try { $fixture->save(50, 0, 50, $invalid); throw new RuntimeException('Invalid phone capacity was accepted.'); }
    catch (DomainException $expected) {}
}
try { $fixture->save(50, 0, 50, 51); throw new RuntimeException('Ineligible phone capacity was accepted.'); }
catch (DomainException $expected) {}
// Trading desktop slots for phone slots still requires a combined resource check.
$fixture->save(25, 25, 50, 50, 25);
if ($fixture->lastLimits !== [25, 50]) { throw new RuntimeException('Mixed capacity changes bypassed combined resources.'); }
$fixture->result = ['eligible_limit' => 0, 'errors' => [['message' => 'Memory: 4 GiB available; 12 GiB required.']]];
try { $fixture->save(100, 50); throw new RuntimeException('Insufficient memory was accepted.'); }
catch (DomainException $error) {
    if (strpos($error->getMessage(), '12 GiB required') === false) { throw new RuntimeException('Resource error detail lost.'); }
}
$fixture->save(100, 100, 100); // Existing enrollment survives a resource shortage.
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = $reflection->newInstanceWithoutConstructor();
$patch = $reflection->getMethod('validateAndNormalizeControlConfigPatch');
foreach ([0, -1, 1001, 100000, '100', true, []] as $invalid) {
    if (!$patch->invoke($module, ['phone_device_limit' => $invalid])['errors'] || !$patch->invoke($module, ['desktop_client_limit' => $invalid])['errors']) {
        throw new RuntimeException('Control API accepted invalid desktop capacity.');
    }
}
foreach ([1, 50, 1000] as $valid) {
    if ($patch->invoke($module, ['phone_device_limit' => $valid])['errors'] || $patch->invoke($module, ['desktop_client_limit' => $valid])['errors']) {
        throw new RuntimeException('Control API rejected a valid desktop capacity.');
    }
}
foreach (['desktop_client_limit', 'phone_device_limit'] as $field) {
    foreach ([0, 1001, '25', true] as $invalid) {
        try { $module->restore(['settings'=>[$field=>$invalid]]); throw new RuntimeException('Legacy restore silently normalized invalid capacity.'); }
        catch (DomainException $expected) {}
    }
}
$normalize = $reflection->getMethod('normalizeDesktopClients');
$clients = [];
for ($index = 0; $index < 1000; $index++) {
    $clients[] = ['id' => 'desk_' . $index, 'client_id' => 'cli_' . $index, 'username' => 'desktop' . $index,
        'name' => 'Fixture', 'password' => 'fixture-only-password', 'enabled' => '1'];
}
$normalized = $normalize->invoke($module, $clients, ['desktop_auth_key' => base64_encode(random_bytes(32)), 'desktop_client_limit' => 1000]);
if (count($normalized) !== 1000 || $normalized[999]['client_id'] !== 'cli_999') {
    throw new RuntimeException('Loading a large enrolled fleet discarded clients or changed identifiers.');
}
echo "Desktop capacity admission, preservation, bounds and specific errors passed.\n";

// Run the real PHP subprocess bridge against inert private Python fixtures.
$capacityDirectory = sys_get_temp_dir() . '/sls-capacity-report-' . bin2hex(random_bytes(8));
mkdir($capacityDirectory, 0700); define('SLS_CAPACITY_FIXTURE_DIRECTORY', $capacityDirectory);
class CapacityReportFixture {
    use \FreePBX\modules\SlsDesktopCapacity;
    const RUNTIME_DIR = SLS_CAPACITY_FIXTURE_DIRECTORY;
    public function probe(): array { return $this->checkDesktopCapacityResources(25, 25); }
}
$probe = new CapacityReportFixture(); $helper = $capacityDirectory . '/sls_resource_capacity.py';
try {
    $report = ['schema' => 1, 'eligible_limit' => 0, 'errors' => [['code' => 'storage_directory_unreadable', 'message' => 'The PBX account cannot read the runtime cache. Run Repair Installation.']]];
    file_put_contents($helper, 'import sys' . "\nprint(" . var_export(json_encode($report), true) . ")\nsys.exit(3)\n");
    if ($probe->probe() !== $report) { throw new RuntimeException('Structured exit-3 resource diagnostic was hidden.'); }
    $report = ['schema' => 1, 'eligible_limit' => 1000, 'eligible_phone_limit' => 1000, 'errors' => 'malformed'];
    file_put_contents($helper, "print(" . var_export(json_encode($report), true) . ")\n");
    if (strpos($probe->probe()['errors'][0]['message'], 'valid report') === false) { throw new RuntimeException('Malformed schema-1 capacity output was accepted.'); }
    file_put_contents($helper, "print('not-json')\n");
    if (strpos($probe->probe()['errors'][0]['message'], 'code 0') === false) { throw new RuntimeException('Invalid resource output omitted the exit code.'); }
    file_put_contents($helper, "print('x' * 1100000)\n");
    if (strpos($probe->probe()['errors'][0]['message'], 'bounded response limit') === false) { throw new RuntimeException('Oversized resource output was buffered or reported generically.'); }
    file_put_contents($helper, "import os\nwhile True: os.write(1,b'x'*8192)\n");
    $started=microtime(true);$unbounded=$probe->probe();$elapsed=microtime(true)-$started;
    if (strpos($unbounded['errors'][0]['message'], 'bounded response limit') === false || $elapsed>3) { throw new RuntimeException('Unbounded resource output was not stopped at the response limit.'); }
    file_put_contents($helper, "import sys\nsys.stderr.write('x' * 70000)\n");
    if (strpos($probe->probe()['errors'][0]['message'], 'bounded response limit') === false) { throw new RuntimeException('Oversized resource diagnostics were allowed to fill the request.'); }
    file_put_contents($helper, "import time\ntime.sleep(9)\n");
    if (strpos($probe->probe()['errors'][0]['message'], 'five-second deadline') === false) { throw new RuntimeException('Resource timeout omitted its deadline.'); }
    chmod($helper,0666);
    if (strpos($probe->probe()['errors'][0]['message'], 'checker is unavailable') === false) { throw new RuntimeException('Writable capacity helper was executed.'); }
} finally { unlink($helper); rmdir($capacityDirectory); }
echo "Actual capacity subprocess bridge preserves actionable probe errors and reports malformed output/timeouts.\n";

$fixture->result=['schema'=>1,'eligible_limit'=>100,'eligible_phone_limit'=>50,
    'requirements'=>['cpu_count'=>5,'memory_bytes'=>9*1073741824,'sls_persistent_free_bytes'=>123456,'temporary_free_bytes'=>234567],
    'hardware'=>['effective_cpu_count'=>4,'effective_memory_bytes'=>8*1073741824],
    'resource_checks'=>['cpu'=>['ok'=>false,'actual'=>4,'required'=>5], 'memory'=>['ok'=>true,'actual'=>8*1073741824,'required'=>8*1073741824]],
    'errors'=>[['message'=>'5 effective CPU cores required; 4 allocated.']]];
$preview=$fixture->previewDeviceCapacity(['desktop_client_limit'=>'25','phone_device_limit'=>'26']);
if (!$preview['success'] || $fixture->lastLimits!==[25,26] || $preview['requirements']!==$fixture->result['requirements']
    || $preview['errors']!==['5 effective CPU cores required; 4 allocated.']
    || $preview['resource_checks']!==$fixture->result['resource_checks']) {
    throw new RuntimeException('UI preview did not use the same combined admission checker and actionable resource report.');
}
$before=$fixture->calls;
foreach ([[],['desktop_client_limit'=>true,'phone_device_limit'=>'25'],['desktop_client_limit'=>'25','phone_device_limit'=>[]],
    ['desktop_client_limit'=>'25','phone_device_limit'=>'1001']] as $invalid) {
    if ($fixture->previewDeviceCapacity($invalid)['success']) { throw new RuntimeException('Invalid capacity preview input was accepted.'); }
}
if ($fixture->calls!==$before) { throw new RuntimeException('Invalid preview input invoked a resource scan.'); }
$fixture->result=['schema'=>1,'requirements'=>[],'errors'=>[['message'=>'The storage probe could not finish.']]];
$preview=$fixture->previewDeviceCapacity(['desktop_client_limit'=>'25','phone_device_limit'=>'25']);
if ($preview['success']||$preview['message']!=='The storage probe could not finish.') { throw new RuntimeException('Failed resource probe produced a positive UI report.'); }
$fixture->result=['schema'=>1,'eligible_limit'=>25,'eligible_phone_limit'=>25,
    'requirements'=>['cpu_count'=>2,'memory_bytes'=>1],'hardware'=>['effective_cpu_count'=>2.0,'effective_memory_bytes'=>1],
    'resource_checks'=>[],'errors'=>['malformed']];
$preview=$fixture->previewDeviceCapacity(['desktop_client_limit'=>'25','phone_device_limit'=>'25']);
if ($preview['success']||!str_contains($preview['message'],'invalid report')) { throw new RuntimeException('Malformed resource diagnostics reached the capacity UI.'); }
$fixture->result=['schema'=>1,'requirements'=>[],'errors'=>[['message'=>['private'=>'value']]]];
$preview=$fixture->previewDeviceCapacity(['desktop_client_limit'=>'25','phone_device_limit'=>'25']);
if($preview['success']||!is_string($preview['message'])||str_contains($preview['message'],'Array')){throw new RuntimeException('Nested capacity diagnostic reached the UI or triggered unsafe coercion.');}
$fixture->result=['eligible_limit'=>0,'eligible_phone_limit'=>0,'errors'=>[['message'=>['private'=>'value']]]];
try{$fixture->save(100,50);throw new RuntimeException('Malformed resource shortage was accepted.');}
catch(DomainException $error){if(!str_contains($error->getMessage(),'invalid shortage details')){throw new RuntimeException('Malformed shortage did not produce the fixed recovery instruction.');}}
echo "Capacity counter uses the server admission report, preserves shortage details and rejects invalid input before probing.\n";
