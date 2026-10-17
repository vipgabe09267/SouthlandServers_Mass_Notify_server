#!/usr/bin/env python3
"""Exercise the actual private renderer without PBX state or a delivery path."""
import base64
import json
import struct
import subprocess
import sys
from pathlib import Path

runtime = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py'
CLEAN_ENV = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'}


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def preview(payload, mms=False):
    result = subprocess.run([sys.executable, '-I', str(runtime), '--preview-mms-image' if mms else '--preview-image'],
                            input=json.dumps(payload), text=True, capture_output=True, timeout=20, env=CLEAN_ENV)
    return result.returncode, json.loads(result.stdout)


def preview_raw(payload):
    result = subprocess.run([sys.executable, '-I', str(runtime), '--preview-image'],
                            input=payload, text=True, capture_output=True, timeout=20, env=CLEAN_ENV)
    return result.returncode, json.loads(result.stdout)


for color, expected in [('#ffffff', (255, 255, 255)), ('#000000', (0, 0, 0)), ('#eab308', (234, 179, 8))]:
    code, result = preview({'title':'Fixture', 'message':'Complete message.', 'color':color})
    require(code == 0 and result['success'] and not result['text_clipped'], result)
    data = base64.b64decode(result['data'], validate=True)
    require(data.startswith(b'\x89PNG\r\n\x1a\n') and struct.unpack('>II', data[16:24]) == (480, 272),
            'Preview is not the required 480x272 PNG.')
    pixel = subprocess.run(['/usr/bin/convert','png:-','-crop','1x1+5+260','-depth','8','rgb:-'], input=data,
                           capture_output=True, check=True, timeout=10, env=CLEAN_ENV).stdout
    require(tuple(pixel) == expected, 'Preview background does not match the requested color.')
    require(result['mime'] == 'image/png', 'Preview MIME type is not image/png.')

code, clipped = preview({'title':'T' * 60, 'message':'instruction ' * 40, 'color':'#ffffff'})
require(code == 0 and clipped['text_clipped'], 'Long preview did not report clipping.')
valid = {'title':'Fixture', 'message':'Complete message.', 'color':'#ffffff'}
for key, value in [('message', 'x'*501), ('color','file:///etc/passwd'), ('title',['bad']), ('message','bad\x00input')]:
    code, result = preview(dict(valid, **{key:value}))
    require(code != 0 and not result['success'], 'Invalid preview input was accepted.')
code, result = preview(dict(valid, recipients=['1000']))
require(code != 0 and not result['success'], 'A preview accepted a delivery recipient.')
for raw in ('{"title":"first","title":"second","message":"body","color":"#ffffff"}',
            '{"title":"Fixture","message":NaN,"color":"#ffffff"}',
            '{"title":"Fixture","message":"' + 'x' * 4050 + '","color":"#ffffff"}'):
    code, result = preview_raw(raw)
    require(code != 0 and not result['success'], 'A duplicate, non-finite, or oversized preview request was accepted.')
print('Actual 480x272 preview rendering, private output, clipping warnings and invalid-input rejection passed.')

code, rendered = preview({'title':'MMS test', 'message':'Read the complete message for full instructions.', 'color':'#1f2937'}, mms=True)
require(code == 0 and rendered['width'] == 1440 and rendered['height'] == 816,
        'MMS preview does not have the required dimensions.')
mms_image = base64.b64decode(rendered['data'], validate=True)
require(mms_image[:8] == b'\x89PNG\r\n\x1a\n' and len(mms_image) <= 300000,
        'MMS preview is not a PNG within the provider limit.')
print('Actual private 1440x816 MMS image rendering and 300 KB provider limit passed.')
