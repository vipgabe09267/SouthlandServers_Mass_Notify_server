<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging.php';
use SLS\MassNotify\LivePagingState;
$root = sys_get_temp_dir() . '/sls-live-audio-' . bin2hex(random_bytes(8));
mkdir($root, 0700); $checks = 0;
function audio_check(bool $condition, string $message): void {
    global $checks; ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
}
function audio_reject(callable $operation, string $message): void {
    try { $operation(); } catch (RuntimeException $error) { audio_check(true, $message); return; }
    throw new RuntimeException($message);
}
function audio_dir(string $name): string {
    global $root; $directory = $root . '/' . $name; mkdir($directory, 0700); return $directory;
}
function audio_remove(string $path): void {
    if (is_dir($path) && !is_link($path)) { foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { audio_remove($path . '/' . $name); } } rmdir($path); }
    else { unlink($path); }
}
try {
    $dir = audio_dir('fresh'); $state = new LivePagingState($dir);
    file_put_contents($dir . '/audio-reservations.lock', ''); // Maintenance may create only the lock.
    $lease = $state->claim(['1000'], 30, 1000.0);
    audio_check($lease['until'] === 1035.0, 'Fresh claim missing');
    audio_check(file_get_contents($dir . '/audio-reservations.lock') === "SLS_AUDIO_STATE_V1\n", 'Initialization marker missing');
    $first = stat($dir . '/audio-reservations.json'); $lock = stat($dir . '/audio-reservations.lock');
    $state->release($lease); clearstatcache();
    audio_check(stat($dir . '/audio-reservations.json')['ino'] !== $first['ino'], 'Commit rewrote data in place');
    audio_check(stat($dir . '/audio-reservations.lock')['ino'] === $lock['ino'], 'Commit replaced permanent lock');
    $decoded = json_decode(file_get_contents($dir . '/audio-reservations.json'));
    audio_check(is_object($decoded->recipients) && is_object($decoded->media) && is_object($decoded->waiting), 'Empty maps incompatible with Python');
    unlink($dir . '/audio-reservations.json');
    audio_reject(static fn() => $state->claim(['1000'], 30), 'Missing initialized state silently reset');
    audio_check(!file_exists($dir . '/audio-reservations.json'), 'Failure created replacement data');

    foreach (['empty' => '', 'broken' => '{', 'array' => '[]', 'null_map' => '{"recipients":null,"media":{}}',
        'nonempty_array' => '{"recipients":[1000],"media":{}}', 'bool' => '{"recipients":{"1000":true},"media":{}}',
        'negative' => '{"recipients":{"1000":-1},"media":{}}', 'missing_map' => '{"media":{}}',
        'badmedia' => '{"recipients":{},"media":{"../unsafe.wav":3000}}'] as $name => $body) {
        $dir = audio_dir($name); file_put_contents($dir . '/audio-reservations.json', $body);
        audio_reject(static fn() => (new LivePagingState($dir))->claim(['1000'], 30), 'Invalid journal accepted: ' . $name);
        audio_check(file_get_contents($dir . '/audio-reservations.json') === $body, 'Invalid evidence changed: ' . $name);
        audio_check(file_get_contents($dir . '/audio-reservations.lock') === '', 'Invalid journal marked initialized');
    }
    $dir = audio_dir('object-recipient-list');
    $body = '{"recipients":{},"media":{},"waiting":{"' . str_repeat('a', 32) . '":{"recipients":{"0":"1000"},"priority":0,"created":1000,"expires":2000,"heartbeat":2000,"duration":30,"media_name":"fixture.wav"}}}';
    file_put_contents($dir . '/audio-reservations.json', $body);
    audio_reject(static fn() => (new LivePagingState($dir))->claim(['1000'], 30, 1001.0), 'Object falsely accepted as waiting recipient list');
    audio_check(file_get_contents($dir . '/audio-reservations.json') === $body, 'Malformed recipient-list evidence changed');
    audio_check(file_get_contents($dir . '/audio-reservations.lock') === '', 'Malformed recipient list marked initialized');
    $dir = audio_dir('legacy'); file_put_contents($dir . '/audio-reservations.json', '{"recipients":[],"media":[]}');
    $state = new LivePagingState($dir); $lease = $state->claim(['1000'], 30, 1000.0);
    audio_check($lease !== [], 'Legacy empty maps rejected');
    $body = file_get_contents($dir . '/audio-reservations.json');
    audio_check($state->claim(['1000'], 30, 1001.0) === [], 'Existing busy reservation forgotten');
    audio_check(file_get_contents($dir . '/audio-reservations.json') === $body, 'Read-only busy check rewrote journal');

    foreach (['symlink', 'hardlink', 'fifo', 'writable'] as $kind) {
        $dir = audio_dir($kind); $victim = $dir . '/victim'; file_put_contents($victim, 'preserve');
        $path = $dir . '/audio-reservations.json';
        if ($kind === 'symlink') { symlink($victim, $path); }
        elseif ($kind === 'hardlink') { link($victim, $path); }
        elseif ($kind === 'fifo') { posix_mkfifo($path, 0600); }
        else { file_put_contents($path, '{"recipients":{},"media":{}}'); chmod($path, 0666); }
        audio_reject(static fn() => (new LivePagingState($dir))->claim(['1000'], 30), 'Unsafe journal accepted: ' . $kind);
        audio_check(file_get_contents($victim) === 'preserve', 'Victim changed');
    }
    $dir = audio_dir('marker'); file_put_contents($dir . '/audio-reservations.lock', 'incomplete');
    audio_reject(static fn() => (new LivePagingState($dir))->claim(['1000'], 30), 'Invalid marker accepted');
    audio_check(!file_exists($dir . '/audio-reservations.json'), 'Invalid marker created data');
    echo "Live audio durable journal: $checks assertions passed.\n";
} finally { audio_remove($root); }
