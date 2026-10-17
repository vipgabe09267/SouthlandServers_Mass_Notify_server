#!/usr/bin/env python3
"""Exercise the real paging readers with isolated file and clock faults."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class PagingReaders(unittest.TestCase):
    def run_fixture(self, attack):
        with tempfile.TemporaryDirectory() as temporary:
            script = Path(temporary) / 'fixture.php'
            script.write_text(r'''<?php
namespace SLS\MassNotify {
    function fopen($path, $mode, ...$args) {
        return \FreePBX\modules\fopen($path, $mode, ...$args);
    }
    function microtime($float = false) {
        return $GLOBALS['clock_reads']++ === 0 ? 1000.0 : 990.0;
    }
    function hrtime($number = false) { return 1000000000; }
    function stream_select(&$read, &$write, &$except, $seconds, $microseconds = 0) {
        $GLOBALS['wait_budget'] = $seconds + $microseconds / 1000000;
        return 0;
    }
}
namespace FreePBX\modules {
    function fopen($path, $mode, ...$args) {
        if (($GLOBALS['swap_path'] ?? '') === $path) {
            unset($GLOBALS['swap_path']); unlink($path); posix_mkfifo($path, 0600);
        }
        return \fopen($path, $mode, ...$args);
    }
}
namespace {
    require ''' + json.dumps(str(ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging.php')) + r''';
    $attack=$argv[2];
    if ($attack==='config_fifo') {
        $path=$argv[1].'/protected.config'; file_put_contents($path,'{"value":1}'); chmod($path,0640);
        $GLOBALS['swap_path']=$path;
        try { \SLS\MassNotify\LivePagingSession::loadSettings($path); throw new \LogicException('FIFO accepted'); }
        catch (\RuntimeException $error) { echo json_encode(['rejected'=>true]); }
    } elseif ($attack==='backward_clock') {
        $GLOBALS['clock_reads']=0;
        $agi=new \SLS\MassNotify\LivePagingAgi(\fopen('php://temp','r+'),\fopen('php://temp','r+'));
        $reader=new \ReflectionMethod($agi,'line'); $reader->setAccessible(true);
        try { $reader->invoke($agi,2); throw new \LogicException('Timeout accepted'); }
        catch (\RuntimeException $error) {
            if ($error->getMessage()!=='agi_timeout') { throw $error; }
            echo json_encode(['budget'=>$GLOBALS['wait_budget']]);
        }
    } else {
        $path=$argv[1].'/protected.config'; file_put_contents($path,'{"value":1}'); chmod($path,0640);
        $before=stat($path); $value=\SLS\MassNotify\LivePagingSession::loadSettings($path);
        echo json_encode(['value'=>$value,'unchanged'=>file_get_contents($path)==='{"value":1}' && stat($path)['ino']===$before['ino']]);
    }
}
''')
            result = subprocess.run(['php', str(script), temporary, attack],
                                    capture_output=True, text=True, timeout=2)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stderr, '')
            return json.loads(result.stdout)

    def test_config_fifo_substitution_does_not_block(self):
        self.assertEqual(self.run_fixture('config_fifo'), {'rejected': True})

    def test_wall_clock_change_does_not_extend_wait(self):
        self.assertLessEqual(self.run_fixture('backward_clock')['budget'], 2)

    def test_valid_private_configuration_is_unchanged(self):
        self.assertEqual(self.run_fixture('valid'), {'value': {'value': 1}, 'unchanged': True})


if __name__ == '__main__':
    unittest.main()
