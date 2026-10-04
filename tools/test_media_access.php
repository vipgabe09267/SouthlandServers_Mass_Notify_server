<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/media-policy.php';
use SLS\MassNotify\MediaAccess;

$dir = sys_get_temp_dir() . '/sls-media-policy-' . bin2hex(random_bytes(8)); mkdir($dir, 0700);
$checks = 0;
function media_check($ok, string $message): void { global $checks; $checks++; if (!$ok) { throw new RuntimeException($message); } }
register_shutdown_function(static function () use ($dir): void {
    foreach (glob($dir . '/*') as $file) { unlink($file); } rmdir($dir);
});
$config = $dir . '/fixture.config'; $file = $dir . '/fixture.png'; $now = time();
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGxkAAAAASUVORK5CYII=');
file_put_contents($file, $png); chmod($file, 0644); touch($file, $now - 100);
$save = static function ($policy, array $extra = []) use ($config): void {
    file_put_contents($config, json_encode(['media_access' => $policy] + $extra, JSON_PRESERVE_ZERO_FRACTION)); chmod($config, 0640);
};
$server = ['REQUEST_METHOD'=>'GET', 'REMOTE_ADDR'=>'192.0.2.10'];
$read = static function ($name = 'fixture.png', $request = null, $time = null) use ($dir, $config, $server, $now): array {
    return MediaAccess::response($request ?? $server, $name, $config, $dir, $time ?? $now);
};
$save([]);
media_check(MediaAccess::normalize([]) === MediaAccess::defaults(), 'Legacy default is not unrestricted.');
media_check($read()['body'] === $png && $read()['status'] === 200, 'Default anonymous PNG download changed.');
media_check($read('fixture.png', ['REQUEST_METHOD'=>'HEAD'])['body'] === $png, 'HEAD cannot calculate exact body length.');
foreach ([null, false, 'bad', ['unknown'=>1], ['network_restricted'=>1], ['network_restricted'=>true], ['allowed_cidrs'=>['0.0.0.0/0']],
        ['allowed_cidrs'=>['::/0']], ['allowed_cidrs'=>['192.0.2.1/33']], ['allowed_cidrs'=>['2001:db8::/129']], ['allowed_cidrs'=>['example.org']],
        ['allowed_cidrs'=>[12]], ['allowed_cidrs'=>null], ['allowed_cidrs'=>array_fill(0, 33, '192.0.2.1')],
        ['max_age_minutes'=>1], ['max_age_minutes'=>1441], ['max_age_minutes'=>600.0], ['max_age_minutes'=>'60']] as $invalid) {
    try { MediaAccess::normalize($invalid); media_check(false, 'Invalid media policy accepted.'); } catch (DomainException $expected) { $checks++; }
    $save($invalid); media_check($read()['status'] === 503, 'Malformed live policy widened access.');
}
$save(['network_restricted'=>true, 'allowed_cidrs'=>['192.0.2.0/24', '2001:db8:abcd::/48']]);
media_check($read()['status'] === 200, 'Allowed IPv4 network denied.');
media_check($read('fixture.png', $server + ['HTTP_X_FORWARDED_FOR'=>'203.0.113.1'])['status'] === 200, 'Untrusted headers changed direct identity.');
$denied = ['REQUEST_METHOD'=>'GET', 'REMOTE_ADDR'=>'203.0.113.1', 'HTTP_X_FORWARDED_FOR'=>'192.0.2.1', 'HTTP_X_FORWARDED_PROTO'=>'https'];
media_check($read('fixture.png', $denied)['status'] === 403, 'An untrusted proxy bypassed media network limits.');
media_check($read('fixture.png', ['REQUEST_METHOD'=>'GET', 'REMOTE_ADDR'=>'2001:db8:abcd::5'])['status'] === 200, 'Allowed IPv6 network denied.');
media_check($read('fixture.png', ['REQUEST_METHOD'=>'GET', 'REMOTE_ADDR'=>'::ffff:192.0.2.10'])['status'] === 403, 'IPv4-mapped IPv6 silently widened IPv4 rules.');
$save(['network_restricted'=>true, 'allowed_cidrs'=>['192.0.2.0/24']], ['api_network'=>['trusted_proxy_cidrs'=>['203.0.113.1']]]);
media_check($read('fixture.png', $denied)['status'] === 200, 'Explicit trusted proxy cannot reach allowed network.');
$denied['HTTP_X_FORWARDED_FOR'] = '192.0.2.1, 198.51.100.1';
media_check($read('fixture.png', $denied)['status'] === 403, 'A client-supplied first forwarded address bypassed the chain.');
unset($denied['HTTP_X_FORWARDED_PROTO']); media_check($read('fixture.png', $denied)['status'] === 403, 'Malformed trusted proxy metadata accepted.');
$save(['max_age_minutes'=>10]);
media_check($read()['status'] === 200 && $read('fixture.png', null, $now + 499)['status'] === 200, 'Media expired too early.');
media_check($read('fixture.png', null, $now + 500)['status'] === 410, 'Exact expiry extended by repeated downloads.');
media_check(filemtime($file) === $now - 100, 'Reading extended media lifetime.');
media_check($read('fixture.png', null, $now - 200)['status'] === 503, 'A backward clock disabled expiry.');
foreach (['../fixture.png', '/fixture.png', 'fixture.png/extra', 'fixture.png?x=1', "fixture.png\0", 'fixture.png' . "\n", 'file.php', 'file.PNG', null, [], str_repeat('x',181) . '.png'] as $invalid) {
    media_check($read($invalid)['status'] === 404, 'An unsafe filename was served.');
}
$save([]);
symlink($file, $dir . '/link.png'); media_check($read('link.png')['status'] === 404, 'Media symlink followed.');
link($file, $dir . '/hard.png'); media_check($read('hard.png')['status'] === 404 && $read()['status'] === 404, 'Media hard link served.'); unlink($dir . '/hard.png');
posix_mkfifo($dir . '/pipe.png', 0600); media_check($read('pipe.png')['status'] === 404, 'FIFO media served.');
file_put_contents($dir . '/spoof.png', '<html>not a PNG</html>'); media_check($read('spoof.png')['status'] === 404, 'MIME spoof served.');
file_put_contents($dir . '/huge.png', str_repeat('a', 5 * 1024 * 1024 + 1)); media_check($read('huge.png')['status'] === 404, 'Oversize image served.');
file_put_contents($dir . '/fixture.xml', '<?xml version="1.0"?><Text>Fixture</Text>');
media_check($read('fixture.xml')['type'] === 'text/xml; charset=utf-8', 'Phone XML compatibility changed.');
$locked = fopen($file, 'r+'); flock($locked, LOCK_EX); media_check($read()['status'] === 404, 'A locked media write was read.'); fclose($locked);
chmod($file, 0666); media_check($read()['status'] === 404, 'World-writable media served.'); chmod($file, 0644);
chmod($file, 0664); media_check($read()['status'] === 404, 'Group-writable media served.'); chmod($file, 0644);
chmod($config, 0644); media_check($read()['status'] === 503, 'Insecure config permissions ignored.'); chmod($config, 0640);
file_put_contents($config, str_repeat(' ', 2 * 1024 * 1024 + 1)); media_check($read()['status'] === 503, 'Unbounded configuration parsed.');
file_put_contents($config, '{"media_access":'); media_check($read()['status'] === 503, 'Invalid JSON policy fell back to open access.');
unlink($config); symlink($dir . '/fixture.xml', $config); media_check($read()['status'] === 503, 'Configuration symlink followed.'); unlink($config);
media_check($read()['status'] === 503, 'Missing config fell back to open access.');
media_check($read('fixture.png', ['REQUEST_METHOD'=>'POST'])['status'] === 405, 'POST downloaded media.');
$form = MediaAccess::form(['network_restricted'=>'1', 'allowed_cidrs'=>"192.0.2.0/24\n2001:db8::/32", 'max_age_minutes'=>'60']);
media_check($form === ['network_restricted'=>true, 'allowed_cidrs'=>['192.0.2.0/24', '2001:db8::/32'], 'max_age_minutes'=>60], 'Form values changed meaning.');
foreach ([[], ['network_restricted'=>'true', 'allowed_cidrs'=>'', 'max_age_minutes'=>'0'], ['network_restricted'=>'0', 'allowed_cidrs'=>[], 'max_age_minutes'=>'0'],
        ['network_restricted'=>'0', 'allowed_cidrs'=>'', 'max_age_minutes'=>'6e2'], ['network_restricted'=>'0', 'allowed_cidrs'=>str_repeat('x',4097), 'max_age_minutes'=>'0']] as $invalid) {
    try { MediaAccess::form($invalid); media_check(false, 'Malformed form accepted.'); } catch (DomainException $expected) { $checks++; }
}
echo "Generated-media policy: $checks configuration, file safety, network, proxy, expiry and compatibility checks passed.\n";
