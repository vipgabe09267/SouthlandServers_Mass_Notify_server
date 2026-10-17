<?php
declare(strict_types=1);
umask(0027);
require_once dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/event-log.php';
use SLS\MassNotify\EventLog;
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function refused(callable $read, string $message): void {
    try { $read(); } catch (InvalidArgumentException|RuntimeException $error) { check($error->getMessage() === $message, $error->getMessage()); return; }
    throw new RuntimeException('Expected failure: ' . $message);
}
$directory = sys_get_temp_dir() . '/sls-log-pages-' . bin2hex(random_bytes(8));
check(mkdir($directory, 0700), 'Unable to create private event-log fixture'); $path = $directory . '/events.jsonl';
try {
    $content = '';
    for ($i=1; $i<=307; $i++) { $content .= json_encode(['id'=>$i, 'text'=>str_repeat('é', $i % 49)]) . "\n"; }
    $initial=$content . '{"incomplete":';check(file_put_contents($path,$initial,LOCK_EX)===strlen($initial),'Unable to write event-log fixture');
    $page = EventLog::page($path, 17); $ids = array_column($page['events'], 'id');
    check($ids === range(307,291), 'First page order or incomplete tail');
    $cursor = $page['next_cursor'];
    $append='true}' . "\n" . json_encode(['id'=>308],JSON_THROW_ON_ERROR) . "\n";
    check(file_put_contents($path,$append,FILE_APPEND|LOCK_EX)===strlen($append),'Unable to append event-log fixture');
    $loops=0;
    while ($page['has_more']) {
        $page = EventLog::page($path, 17, $page['next_cursor']);
        array_push($ids, ...array_column($page['events'], 'id'));
        check(++$loops < 25, 'Cursor did not advance');
    }
    check($ids === range(307,1), 'Missing, duplicated or appended records in frozen snapshot');
    check(EventLog::page($path, 1)['events'][0]['id'] === 308, 'New request misses new append');
    check(chmod($path,0644),'Unable to create public event-log fixture');
    refused(fn()=>EventLog::page($path), 'event_log_unsafe');
    check(chmod($path,0640),'Unable to restore private event-log fixture');
    refused(fn()=>EventLog::page($path, 25, str_repeat('x', 769)), 'invalid_event_cursor');
    $decoded=base64_decode(strtr($cursor, '-_', '+/'),true);check(is_string($decoded),'Generated cursor is not base64');
    $token = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);check(is_array($token),'Generated cursor is not an object');
    $token['before'] = PHP_INT_MAX;
    refused(fn()=>EventLog::page($path,25,rtrim(strtr(base64_encode(json_encode($token,JSON_THROW_ON_ERROR)),'+/','-_'),'=')), 'invalid_event_cursor');
    $lock=fopen($path,'r+b');check(is_resource($lock)&&flock($lock,LOCK_EX),'Unable to lock event-log fixture');
    refused(fn()=>EventLog::page($path), 'event_log_busy'); check(fclose($lock),'Unable to close event-log fixture lock');
    // Same-inode compaction with an equal byte count must invalidate the cursor.
    $raw=file_get_contents($path);check(is_string($raw),'Unable to read event-log compaction fixture');$compacted=str_replace('"id":1,','"id":9,',$raw);
    check($compacted!==$raw&&file_put_contents($path,$compacted,LOCK_EX)===strlen($compacted),'Unable to rewrite event-log compaction fixture');
    refused(fn()=>EventLog::page($path,17,$cursor), 'event_log_changed');
    check(file_put_contents($path,$content,LOCK_EX)===strlen($content),'Unable to restore event-log fixture');
    $cursor=EventLog::page($path,10)['next_cursor'];check(rename($path,$path.'.old'),'Unable to rotate event-log fixture');check(file_put_contents($path,$content,LOCK_EX)===strlen($content),'Unable to replace event-log fixture');
    refused(fn()=>EventLog::page($path,10,$cursor), 'event_log_changed');
    check(link($path,$path.'.link'),'Unable to hard-link event-log fixture');refused(fn()=>EventLog::page($path), 'event_log_unsafe');check(unlink($path.'.link'),'Unable to remove event-log hard link');
    check(unlink($path)&&symlink($path.'.old',$path),'Unable to create event-log symlink fixture');refused(fn()=>EventLog::page($path), 'event_log_unsafe');check(unlink($path),'Unable to remove event-log symlink');
    $duplicates="{\"id\":1,\"id\":2}\n{\"id\":3}\n";check(file_put_contents($path,$duplicates,LOCK_EX)===strlen($duplicates),'Unable to write duplicate-key event fixture');
    check(array_column(EventLog::page($path)['events'],'id')===[3],'Duplicate-key event was exposed through the Control API');
    // Large invalid records cannot exhaust memory or trap traversal at one cursor.
    $oversized="{\"id\":1}\n" . str_repeat('x', 1200000) . "\n{\"id\":2}\n";check(file_put_contents($path,$oversized,LOCK_EX)===strlen($oversized),'Unable to write oversized event-log fixture');
    $page=EventLog::page($path); $ids=array_column($page['events'],'id'); $loops=0;
    while ($page['has_more']) { $page=EventLog::page($path,25,$page['next_cursor']); array_push($ids,...array_column($page['events'],'id')); check(++$loops<5,'Oversized line pagination stuck'); }
    check($ids===[2,1], 'Oversized line lost valid records');
    echo "Event pagination: frozen append-safe pages, complete traversal, compaction/rotation, malformed cursors, busy/unsafe files and oversized records passed.\n";
} finally { foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory); }
