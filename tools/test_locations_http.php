<?php
declare(strict_types=1);
$root = dirname(__DIR__); $count = 0;
function locations_http_check(bool $condition, string $message): void { global $count; $count++; if (!$condition) { throw new RuntimeException($message); } }
$fixture = <<<'PHP'
$request=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$_SERVER['REQUEST_METHOD']='POST'; $_POST=$request;
class LocationHttpFixture {
    public function validateCsrfToken($value) { return is_string($value)&&hash_equals('fixture-csrf',$value); }
    public function __call($name,$args) { return ['success'=>true,'called'=>$name,'payload'=>$args[0]]; }
}
class FreePBX { public static function create() { return (object)['Slsmassnotifyserver'=>new LocationHttpFixture()]; } }
register_shutdown_function(static function(){fwrite(STDERR,':STATUS:'.(http_response_code()?:200));});
PHP;
$fixture .= 'require ' . var_export($root . '/slsmassnotifyserver/page.slsmassnotifyserver_locations.php', true) . ';';
$invoke = static function (array $request) use ($fixture): array {
    $process = proc_open([PHP_BINARY, '-n', '-r', $fixture], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Unable to start isolated controller fixture.'); }
    fwrite($pipes[0], json_encode($request, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    locations_http_check(proc_close($process) === 0, 'Controller fixture failed: ' . $err);
    locations_http_check(preg_match('/^:STATUS:([0-9]{3})$/D', $err, $match) === 1, 'Unexpected controller warning or output: ' . $err);
    return [(int)$match[1], json_decode($out, true, 16, JSON_THROW_ON_ERROR)];
};
foreach (['save_location' => 'saveLocation', 'delete_location' => 'deleteLocation', 'set_default_site' => 'setDefaultSite', 'save_audience' => 'saveLocationAudience', 'delete_audience' => 'deleteLocationAudience', 'save_recipients' => 'saveLocationRecipients', 'preview_location_audience' => 'previewLocationAudience', 'create_location_audience' => 'createLocationAudienceGroup', 'preview_geographic_audience' => 'previewGeographicAudience', 'create_geographic_audience' => 'createGeographicAudienceGroup'] as $action => $method) {
    $input = ['slsmassnotifyserver_action' => $action, 'payload' => json_encode(['revision' => 'fixture-revision'])];
    [$status, $result] = $invoke($input);
    locations_http_check($status === 403 && !$result['success'] && !isset($result['called']), 'CSRF failure reached a location operation.');
    [$status, $result] = $invoke($input + ['slsmassnotifyserver_csrf' => 'fixture-csrf']);
    locations_http_check($status === 200 && $result['called'] === $method && $result['payload']['revision'] === 'fixture-revision', 'Controller dispatched the wrong method or altered payload.');
}
foreach (['', '{bad', '[]', 'null', str_repeat('x', 786433), str_repeat('{"a":', 13) . '1' . str_repeat('}', 13), ['not text']] as $payload) {
    [$status, $result] = $invoke(['slsmassnotifyserver_action' => 'save_location', 'slsmassnotifyserver_csrf' => 'fixture-csrf', 'payload' => $payload]);
    locations_http_check($status === 400 && !$result['success'] && !isset($result['called']), 'Invalid request reached a location mutation.');
}
[$status, $result] = $invoke(['slsmassnotifyserver_action' => 'send_alert', 'slsmassnotifyserver_csrf' => 'fixture-csrf', 'payload' => '{"revision":"fixture"}']);
locations_http_check($status === 400 && !isset($result['called']), 'Unsupported action reached a location mutation.');

require_once $root . '/slsmassnotifyserver/LocationDirectory.php';
function load_view(string $path, array $data): string { extract($data); ob_start(); include $path; return ob_get_clean(); }
$malicious = '</script><img src=x onerror=alert(1)>';
$state = ['directory' => ['schema' => 1, 'nodes' => [['name' => $malicious]]], 'catalog' => [], 'revision' => 'fixture'];
$html = load_view($root . '/slsmassnotifyserver/views/locations.php', ['state' => $state, 'csrf_token' => 'token"<bad>', 'hero_image' => 'fixture.png']);
locations_http_check(!str_contains($html, $malicious) && !str_contains($html, 'token"<bad>'), 'Location bootstrap injected markup.');
preg_match('/<script type="application\/json" id="sls-location-data">(.*?)<\/script>/s', $html, $match);
$decoded = json_decode($match[1] ?? '', true, 16, JSON_THROW_ON_ERROR);
locations_http_check($decoded['state'] === $state && $decoded['csrf'] === 'token"<bad>', 'Escaped bootstrap changed values.');
locations_http_check(str_contains($html, 'role="status"') && str_contains($html, 'aria-labelledby="sls-location-audience-title"'), 'Accessible status/audience controls missing.');
foreach (['locations.js', 'geographic.js', 'audiences.js'] as $script) {
    $process = proc_open(['node', '--check', $root . '/slsmassnotifyserver/views/' . $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    locations_http_check(proc_close($process) === 0, 'Location JavaScript syntax failed: ' . $err);
}
echo "Location controller CSRF, request bounds, action routing, escaping and editor syntax: $count checks passed.\n";
