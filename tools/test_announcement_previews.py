#!/usr/bin/env python3
"""Exercise the actual private renderer without PBX state or a delivery path."""
import base64
import json
import struct
import subprocess
from pathlib import Path

runtime = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py'


def preview(payload, mms=False):
    result = subprocess.run(['python3', '-I', str(runtime), '--preview-mms-image' if mms else '--preview-image'],
                            input=json.dumps(payload), text=True, capture_output=True, timeout=20)
    return result.returncode, json.loads(result.stdout)


for color, expected in [('#ffffff', (255, 255, 255)), ('#000000', (0, 0, 0)), ('#eab308', (234, 179, 8))]:
    code, result = preview({'title':'Fixture', 'message':'Complete message.', 'color':color})
    assert code == 0 and result['success'] and not result['text_clipped'], result
    data = base64.b64decode(result['data'], validate=True)
    assert data.startswith(b'\x89PNG\r\n\x1a\n') and struct.unpack('>II', data[16:24]) == (480, 272)
    pixel = subprocess.run(['convert','png:-','-crop','1x1+5+260','-depth','8','rgb:-'], input=data,
                           capture_output=True, check=True, timeout=10).stdout
    assert tuple(pixel) == expected
    assert result['mime'] == 'image/png'

code, clipped = preview({'title':'T' * 60, 'message':'instruction ' * 40, 'color':'#ffffff'})
assert code == 0 and clipped['text_clipped']
valid = {'title':'Fixture', 'message':'Complete message.', 'color':'#ffffff'}
for key, value in [('message', 'x'*501), ('color','file:///etc/passwd'), ('title',['bad']), ('message','bad\x00input')]:
    code, result = preview(dict(valid, **{key:value}))
    assert code != 0 and not result['success']
code, result = preview(dict(valid, recipients=['1000']))
assert code != 0 and not result['success']
print('Actual 480x272 preview rendering, private output, clipping warnings and invalid-input rejection passed.')

code, rendered = preview({'title':'MMS test', 'message':'Read the complete message for full instructions.', 'color':'#1f2937'}, mms=True)
assert code == 0 and rendered['width'] == 1440 and rendered['height'] == 816
mms_image = base64.b64decode(rendered['data'], validate=True)
assert mms_image[:8] == b'\x89PNG\r\n\x1a\n' and len(mms_image) <= 300000
print('Actual private 1440x816 MMS image rendering and 300 KB provider limit passed.')
