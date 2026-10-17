#!/usr/bin/env python3
"""Load a valid retained schedule journal under a bounded PHP memory limit."""
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ScheduleJournalMemory(unittest.TestCase):
    def test_actual_runtime_reader_under_64_mib(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory=Path(temporary);path=directory/'schedule.json'
            occurrences={}
            for index in range(17000):
                identity='occ_'+hashlib.sha256(str(index).encode()).hexdigest()[:20]
                occurrences[identity]={'occurrence_id':identity,'schedule_id':'private_schedule',
                    'schedule_name':'Private retained schedule','state':'success','message':'x'*180,
                    'run_at_utc':'2026-10-13T12:00:00Z','claimed_at':'2026-10-13T12:00:00Z',
                    'completed_at':'2026-10-13T12:00:01Z','updated_at':'2026-10-13T12:00:01Z','attempts':1}
            ledger=json.dumps({'version':1,'occurrences':occurrences,'worker':{}},separators=(',',':'))
            if path.write_text(ledger,encoding='utf-8') != len(ledger):
                self.fail('The retained-journal fixture was not written completely.')
            script=directory/'reader.php'
            source='''<?php
interface BMO {}
require '''+json.dumps(str(ROOT/'slsmassnotifyserver/Slsmassnotifyserver.class.php'))+''';
$reflection=new ReflectionClass(FreePBX\\modules\\Slsmassnotifyserver::class);$lines=file($reflection->getFileName());
$fixture='class Reader {const MAX_SCHEDULES=50;const MAX_SCHEDULE_OCCURRENCES=500;const NATIVE_BACKUP_MAX_LEDGER_BYTES=16777216;const SCHEDULE_STATE_JSON='.var_export($argv[1],true).';';
foreach(['loadScheduleExecutionStore','validateNativeScheduleLedger','readNativeBackupFile','parseScheduleUtcTimestamp']as$name){
    $method=$reflection->getMethod($name);$fixture.=implode('',array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));
}
eval($fixture.' public function read(){return $this->loadScheduleExecutionStore(true);}}');unset($fixture,$lines);
$ledger=(new Reader)->read();echo json_encode(['count'=>count($ledger['occurrences']),'peak'=>memory_get_peak_usage(true)]);
'''
            if script.write_text(source,encoding='utf-8') != len(source):
                self.fail('The retained-journal reader fixture was not written completely.')
            environment={'PATH':'/usr/bin:/bin','LANG':'C','LC_ALL':'C'}
            result=subprocess.run(['/usr/bin/php','-d','memory_limit=64M',str(script),str(path)],
                                  capture_output=True,text=True,timeout=8,env=environment,cwd=directory)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(result.stderr,'')
            report=json.loads(result.stdout)
            self.assertEqual(report['count'],17000)
            self.assertLess(report['peak'],64*1048576)


if __name__=='__main__':
    unittest.main()
