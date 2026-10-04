#!/usr/bin/env python3
"""Real locks and the open/unlink/flock race, entirely in disposable storage."""
import fcntl
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
from unittest import mock

root = Path(__file__).resolve().parents[1]
runtime = root / 'slsmassnotifyserver/bin/sls_mass_notify'
sys.path.insert(0, str(runtime))
import sls_storage_maintenance as storage

now = time.time()
with tempfile.TemporaryDirectory() as directory:
    directory = Path(directory)
    def job(index, state='complete', age=31*86400):
        ident = 'job_' + format(index, '032x')
        record = directory / (ident+'.json')
        record.write_text(json.dumps({'id':ident,'state':state}))
        lock = directory / (ident+'.json.lock'); lock.touch(mode=0o640)
        os.utime(record,(now-age,now-age)); os.utime(lock,(now-age,now-age))
        return record, lock
    completed, completed_lock = job(1)
    held_job, held_lock = job(2)
    running, running_lock = job(3,'running')
    recent, recent_lock = job(4,age=20)
    pending, pending_lock = job(5)
    (directory/('pending_'+pending.stem+'.mark')).touch()
    orphan, orphan_lock = job(6); orphan.unlink()
    unsafe, unsafe_lock = job(7); unsafe.unlink()
    link = directory/'unrelated'; os.link(unsafe_lock, link)
    with held_lock.open('rb') as held, mock.patch.object(storage,'lock_reclamation_ready',return_value=True):
        fcntl.flock(held,fcntl.LOCK_EX|fcntl.LOCK_NB)
        assert storage.prune_announcement_jobs(directory,now)==1
        assert not completed.exists() and not completed_lock.exists() and not orphan_lock.exists()
        assert all(p.exists() for p in (held_job,held_lock,running,running_lock,recent,recent_lock,pending,pending_lock,unsafe_lock,link))
    with mock.patch.object(storage,'lock_reclamation_ready',return_value=False):
        assert storage.prune_announcement_jobs(directory,now)==1
        assert not held_job.exists() and held_lock.exists()
    with mock.patch.object(storage,'lock_reclamation_ready',return_value=True):
        assert storage.prune_announcement_jobs(directory,now)==0 and not held_lock.exists()

with tempfile.TemporaryDirectory() as directory:
    source = (runtime/'sls_announcement_jobs.php').read_text().removeprefix('<?php')
    script = '''<?php
namespace Race;
use \\RuntimeException; use \\Throwable;
function flock($handle,$mode) {
    if (!empty($GLOBALS['replace_lock']) && ($mode & LOCK_EX)) {
        $GLOBALS['replace_lock']=false;
        $path=stream_get_meta_data($handle)['uri']; unlink($path);
        file_put_contents($path,''); chmod($path,0640);
    }
    return \\flock($handle,$mode);
}
''' + source + '\n$store=new SlsAnnouncementJobStore('+json.dumps(directory)+');'
    script += '''
$id='job_'.str_repeat('a',32); $GLOBALS['replace_lock']=true;
if ($store->lock($id)!==null) { throw new RuntimeException('Detached lock inode was accepted.'); }
$lock=$store->lock($id);
if (!is_resource($lock)) { throw new RuntimeException('Replacement lock could not be acquired.'); }
SlsAnnouncementJobStore::unlock($lock);
'''
    fixture = Path(directory)/'race.php'; fixture.write_text(script)
    subprocess.run(['php',str(fixture)],check=True,timeout=10)
print('Terminal and orphan lock retirement, active/pending/held/unsafe preservation, upgrade grace and detached-inode race passed.')
