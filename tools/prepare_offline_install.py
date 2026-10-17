#!/usr/bin/python3
"""Validate an offline support bundle before printing safe installer arguments.

This tool neither downloads nor installs. Package signatures and the candidate's
own hash locks remain the authorities; a bundle index never approves live files.
Debian prerequisites must already exist or use an independently trusted APT mirror.
"""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shlex
import stat
import subprocess
import tarfile
import tempfile
import time
import xml.etree.ElementTree as ET

ANCHOR=b'-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAFMDOgOaBGcaI8d+v0w/NX4RbwGlBsoktNc2V2F8UkUQ=\n-----END PUBLIC KEY-----\n'


def read(path,limit):
    path=Path(path)
    for parent in reversed(path.absolute().parents):
        info=parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid!=0 or info.st_mode&0o022 and not info.st_mode&stat.S_ISVTX:
            raise ValueError('Offline bundle needs protected root-owned parents: '+str(path))
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    with os.fdopen(fd,'rb') as stream:
        info=os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or info.st_uid!=0 or info.st_mode&0o022 or not 0<info.st_size<=limit:
            raise ValueError('Unsafe or oversized offline input: '+path.name)
        body=stream.read(limit+1)
        after=os.fstat(stream.fileno())
        if len(body)!=info.st_size or (info.st_size,info.st_mtime_ns,info.st_ctime_ns)!=(after.st_size,after.st_mtime_ns,after.st_ctime_ns):
            raise ValueError('Offline input changed while reading: '+path.name)
        return body


def release(directory):
    body=read(directory/'release-manifest.json',16384);signature=read(directory/'release-manifest.sig',64)
    with tempfile.TemporaryDirectory(prefix='sls-offline-auth-') as name:
        scratch=Path(name)
        for filename,value in (('pub',ANCHOR),('body',body),('sig',signature)): (scratch/filename).write_bytes(value)
        subprocess.run(['/usr/bin/openssl','pkeyutl','-verify','-rawin','-pubin','-inkey',str(scratch/'pub'),'-in',str(scratch/'body'),'-sigfile',str(scratch/'sig')],check=True,timeout=10,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    manifest=json.loads(body)
    version=manifest.get('version','')
    if manifest.get('schema')!=1 or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-beta)?',version) or manifest.get('package')!='slsmassnotifyserver-'+version+'.tgz':
        raise ValueError('Offline release identity is invalid')
    signing=manifest.get('signing')
    if signing is not None and (not isinstance(signing,dict) or type(signing.get('expires_at')) is not int or type(signing.get('issued_at')) is not int or not signing['issued_at']<=int(time.time())+300<signing['expires_at']):
        raise ValueError('Offline signed release metadata is expired or invalid')
    for filename,field,bound in (('install_release.sh','installer_sha256',2097152),(manifest['package'],'package_sha256',54525952)):
        if hashlib.sha256(read(directory/filename,bound)).hexdigest()!=manifest.get(field): raise ValueError('Offline artifact differs from its publisher signature: '+filename)
    return manifest


def members(body,root):
    result={};total=0;count=0
    with tarfile.open(fileobj=io.BytesIO(body),mode='r:gz') as archive:
        for member in archive:
            count+=1
            path=Path(member.name)
            if count>20000 or path.is_absolute() or '..' in path.parts or not path.parts or path.parts[0]!=root or not (member.isfile() or member.isdir()) or member.mode&0o6000:
                raise ValueError('Unsafe offline archive member')
            if member.isdir(): continue
            name='/'.join(path.parts[1:]);total+=member.size
            if name in result or member.size>67108864 or total>536870912: raise ValueError('Duplicate or oversized offline archive input')
            result[name]=archive.extractfile(member).read(member.size+1)
    return result


def validate(directory):
    manifest=release(directory)
    contents=members(read(directory/manifest['package'],54525952),'slsmassnotifyserver')
    locks=b'\n'.join(contents['bin/sls_mass_notify/'+name] for name in ('piper-packaging.lock','piper-requirements.lock'))
    allowed=set(re.findall(rb'--hash=sha256:([a-f0-9]{64})',locks))
    wheels=list((directory/'assets/wheels').iterdir())
    if not wheels or len(wheels)>100: raise ValueError('Provide the complete qualified wheelhouse (at most 100 wheels)')
    for wheel in wheels:
        if not wheel.name.endswith('.whl') or hashlib.sha256(read(wheel,134217728)).hexdigest().encode() not in allowed:
            raise ValueError('Wheel is not authorized by the signed candidate hash locks: '+wheel.name)
    voice_script=contents['bin/sls_mass_notify_install_piper_voices.sh'].decode()
    block=voice_script.split('declare -A hashes=(',1)
    if len(block)!=2: raise ValueError('Signed candidate voice catalogue format needs a qualified adapter')
    voices=dict(re.findall(r'\["([A-Za-z0-9_.-]+)"\]="([a-f0-9]{64})"',block[1].split('\n)',1)[0]))
    if not voices: raise ValueError('Signed candidate voice catalogue is empty')
    for filename,digest in voices.items():
        if hashlib.sha256(read(directory/'assets/voices'/filename,67108864)).hexdigest()!=digest:
            raise ValueError('Offline voice does not match the signed candidate catalogue: '+filename)
    # Upgrade prerequisites remain verified by the installer's pinned GPG
    # upstream workflow; this check rejects surprising bundle paths early.
    upstream=directory/'upstream'
    if not upstream.is_dir() or len(list(upstream.iterdir()))>20: raise ValueError('Supply the exact installed Dashboard/Framework signed upstream packages in upstream/')
    for package in upstream.iterdir():
        if not re.fullmatch(r'(?:dashboard|framework)-17\.[0-9]+(?:\.[0-9]+){1,3}\.tgz\.gpg',package.name): raise ValueError('Unexpected upstream package name')
        read(package,67108864)
    verify_upstream(list(upstream.iterdir()),contents)
    verify_wheel_resolution(directory/'assets/wheels',contents)
    previous=directory/'previous'
    if previous.exists(): release(previous)
    return {'schema':1,'ok':True,'version':manifest['version'],'wheel_count':len(wheels),'voice_count':len(voices),
            'upstream_count':len(list(upstream.iterdir())),'previous_release':previous.exists(),
            'debian_packages_included':False,'wheel_resolution_verified':True,'upstream_signatures_verified':True}


def verify_wheel_resolution(wheelhouse,contents):
    with tempfile.TemporaryDirectory(prefix='sls-offline-wheels-') as name:
        scratch=Path(name)
        for filename in ('piper-packaging.lock','piper-requirements.lock'):
            lock=scratch/filename;lock.write_bytes(contents['bin/sls_mass_notify/'+filename])
            result=subprocess.run(['/usr/bin/python3','-I','-m','pip','--isolated','install','--dry-run','--ignore-installed',
                '--only-binary=:all:','--require-hashes','--no-input','--no-index','--find-links',str(wheelhouse),'-r',str(lock)],
                stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,timeout=120)
            if result.returncode: raise ValueError('Offline wheels do not resolve the complete compatible signed lock: '+filename)


def verify_upstream(packages,contents):
    source=contents['bin/sls_mass_notify/sls_upgrade_trust.py'].decode()
    signer=re.search(r"UPSTREAM_SIGNER = '([A-F0-9]{40})'",source)
    digest=re.search(r"UPSTREAM_KEY_SHA256 = '([a-f0-9]{64})'",source)
    key=contents['bin/sls_mass_notify/freepbx-mirror-signing.pub']
    if not signer or not digest or hashlib.sha256(key).hexdigest()!=digest[1]: raise ValueError('Candidate upstream verification key is inconsistent')
    with tempfile.TemporaryDirectory(prefix='sls-offline-stock-') as name:
        scratch=Path(name);scratch.chmod(0o700);(scratch/'key').write_bytes(key)
        command=['/usr/bin/gpg','--no-options','--homedir',str(scratch),'--batch','--no-tty','--no-auto-key-retrieve','--no-autostart']
        subprocess.run(command+['--import',str(scratch/'key')],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=20)
        for number,package in enumerate(packages):
            plain=scratch/('package-'+str(number)+'.tgz')
            def bounded_output():
                import resource
                resource.setrlimit(resource.RLIMIT_FSIZE,(67108864,67108864))
            result=subprocess.run(command+['--status-fd','1','--output',str(plain),'--decrypt',str(package)],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=90,preexec_fn=bounded_output)
            status=result.stdout.decode('ascii','strict').splitlines()
            valid=[row.split() for row in status if row.startswith('[GNUPG:] VALIDSIG ')]
            if result.returncode or len(valid)!=1 or valid[0][2]!=signer[1] or valid[0][-1]!=signer[1] or any(re.match(r'\[GNUPG:\] (?:BADSIG|ERRSIG|EXPKEYSIG|EXPSIG|REVKEYSIG|KEYREVOKED)\b',row) for row in status):
                raise ValueError('Invalid, expired or unapproved upstream signature: '+package.name)
            module,version=package.name[:-8].split('-',1)
            data=members(read(plain,67108864),module)
            xml=ET.fromstring(data['module.xml'])
            if xml.findtext('rawname')!=module or xml.findtext('version')!=version: raise ValueError('Upstream package identity mismatch: '+package.name)


def installer_environment(directory):
    """Return paths and the package digest from authenticated release metadata."""
    directory=Path(directory).absolute()
    manifest=release(directory)
    values={'SLS_MASS_NOTIFY_TGZ_URL':'',
            'SLS_MASS_NOTIFY_TGZ':str(directory/manifest['package']),
            'SLS_MASS_NOTIFY_SHA256':manifest['package_sha256'],
            'SLS_MASS_NOTIFY_MANIFEST':str(directory/'release-manifest.json'),
            'SLS_MASS_NOTIFY_MANIFEST_SIGNATURE':str(directory/'release-manifest.sig'),
            'SLS_MASS_NOTIFY_UPSTREAM_PACKAGES':str(directory/'upstream'),
            'SLS_MASS_NOTIFY_OFFLINE_ASSETS':str(directory/'assets')}
    if (directory/'previous').exists():
        previous=release(directory/'previous')
        values.update({'SLS_MASS_NOTIFY_PREVIOUS_TGZ':str(directory/'previous'/previous['package']),
                       'SLS_MASS_NOTIFY_PREVIOUS_MANIFEST':str(directory/'previous/release-manifest.json'),
                       'SLS_MASS_NOTIFY_PREVIOUS_MANIFEST_SIGNATURE':str(directory/'previous/release-manifest.sig')})
    return values


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory',type=Path)
    parser.add_argument('--print-environment',action='store_true')
    args=parser.parse_args();directory=args.directory.absolute()
    result=validate(directory)
    if args.print_environment:
        values=installer_environment(directory)
        for key,value in values.items(): print('export '+key+'='+shlex.quote(value))
    else: print(json.dumps(result,indent=2,sort_keys=True))


if __name__=='__main__':
    try: main()
    except (ValueError,OSError,KeyError,tarfile.TarError,subprocess.SubprocessError) as error:
        raise SystemExit('Offline bundle: '+str(error)) from None
