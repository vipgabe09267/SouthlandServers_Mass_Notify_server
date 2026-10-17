#!/usr/bin/python3
"""Check actual privilege-transition prefixes with inert private worker tails."""
import json
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import tempfile
import unittest

if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or any(
    os.environ.get('SLS_TEST_PARENT_' + label + '_NS') in
    (None, '', os.readlink('/proc/self/ns/' + kind))
    for label, kind in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')]
):
    raise SystemExit('Use the private fixture runner; no account-transition helpers were run.')

ROOT=Path(__file__).resolve().parents[1]
TOKENS=(
    'GITHUB_TOKEN','GH_TOKEN','SLS_MASS_NOTIFY_GITHUB_TOKEN',
    'AWS_SECRET_ACCESS_KEY','DATABASE_URL','SMTP_PASSWORD',
    'AZURE_CLIENT_SECRET','GOOGLE_APPLICATION_CREDENTIALS','KUBECONFIG',
    'MYSQL_PWD','PGPASSWORD','REDIS_URL','SSH_AUTH_SOCK',
    # PYTHONPATH is inert under the production Python ``-I`` boundary.
    # PHP_INI_SCAN_DIR cannot be used as a synthetic credential: PHP consumes
    # it before the worker has an opportunity to execute its re-exec boundary.
    'PYTHONPATH',
    'HTTP_PROXY','HTTPS_PROXY','NO_PROXY',
)


class RuntimeEnvironment(unittest.TestCase):
    def setUp(self):
        if os.environ.get('SLS_TEST_NAMESPACE')!='entered':self.fail('Use tools/run_isolated_tests.sh')
        account=pwd.getpwnam('asterisk')
        self.expected_uid=account.pw_uid;self.expected_gid=account.pw_gid
        self.expected_groups=sorted(os.getgrouplist('asterisk',account.pw_gid))
        self.environment={
            'PATH':'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'LANG':'C.UTF-8','LC_ALL':'C.UTF-8',
            **dict.fromkeys(TOKENS,'SLS_PRIVATE_BOUNDARY_FIXTURE'),
        }

    def test_php_worker_transitions_remove_inherited_environment(self):
        with tempfile.TemporaryDirectory(prefix='sls-root-worker-env-') as temporary:
            folder=Path(temporary);folder.chmod(0o755)
            shutil.copytree(ROOT/'slsmassnotifyserver/bin/sls_mass_notify',folder,dirs_exist_ok=True)
            cases=[('sls_mass_notify_cluster_worker.php','    // Installer acquires','--status',False),
                   ('sls_mass_notify_automation_worker.php','    $account=posix_getpwnam','--sources',False),
                   ('sls_mass_notify_announcement_worker.php','    $store = new SlsAnnouncementJobStore();','--health-check',True)]
            for name,marker,argument,shutdown in cases:
                with self.subTest(worker=name):
                    source=(ROOT/'slsmassnotifyserver/bin'/name).read_text(encoding='utf-8')
                    prefix=source.split(marker,1)[0]
                    if prefix==source:self.fail('Missing actual transition boundary')
                    tail='''$initial=(string)file_get_contents('/proc/self/environ');$present=false;
foreach('''+repr(list(TOKENS))+''' as $name){$present=$present||getenv($name)!==false||str_contains($initial,$name.'=');}
echo json_encode(['uid'=>posix_geteuid(),'gid'=>posix_getegid(),'groups'=>posix_getgroups(),'token_present'=>$present]),"\\n";
'''
                    if shutdown:tail+='$finished=true;\n'
                    tail+='exit(0); } catch(Throwable $error){fwrite(STDERR,"Private transition failed.\\n");exit(1);}\n'
                    script=folder/name;script.write_text(prefix+tail,encoding='utf-8');script.chmod(0o644)
                    result=subprocess.run(['/usr/bin/php',str(script),argument],env=self.environment,capture_output=True,text=True,timeout=5,check=False)
                    self.assertEqual(result.returncode,0,result.stdout+result.stderr)
                    value=json.loads(result.stdout);self.assertEqual(value['uid'],self.expected_uid);self.assertEqual(value['gid'],self.expected_gid)
                    self.assertEqual(sorted(value['groups']),self.expected_groups)
                    self.assertFalse(value['token_present'],'A root worker retained inherited caller variables after its account transition')

    def test_uninstaller_children_receive_clean_environment(self):
        with tempfile.TemporaryDirectory() as temporary:
            Path(temporary).chmod(0o755)
            probe=Path(temporary)/'probe.py';probe.write_text('import json,os; print(json.dumps({"uid":os.geteuid(),"gid":os.getegid(),"groups":os.getgroups(),"token_present":any(name in os.environ for name in '+repr(TOKENS)+')}))',encoding='utf-8');probe.chmod(0o644)
            # The script is sourced only to inspect child inheritance; main and
            # teardown functions are never called, and no PBX path is mutated.
            result=subprocess.run(['/bin/bash','-c','source "$1"; run_as_asterisk /usr/bin/python3 -I "$2"',
                'fixture',str(ROOT/'tools/uninstall_release.sh'),str(probe)],env=self.environment,capture_output=True,text=True,timeout=5,check=False)
            self.assertEqual(result.returncode,0,result.stdout+result.stderr)
            value=json.loads(result.stdout);self.assertEqual(value['uid'],self.expected_uid);self.assertEqual(value['gid'],self.expected_gid)
            self.assertEqual(sorted(value['groups']),self.expected_groups);self.assertFalse(value['token_present'])

    def test_speech_transition_removes_inherited_environment(self):
        with tempfile.TemporaryDirectory(prefix='sls-speech-root-env-') as temporary:
            folder=Path(temporary);folder.chmod(0o755)
            shutil.copytree(ROOT/'slsmassnotifyserver/bin/sls_mass_notify',folder,dirs_exist_ok=True)
            source=(ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_speech_admission.py').read_text(encoding='utf-8')
            marker="        admission = Admission(read_private_json('mass-notifications.config'), args.max_seconds or 1)"
            prefix=source.split(marker,1)[0]
            if prefix==source:self.fail('Missing actual speech privilege transition')
            tail='''        try:
            initial=Path('/proc/self/environ').read_bytes()
        except PermissionError:
            initial=b''
        print(json.dumps({'uid':os.geteuid(),'gid':os.getegid(),'groups':os.getgroups(),'token_present':any(name in os.environ or (name+'=').encode() in initial for name in '''+repr(TOKENS)+''')}))
        return 0
    except Exception:
        return 1
if __name__=='__main__':raise SystemExit(main())
'''
            script=folder/'sls_speech_admission.py';script.write_text(prefix+tail,encoding='utf-8');script.chmod(0o644)
            result=subprocess.run(['/usr/bin/python3','-I',str(script),'--reserve-media-bytes','1'],env=self.environment,capture_output=True,text=True,timeout=5,check=False)
            self.assertEqual(result.returncode,0,result.stdout+result.stderr)
            value=json.loads(result.stdout);self.assertEqual(value['uid'],self.expected_uid);self.assertEqual(value['gid'],self.expected_gid)
            self.assertEqual(sorted(value['groups']),self.expected_groups);self.assertFalse(value['token_present'])


if __name__=='__main__':unittest.main()
