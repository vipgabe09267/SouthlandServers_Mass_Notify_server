<?php
declare(strict_types=1);
interface BMO {}
$_SERVER['HTTP_HOST'] = 'pbx.example.test';
$moduleDirectory = $argv[1] ?? dirname(__DIR__) . '/slsmassnotifyserver';
require $moduleDirectory . '/Slsmassnotifyserver.class.php';
$reflection = new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class);
$module = $reflection->newInstanceWithoutConstructor();
$normalizer = $reflection->getMethod('normalizeSipNotifySettings');
$normalizer->setAccessible(true);
$normalize = static function ($url, $scheme = 'https') use ($normalizer, $module) {
    return $normalizer->invoke($module, [
        'pbx_host' => 'pbx.example.test', 'media_scheme' => $scheme,
        'media_base_url' => $url, 'format_overrides' => [], 'device_format_overrides' => [],
    ]);
};
$checks = 0;
$assert = static function ($condition) use (&$checks) {
    $checks++;
    if (!$condition) { throw new RuntimeException('Phone media URL regression failed.'); }
};
foreach ([1, 80, 443, 8443, 9443, 65535] as $port) {
    $url = 'https://pbx.example.test:' . $port . '/sls_mass_notify';
    $value = $normalize($url);
    $assert($value['media_base_url'] === $url);
    $assert($normalizer->invoke($module, $value) === $value);
    $assert($value['base_url'] === 'https://pbx.example.test/api/sipnotify');
}
foreach (['http', 'https'] as $scheme) {
    $assert($normalize('', $scheme)['media_base_url'] === $scheme . '://pbx.example.test/sls_mass_notify');
}
foreach ([
    'https://other.example.test:8443/sls_mass_notify',
    'https://pbx.example.test.evil:8443/sls_mass_notify',
    'https://user@pbx.example.test:8443/sls_mass_notify',
    'https://pbx.example.test:0/sls_mass_notify',
    'https://pbx.example.test:65536/sls_mass_notify',
    'https://pbx.example.test:8443/admin',
    'https://pbx.example.test:8443/sls_mass_notify?x=1',
    'https://pbx.example.test:8443/sls_mass_notify#fragment',
    "https://pbx.example.test:8443/sls_mass_notify\n",
    ['invalid'],
] as $url) {
    $assert($normalize($url)['media_base_url'] === 'https://pbx.example.test/sls_mass_notify');
}
$assert($normalize('HTTP://PBX.EXAMPLE.TEST:09443/sls_mass_notify/')['media_base_url'] === 'https://pbx.example.test:9443/sls_mass_notify');
echo "Phone media URL normalization: $checks checks passed.\n";
