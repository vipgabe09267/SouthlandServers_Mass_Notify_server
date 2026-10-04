<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/event-log.php';
use SLS\MassNotify\EventLog;
function check($ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function refused(callable $read, string $message): void {
    try { $read(); } catch (Throwable $error) { check($error->getMessage() === $message, $error->getMessage()); return; }
    throw new RuntimeException('Expected failure: ' . $message);
}
$directory = sys_get_temp_dir() . '/sls-log-pages-' . bin2hex(random_bytes(8));
mkdir($directory, 0700); $path = $directory . '/events.jsonl';
try {
    $content = '';
    for ($i=1; $i<=307; $i++) { $content .= json_encode(['id'=>$i, 'text'=>str_repeat('é', $i % 49)]) . "\n"; }
    file_put_contents($path, $content . '{"incomplete":');
    $page = EventLog::page($path, 17); $ids = array_column($page['events'], 'id');
    check($ids === range(307,291), 'First page order or incomplete tail');
    $cursor = $page['next_cursor'];
    file_put_contents($path, 'true}' . "\n" . json_encode(['id'=>308]) . "\n", FILE_APPEND);
    $loops=0;
    while ($page['has_more']) {
        $page = EventLog::page($path, 17, $page['next_cursor']);
        array_push($ids, ...array_column($page['events'], 'id'));
        check(++$loops < 25, 'Cursor did not advance');
    }
    check($ids === range(307,1), 'Missing, duplicated or appended records in frozen snapshot');
    check(EventLog::page($path, 1)['events'][0]['id'] === 308, 'New request misses new append');
    refused(fn()=>EventLog::page($path, 25, str_repeat('x', 769)), 'invalid_event_cursor');
    $token = json_decode(base64_decode(strtr($cursor, '-_', '+/')), true);
    $token['before'] = PHP_INT_MAX;
    refused(fn()=>EventLog::page($path,25,rtrim(strtr(base64_encode(json_encode($token)),'+/','-_'),'=')), 'invalid_event_cursor');
    $lock=fopen($path,'r+b'); flock($lock,LOCK_EX);
    refused(fn()=>EventLog::page($path), 'event_log_busy'); fclose($lock);
    // Same-inode compaction with an equal byte count must invalidate the cursor.
    $raw=file_get_contents($path); file_put_contents($path,str_replace('"id":1,','"id":9,',$raw));
    refused(fn()=>EventLog::page($path,17,$cursor), 'event_log_changed');
    file_put_contents($path,$content);
    $cursor=EventLog::page($path,10)['next_cursor']; rename($path,$path.'.old'); file_put_contents($path,$content);
    refused(fn()=>EventLog::page($path,10,$cursor), 'event_log_changed');
    link($path,$path.'.link'); refused(fn()=>EventLog::page($path), 'event_log_unsafe'); unlink($path.'.link');
    unlink($path); symlink($path.'.old',$path); refused(fn()=>EventLog::page($path), 'event_log_unsafe'); unlink($path);
    // Large invalid records cannot exhaust memory or trap traversal at one cursor.
    file_put_contents($path,"{\"id\":1}\n" . str_repeat('x', 1200000) . "\n{\"id\":2}\n");
    $page=EventLog::page($path); $ids=array_column($page['events'],'id'); $loops=0;
    while ($page['has_more']) { $page=EventLog::page($path,25,$page['next_cursor']); array_push($ids,...array_column($page['events'],'id')); check(++$loops<5,'Oversized line pagination stuck'); }
    check($ids===[2,1], 'Oversized line lost valid records');
    echo "Event pagination: frozen append-safe pages, complete traversal, compaction/rotation, malformed cursors, busy/unsafe files and oversized records passed.\n";
} finally { foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory); }
