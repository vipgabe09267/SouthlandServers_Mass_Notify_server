#!/usr/bin/python3
"""Run a signed offline installer on disposable QEMU FreePBX images.

Never connects to an external PBX. The only guest endpoint is the loopback
forward created by this process, and guest outbound networking is disabled.
The supplied, SHA-256 reviewed image must be sanitized and explicitly marked
with /etc/sls-qualification-vm containing SLS_DISPOSABLE_QUALIFICATION_ONLY.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import socket
import subprocess
import tempfile
import time

SCENARIOS=('install','inactive-cron','missing-dashboard','term','int','hup','power-loss')


def run(args,timeout=120,**kwargs):
    return subprocess.run(args,check=True,timeout=timeout,stdin=subprocess.DEVNULL,**kwargs)


class Guest:
    def __init__(self,image,scratch,ram):
        self.image=image;self.scratch=scratch;self.ram=ram;self.process=None
        information=json.loads(subprocess.run(['qemu-img','info','-f','qcow2','--output=json',str(image)],check=True,capture_output=True,text=True,timeout=20).stdout)
        if information.get('backing-filename') or information.get('full-backing-filename') or 'data-file' in json.dumps(information):
            raise RuntimeError('Use a standalone reviewed qcow2 image without backing files or external data files.')
        self.key=scratch/'key';self.disk=scratch/'guest.qcow2'
        run(['ssh-keygen','-q','-t','ed25519','-N','','-f',str(self.key)],stdout=subprocess.DEVNULL)
        run(['qemu-img','create','-q','-f','qcow2','-F','qcow2','-b',str(image),str(self.disk)])
        run(['virt-customize','--no-network','-a',str(self.disk),'--ssh-inject','root:file:'+str(self.key)+'.pub'],timeout=300,stdout=subprocess.DEVNULL)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1',0));self.port=listener.getsockname()[1]
        self.ssh_args=['ssh','-F','/dev/null','-i',str(self.key),'-p',str(self.port),
            '-o','BatchMode=yes','-o','IdentityAgent=none','-o','ForwardAgent=no',
            '-o','StrictHostKeyChecking=accept-new','-o','UserKnownHostsFile='+str(scratch/'known_hosts'),
            '-o','ConnectTimeout=3','root@127.0.0.1']
    def start(self):
        accelerator='kvm' if os.access('/dev/kvm',os.R_OK|os.W_OK) else 'tcg'
        self.process=subprocess.Popen(['qemu-system-x86_64','-accel',accelerator,'-m',str(self.ram),'-smp','2',
            '-display','none','-serial','file:'+str(self.scratch/'serial.log'),'-no-reboot',
            '-drive','file='+str(self.disk)+',if=virtio,format=qcow2',
            '-netdev','user,id=lab,restrict=on,hostfwd=tcp:127.0.0.1:'+str(self.port)+'-:22',
            '-device','virtio-net-pci,netdev=lab'],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
        deadline=time.monotonic()+300
        while time.monotonic()<deadline:
            if self.process.poll() is not None: raise RuntimeError('Disposable VM failed to start; see its serial/error log.')
            result=subprocess.run(self.ssh_args+['true'],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=5)
            if result.returncode==0:
                self.ssh('test "$(cat /etc/sls-qualification-vm)" = SLS_DISPOSABLE_QUALIFICATION_ONLY')
                return
            time.sleep(2)
        raise RuntimeError('Disposable VM did not become ready.')
    def ssh(self,command,timeout=120,check=True):
        result=subprocess.run(self.ssh_args+[command],stdin=subprocess.DEVNULL,capture_output=True,text=True,timeout=timeout)
        if check and result.returncode: raise RuntimeError('Guest command failed: '+command+'\n'+result.stdout+result.stderr)
        return result
    def copy(self,bundle):
        options=self.ssh_args[1:-1]
        # scp uses -P; all identity, host-key and forwarding restrictions remain.
        options[options.index('-p')]='-P'
        run(['scp',*options,'-r',str(bundle),'root@127.0.0.1:/root/sls-qualification-bundle'],timeout=300,stdout=subprocess.DEVNULL)
    def stop(self,abrupt=False):
        if self.process is not None and self.process.poll() is None:
            self.process.kill() if abrupt else self.process.terminate()
            try:self.process.wait(timeout=15)
            except subprocess.TimeoutExpired:self.process.kill();self.process.wait(timeout=10)


def installation_command():
    return 'source /root/sls-qualification-environment; bash /root/sls-qualification-bundle/install_release.sh'


def scenario(guest,bundle,name,baseline):
    guest.start();guest.copy(bundle)
    # The prepared image must include Debian dependencies and a sanitized local
    # PBX. No carrier, provider, mail or production network is reachable here.
    installed=guest.ssh("python3 -c 'import pathlib,xml.etree.ElementTree as E;p=pathlib.Path(\"/var/www/html/admin/modules/slsmassnotifyserver/module.xml\");print(E.fromstring(p.read_bytes()).findtext(\"version\") if p.exists() else \"fresh\")'").stdout.strip()
    if installed!=baseline:raise RuntimeError('VM baseline mismatch: expected '+baseline+', observed '+installed)
    # Bundle path names are fixed and shell-quoted. No credentials are copied.
    packages=list(bundle.glob('slsmassnotifyserver-*.tgz'))
    if len(packages)!=1:raise RuntimeError('Provide exactly one signed candidate package.')
    values={'SLS_MASS_NOTIFY_TGZ':'/root/sls-qualification-bundle/'+packages[0].name,
        'SLS_MASS_NOTIFY_MANIFEST':'/root/sls-qualification-bundle/release-manifest.json',
        'SLS_MASS_NOTIFY_MANIFEST_SIGNATURE':'/root/sls-qualification-bundle/release-manifest.sig',
        'SLS_MASS_NOTIFY_UPSTREAM_PACKAGES':'/root/sls-qualification-bundle/upstream',
        'SLS_MASS_NOTIFY_OFFLINE_ASSETS':'/root/sls-qualification-bundle/assets'}
    dashboards=list((bundle/'upstream').glob('dashboard-*.tgz.gpg'))
    if dashboards:
        versions=[path.name[len('dashboard-'):-8] for path in dashboards]
        values['SLS_MASS_NOTIFY_BOOTSTRAP_DASHBOARD_VERSION']=max(versions,key=lambda item:tuple(map(int,item.split('.'))))
    previous=bundle/'previous'
    if previous.exists():
        old=list(previous.glob('slsmassnotifyserver-*.tgz'))
        if len(old)!=1:raise RuntimeError('Provide one signed previous release.')
        values.update({'SLS_MASS_NOTIFY_PREVIOUS_TGZ':'/root/sls-qualification-bundle/previous/'+old[0].name,
            'SLS_MASS_NOTIFY_PREVIOUS_MANIFEST':'/root/sls-qualification-bundle/previous/release-manifest.json',
            'SLS_MASS_NOTIFY_PREVIOUS_MANIFEST_SIGNATURE':'/root/sls-qualification-bundle/previous/release-manifest.sig'})
    script='\n'.join('export '+key+'='+shlex.quote(value) for key,value in values.items())+'\n'
    guest.ssh('umask 077; printf %s '+shlex.quote(script)+' > /root/sls-qualification-environment')
    guest.ssh("python3 - <<'PY'\nimport hashlib,pathlib\np=pathlib.Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')\nrows={}\nfor name in ('mass-notifications.config','mass-notifications.pending.config'):\n f=p/name\n if f.exists():rows[name]=hashlib.sha256(f.read_bytes()).hexdigest()\nimport json;pathlib.Path('/root/sls-config-before.json').write_text(json.dumps(rows))\nPY")
    if name=='inactive-cron':guest.ssh('systemctl stop cron')
    if name=='missing-dashboard':
        if baseline!='fresh':raise RuntimeError('Absent Dashboard scenario needs a fresh sanitized FreePBX image.')
        guest.ssh('mv /var/www/html/admin/modules/dashboard /root/dashboard-before-fixture')
    if name in ('term','int','hup','power-loss'):
        start="import os,pathlib,subprocess\noutput=open('/root/sls-test-install.log','wb')\np=subprocess.Popen(['/usr/bin/bash','/root/sls-qualification-bundle/install_release.sh'],env={**os.environ,**"+repr(values)+"},stdin=subprocess.DEVNULL,stdout=output,stderr=subprocess.STDOUT,start_new_session=True)\npathlib.Path('/root/sls-test-install.pid').write_text(str(p.pid))\n"
        guest.ssh('python3 -c '+shlex.quote(start))
        guest.ssh("timeout 900 bash -c 'until python3 -c '\"'\"'import json,pathlib,sys;sys.exit(0 if any(json.loads(p.read_text()).get(\"phase\")==\"mutating\" for p in pathlib.Path(\"/var/lib/sls-mass-notify-installer\").glob(\"transaction-*/transaction.json\")) else 1)'\"'\"'; do sleep .2; done'",timeout=910)
        if name=='power-loss':
            guest.stop(abrupt=True);guest.start()
            blocked=guest.ssh(installation_command(),timeout=300,check=False)
            if blocked.returncode!=3:raise RuntimeError('A power-interrupted transaction was not blocked before mutation.')
            directory=guest.ssh("python3 -c 'import json,pathlib;print(next(str(p.parent) for p in pathlib.Path(\"/var/lib/sls-mass-notify-installer\").glob(\"transaction-*/transaction.json\") if json.loads(p.read_text())[\"phase\"] not in (\"committed\",\"rolled_back\",\"abandoned\")))'").stdout.strip()
            guest.ssh('bash /root/sls-qualification-bundle/install_release.sh --recover '+shlex.quote(directory),timeout=1800)
        else:
            guest.ssh('kill -'+name.upper()+' "$(cat /root/sls-test-install.pid)"')
            guest.ssh("timeout 900 bash -c 'while kill -0 \"$(cat /root/sls-test-install.pid)\" 2>/dev/null; do sleep 1; done'",timeout=910)
    result=guest.ssh(installation_command(),timeout=3600,check=False)
    if result.returncode:raise RuntimeError('Installer failed in '+name+': '+result.stdout+result.stderr)
    guest.ssh('fwconsole reload',timeout=180)
    guest.ssh("python3 - <<'PY'\nimport hashlib,json,pathlib\np=pathlib.Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')\nfor name,digest in json.loads(pathlib.Path('/root/sls-config-before.json').read_text()).items():\n assert hashlib.sha256((p/name).read_bytes()).hexdigest()==digest,'Protected settings changed: '+name\nPY")
    doctor=guest.ssh('/usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_install_doctor.py --json',timeout=60)
    return {'scenario':name,'baseline':baseline,'ok':True,'doctor':json.loads(doctor.stdout)}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image',type=Path,required=True);parser.add_argument('--image-sha256',required=True)
    parser.add_argument('--bundle',type=Path,required=True);parser.add_argument('--baseline',choices=('fresh','0.1.4-beta','0.1.5-beta'),required=True)
    parser.add_argument('--scenarios',nargs='+',choices=SCENARIOS,default=list(SCENARIOS))
    parser.add_argument('--report',type=Path,required=True)
    parser.add_argument('--allow-disposable-vm',action='store_true')
    args=parser.parse_args()
    if not args.allow_disposable_vm:parser.error('Explicit --allow-disposable-vm is required; this tool is never a production PBX test.')
    if not re.fullmatch(r'[a-f0-9]{64}',args.image_sha256):parser.error('Use an independently reviewed SHA-256 for the sanitized qcow2 image.')
    with args.image.open('rb') as stream:
        if hashlib.file_digest(stream,'sha256').hexdigest()!=args.image_sha256:raise SystemExit('VM image digest differs from reviewed evidence.')
    for utility in ('qemu-img','qemu-system-x86_64','virt-customize','ssh','scp','ssh-keygen'):
        if shutil.which(utility) is None:raise SystemExit('Disposable VM controller requires '+utility)
    import importlib.util
    spec=importlib.util.spec_from_file_location('offline_validation',Path(__file__).with_name('prepare_offline_install.py'))
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);module.validate(args.bundle.absolute())
    results=[]
    for name in args.scenarios:
        with tempfile.TemporaryDirectory(prefix='sls-freepbx-vm-') as temporary:
            guest=Guest(args.image.absolute(),Path(temporary),4096)
            try:results.append(scenario(guest,args.bundle.absolute(),name,args.baseline))
            finally:guest.stop()
    args.report.write_text(json.dumps({'schema':1,'profile':'FreePBX17/Debian12/amd64/2vCPU/4096MiB','results':results},indent=2)+'\n')


if __name__=='__main__':main()
