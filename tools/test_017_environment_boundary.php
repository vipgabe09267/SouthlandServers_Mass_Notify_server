<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/PhoneEventService.php';
class RuntimeEnvironmentFixture {
    use FreePBX\modules\SlsPhoneEventService;
    public function probe(string $path): array { return $this->phoneCollectorRunHealthProbe($path,'/usr/sbin/runuser -u asterisk -- '); }
}
$root=sys_get_temp_dir().'/sls-runtime-environment-'.bin2hex(random_bytes(8));mkdir($root,0755);chmod($root,0755);
$path=$root.'/probe.py';
file_put_contents($path,"import json,os\nprint(json.dumps({'token_present':any(os.getenv(name) for name in ('GITHUB_TOKEN','GH_TOKEN','SLS_MASS_NOTIFY_GITHUB_TOKEN'))}))\n");chmod($path,0644);
try {
    foreach (['GITHUB_TOKEN','GH_TOKEN','SLS_MASS_NOTIFY_GITHUB_TOKEN'] as $name) { putenv($name.'=SLS_PRIVATE_FIXTURE_VALUE'); }
    $result=(new RuntimeEnvironmentFixture())->probe($path);
    if ($result['status']!==0 || ($result['result']['token_present'] ?? null)!==false) {
        throw new RuntimeException('Root health probe passed download credentials across its runtime-account boundary.');
    }
} finally {
    foreach (['GITHUB_TOKEN','GH_TOKEN','SLS_MASS_NOTIFY_GITHUB_TOKEN'] as $name) { putenv($name); }
    unlink($path);rmdir($root);
}
echo "Actual PHP collector probe clears all supported download tokens before dropping privilege.\n";
