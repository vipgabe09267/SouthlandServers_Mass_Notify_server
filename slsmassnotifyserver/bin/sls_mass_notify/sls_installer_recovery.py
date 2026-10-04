#!/usr/bin/python3
"""Root-private static installation recovery, never executable recovery hooks.

The authenticated installer bootstraps this helper before taking the original
snapshot. Operational data, configuration, images and journals are excluded.
Restoring static bytes does not authorize executing an old release: SLS root
cron jobs remain disabled until an independently authenticated release is admitted.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import sys
import uuid

SAFE_UNIT = b'# Managed by SLS Mass Notify Server\n[Unit]\nDescription=SLS Mass Notify phone outcome collector\nAfter=network.target asterisk.service freepbx.service\nStartLimitIntervalSec=0\n\n[Service]\nType=simple\nUser=asterisk\nGroup=asterisk\nUMask=0027\nExecStart=/usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_phone_events.py\nRestart=on-failure\nRestartSec=5\nTimeoutStopSec=10\nNoNewPrivileges=yes\nPrivateTmp=yes\nProtectSystem=strict\nProtectHome=yes\nReadWritePaths=/var/lib/asterisk/SLS_Mass_Notifications_Plugin\nRestrictAddressFamilies=AF_UNIX AF_INET AF_INET6\nRestrictSUIDSGID=yes\nLockPersonality=yes\n\n[Install]\nWantedBy=multi-user.target\n'
SERVICE = 'sls-mass-notify-phone-events.service'
RUNTIME = '/usr/local/bin/sls_mass_notify'
TRUST = '/var/lib/sls-mass-notify-trust'
TREES = (RUNTIME, '/var/www/html/api/sipnotify', '/var/www/html/api/sls-mass-notify', '/var/www/html/sls_mass_notify/assets', '/var/www/html/mass-notify')
CUSTOM_RECORDINGS = tuple('/var/lib/asterisk/sounds/en/custom/' + name + '.wav' for name in (
 'SLS_Mass_Notify_Paging_Tone_Opening', 'SLS_Mass_Notify_Paging_Tone_Closing',
 'SLS_Mass_Notify_NWS_Alert', 'SLS_Mass_Notify_Lightning_Alert',
 'Paging_Tone_Opening', 'Paging_Tone_Closing', 'NWS_alert', 'Lightning_alert'))
FACTORY_TONES = tuple('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds/tones/' + name + '.wav' for name in (
 'opening_Paging_Tone_Opening', 'closing_Paging_Tone_Closing', 'opening_NWS_alert', 'opening_Lightning_alert'))
FIXED = (*CUSTOM_RECORDINGS, *FACTORY_TONES, '/usr/local/bin/slsconsole',
 '/usr/local/sbin/sign_sls_mass_notify_local_sig.sh',
 '/etc/systemd/system/sls-mass-notify-phone-events.service',
 '/etc/apache2/conf-available/sls-mass-notify.conf',
 '/etc/logrotate.d/sls-mass-notify',
 '/etc/asterisk/extensions_custom.conf', '/etc/asterisk/manager_custom.conf', '/etc/asterisk/sip_notify_custom.conf',
 '/var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php',
 '/var/www/html/admin/modules/dashboard/sections/Overview.class.php',
 '/var/www/html/admin/modules/dashboard/sections/NwsAlertsAnnouncement.class.php',
 '/var/www/html/admin/modules/dashboard/views/sections/slsmassnotifyserver-announcement.php',
 '/var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php',
 '/var/www/html/admin/modules/dashboard/module.sig',
 '/var/www/html/admin/modules/framework/module.sig',
 '/var/www/html/admin/views/menu_items.php',
 TRUST + '/slsmassnotifyserver.active.json', TRUST + '/dashboard.active.json', TRUST + '/framework.active.json',
)
LINKS = {
 '/etc/systemd/system/multi-user.target.wants/sls-mass-notify-phone-events.service': '/etc/systemd/system/sls-mass-notify-phone-events.service',
 '/etc/apache2/conf-enabled/sls-mass-notify.conf': '../conf-available/sls-mass-notify.conf',
 '/var/lib/asterisk/sounds/SLS_Mass_Notifications_Plugin': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds',
 '/var/lib/asterisk/sounds/en/SLS_Mass_Notifications_Plugin': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds',
}
MAX_FILES = 100000
MAX_FILE = 128 * 1024 * 1024
MAX_TOTAL = 2 * 1024 * 1024 * 1024
MAX_MANIFEST = 32 * 1024 * 1024
CRON_MARKERS = ('/usr/local/bin/sls_mass_notify/', '/usr/local/sbin/sign_sls_mass_notify_local_sig.sh',
                '/var/www/html/admin/modules/slsmassnotifyserver/', 'SLS_Mass_Notifications_Plugin')
USER_CRON_JOB = re.compile(rb'(?:^|[\s/])(?:sls_mass_notify_(?:weather_poll|nws_poll|schedule_worker|announcement_worker|update|maintenance)\.(?:sh|php)|nws_weather_alert\.sh|nwsalerts_ensure_menu_patch\.sh)(?=$|[\s\"\'])')

def sls_user_job(line):
    return not line.lstrip().startswith(b'#') and USER_CRON_JOB.search(line) is not None

class RecoveryError(RuntimeError): pass

def fingerprint(s):
    return (s.st_dev, s.st_ino, s.st_size, s.st_mtime_ns, s.st_ctime_ns, s.st_nlink)

def root_file(path):
    return path.startswith(RUNTIME + '/') or path == RUNTIME or path.startswith(TRUST + '/') or path.startswith('/usr/local/sbin/') or path.startswith('/etc/systemd/') or path.startswith('/etc/apache2/') or path.startswith('/etc/logrotate.')

def safe_piper_link(path, target):
    relative=path.removeprefix(RUNTIME+'/piper/')
    relative=re.sub(r'^\.replacement-[a-f0-9]{24}/','',relative)
    return (relative=='venv/lib64' and target=='lib') or (bool(re.fullmatch(r'venv/bin/python(?:3(?:\.[0-9]{1,2})?)?',relative)) and bool(re.fullmatch(r'(?:/usr/bin/)?python3(?:\.[0-9]{1,2})?',target)))

def allowed(path):
    if not isinstance(path,str) or str(Path(path)) != path or '..' in Path(path).parts or any(ord(c)<32 for c in path): return False
    return path in FIXED or path in LINKS or any(path == p or path.startswith(p + '/') for p in TREES)

class Recovery:
    def __init__(self, snapshot, prefix='/', cron=None, command=None, user_cron=None):
        self.snapshot=Path(snapshot)
        self.prefix=Path(prefix)
        self.uid=pwd.getpwnam('asterisk').pw_uid
        self.cron=cron or self.system_cron
        self.user_cron=user_cron or (lambda body=None: self.system_cron(body,user='asterisk'))
        self.command=command or self.system_command
        self.total=0
        if not self.snapshot.is_absolute() or '..' in self.snapshot.parts: raise RecoveryError('Snapshot path must be canonical and absolute')

    @staticmethod
    def directory(path, protected=False, create=False):
        fd=os.open('/', os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC)
        try:
            for part in Path(path).parts[1:]:
                if create:
                    try: os.mkdir(part, 0o755, dir_fd=fd)
                    except FileExistsError: pass
                child=os.open(part,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=fd)
                os.close(fd); fd=child
                s=os.fstat(fd)
                if protected and (s.st_uid != 0 or (s.st_mode & 0o022 and not s.st_mode & stat.S_ISVTX)):
                    raise RecoveryError('Unprotected root recovery/runtime ancestor: ' + str(path))
            result,fd=fd,-1
            return result
        finally:
            if fd>=0: os.close(fd)

    def dest(self,path): return self.prefix.joinpath(*Path(path).parts[1:])

    @staticmethod
    def read_at(parent,name,limit,protected=False):
        fd=os.open(name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK|os.O_CLOEXEC,dir_fd=parent)
        try:
            before=os.fstat(fd)
            if not stat.S_ISREG(before.st_mode) or before.st_nlink!=1 or before.st_size>limit:
                raise RecoveryError('Unsafe, linked or oversized recovery file: '+name)
            if protected and (before.st_uid!=0 or before.st_mode&0o022): raise RecoveryError('Unprotected recovery source: '+name)
            body=bytearray()
            while len(body)<=limit:
                chunk=os.read(fd,min(1024*1024,limit+1-len(body)))
                if not chunk: break
                body.extend(chunk)
            after=os.fstat(fd); current=os.stat(name,dir_fd=parent,follow_symlinks=False)
            if len(body)>limit or fingerprint(before)!=fingerprint(after) or fingerprint(after)!=fingerprint(current):
                raise RecoveryError('Recovery source changed during read: '+name)
            return bytes(body),before
        finally: os.close(fd)

    @staticmethod
    def write_at(parent,name,body,mode=0o600,uid=0,gid=0):
        temp='.sls-recovery-'+uuid.uuid4().hex
        fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_CLOEXEC,0o600,dir_fd=parent)
        try:
            view=memoryview(body)
            while view:
                n=os.write(fd,view)
                if n<=0: raise RecoveryError('Short recovery write')
                view=view[n:]
            os.fchown(fd,uid,gid); os.fchmod(fd,mode); os.fsync(fd)
        finally: os.close(fd)
        try:
            os.replace(temp,name,src_dir_fd=parent,dst_dir_fd=parent); os.fsync(parent)
        finally:
            try: os.unlink(temp,dir_fd=parent)
            except FileNotFoundError: pass

    @staticmethod
    def system_cron(body=None,user='root'):
        if user not in ('root','asterisk') or body is not None and (not isinstance(body,bytes) or len(body)>1024*1024):
            raise RecoveryError('Invalid recovery crontab account or size')
        args=['/usr/bin/crontab','-u',user]+(['-l'] if body is None else ['-'])
        # crontab output is bounded on both read and write; no shell interpolation.
        import tempfile
        with tempfile.TemporaryFile() as output:
            result=subprocess.run(args,input=body,stdout=output,stderr=output,timeout=15,env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C'})
            if output.tell()>1024*1024: raise RecoveryError('Root crontab exceeds recovery bound')
            output.seek(0); value=output.read()
        if body is None and result.returncode==1 and value.strip()==('no crontab for '+user).encode(): return b''
        if result.returncode: raise RecoveryError('Root crontab operation failed')
        return value

    @staticmethod
    def system_command(args,check=True):
        import tempfile
        with tempfile.TemporaryFile() as output:
            result=subprocess.run(args,stdout=output,stderr=output,timeout=45,env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C'})
            if output.tell()>1024*1024: raise RecoveryError('Recovery service command exceeded output bound')
            output.seek(0); body=output.read()
        if check and result.returncode: raise RecoveryError('Fixed recovery service command failed: '+args[0])
        return body.decode('utf-8',errors='strict').strip()

    def capture_service_state(self):
        output=self.command(['/usr/bin/systemctl','show',SERVICE,'--property=LoadState,UnitFileState,ActiveState'])
        values=dict(line.split('=',1) for line in output.splitlines() if '=' in line)
        state={'enabled':'not-found' if values.get('LoadState')=='not-found' else values.get('UnitFileState'),
               'active':values.get('ActiveState')}
        self.valid_service_state(state)
        return state

    @staticmethod
    def valid_service_state(state):
        if (not isinstance(state,dict) or set(state)!={'enabled','active'}
            or state['enabled'] not in ('enabled','disabled','static','indirect','enabled-runtime','masked','masked-runtime','not-found')
            or state['active'] not in ('active','inactive','failed','unknown')):
            raise RecoveryError('Collector service state is transitional or unrecognized; retry after it stabilizes')

    def service_state(self):
        return {'ok':True,'service':SERVICE,'service_state':self.load()['service_state'],'automatic_activation_safe':False}

    def restore_services(self):
        self.verify()
        state=self.load()['service_state']
        if state['enabled'] not in ('enabled','disabled','not-found'):
            raise RecoveryError('Prior collector enable state needs explicit manual recovery')
        unitpath='/etc/systemd/system/'+SERVICE
        if state['enabled']!='not-found':
            parent=self.directory(self.dest(unitpath).parent,protected=True)
            try: unit,_=self.read_at(parent,SERVICE,MAX_FILE,True)
            finally: os.close(parent)
            if unit!=SAFE_UNIT: raise RecoveryError('Prior collector unit is not the fixed unprivileged recovery unit; manual activation required')
        self.command(['/usr/bin/systemctl','daemon-reload'])
        if state['enabled']!='not-found':
            loaded=self.command(['/usr/bin/systemctl','show',SERVICE,'--property=User,Group,FragmentPath,DropInPaths'])
            properties=dict(line.split('=',1) for line in loaded.splitlines() if '=' in line)
            expected={'User':'asterisk','Group':'asterisk','FragmentPath':unitpath,'DropInPaths':''}
            if properties!=expected: raise RecoveryError('Loaded collector unit contains unexpected identity, fragment or overrides')
            self.command(['/usr/bin/systemctl','enable' if state['enabled']=='enabled' else 'disable',SERVICE])
        self.command(['/usr/bin/systemctl','restart' if state['active']=='active' else 'stop',SERVICE],state['enabled']!='not-found')
        actual=self.capture_service_state()
        if actual['enabled']!=state['enabled'] or (actual['active']=='active')!=(state['active']=='active'):
            raise RecoveryError('Collector process/enable-state recovery did not satisfy prior state')
        self.command(['/usr/sbin/apache2ctl','configtest'])
        self.command(['/usr/bin/systemctl','reload','apache2'])
        return {'ok':True,'collector_state_restored':True,'apache_reloaded':True,'automatic_activation_safe':False}

    def inspect(self,path,blobfd=None,records=None):
        if not allowed(path): raise RecoveryError('Path outside fixed recovery inventory')
        if records is not None and len(records)>=MAX_FILES: raise RecoveryError('Static snapshot exceeds entry bound')
        target=self.dest(path)
        try: parent=self.directory(target.parent,protected=root_file(path))
        except FileNotFoundError:
            if records is not None: records[path]={'kind':'absent'}
            return
        try:
            try: s=os.stat(target.name,dir_fd=parent,follow_symlinks=False)
            except FileNotFoundError:
                if records is not None: records[path]={'kind':'absent'}
                return
            if s.st_uid not in (0,self.uid) or (root_file(path) and (s.st_uid!=0 or (not stat.S_ISLNK(s.st_mode) and s.st_mode&0o022))):
                raise RecoveryError('Untrusted ownership/mode in static inventory: '+path)
            if path not in TREES and s.st_dev!=os.fstat(parent).st_dev: raise RecoveryError('Mounted child in static inventory: '+path)
            entry={'uid':s.st_uid,'gid':s.st_gid,'mode':stat.S_IMODE(s.st_mode)}
            if s.st_mode&0o7000: raise RecoveryError('Special permissions in static inventory: '+path)
            if stat.S_ISDIR(s.st_mode):
                if path not in TREES and not any(path.startswith(p+'/') for p in TREES): raise RecoveryError('Expected fixed file, found directory: '+path)
                entry['kind']='dir'
                child=os.open(target.name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=parent)
                try:
                    names=sorted(os.listdir(child))
                    if (os.fstat(child).st_dev,os.fstat(child).st_ino)!=(s.st_dev,s.st_ino): raise RecoveryError('Directory changed during snapshot')
                    if len(names)>MAX_FILES: raise RecoveryError('Static directory exceeds entry bound')
                    if records is not None: records[path]=entry
                    for name in names: self.inspect(path+'/'+name,blobfd,records)
                    if fingerprint(s)!=fingerprint(os.stat(target.name,dir_fd=parent,follow_symlinks=False)):
                        raise RecoveryError('Directory changed during snapshot: '+path)
                finally: os.close(child)
                return
            if stat.S_ISREG(s.st_mode):
                body,opened=self.read_at(parent,target.name,MAX_FILE,root_file(path))
                if fingerprint(s)!=fingerprint(opened): raise RecoveryError('File replaced during snapshot')
                self.total+=len(body)
                if self.total>MAX_TOTAL: raise RecoveryError('Static snapshot exceeds 2 GiB bound')
                digest=hashlib.sha256(body).hexdigest()
                entry.update(kind='file',sha256=digest,size=len(body))
                if blobfd is not None:
                    try: os.stat(digest,dir_fd=blobfd,follow_symlinks=False)
                    except FileNotFoundError: self.write_at(blobfd,digest,body)
            elif stat.S_ISLNK(s.st_mode):
                link=os.readlink(target.name,dir_fd=parent)
                if path in LINKS:
                    if link!=LINKS[path]: raise RecoveryError('Unexpected fixed symlink target: '+path)
                elif not path.startswith(RUNTIME+'/piper/'):
                    raise RecoveryError('Unexpected symlink in static inventory: '+path)
                elif s.st_uid!=0 or not safe_piper_link(path,link):
                    raise RecoveryError('Unsafe Piper symlink')
                if fingerprint(s)!=fingerprint(os.stat(target.name,dir_fd=parent,follow_symlinks=False)):
                    raise RecoveryError('Symlink changed during snapshot')
                entry.update(kind='link',target=link)
            else: raise RecoveryError('Special file in static recovery inventory: '+path)
            if records is not None:
                records[path]=entry
                if len(records)>MAX_FILES: raise RecoveryError('Static snapshot exceeds entry bound')
        finally: os.close(parent)

    def snapshot_create(self):
        parent=self.directory(self.snapshot.parent,protected=True)
        try:
            owner=os.fstat(parent)
            if owner.st_uid!=0 or stat.S_IMODE(owner.st_mode)!=0o700: raise RecoveryError('Recovery parent must be root-owned mode 0700')
            try: os.stat(self.snapshot.name,dir_fd=parent,follow_symlinks=False)
            except FileNotFoundError: pass
            else: raise RecoveryError('Original snapshot already exists; refusing replacement')
            stage='.static-building-'+uuid.uuid4().hex
            os.mkdir(stage,0o700,dir_fd=parent)
            stagefd=os.open(stage,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=parent)
            try:
                os.mkdir('blobs',0o700,dir_fd=stagefd)
                blobs=os.open('blobs',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=stagefd)
                try:
                    records={}; self.total=0
                    for path in (*TREES,*FIXED,*LINKS): self.inspect(path,blobs,records)
                    cron=self.cron()
                    if len(cron)>1024*1024: raise RecoveryError('Root crontab exceeds recovery bound')
                    self.write_at(stagefd,'root-cron.original',cron)
                    user_cron=self.user_cron()
                    if len(user_cron)>1024*1024: raise RecoveryError('Asterisk crontab exceeds recovery bound')
                    self.write_at(stagefd,'asterisk-cron.original',user_cron)
                    manifest={'schema':1,'records':records,'cron_sha256':hashlib.sha256(cron).hexdigest(),'user_cron_sha256':hashlib.sha256(user_cron).hexdigest(),'automatic_activation_safe':False,'service_state':self.capture_service_state()}
                    self.write_at(stagefd,'manifest.json',json.dumps(manifest,sort_keys=True,separators=(',',':')).encode())
                    os.fsync(blobs); os.fsync(stagefd)
                finally: os.close(blobs)
            finally: os.close(stagefd)
            # Original private snapshot is never replaced; failed stage is retained.
            os.rename(stage,self.snapshot.name,src_dir_fd=parent,dst_dir_fd=parent); os.fsync(parent)
        finally: os.close(parent)
        return {'ok':True,'snapshot':str(self.snapshot),'automatic_activation_safe':False}

    def load(self):
        fd=self.directory(self.snapshot,protected=True)
        try:
            if stat.S_IMODE(os.fstat(fd).st_mode)!=0o700: raise RecoveryError('Snapshot must remain root-only')
            raw,_=self.read_at(fd,'manifest.json',MAX_MANIFEST,True)
            manifest=json.loads(raw)
            records=manifest.get('records')
            self.valid_service_state(manifest.get('service_state'))
            if manifest.get('schema')!=1 or not isinstance(records,dict) or len(records)>MAX_FILES: raise RecoveryError('Invalid static manifest')
            if not all(p in records for p in (*TREES,*FIXED,*LINKS)): raise RecoveryError('Incomplete fixed inventory')
            blobs=os.open('blobs',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=fd)
            try:
                bstat=os.fstat(blobs)
                if bstat.st_uid!=0 or stat.S_IMODE(bstat.st_mode)!=0o700: raise RecoveryError('Unprotected recovery blobs')
                total=0
                for path,entry in records.items():
                    if not allowed(path) or not isinstance(entry,dict) or entry.get('kind') not in ('absent','file','dir','link'): raise RecoveryError('Invalid recovery entry')
                    if entry['kind']=='absent': continue
                    if entry.get('uid') not in (0,self.uid) or type(entry.get('gid')) is not int or entry['gid']<0 or type(entry.get('mode')) is not int or entry['mode']&~0o777:
                        raise RecoveryError('Invalid recovery ownership/mode')
                    if root_file(path) and (entry['uid']!=0 or (entry['kind']!='link' and entry['mode']&0o022)): raise RecoveryError('Unsafe root runtime record')
                    if entry['kind']=='file':
                        digest=entry.get('sha256','')
                        if not isinstance(digest,str) or not re.fullmatch('[a-f0-9]{64}',digest): raise RecoveryError('Invalid blob identity')
                        body,_=self.read_at(blobs,digest,MAX_FILE,True); total+=len(body)
                        if total>MAX_TOTAL or len(body)!=entry.get('size') or hashlib.sha256(body).hexdigest()!=digest: raise RecoveryError('Recovery blob verification failed')
                    if entry['kind']=='link':
                        target=entry.get('target')
                        if not isinstance(target,str) or not target or len(target)>4096 or any(ord(c)<32 for c in target): raise RecoveryError('Invalid recovery link')
                        if path in LINKS:
                            if target!=LINKS[path]: raise RecoveryError('Invalid fixed recovery link')
                        elif not safe_piper_link(path,target) or entry['uid']!=0: raise RecoveryError('Invalid recovery symlink path')
                    if entry['kind']=='dir' and not any(path==p or path.startswith(p+'/') for p in TREES): raise RecoveryError('Invalid recovery directory')
                cron,_=self.read_at(fd,'root-cron.original',1024*1024,True)
                if hashlib.sha256(cron).hexdigest()!=manifest.get('cron_sha256'): raise RecoveryError('Original root cron verification failed')
                user_cron,_=self.read_at(fd,'asterisk-cron.original',1024*1024,True)
                if hashlib.sha256(user_cron).hexdigest()!=manifest.get('user_cron_sha256'): raise RecoveryError('Original asterisk cron verification failed')
            finally: os.close(blobs)
            return manifest
        finally: os.close(fd)

    def remove(self,path):
        target=self.dest(path)
        try: parent=self.directory(target.parent,protected=root_file(path))
        except FileNotFoundError: return
        try:
            try: s=os.stat(target.name,dir_fd=parent,follow_symlinks=False)
            except FileNotFoundError: return
            if stat.S_ISDIR(s.st_mode):
                child=os.open(target.name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=parent)
                try: names=os.listdir(child)
                finally: os.close(child)
                for name in names: self.remove(path+'/'+name)
                os.rmdir(target.name,dir_fd=parent)
            else:
                if not (stat.S_ISREG(s.st_mode) and s.st_nlink==1) and not stat.S_ISLNK(s.st_mode): raise RecoveryError('Unsafe replacement encountered during recovery')
                os.unlink(target.name,dir_fd=parent)
            os.fsync(parent)
        finally: os.close(parent)

    def original_user_jobs(self):
        fd=self.directory(self.snapshot,protected=True)
        try: body,_=self.read_at(fd,'asterisk-cron.original',1024*1024,True)
        finally: os.close(fd)
        return [line for line in body.splitlines() if sls_user_job(line)]

    def restore_user_jobs(self,disable=False):
        current=self.user_cron()
        if len(current)>1024*1024: raise RecoveryError('Current asterisk crontab exceeds recovery bound')
        unrelated=[line for line in current.splitlines() if not sls_user_job(line)]
        jobs=[] if disable else self.original_user_jobs()
        replacement=b'\n'.join(unrelated+jobs)
        if replacement: replacement+=b'\n'
        if len(replacement)>1024*1024: raise RecoveryError('Restored asterisk crontab exceeds recovery bound')
        if replacement!=current: self.user_cron(replacement)

    def restore(self):
        manifest=self.load(); records=manifest['records']
        # Preflight every current destination before any restore mutation.
        current={}; self.total=0
        for path in (*TREES,*FIXED,*LINKS): self.inspect(path,records=current)
        snapfd=self.directory(self.snapshot,protected=True)
        blobs=os.open('blobs',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=snapfd)
        try:
            # Do not revive legacy root PHP/module bootstrap jobs. Preserve current
            # unrelated jobs, including administrator changes made during upgrade.
            current_cron=self.cron()
            safe=b'\n'.join(line for line in current_cron.splitlines() if not any(marker.encode() in line for marker in CRON_MARKERS))
            safe=(safe+b'\n') if safe else b''
            if safe!=current_cron: self.cron(safe)
            self.restore_user_jobs(disable=True)
            self.write_at(snapfd,'restore-started.json',b'{"automatic_activation_safe":false,"state":"restoring"}')
            for path in sorted(current,key=lambda p:(p.count('/'),p),reverse=True):
                if current[path]['kind']!='absent' and (path not in records or records[path]['kind']=='absent' or records[path]['kind']!=current[path]['kind']): self.remove(path)
            for path,entry in sorted(records.items(),key=lambda item:(item[0].count('/'),item[0])):
                kind=entry['kind']
                if kind=='absent': continue
                target=self.dest(path); parent=self.directory(target.parent,protected=root_file(path),create=True)
                try:
                    if kind=='dir':
                        try: os.mkdir(target.name,entry['mode'],dir_fd=parent)
                        except FileExistsError: pass
                        child=os.open(target.name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=parent)
                        try: os.fchown(child,entry['uid'],entry['gid']); os.fchmod(child,entry['mode']); os.fsync(child)
                        finally: os.close(child)
                    elif kind=='file':
                        body,_=self.read_at(blobs,entry['sha256'],MAX_FILE,True)
                        if hashlib.sha256(body).hexdigest()!=entry['sha256']: raise RecoveryError('Recovery blob changed')
                        self.write_at(parent,target.name,body,entry['mode'],entry['uid'],entry['gid'])
                    else:
                        try: old=os.stat(target.name,dir_fd=parent,follow_symlinks=False)
                        except FileNotFoundError: old=None
                        if old is not None:
                            if not stat.S_ISLNK(old.st_mode): raise RecoveryError('Link destination changed during restore')
                            os.unlink(target.name,dir_fd=parent)
                        os.symlink(entry['target'],target.name,dir_fd=parent)
                        os.chown(target.name,entry['uid'],entry['gid'],dir_fd=parent,follow_symlinks=False)
                    os.fsync(parent)
                finally: os.close(parent)
            self.restore_user_jobs()
            self.verify()
            report={'ok':True,'automatic_activation_safe':False,'sls_root_jobs_disabled':True,
                    'message':'Static files restored. Admit an authenticated prior release before enabling SLS root jobs or activating its services.'}
            self.write_at(snapfd,'restore-result.json',json.dumps(report,sort_keys=True).encode())
            return report
        finally: os.close(blobs); os.close(snapfd)

    def verify(self):
        manifest=self.load(); current={}; self.total=0
        for path in (*TREES,*FIXED,*LINKS): self.inspect(path,records=current)
        if current!=manifest['records']: raise RecoveryError('Restored static inventory differs from original snapshot')
        if [line for line in self.user_cron().splitlines() if sls_user_job(line)]!=self.original_user_jobs():
            raise RecoveryError('Restored SLS user jobs differ from their original schedule')
        if any(any(marker.encode() in line for marker in CRON_MARKERS) for line in self.cron().splitlines()):
            raise RecoveryError('SLS root jobs must remain disabled until authenticated activation')
        return {'ok':True,'snapshot_verified':True,'restored_static_verified':True,'automatic_activation_safe':False}

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--snapshot',required=True)
    parser.add_argument('action',choices=('snapshot','restore','verify','service-state','restore-services'))
    args=parser.parse_args()
    if os.geteuid()!=0: raise RecoveryError('Static recovery requires root')
    # Do not load sibling Python or installed PHP. Installer must authenticate
    # this standalone helper before placing it in a protected bootstrap directory.
    here=Path(__file__).absolute(); parent=Recovery.directory(here.parent,protected=True)
    try: Recovery.read_at(parent,here.name,MAX_FILE,True)
    finally: os.close(parent)
    recovery=Recovery(args.snapshot)
    result=recovery.snapshot_create() if args.action=='snapshot' else getattr(recovery,args.action.replace('-','_'))()
    print(json.dumps(result,sort_keys=True))

if __name__=='__main__':
    try: main()
    except (RecoveryError,OSError,ValueError,KeyError,TypeError,subprocess.SubprocessError) as error:
        print('SLS static recovery failed: '+str(error),file=sys.stderr)
        sys.exit(1)
