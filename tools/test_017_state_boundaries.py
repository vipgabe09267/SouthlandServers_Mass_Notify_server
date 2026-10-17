#!/usr/bin/python3
"""Private crash-state, mixed-writer and diagnostic qualification fixtures."""
import concurrent.futures
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import time
from unittest import mock

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'slsmassnotifyserver'
RUNTIME = MODULE / 'bin/sls_mass_notify'


def php(code, *arguments):
    result = subprocess.run(['php', '-r', code, *map(str, arguments)], capture_output=True, text=True, timeout=10)
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return result.stdout


class StateBoundaries(unittest.TestCase):
    def test_lightning_quota_reservations_and_refunds_are_serialized(self):
        sys.path.insert(0,str(RUNTIME))
        spec=importlib.util.spec_from_file_location('quota_transaction_fixture',RUNTIME/'sls_mass_notify_xweather_poll.py')
        lightning=importlib.util.module_from_spec(spec);spec.loader.exec_module(lightning)
        with tempfile.TemporaryDirectory() as temporary:
            quota=Path(temporary)/'quota.json';now=int(time.time())
            quota.write_text(json.dumps({'quota_bucket_tokens':10,'quota_bucket_updated':now,'quota_reset_marker':'period-a'}));quota.chmod(0o640)
            status={'xweather_rate_limit_period':300,'xweather_last_query_cost_tokens':10,'xweather_rate_remaining_period':300,'xweather_rate_reset_period':'period-a'}
            with mock.patch.object(lightning,'QUOTA_STATE_FILE',quota),mock.patch.object(lightning,'read_json_object',return_value=status):
                with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                    reservations=list(pool.map(lambda _:lightning.reserve_shared_quota(now),range(2)))
                self.assertEqual(sum(row[0] for row in reservations),1)
                self.assertEqual(json.loads(quota.read_text())['quota_bucket_tokens'],0)
                with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                    list(pool.map(lambda _:lightning.adjust_shared_quota(5,10),range(2)))
                self.assertEqual(json.loads(quota.read_text())['quota_bucket_tokens'],10)

    def test_atomic_and_lightning_parent_lock_and_read_boundaries(self):
        sys.path.insert(0,str(RUNTIME))
        import sls_atomic_json as atomic
        spec=importlib.util.spec_from_file_location('re_audit_lightning',RUNTIME/'sls_mass_notify_xweather_poll.py')
        lightning=importlib.util.module_from_spec(spec);spec.loader.exec_module(lightning)
        with tempfile.TemporaryDirectory(prefix='sls-atomic-boundaries-') as temporary:
            root=Path(temporary);outside=root/'outside';outside.mkdir(mode=0o700)
            alias=root/'alias';alias.symlink_to(outside,target_is_directory=True)
            for writer in ('shared','lightning'):
                destination=alias/'unexpected'/'status.json'
                with self.assertRaises((OSError,RuntimeError)):
                    if writer=='shared':
                        with atomic.locked_json(destination) as state:state['fixture']=True
                    else:lightning._atomic_state_update(destination,{'fixture':True})
                self.assertEqual(list(outside.iterdir()),[])
            path=root/'storm.json';lightning._atomic_state_update(path,{'active':True,'original':'preserved'})
            lock=path.with_name(path.name+'.lock');inode=lock.stat().st_ino
            lightning._atomic_state_update(path,{'nearest_miles':1.2})
            self.assertEqual(lightning._read_json_file(path)['original'],'preserved')
            self.assertEqual(lock.stat().st_ino,inode)
            with lock.open('r+b') as held:
                fcntl.flock(held,fcntl.LOCK_EX)
                started=time.monotonic()
                with self.assertRaises(RuntimeError):lightning._atomic_state_update(path,{'must_not_write':True})
                self.assertLess(time.monotonic()-started,2.5)
            before=path.read_bytes();path.unlink()
            with self.assertRaises(RuntimeError):lightning._read_json_file(path)
            path.write_bytes(before);path.chmod(0o640)
            lock.unlink();target=root/'target';target.write_text('unchanged');target.chmod(0o640)
            os.link(target,lock);metadata=target.stat()
            with self.assertRaises(RuntimeError):lightning._atomic_state_update(path,{'must_not_write':True})
            self.assertEqual((target.stat().st_ino,target.stat().st_uid,target.stat().st_mode),(metadata.st_ino,metadata.st_uid,metadata.st_mode))
            self.assertEqual(target.read_text(),'unchanged')
            fifo=root/'fifo.json';os.mkfifo(fifo,0o640)
            started=time.monotonic()
            with self.assertRaises(RuntimeError):lightning._read_json_file(fifo)
            self.assertLess(time.monotonic()-started,.5)

    def test_enterprise_initialization_resumes_before_application_effects(self):
        with tempfile.TemporaryDirectory() as temporary:
            for kind, initial, marker in (
                ('enterprise-operations', {'schema':1,'clock':0,'approvals':[],'drills':[]}, 'SLS_ENTERPRISE_OPERATIONS_V1\n'),
                ('enterprise-integrations', {'schema':1,'operations':[],'bindings':[],'responses':[],'sensors':[]}, 'SLS_ENTERPRISE_INTEGRATIONS_V1\n'),
            ):
                for stage in range(4):
                    base = Path(temporary) / (kind + str(stage)); base.mkdir(mode=0o700)
                    folder = base / kind; folder.mkdir(mode=0o700)
                    body = json.dumps(initial, separators=(',', ':'))
                    intent = folder / 'initialization.pending'
                    intent.write_text('SLS_INIT_V1\n'+kind+'\n'+hashlib.sha256(body.encode()).hexdigest()+'\n'); intent.chmod(0o600)
                    if stage >= 1:
                        state = folder / 'worker-state.json'; state.write_text(body); state.chmod(0o600)
                    if stage >= 2:
                        fence = base / ('.'+kind+'-required'); fence.write_text(marker); fence.chmod(0o600)
                    if stage >= 3:
                        lock = folder / 'announcement-send.lock'; lock.write_text(marker); lock.chmod(0o600)
                    name = 'EnterpriseOperationsStore' if kind == 'enterprise-operations' else 'EnterpriseIntegrationsStore'
                    code = 'require '+json.dumps(str(MODULE/(name+'.php')))+';'
                    code += '$s=new SLS\\MassNotify\\'+name+'($argv[1]);$s->transaction(static function(array &$state){$state["schema"]=1;});echo "ok";'
                    self.assertEqual(php(code, folder), 'ok')
                    self.assertFalse(intent.exists())
                    self.assertEqual(json.loads((folder/'worker-state.json').read_text()), initial)
                    before = (folder/'announcement-send.lock').stat().st_ino
                    php(code, folder)
                    self.assertEqual((folder/'announcement-send.lock').stat().st_ino, before)

    def test_identity_empty_intent_recovers_but_history_is_never_reset(self):
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)/'enterprise-state'
            constructor = 'require '+json.dumps(str(MODULE/'EnterpriseIdentityStore.php'))+';$s=new SLS\\MassNotify\\EnterpriseIdentityStore($argv[1]);'
            php(constructor, folder)
            intent = folder/'login.initializing'; intent.write_text('SLS_ENTERPRISE_LEDGER_INIT_V1\nlogin\n'); intent.chmod(0o600)
            code = constructor+'$s->transaction("login",static function(array &$rows){$rows[str_repeat("a",64)]=["provider_id"=>"idp_".str_repeat("b",24),"revision"=>str_repeat("c",64),"protocol"=>"saml","browser"=>str_repeat("d",64),"created_at"=>1000,"expires_at"=>1300,"request_id"=>"ONELOGIN_".str_repeat("e",40)];});echo "ok";'
            self.assertEqual(php(code, folder), 'ok')
            before = (folder/'login.json').read_bytes()
            intent.write_text('SLS_ENTERPRISE_LEDGER_INIT_V1\nlogin\n'); intent.chmod(0o600)
            result = subprocess.run(['php','-r',code,str(folder)],capture_output=True,text=True,timeout=10)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual((folder/'login.json').read_bytes(), before)
            self.assertTrue(intent.exists())

    def test_sms_empty_initialization_recovers_and_unknown_schema_is_preserved(self):
        with tempfile.TemporaryDirectory() as temporary:
            for present in (False, True):
                folder=Path(temporary)/str(present);folder.mkdir(mode=0o700)
                intent=folder/'initialization.pending';intent.write_text('SLS_SMS_INITIALIZATION_V1\n');intent.chmod(0o600)
                if present:
                    (folder/'deliveries.sqlite').touch(mode=0o600)
                code='require '+json.dumps(str(MODULE/'api/sls-mass-notify/sms/Store.php'))+';new SLS\\MassNotify\\Sms\\Store($argv[1]);echo "ok";'
                self.assertEqual(php(code,folder),'ok');self.assertFalse(intent.exists())
                self.assertEqual((folder/'initialized').read_text(),'SLS_SMS_LEDGER_V1\n')
            folder=Path(temporary)/'unknown';folder.mkdir(mode=0o700)
            (folder/'initialization.pending').write_text('SLS_SMS_INITIALIZATION_V1\n');(folder/'initialization.pending').chmod(0o600)
            import sqlite3
            with sqlite3.connect(folder/'deliveries.sqlite') as db:db.execute('CREATE TABLE unknown_history (id TEXT)')
            (folder/'deliveries.sqlite').chmod(0o600)
            result=subprocess.run(['php','-r',code,str(folder)],capture_output=True,text=True,timeout=10)
            self.assertNotEqual(result.returncode,0)
            with sqlite3.connect(folder/'deliveries.sqlite') as db:
                self.assertEqual(db.execute('SELECT name FROM sqlite_master WHERE type="table"').fetchall(),[('unknown_history',)])
            self.assertTrue((folder/'initialization.pending').exists())

    def test_php_and_python_status_writers_preserve_each_others_patches(self):
        with tempfile.TemporaryDirectory() as temporary:
            path=Path(temporary)/'status.json'
            python='import sys;sys.path.insert(0,sys.argv[1]);from sls_atomic_json import locked_json\nwith locked_json(sys.argv[2]) as data:data[sys.argv[3]]=True'
            code='require '+json.dumps(str(RUNTIME/'sls_atomic_json.php'))+';SlsAtomicJson::transaction($argv[1],[],static function(array $data)use($argv){$data[$argv[2]]=true;return [$data,null,true];});'
            def write(index):
                args=['php','-r',code,str(path),'writer_'+str(index)] if index%2 else [sys.executable,'-c',python,str(RUNTIME),str(path),'writer_'+str(index)]
                result=subprocess.run(args,capture_output=True,text=True,timeout=10)
                self.assertEqual(result.returncode,0,result.stderr)
            with concurrent.futures.ThreadPoolExecutor(max_workers=4) as workers:list(workers.map(write,range(16)))
            self.assertEqual(json.loads(path.read_text()),{'writer_'+str(i):True for i in range(16)})
            self.assertEqual(path.with_name('status.json.lock').read_text(),'SLS_JSON_V1\n')

    def test_vm_prerequisite_failure_retains_a_private_report(self):
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary);image=root/'image';image.write_bytes(b'not an approved image')
            report=root/'report.json'
            result=subprocess.run([sys.executable,str(ROOT/'tools/qualify_installer_vm.py'),'--allow-disposable-vm',
                '--image',str(image),'--image-sha256','a'*64,'--bundle',str(root/'missing'),
                '--baseline','fresh','--report',str(report)],capture_output=True,text=True,timeout=10)
            self.assertEqual(result.returncode,1)
            row=json.loads(report.read_text())['results'][0]
            self.assertEqual(row['scenario'],'prerequisites');self.assertIs(row['ok'],False)
            self.assertEqual(report.stat().st_mode&0o777,0o600)
            self.assertFalse(list(root.glob('.report.json.*')))

    def test_php_journals_reject_duplicate_keys_and_only_explicit_caches_recreate(self):
        with tempfile.TemporaryDirectory() as temporary:
            path=Path(temporary)/'status.json'
            code='require '+json.dumps(str(RUNTIME/'sls_atomic_json.php'))+';SlsAtomicJson::transaction($argv[1],[],static function($rows){return [$rows,null,false];});'
            for raw in ('{"x":1,"x":0}', '{"outer":{"x":1,"\\u0078":0}}', '{"finite":1e400}'):
                path.write_text(raw);path.chmod(0o640)
                result=subprocess.run(['php','-r',code,str(path)],capture_output=True,text=True,timeout=10)
                self.assertNotEqual(result.returncode,0);self.assertEqual(path.read_text(),raw)
            path.write_text(json.dumps({'first':{'x':1},'second':{'x':2},'text':'quoted "key": value and braces { }'}))
            self.assertEqual(php(code,path),'')
            lock=path.with_name('status.json.lock');inode=lock.stat().st_ino;path.unlink()
            result=subprocess.run(['php','-r',code,str(path)],capture_output=True,text=True,timeout=10)
            self.assertNotEqual(result.returncode,0);self.assertFalse(path.exists())
            cache=code.replace('});','},1048576,false);')
            self.assertEqual(php(cache,path),'');self.assertEqual(path.read_text(),'[]\n')
            self.assertEqual(lock.stat().st_ino,inode)

    def test_php_journal_refuses_a_replaced_lock_before_entering_callback(self):
        with tempfile.TemporaryDirectory(prefix='sls-php-detached-lock-') as temporary:
            root = Path(temporary)
            path = root / 'history.json'
            path.write_bytes(b'{"total":1}\n')
            path.chmod(0o640)
            source = (RUNTIME / 'sls_atomic_json.php').read_text().removeprefix('<?php')
            source = source.replace('declare(strict_types=1);', '', 1)
            harness = '''<?php
namespace PrivateLeaseRace;
use \\RuntimeException;
function flock($handle, $operation) {
    $result = \\flock($handle, $operation);
    if ($result && ($operation & LOCK_EX) && empty($GLOBALS['replaced'])) {
        $GLOBALS['replaced'] = true;
        $path = stream_get_meta_data($handle)['uri'];
        unlink($path); file_put_contents($path, ''); chmod($path, 0640);
    }
    return $result;
}
''' + source + '''
$entered = false; $refused = false;
try {
    SlsAtomicJson::transaction($argv[1], [], static function($data) use (&$entered) {
        $entered = true; $data['total']++; return [$data, null, true];
    });
} catch (\\Throwable $error) { $refused = true; }
echo json_encode(['refused' => $refused, 'entered' => $entered]);
'''
            script = root / 'fixture.php'
            script.write_text(harness)
            result = subprocess.run(['php', str(script), str(path)], capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout), {'refused': True, 'entered': False})
            self.assertEqual(path.read_bytes(), b'{"total":1}\n')
            self.assertEqual(path.with_name('history.json.lock').read_bytes(), b'')

    def test_php_journal_rejects_invalid_callback_contract_without_mutation(self):
        with tempfile.TemporaryDirectory(prefix='sls-php-callback-contract-') as temporary:
            path = Path(temporary) / 'history.json'
            original = b'{"total":1}\n'
            path.write_bytes(original)
            path.chmod(0o640)
            prefix = 'require '+json.dumps(str(RUNTIME/'sls_atomic_json.php'))+';'
            callbacks = [
                'static function(array $rows){return $rows;}',
                'static function(array $rows){return [$rows,null,1];}',
                'static function(array $rows){return ["scalar",null,true];}',
            ]
            for callback in callbacks:
                code = prefix+'SlsAtomicJson::transaction($argv[1],[],%s);' % callback
                result = subprocess.run(['php','-r',code,str(path)],capture_output=True,text=True,timeout=10)
                self.assertNotEqual(result.returncode,0)
                self.assertEqual(path.read_bytes(),original)
            code = prefix+'SlsAtomicJson::transaction($argv[1],[],static function(array $rows){return [$rows,null,false];},1);'
            result = subprocess.run(['php','-r',code,str(path)],capture_output=True,text=True,timeout=10)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(path.read_bytes(),original)

    def test_php_object_storage_keeps_an_empty_top_level_object(self):
        with tempfile.TemporaryDirectory(prefix='sls-php-object-storage-') as temporary:
            path = Path(temporary) / 'object.json'
            prefix = 'require '+json.dumps(str(RUNTIME/'sls_atomic_json.php'))+';'
            code = prefix+'SlsAtomicJson::transaction($argv[1],[],static function(array $rows){return [$rows,null,false];},1048576,false,true);'
            self.assertEqual(php(code,path),'')
            self.assertEqual(path.read_bytes(),b'{}\n')
            before = path.read_bytes()
            code = prefix+'SlsAtomicJson::transaction($argv[1],[],static function(array $rows){return [[1],null,true];},1048576,true,true);'
            result = subprocess.run(['php','-r',code,str(path)],capture_output=True,text=True,timeout=10)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(path.read_bytes(),before)

    def test_retained_usage_does_not_follow_links_or_read_state(self):
        spec=importlib.util.spec_from_file_location('retained_usage_fixture',RUNTIME/'sls_storage_maintenance.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary);(root/'mass-notifications.config').write_bytes(b'private setting')
            (root/'announcement-jobs').mkdir();(root/'announcement-jobs/example.json').write_bytes(b'job')
            result=module.retained_usage(root)
            self.assertEqual(result['configuration']['bytes'],15);self.assertEqual(result['announcement_jobs']['bytes'],3)
            (root/'outside').symlink_to('/etc')
            result=module.retained_usage(root)
            self.assertTrue(all(not row['complete'] for row in result.values()))

    def test_retained_usage_handles_deep_directories_without_python_recursion(self):
        spec=importlib.util.spec_from_file_location('retained_usage_deep_fixture',RUNTIME/'sls_storage_maintenance.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary);current=root
            for _ in range(80):
                current=current/'d';current.mkdir()
            (current/'evidence').write_bytes(b'x')
            previous=sys.getrecursionlimit()
            try:
                sys.setrecursionlimit(70)
                result=module.retained_usage(root)
            finally:
                sys.setrecursionlimit(previous)
            self.assertTrue(all(row['complete'] for row in result.values()))
            self.assertEqual(result['other_state']['bytes'],1)


if __name__ == '__main__':unittest.main()
