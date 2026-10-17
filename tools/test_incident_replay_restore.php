<?php
declare(strict_types=1);
require __DIR__.'/../slsmassnotifyserver/IncidentStore.php';
use SLS\MassNotify\IncidentStore;
$root=sys_get_temp_dir().'/sls-incident-replay-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    $store=new IncidentStore($root.'/incidents');$method=new ReflectionMethod($store,'restoredReplayBlocked');
    $id='inc_'.str_repeat('a',32);$guard=$root.'/.incident-replay-blocked.json';$marker=$root.'/.incident-replay-required';
    file_put_contents($marker,"SLS_RESTORED_INCIDENT_REPLAY_V1\n");chmod($marker,0600);
    foreach(['{"schema":1,"ids":["'.$id.'"],"ids":[]}','{"schema":true,"ids":[]}'] as $raw){
        file_put_contents($guard,$raw);chmod($guard,0600);$rejected=false;
        try{$method->invoke($store,$id);}catch(RuntimeException $expected){$rejected=true;}
        if(!$rejected||file_get_contents($guard)!==$raw){throw new RuntimeException('Ambiguous incident replay history was accepted or modified');}
    }
    file_put_contents($guard,json_encode(['schema'=>1,'ids'=>[$id]],JSON_THROW_ON_ERROR));chmod($guard,0600);
    $intent=$root.'/.incident-replay-initializing.json';file_put_contents($intent,json_encode(['schema'=>1,'ids'=>[$id]],JSON_THROW_ON_ERROR));chmod($intent,0600);
    $rejected=false;try{$method->invoke($store,'inc_'.str_repeat('b',32));}catch(RuntimeException $expected){$rejected=true;}
    if(!$rejected){throw new RuntimeException('Incident admission ignored incomplete restored-history initialization');}
    unlink($intent);
    if(!$method->invoke($store,$id)||$method->invoke($store,'inc_'.str_repeat('b',32))){throw new RuntimeException('Valid restored identity membership changed');}
    $activeId = 'inc_' . str_repeat('c', 32);
    $active = $root . '/incidents/' . $activeId . '.json';
    foreach ([
        '{"schema":1,"id":"' . $activeId . '","timeline":[],"operations":{"accepted":{"state":"uncertain"}},"operations":[]}',
        '{"schema":1,"id":"' . $activeId . '","timeline":[],"operations":[],"unexpected":1e309}',
    ] as $raw) {
        file_put_contents($active, $raw);
        chmod($active, 0600);
        $rejected = false;
        try { $store->read($activeId); } catch (RuntimeException $expected) { $rejected = true; }
        if (!$rejected || file_get_contents($active) !== $raw) {
            throw new RuntimeException('Ambiguous active incident history was accepted or modified');
        }
    }
    unlink($active);
    $archivePath = $root . '/archive';
    mkdir($archivePath, 0700);
    $archive = new SLS\MassNotify\IncidentArchive($archivePath);
    $record = ['schema' => 1, 'id' => $activeId, 'created_at' => '2026-10-13T00:00:00+00:00', 'timeline' => [], 'operations' => []];
    $summary = ['id' => $activeId, 'created_at' => $record['created_at']];
    $archive->put($record, $summary, $record['created_at'], 64);
    foreach ([
        static fn() => $archive->listing(0, null),
        static fn() => $archive->listing(201, null),
        static fn() => $archive->listing(10, ['id' => $activeId, 'created_at' => '2026-10-13T00:00:00']),
        static fn() => $archive->listing(10, ['id' => $activeId, 'created_at' => $record['created_at'], 'archived' => false]),
        static fn() => $archive->put($record, $summary + ['padding' => str_repeat('x', 8192)], $record['created_at'], 64),
    ] as $unsafe) {
        $rejected = false;
        try { $unsafe(); } catch (InvalidArgumentException | RuntimeException $expected) { $rejected = true; }
        if (!$rejected) { throw new RuntimeException('Invalid archive input was accepted'); }
    }
    $markerPath = $archivePath . '/initialized';
    $marker = file_get_contents($markerPath);
    file_put_contents($markerPath, $marker . 'x');
    $rejected = false;
    try { $archive->contains($activeId); } catch (RuntimeException $expected) { $rejected = true; }
    file_put_contents($markerPath, $marker);
    if (!$rejected) { throw new RuntimeException('Oversized archive marker was accepted'); }
    $lockPath = $archivePath . '/storage.lock';
    file_put_contents($lockPath, 'x');
    $rejected = false;
    try { $archive->contains($activeId); } catch (RuntimeException $expected) { $rejected = true; }
    file_put_contents($lockPath, '');
    if (!$rejected) { throw new RuntimeException('Nonempty archive lock was accepted'); }
    $db = new PDO('sqlite:' . $archivePath . '/reports.sqlite');
    $statement = $db->prepare('UPDATE reports SET raw_bytes=? WHERE id=?');
    $statement->execute(['not-an-integer', $activeId]);
    $rejected = false;
    try { $archive->read($activeId); } catch (RuntimeException $expected) { $rejected = true; }
    if (!$rejected) { throw new RuntimeException('Invalid archive column type was accepted'); }
    $statement->execute([strlen(json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $activeId]);
    $badBody = '{"schema":1,"id":"' . $activeId . '","operations":{"accepted":{}},"operations":[]}';
    $statement = $db->prepare('UPDATE reports SET body=?,digest=?,raw_bytes=? WHERE id=?');
    $statement->bindValue(1, gzencode($badBody), PDO::PARAM_LOB);
    $statement->bindValue(2, hash('sha256', $badBody));
    $statement->bindValue(3, strlen($badBody), PDO::PARAM_INT);
    $statement->bindValue(4, $activeId);
    $statement->execute();
    $before = hash_file('sha256', $archivePath . '/reports.sqlite');
    $rejected = false;
    try { $archive->read($activeId); } catch (RuntimeException $expected) { $rejected = true; }
    if (!$rejected || hash_file('sha256', $archivePath . '/reports.sqlite') !== $before) {
        throw new RuntimeException('Ambiguous archived incident history was accepted or modified');
    }
    $db->prepare('UPDATE reports SET summary=? WHERE id=?')->execute(['{"id":"' . $activeId . '","state":"closed","state":"open"}', $activeId]);
    $before = hash_file('sha256', $archivePath . '/reports.sqlite');
    $rejected = false;
    try { $archive->listing(10, null); } catch (RuntimeException $expected) { $rejected = true; }
    if (!$rejected || hash_file('sha256', $archivePath . '/reports.sqlite') !== $before) {
        throw new RuntimeException('Ambiguous archived summary was accepted or modified');
    }
    echo "Incident history: ambiguous active/archive JSON and pending restoration rejected; valid membership preserved.\n";
}finally{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){$entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname());}
    rmdir($root);
}
