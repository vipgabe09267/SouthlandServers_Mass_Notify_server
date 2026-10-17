#!/usr/bin/python3
"""Compile disabled Labs examples with explicitly supplied local Arduino SDKs."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time

BOARDS=(('arduino_mkr_sensor','arduino:samd:mkrwifi1010'),
        ('arduino_mkr_sensor','arduino:samd:nano_33_iot'),
        ('esp32_sensor','esp32:esp32:esp32'))


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--cli',type=Path,required=True)
    parser.add_argument('--config',type=Path,required=True)
    parser.add_argument('--workspace',type=Path,required=True)
    args=parser.parse_args()
    if not args.cli.is_file() or not os.access(args.cli,os.X_OK) or not args.config.is_file():
        parser.error('Supply a separately verified Arduino CLI and SDK configuration; this tool downloads nothing.')
    args.workspace.mkdir(mode=0o700,parents=True,exist_ok=True)
    metadata=args.workspace.lstat()
    if args.workspace.is_symlink() or metadata.st_uid!=os.geteuid() or metadata.st_mode&0o077:
        parser.error('Use a private workspace owned by the current build user.')
    work=Path(tempfile.mkdtemp(prefix='sensor-build-',dir=args.workspace))
    source=Path(__file__).resolve().parents[1]/'slsmassnotifyserver/examples/sensors'
    environment={key:value for key,value in os.environ.items() if key not in {'GITHUB_TOKEN','GH_TOKEN','SLS_MASS_NOTIFY_GITHUB_TOKEN'}}
    rows=[]
    for name,board in BOARDS:
        payload=(source/(name+'.ino')).read_bytes()
        if not re.search(rb'static const bool ENABLE_SEND\s*=\s*false;',payload):
            raise SystemExit('Only the disabled distribution examples may be qualified here; no firmware was executed.')
        sketch=work/board.rsplit(':',1)[1]/name;sketch.mkdir(mode=0o700,parents=True)
        (sketch/(name+'.ino')).write_bytes(payload)
        log=work/(board.rsplit(':',1)[1]+'.log');started=time.monotonic()
        try:
            with log.open('x',encoding='utf-8') as output:
                result=subprocess.run([str(args.cli.resolve()),'--config-file',str(args.config.resolve()),
                    'compile','--jobs','1','--fqbn',board,'--build-path',str(sketch.parent/'build'),str(sketch)],
                    cwd=work,env=environment,stdout=output,stderr=subprocess.STDOUT,timeout=600)
                status=result.returncode
        except subprocess.TimeoutExpired:status=124
        rows.append({'board':board,'source_sha256':hashlib.sha256(payload).hexdigest(),'ok':status==0,
                     'exit_code':status,'seconds':round(time.monotonic()-started,2),'hardware_tested':False})
        report=work/'report.json'
        temporary=work/'.report.next'
        with temporary.open('x',encoding='utf-8') as output:
            output.write(json.dumps({'schema':1,'scope':'SDK compilation only; no upload or hardware execution','results':rows},indent=2)+'\n')
            output.flush();os.fsync(output.fileno())
        os.replace(temporary,report)
        print(json.dumps(rows[-1]),flush=True)
    print('Retained compilation report: '+str(work/'report.json'))
    return 0 if all(row['ok'] for row in rows) else 1


if __name__=='__main__':raise SystemExit(main())
