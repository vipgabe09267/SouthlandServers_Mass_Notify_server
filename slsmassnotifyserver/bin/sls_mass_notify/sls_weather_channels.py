#!/usr/bin/python3
"""Frozen SMS/external-voice audiences for Weather and Lightning delivery."""
import importlib.util
import hashlib
import json
import os
from pathlib import Path

import sys as _config_sys
_config_sys.dont_write_bytecode = True
_config_crypto_spec = importlib.util.spec_from_file_location("sls_config_crypto", Path(__file__).resolve().with_name("sls_config_crypto.py"))
_config_crypto = importlib.util.module_from_spec(_config_crypto_spec)
_config_crypto_spec.loader.exec_module(_config_crypto)
import re
import stat
import sys
import time

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')

def fingerprint(config, kind, identifier):
    section=config.get('outbound_voice' if kind=='voice' else 'announcement_sms') or {}
    row=next((r for r in section.get('recipients',[]) if isinstance(r,dict) and r.get('id')==identifier),None)
    if not row or str(section.get('enabled','0')).lower() in {'0','false',''} or str(row.get('enabled','0')).lower() in {'0','false',''}:
        return ''
    policy={k:v for k,v in section.items() if k!='recipients'}
    body=json.dumps({'policy':policy,'recipient':row},sort_keys=True,separators=(',',':'),ensure_ascii=False,allow_nan=False).encode()
    return hashlib.sha256(body).hexdigest()

def snapshot(config, group):
    result={'voice_recipient_ids':[],'sms_recipient_ids':[],'fingerprints':{}}
    for kind,key in [('voice','voice_recipient_ids'),('sms','sms_recipient_ids')]:
        values=group.get(key,[])
        if not isinstance(values,list) or len(values)>(1000 if kind=='voice' else 50): raise ValueError('Weather channel recipient selection is invalid.')
        for identifier in values:
            if not isinstance(identifier,str) or not re.fullmatch(kind+r'_[a-f0-9]{24}',identifier): raise ValueError('Weather channel recipient identifier is invalid.')
            result[key].append(identifier)
            result['fingerprints'][kind+':'+identifier]=fingerprint(config,kind,identifier)
    return result

def authorize(config, context, directory=DATA, now=None):
    from sls_notification_destinations import validate_external_weather
    now=int(time.time() if now is None else now)
    if not isinstance(context,dict) or set(context)-{'source_validity','event','severity','deadline_at','channels'}:
        raise ValueError('Invalid Weather channel context.')
    deadline=context.get('deadline_at')
    if type(deadline) is not int or deadline <= now: return {'status':'cancelled','reason':'source_expired','voice_recipient_ids':[],'sms_recipient_ids':[]}
    record={'source_validity':context.get('source_validity'),'expires_at':deadline,
            'payload':{'event':context.get('event',''),'severity':context.get('severity','')}}
    status,reason,group=validate_external_weather(record,config,directory,now)
    result={'status':status,'reason':reason,'voice_recipient_ids':[],'sms_recipient_ids':[]}
    if status!='eligible' or group is None: return result
    original=context.get('channels')
    if not isinstance(original,dict) or set(original)!= {'voice_recipient_ids','sms_recipient_ids','fingerprints'} or not isinstance(original['fingerprints'],dict): raise ValueError('Missing frozen Weather channel recipients.')
    for kind,key in [('voice','voice_recipient_ids'),('sms','sms_recipient_ids')]:
        values=original[key]
        if not isinstance(values,list) or len(values)>(1000 if kind=='voice' else 50): raise ValueError('Invalid frozen Weather recipient list.')
        current=set(group.get(key,[]))
        for identifier in values:
            if not isinstance(identifier,str) or not re.fullmatch(kind+r'_[a-f0-9]{24}',identifier): raise ValueError('Invalid Weather recipient identity.')
            expected=original['fingerprints'].get(kind+':'+identifier,'')
            if identifier in current and expected and expected==fingerprint(config,kind,identifier):result[key].append(identifier)
    return result

def read_config():
    path=DATA/'mass-notifications.config'
    if DATA.resolve()!=DATA or path.is_symlink(): raise ValueError('Protected Weather configuration is unavailable.')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        info=os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != DATA.stat().st_uid or info.st_nlink!=1 or info.st_mode&0o022 or info.st_size>_config_crypto.MAX_FILE_BYTES: raise ValueError('Unsafe protected Weather configuration.')
        with os.fdopen(fd,'rb',closefd=False) as handle:body=handle.read(_config_crypto.MAX_FILE_BYTES+1)
        if len(body)>_config_crypto.MAX_FILE_BYTES: raise ValueError('Protected Weather configuration is too large.')
        after=path.lstat()
        if (after.st_dev,after.st_ino)!=(info.st_dev,info.st_ino):raise ValueError('Protected Weather configuration changed while reading.')
        config=_config_crypto.decode_config(body)
        if not isinstance(config,dict):raise ValueError('Protected Weather configuration is invalid.')
        return config
    finally:os.close(fd)

if __name__=='__main__':
    try:
        if sys.argv[1:]!=['authorize']: raise ValueError('Unsupported Weather channel action.')
        raw=sys.stdin.buffer.read(262145)
        if len(raw)>262144:raise ValueError('Weather channel request exceeds its limit.')
        print(json.dumps(authorize(read_config(),json.loads(raw)),separators=(',',':')))
    except Exception:
        print(json.dumps({'status':'deferred','reason':'weather_authorization_unavailable','voice_recipient_ids':[],'sms_recipient_ids':[]}))
        raise SystemExit(1)
