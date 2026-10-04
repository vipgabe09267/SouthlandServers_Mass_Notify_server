#!/usr/bin/python3
"""Real private-network adapter traffic and unprivileged approved scripts only."""
import hashlib
import http.server
import importlib.util
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKER = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_trigger_actions.py'
spec = importlib.util.spec_from_file_location('sls_trigger_actions', WORKER)
actions = importlib.util.module_from_spec(spec)
spec.loader.exec_module(actions)


class TriggerActions(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not os.environ.get('SLS_TEST_NAMESPACE') == 'entered':
            raise RuntimeError('Run only with tools/run_isolated_tests.sh')
        subprocess.run(['ip', 'link', 'set', 'lo', 'up'], check=True)
        subprocess.run(['ip', 'address', 'add', '192.168.240.10/32', 'dev', 'lo'], check=True)
        cls.directory = Path('/var/lib/asterisk/approved-script-fixtures')
        cls.directory.mkdir(mode=0o755)
        cls.directory.chmod(0o755)
        cls.worker_path = cls.directory / 'sls_trigger_actions.py'
        cls.worker_path.write_bytes(WORKER.read_bytes())
        cls.worker_path.chmod(0o644)

    def worker(self, action, event=None, timeout=15):
        value = {'action': action, 'event': event or {'event_id':'test','message':'fixture only'}}
        result = subprocess.run(['/usr/sbin/runuser', '-u', 'nobody', '--', '/usr/bin/python3', str(self.worker_path)],
            input=json.dumps(value), capture_output=True, text=True, timeout=timeout,
            env={'PATH':'/usr/bin:/bin','SHOULD_NOT_REACH_SCRIPT':'secret','PYTHONDONTWRITEBYTECODE':'1'})
        self.assertTrue(result.stdout, result.stderr)
        return json.loads(result.stdout)

    def script(self, text, extension='sh'):
        path = self.directory / ('script-' + os.urandom(8).hex() + '.' + extension)
        path.write_text(text); path.chmod(0o644)
        return {'enabled':True,'kind':'script','path':str(path),'sha256':hashlib.sha256(path.read_bytes()).hexdigest()}

    def test_script_requires_unprivileged_account(self):
        action = self.script('exit 0\n')
        with self.assertRaises(actions.ActionError): actions.run_script(action, {})
        self.assertEqual(self.worker(action)['state'], 'completed')

    def test_script_input_and_environment(self):
        action = self.script('test -z "${SHOULD_NOT_REACH_SCRIPT:-}" || exit 5\nIFS= read -r input\nprintf "%s" "$input" | /usr/bin/python3 -c \'import json,sys; d=json.load(sys.stdin); assert d["message"] == "$(touch /tmp/should-not-exist)"\'\n')
        self.assertEqual(self.worker(action, {'message':'$(touch /tmp/should-not-exist)'})['state'],'completed')
        self.assertFalse(Path('/tmp/should-not-exist').exists())

    def test_javascript(self):
        action = self.script("let raw='';process.stdin.on('data',d=>raw+=d);process.stdin.on('end',()=>{if(JSON.parse(raw).event_id!=='test')process.exit(7);});\n", 'js')
        self.assertEqual(self.worker(action)['state'],'completed')

    def test_changed_hash_link_and_permissions(self):
        action = self.script('exit 0\n'); Path(action['path']).write_text('exit 2\n')
        self.assertEqual(self.worker(action)['state'],'failed')
        Path(action['path']).chmod(0o666)
        self.assertEqual(self.worker(action)['state'],'failed')
        Path(action['path']).unlink(); Path(action['path']).symlink_to('/etc/passwd')
        self.assertEqual(self.worker(action)['state'],'failed')
        action = self.script('exit 0\n'); os.link(action['path'], action['path']+'.link')
        self.assertEqual(self.worker(action)['state'],'failed')

    def test_output_and_timeout_boundaries(self):
        action = self.script('/usr/bin/yes unsafe-output\n')
        self.assertEqual(self.worker(action)['state'],'uncertain')
        action = self.script('sleep 90 &\nwait\n')
        self.assertEqual(self.worker(action)['state'],'uncertain')

    def test_nonzero_script_is_not_success(self):
        result = self.worker(self.script('exit 23\n'))
        self.assertEqual(result['state'],'failed'); self.assertEqual(result['exit_code'],23)

    def test_brightsign_actual_datagram(self):
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as receiver:
            receiver.bind(('192.168.240.10',0)); receiver.settimeout(2)
            result = actions.execute({'enabled':True,'kind':'brightsign_udp','host':'192.168.240.10','port':receiver.getsockname()[1],'message':'fixture-event'}, {})
            self.assertEqual(result['state'],'submitted'); self.assertEqual(receiver.recvfrom(256)[0], b'fixture-event')

    def test_patlite_exact_command_and_redirect_refusal(self):
        paths = []; status = [200]
        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                paths.append(self.path); self.send_response(status[0]); self.send_header('Location','http://127.0.0.1/never'); self.end_headers(); self.wfile.write(b'OK')
            def log_message(self, *args): pass
        server = http.server.HTTPServer(('192.168.240.10',0),Handler)
        thread = threading.Thread(target=server.serve_forever,daemon=True); thread.start()
        try:
            action = {'enabled':True,'kind':'patlite_nhv','host':'192.168.240.10','port':server.server_port,'scheme':'http','led':'12000','clear':False}
            self.assertEqual(actions.execute(action,{})['state'],'submitted'); self.assertEqual(paths[-1],'/api/control?led=12000')
            action['clear']=True; actions.execute(action,{}); self.assertEqual(paths[-1],'/api/control?clear=1')
            status[0]=302; self.assertEqual(actions.execute(action,{})['state'],'failed'); self.assertEqual(len(paths),3)
        finally: server.shutdown(); server.server_close(); thread.join()

    def test_denied_targets_and_payloads(self):
        for host in ('127.0.0.1','169.254.169.254','8.8.8.8','224.0.0.1','192.168.1.255','192.168.1.0','localhost','192.168.1.2/other'):
            with self.assertRaises(actions.ActionError): actions.execute({'enabled':True,'kind':'brightsign_udp','host':host,'port':5000,'message':'x'}, {})
        with self.assertRaises(actions.ActionError): actions.execute({'enabled':False,'kind':'script'}, {})


if __name__ == '__main__': unittest.main()
