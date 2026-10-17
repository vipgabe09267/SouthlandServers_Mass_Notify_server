#!/usr/bin/python3
"""Read-only SLS installation diagnostics and redacted deployment profile."""
from __future__ import annotations

import argparse
import datetime
from concurrent.futures import ThreadPoolExecutor
import http.client
import json
import math
import os
from pathlib import Path
import platform
import pwd
import re
import selectors
import socket
import ssl
import stat
import subprocess
import sys
import threading
import time
import xml.etree.ElementTree as ET

PROFILE = {'id': 'freepbx17-debian12-amd64', 'freepbx_major': 17,
           'os_id': 'debian', 'os_version': '12', 'python': 'CPython 3.11',
           'architecture': 'amd64', 'web_server': 'Apache 2',
           'layout': 'standard FreePBX paths', 'qualification': 'isolated fixtures; full-PBX VM qualification required'}
REQUIRED_PATHS = ('/etc/freepbx.conf', '/usr/sbin/fwconsole', '/usr/sbin/asterisk',
                  '/var/www/html/admin/modules/framework/module.xml', '/usr/sbin/runuser',
                  '/usr/bin/flock', '/usr/bin/timeout', '/usr/bin/openssl', '/usr/bin/gpg')
RUNTIME = Path('/usr/local/bin/sls_mass_notify')
TRUST_ROOT = Path('/var/lib/sls-mass-notify-trust')
JOURNAL = Path('/var/lib/sls-mass-notify-installer')
DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
WEB_ROOT = Path('/var/www/html')
PHP_EXTENSIONS = ('pdo_sqlite', 'curl', 'dom', 'mbstring', 'posix', 'openssl', 'sodium')
PHP_FUNCTIONS = ('exec', 'proc_open', 'posix_geteuid', 'posix_getpwnam', 'openssl_encrypt',
                 'openssl_decrypt', 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt', 'sodium_crypto_pwhash_str')
PHASES = {'preparing', 'prepared', 'mutating', 'recovering', 'recovery_failed', 'committed', 'rolled_back', 'abandoned'}
STAGES = {'initialization', 'hardware admission', 'platform validation', 'release download and validation',
          'publisher and stock approval', 'FreePBX ownership protection',
          'legacy configuration and runtime permissions', 'authenticated prerequisite validation',
          'previous phone collector recovery', 'notification worker coordination',
          'configuration coordination', 'dependency installation', 'timezone validation',
          'protected runtime preparation', 'module activation', 'runtime integration',
          'post-install verification', 'interrupted installation recovery'}


def command(args, timeout=5):
    process = None
    try:
        process = subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL, start_new_session=True,
            env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C.UTF-8', 'PYTHONDONTWRITEBYTECODE':'1'})
        output = bytearray(); deadline=time.monotonic()+timeout
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            while selector.get_map():
                remaining=deadline-time.monotonic()
                if remaining<=0: return 124,''
                for key,unused in selector.select(min(remaining,.2)):
                    block=os.read(key.fd, min(65536,262145-len(output)))
                    if not block: selector.unregister(key.fileobj);continue
                    output.extend(block)
                    if len(output)>262144:return 125,''
        return process.wait(timeout=max(.01,deadline-time.monotonic())),output.decode('utf-8','replace')
    except (OSError, subprocess.TimeoutExpired): return 124,''
    finally:
        if process is not None:
            # A child can leave a descendant holding stdout after it exits.
            # Always retire our private process group, including that case.
            try:os.killpg(process.pid,9)
            except ProcessLookupError:pass
            if process.poll() is None:
                process.wait(timeout=2)
            if process.stdout:process.stdout.close()


def bounded(path, limit=65536):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > limit:
            raise ValueError('Unsafe or oversized diagnostic input')
        body=stream.read(limit+1);after=os.fstat(stream.fileno())
        if len(body)!=info.st_size or (info.st_ino,info.st_size,info.st_mtime_ns,info.st_ctime_ns)!=(after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns):
            raise ValueError('Diagnostic input changed during inspection')
        return body


def served_php_targets(dump):
    """Host headers come from parsed Apache vhosts; sockets are always loopback."""
    targets=[]
    for line in dump[:262144].splitlines():
        match=re.match(r'^\s*(?:(\*|[0-9.]+|\[[0-9a-f:]+\]):([0-9]+)\s+|port\s+([0-9]+)\s+namevhost\s+)([^\s(]+)',line,re.I)
        if not match:continue
        port=int(match[2] or match[3]);host=match[4].lower()
        if not 1<=port<=65535 or not re.fullmatch(r'(?=.{1,253}$)[a-z0-9.-]+',host):continue
        address='::1' if (match[1] or '').startswith('[') else '127.0.0.1'
        for scheme in (('https','http') if port in (443,8443) else ('http','https')):
            target=(scheme,address,port,host)
            if target not in targets:targets.append(target)
        if len(targets)>=32:break
    return targets+[target for target in [('http','127.0.0.1',80,'127.0.0.1'),('https','127.0.0.1',443,'127.0.0.1')] if target not in targets]


class LoopbackTLS(http.client.HTTPSConnection):
    def __init__(self,address,port,server_name,timeout):
        super().__init__(address,port,timeout=timeout,context=ssl._create_unverified_context())
        self.server_name=server_name
    def connect(self):
        # Certificate validation is outside this local runtime probe. No public
        # DNS, proxy, redirects or caller-supplied destination is involved.
        sock=socket.create_connection((self.host,self.port),self.timeout)
        try:self.sock=self._context.wrap_socket(sock,server_hostname=self.server_name)
        except BaseException:sock.close();raise


def served_php_probe(targets,timeout=4):
    deadline=time.monotonic()+timeout
    for scheme,address,port,host in targets:
        if address not in ('127.0.0.1','::1') or scheme not in ('http','https') or type(port) is not int or not 1<=port<=65535:continue
        if not isinstance(host,str) or not re.fullmatch(r'(?=.{1,253}$)[a-z0-9.-]+',host):continue
        remaining=deadline-time.monotonic()
        if remaining<=0:break
        connection=None;timer=None
        try:
            connection=LoopbackTLS(address,port,host,min(.8,remaining)) if scheme=='https' else http.client.HTTPConnection(address,port,timeout=min(.8,remaining))
            connection.connect()
            def expire(expired_socket=connection.sock):
                try:expired_socket.shutdown(socket.SHUT_RDWR)
                except OSError:pass
                try:expired_socket.close()
                except OSError:pass
            timer=threading.Timer(remaining,expire);timer.daemon=True;timer.start()
            connection.request('GET','/api/sls-mass-notify/?sls_installer_runtime=1',headers={'Host':host+':'+str(port),'Accept':'application/json','Connection':'close'})
            response=connection.getresponse()
            if response.status not in (200,503):continue
            raw=response.read(16385)
            if len(raw)>16384:continue
            value=json.loads(raw)
            if not isinstance(value,dict) or value.get('probe')!='sls_served_php_v1' or type(value.get('ok')) is not bool:continue
            if not isinstance(value.get('php_version'),str) or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+[A-Za-z0-9.+~-]{0,48}',value['php_version']):continue
            if value.get('php_sapi') not in ('apache2handler','fpm-fcgi','cgi-fcgi','cli-server'):continue
            if type(value.get('runtime_account_ok')) is not bool:continue
            maps={name:value.get(name) for name in ('extensions','functions')}
            if any(not isinstance(maps[name],dict) or set(maps[name])!=set(keys) or any(type(v) is not bool for v in maps[name].values()) for name,keys in [('extensions',PHP_EXTENSIONS),('functions',PHP_FUNCTIONS)]):continue
            expected=not all(maps['extensions'].values()) or not all(maps['functions'].values()) or not value['runtime_account_ok'] or tuple(map(int,value['php_version'].split('.',2)[:2]))<(8,2)
            if value['ok'] is expected or (response.status==200)!=value['ok']:continue
            return {'available':True,'ok':value['ok'],'php_version':value['php_version'],'php_sapi':value['php_sapi'],
                    'runtime_account_ok':value['runtime_account_ok'],'extensions':maps['extensions'],'functions':maps['functions']}
        except (OSError,ValueError,http.client.HTTPException):pass
        finally:
            if timer is not None:timer.cancel()
            if connection is not None:connection.close()
    return {'available':False,'ok':False,'failure_code':'served_php_probe_unavailable'}


def collector_probe():
    helper=RUNTIME/'sls_phone_events.py'
    if not protected(helper):return {'ok':False,'failure_code':'phone_collector_helper_unsafe_or_missing'}
    try:account=pwd.getpwnam('asterisk')
    except KeyError:return {'ok':False,'failure_code':'phone_collector_runtime_account_missing'}
    if account.pw_uid<=0 or os.geteuid() not in (0,account.pw_uid):return {'ok':False,'failure_code':'phone_collector_web_uid_mismatch'}
    args=['/usr/bin/python3','-I',str(helper),'--health']
    if os.geteuid()==0:args=['/usr/bin/env','-u','GITHUB_TOKEN','-u','GH_TOKEN','-u','SLS_MASS_NOTIFY_GITHUB_TOKEN','/usr/sbin/runuser','-u','asterisk','--']+args
    status,raw=command(args,2)
    try:
        value=json.loads(raw)
        if not isinstance(value,dict):raise ValueError()
        if value.get('ok') is True and value.get('connected') is True and status==0:
            age=value.get('heartbeat_age_seconds')
            if type(age) not in (int,float) or not math.isfinite(age) or not 0<=age<=15:raise ValueError()
            counts={key:value.get(key) for key in ('active_batches','reserved_contacts')}
            if any(type(count) is not int or not 0<=count<=100000 for count in counts.values()):raise ValueError()
            return {'ok':True,'connected':True,'heartbeat_age_seconds':age,**counts}
        code=value.get('failure_code')
        if status==1 and value.get('ok') is False and isinstance(code,str) and re.fullmatch(r'phone_[a-z_]{1,64}',code):return {'ok':False,'connected':False,'failure_code':code}
    except (ValueError,TypeError):pass
    return {'ok':False,'failure_code':'phone_collector_probe_timeout' if status in (124,137) else 'phone_collector_health_invalid'}


TRUST_DIAGNOSTIC=r'''
import json,pathlib,sys,types
sys.dont_write_bytecode=True
runtime,root,webroot=map(pathlib.Path,sys.argv[1:])
try:
    loader=types.ModuleType('sls_doctor_trust');loader.__file__=str(runtime/'sls_module_trust.py')
    exec(compile((runtime/'sls_module_trust.py').read_bytes(),loader.__file__,'exec'),loader.__dict__)
    inventory,generation=loader.load(root,'slsmassnotifyserver')
    publisher=types.ModuleType('sls_doctor_publisher');publisher.__file__=str(runtime/'sls_release_trust.py')
    exec(compile(loader.read(runtime/'sls_release_trust.py',262144,protected=True),publisher.__file__,'exec'),publisher.__dict__)
    manifest=publisher.authenticate_manifest(loader.read(generation/'release-manifest.json',16384,protected=True),loader.read(generation/'release-manifest.sig',64,protected=True),loader.PUBLIC_KEY,root)
    version=inventory['version']
    if manifest.get('version')!=version or manifest.get('tag')!='slsmassnotifyserver-'+version or manifest.get('package')!='slsmassnotifyserver-'+version+'.tgz' or manifest.get('package_sha256')!=inventory['source']['archive_sha256']:raise ValueError()
    loader.check(root,'slsmassnotifyserver',webroot)
    stock={}
    for module in ('framework','dashboard'):
        try:loader.check(root,module,webroot);stock[module]=True
        except Exception:stock[module]=False
    print(json.dumps({'ok':all(stock.values()),'publisher_authenticated':True,'installed_inventory_matches':True,'stock_inventories':stock,
       'version':version,'package':manifest['package'],'package_sha256':manifest['package_sha256'],'installer_sha256':manifest.get('installer_sha256'),'inventory_sha256':generation.name.rsplit('-',1)[1]}))
except Exception:
    print(json.dumps({'ok':False,'failure_code':'release_trust_or_inventory_invalid'}));sys.exit(1)
'''


def release_probe():
    if os.geteuid()!=0:return {'ok':False,'root_required':True,'failure_code':'release_trust_root_inspection_required'}
    if not all(protected(RUNTIME/name) for name in ('sls_module_trust.py','sls_release_trust.py')):return {'ok':False,'failure_code':'release_trust_helper_unsafe_or_missing'}
    status,raw=command(['/usr/bin/python3','-I','-c',TRUST_DIAGNOSTIC,str(RUNTIME),str(TRUST_ROOT),str(WEB_ROOT)],5)
    try:
        value=json.loads(raw)
        if not isinstance(value,dict) or type(value.get('ok')) is not bool:raise ValueError()
        if status==0 and value.get('publisher_authenticated') is True and value.get('installed_inventory_matches') is True:
            if not isinstance(value.get('version'),str) or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-beta)?',value['version']):raise ValueError()
            if value.get('package')!='slsmassnotifyserver-'+value['version']+'.tgz':raise ValueError()
            if any(not isinstance(value.get(key),str) or not re.fullmatch('[a-f0-9]{64}',value[key]) for key in ('package_sha256','installer_sha256','inventory_sha256')):raise ValueError()
            stock=value.get('stock_inventories')
            if not isinstance(stock,dict) or set(stock)!={'framework','dashboard'} or any(type(v) is not bool for v in stock.values()) or value['ok']!=all(stock.values()):raise ValueError()
            return {key:value[key] for key in ('ok','publisher_authenticated','installed_inventory_matches','stock_inventories','version','package','package_sha256','installer_sha256','inventory_sha256')}
    except (ValueError,TypeError):pass
    return {'ok':False,'failure_code':'release_trust_probe_timeout' if status in (124,137) else 'release_trust_or_inventory_invalid'}


def served_php_local():
    status,dump=command(['/usr/sbin/apache2ctl','-t','-D','DUMP_VHOSTS'],1)
    return served_php_probe(served_php_targets(dump if status==0 else ''))


def resource_probe(desktops,phones):
    helper=RUNTIME/'sls_resource_capacity.py'
    if not protected(helper):return {'ok':False,'failure_code':'resource_helper_unsafe_or_missing'}
    status,output=command(['/usr/bin/python3','-I',str(helper),'--check','--mode','install','--desktop-limit',str(desktops),'--phone-limit',str(phones)],5)
    try:
        value=json.loads(output)
        if not isinstance(value,dict) or value.get('schema')!=1 or not isinstance(value.get('resource_checks'),dict):raise ValueError()
        result={key:value.get(key) for key in ('schema','requested_limit','requested_phone_limit','eligible_limit','eligible_phone_limit','requirements','resource_checks')}
        return {'ok':status==0,'allocation':result}
    except (ValueError,TypeError):return {'ok':False,'failure_code':'resource_probe_timeout' if status in (124,137) else 'resource_probe_invalid'}


def installation_state():
    unfinished=[];latest=None;unsafe=False;root_required=False
    try:
        if JOURNAL.exists():
            with os.scandir(JOURNAL) as iterator:
                names=[]
                for entry in iterator:
                    if len(names)>=100:raise ValueError('Journal entry limit')
                    names.append(entry.name)
            for name in names:
                if not re.fullmatch(r'transaction-[0-9a-f]{24}',name):raise ValueError('Unknown evidence')
                path=JOURNAL/name/'transaction.json'
                if not protected(path):raise ValueError('Unsafe evidence')
                value=json.loads(bounded(path))
                if not isinstance(value,dict):raise ValueError('Invalid evidence')
                phase=value.get('phase');updated=value.get('updated_at')
                if value.get('schema')!=1 or phase not in PHASES or type(updated) is not int or not 0<=updated<=time.time()+300:raise ValueError('Invalid evidence')
                row={'transaction_id':name,'phase':phase,'updated_at':updated}
                stage=value.get('stage');stage_at=value.get('stage_at')
                if stage is not None:
                    if not isinstance(stage,str) or stage not in STAGES or type(stage_at) is not int or not 0<=stage_at<=updated:raise ValueError('Invalid stage')
                    row.update(stage=stage,stage_at=stage_at)
                if latest is None or updated>latest['updated_at']:latest=row
                if phase not in ('committed','rolled_back','abandoned'):unfinished.append(row)
    except PermissionError:root_required=True
    except (OSError,ValueError,TypeError):unsafe=True
    failure=None;path=DATA/'install-failure.json'
    try:
        meta=path.lstat();account=pwd.getpwnam('asterisk')
        if meta.st_uid not in (0,account.pw_uid) or meta.st_mode&0o022:raise ValueError('Unsafe failure')
        value=json.loads(bounded(path))
        if not isinstance(value,dict):raise ValueError('Invalid failure')
        stage=value.get('stage');category=value.get('error_category');failed_at=value.get('failed_at')
        if not isinstance(stage,str) or stage not in STAGES or not isinstance(category,str) or not re.fullmatch('[a-z][a-z0-9_]{0,63}',category):raise ValueError('Invalid failure')
        moment=datetime.datetime.fromisoformat(failed_at)
        if moment.tzinfo is None or moment.timestamp()>time.time()+300:raise ValueError('Invalid failure time')
        failure={'stage':stage,'error_category':category,'failed_at':moment.astimezone(datetime.timezone.utc).isoformat(),'historical_only':True}
        if latest and latest['phase']=='committed' and latest['updated_at']>=moment.timestamp():failure['superseded_by_commit']=True
        else:failure['superseded_by_commit']=False
    except FileNotFoundError:pass
    except (OSError,ValueError,TypeError,KeyError):failure={'unavailable':True}
    return {'ok':not unfinished and not unsafe,'unfinished':unfinished,'unsafe_evidence':unsafe,'root_required':root_required,'latest_transaction':latest,'last_failure':failure}


def module_version(path, rawname):
    try:
        body=bounded(path,1048576)
        if b'<!DOCTYPE' in body.upper() or b'<!ENTITY' in body.upper(): return None
        root=ET.fromstring(body)
        value=root.findtext('version','')
        return value if root.findtext('rawname')==rawname and re.fullmatch(r'[0-9]+(?:\.[0-9]+){1,4}(?:-beta)?',value) else None
    except (OSError, ValueError, ET.ParseError): return None


def protected(path):
    try:
        for item in (path,*path.parents):
            info=item.lstat()
            expected=stat.S_ISREG if item==path else stat.S_ISDIR
            if not expected(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022: return False
        return path.stat().st_nlink==1
    except OSError: return False


def report(desktops=25, phones=25):
    checks=[]
    def add(code,ok,observed,fix,severity='error'):
        checks.append({'code':code,'ok':bool(ok),'observed':observed,'remedy':None if ok else fix,'severity':severity})
    try:
        release_path=Path('/etc/os-release')
        if release_path.is_symlink() and release_path.resolve()==Path('/usr/lib/os-release'): release_path=Path('/usr/lib/os-release')
        text=bounded(release_path).decode()
        release=dict(re.findall(r'^([A-Z_]+)=["\']?([^\n"\']*)',text,re.M))
    except (OSError,ValueError): release={}
    add('supported_os',release.get('ID')=='debian' and release.get('VERSION_ID')=='12',
        {'id':release.get('ID'),'version':release.get('VERSION_ID')},
        'Use the qualified Debian 12 profile, or supply a separately tested platform adapter. Do not force installation.')
    add('python_abi',sys.implementation.name=='cpython' and sys.version_info[:2]==(3,11) and platform.machine()=='x86_64',
        {'implementation':sys.implementation.name,'version':platform.python_version(),'architecture':platform.machine()},
        'Use CPython 3.11 on amd64, or qualify a compatible hash-locked wheel catalogue before installation.')
    version=module_version(Path('/var/www/html/admin/modules/framework/module.xml'),'framework')
    add('freepbx_version',bool(version and version.startswith('17.')),version,'Install on a supported FreePBX 17 deployment.')
    missing=[path for path in REQUIRED_PATHS if not Path(path).exists()]
    add('bootstrap_paths',not missing,{'missing':missing},'Repair the listed prerequisites before coordination or release downloads.')
    dashboard=module_version(Path('/var/www/html/admin/modules/dashboard/module.xml'),'dashboard')
    add('dashboard_prerequisite',dashboard is not None,dashboard,
        'The installer can bootstrap an absent Dashboard from its independently signed upstream package. Existing unknown differences require review.')
    # Independent probes run concurrently so the admin report remains inside
    # the web caller's deadline. Every child has its own byte/time limit.
    with ThreadPoolExecutor(max_workers=8) as workers:
        tasks={unit:workers.submit(command,['/usr/bin/systemctl','is-active',unit],1) for unit in ('apache2','cron','sls-mass-notify-phone-events')}
        tasks.update(apache=workers.submit(command,['/usr/sbin/apache2ctl','configtest'],2),
                     asterisk=workers.submit(command,['/usr/sbin/asterisk','-rx','core show version'],2),
                     served_php=workers.submit(served_php_local),collector=workers.submit(collector_probe),
                     release=workers.submit(release_probe),resource=workers.submit(resource_probe,desktops,phones))
        observations={name:task.result() for name,task in tasks.items()}
    for unit in ('apache2','cron','sls-mass-notify-phone-events'):
        status,output=observations[unit]
        add('service_'+unit,status==0,output.strip() if output.strip() in ('active','inactive','failed','unknown','activating','deactivating') else 'unavailable',
            'Inspect systemctl status and the service journal. This doctor does not start, reload or repair services.',
            'warning' if unit=='sls-mass-notify-phone-events' else 'error')
    status,_=observations['apache']
    add('apache_configuration',status==0,'accepted' if status==0 else 'failed or unavailable',
        'Run apache2ctl configtest and repair the reported configuration. DocumentRoot warnings alone do not establish route failure.')
    php=observations['served_php']
    add('served_php_runtime',php.get('ok') is True,php,
        'Check the local Apache virtual host/API route and its PHP handler. Enable the named extensions/functions and use the asterisk runtime account; CLI PHP alone is insufficient.')
    status,output=observations['asterisk'];match=re.match(r'^Asterisk ([0-9]+(?:\.[0-9]+){1,3}(?:[-+][A-Za-z0-9._~-]{1,48})?)\b',output.strip())
    asterisk=match[1] if status==0 and match else None
    add('asterisk_version',asterisk is not None,asterisk,'Run asterisk -rx "core show version" and inspect the local control socket; this doctor does not restart Asterisk.')
    collector=observations['collector']
    add('authenticated_collector',collector.get('ok') is True,collector,
        'Review the collector service journal, loopback AMI connection and protected admission ledger. An active service without a current authenticated heartbeat cannot authorize phone audio.')
    release_identity=observations['release']
    add('release_trust',release_identity.get('ok') is True,release_identity,
        'Use sudo slsconsole doctor --json for root-only publisher/stock inventory verification. Preserve rejected evidence and restore matching signed module/runtime files; never approve arbitrary installed hashes.',
        'warning' if release_identity.get('root_required') else 'error')
    state=installation_state()
    add('unfinished_installation',state['ok'],{key:state[key] for key in ('unfinished','unsafe_evidence','latest_transaction','last_failure')},
        'Preserve the transaction directory. Run the publisher-authenticated installer with --recover and that exact directory before a new upgrade.')
    if state['root_required']:
        checks[-1].update(ok=False,severity='warning',observed='Protected recovery evidence requires root inspection',
            remedy='Run sudo slsconsole doctor --json. The web process is not granted access to root recovery snapshots.')
    resource=observations['resource']
    add('resource_capacity',resource.get('ok') is True,resource,
        'Reduce the requested workload or provide the reported CPU/RAM/filesystem headroom. Restore a missing/unsafe protected helper through the authenticated installer; no web-owned helper is executed.',
        'warning' if resource.get('failure_code')=='resource_helper_unsafe_or_missing' else 'error')
    return {'schema':1,'generated_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
            'read_only':True,'profile':PROFILE,'installed_sls_version':module_version(Path('/var/www/html/admin/modules/slsmassnotifyserver/module.xml'),'slsmassnotifyserver'),
            'detected_runtime':{'asterisk_version':asterisk,'served_php':php},'release_identity':release_identity,'installation_state':state,
            'ok':not any(not row['ok'] and row['severity']=='error' for row in checks),'checks':checks,
            'not_exercised':['database writes','module hooks','new AMI connections','network/provider delivery','physical endpoints','load certification','full-PBX VM qualification']}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--json',action='store_true',help='Print a redacted JSON support profile to stdout.')
    parser.add_argument('--desktop-limit',type=int,default=25)
    parser.add_argument('--phone-limit',type=int,default=25)
    args=parser.parse_args()
    if not 1<=args.desktop_limit<=1000 or not 1<=args.phone_limit<=1000: parser.error('Capacities must be between 1 and 1000.')
    value=report(args.desktop_limit,args.phone_limit)
    if args.json: print(json.dumps(value,indent=2,sort_keys=True))
    else:
        print('SLS installation doctor · read-only · '+value['profile']['id'])
        for row in value['checks']:
            print(('PASS' if row['ok'] else 'WARN' if row['severity']=='warning' else 'FAIL')+' '+row['code']+': '+json.dumps(row['observed'],sort_keys=True))
            if row['remedy']: print('  '+row['remedy'])
    return 0 if value['ok'] else 1


if __name__=='__main__': raise SystemExit(main())
