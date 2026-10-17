#!/usr/bin/env python3
"""Read desktop status through the actual trait with private journal faults."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class DesktopStatusReaders(unittest.TestCase):
    def test_raced_fifo_and_ambiguous_receipts_are_rejected(self):
        for attack in ('presence_fifo', 'receipt_fifo', 'duplicate_receipt'):
            with self.subTest(attack=attack), tempfile.TemporaryDirectory() as temporary:
                directory = Path(temporary)
                script = directory / 'fixture.php'
                script.write_text(r'''<?php
namespace FreePBX\modules {
    function fopen($path, $mode, ...$args) {
        if (($GLOBALS['swap_path'] ?? '') === $path) {
            unset($GLOBALS['swap_path']); unlink($path); posix_mkfifo($path,0600);
        }
        return \fopen($path,$mode,...$args);
    }
}
namespace {
    require ''' + json.dumps(str(ROOT / 'slsmassnotifyserver/AnnouncementDelivery.php')) + r''';
    eval('class Reader { use \\FreePBX\\modules\\SlsAnnouncementDelivery; const PLUGIN_DATA_DIR = '.var_export($argv[1],true).';'
        .' public function presence() {return $this->announcementDesktopPresence();}'
        .' public function receipt($row) {return $this->refreshAnnouncementDesktopReceipts(["receipts"=>[$row]],gmdate("c"));}}');
    $reader=new Reader; $attack=$argv[2];
    if ($attack==='presence_fifo') {
        $path=$argv[1].'/desktop-last-seen.json'; file_put_contents($path,'{}');chmod($path,0640);
        $GLOBALS['swap_path']=$path; echo json_encode($reader->presence());
    } else {
        mkdir($argv[1].'/sipnotify',0750);mkdir($argv[1].'/sipnotify/acknowledgements',0750);
        $path=$argv[1].'/sipnotify/acknowledgements/'.hash('sha256','id:client-a').'.json';
        $entry=['event_id'=>'original','ack_at'=>gmdate('c'),'username'=>'alice','client_id'=>'client-a','receipt_type'=>'client_acknowledgement'];
        $members=json_encode([hash('sha256','original')=>$entry]);
        $raw='{"schema":1,"acknowledgements":'.$members.'}';
        if ($attack==='duplicate_receipt') {$raw='{"schema":1,"acknowledgements":{},"acknowledgements":'.$members.'}';}
        file_put_contents($path,$raw);chmod($path,0640);
        if ($attack==='receipt_fifo') {$GLOBALS['swap_path']=$path;}
        echo json_encode($reader->receipt(['channel'=>'desktop','target'=>'alice','client_id'=>'client-a','event_id'=>'original','state'=>'published']));
    }
}
''')
                result = subprocess.run(['php', str(script), temporary, attack],
                                        capture_output=True, text=True, timeout=2)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stderr, '')
                data = json.loads(result.stdout)
                if attack == 'presence_fifo':
                    self.assertEqual(data, [])
                else:
                    self.assertEqual(data['receipts'][0]['state'], 'published')
                    self.assertIn('receipt_status_error', data)


if __name__ == '__main__':
    unittest.main()
