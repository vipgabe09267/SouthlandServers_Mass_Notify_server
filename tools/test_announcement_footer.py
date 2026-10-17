#!/usr/bin/env python3
"""Render the footer and exercise result transitions without sending a page."""
from pathlib import Path
import re
import subprocess


ROOT = Path(__file__).resolve().parents[1]
VIEW = ROOT / 'slsmassnotifyserver/dashboard/views/sections/sls-mass-notify-announcement.php'
ENVIRONMENT = {'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C'}


def require(value, message):
    if not value:
        raise RuntimeError(message)


def function(source, name, next_name):
    marker = '\tfunction ' + name
    next_marker = '\tfunction ' + next_name
    require(source.count(marker) == 1, 'Announcement helper is missing or duplicated: ' + name)
    require(source.count(next_marker) == 1,
            'Announcement helper boundary is missing or duplicated: ' + next_name)
    start = source.find(marker)
    end = source.find(next_marker, start)
    require(start >= 0 and end > start, 'Announcement helper boundaries are invalid: ' + name)
    return source[start:end]


source = VIEW.read_text(encoding='utf-8')
require(
    'Review destinations' not in source and 'id="sls-announcement-preview"' not in source,
    'Obsolete preview controls returned.')
require(
    'remaining > 0 || requestInFlight || deliveryOutcomeUnknown || activeJob' in source,
    'Duplicate-submit guard is missing.')
require('class="btn btn-primary sls-send-button"' in source,
        'Primary send control is missing.')
submit = source.find("form.addEventListener('submit'")
require(
    submit >= 0 and 'error && error.message ? error.message' not in source[submit:],
    'Raw transport errors can reach the footer.')

php = (
    '$setup_complete=true; $csrf_token="fixture"; '
    '$announcement_desktop_clients=[['
    '"client_id"=>"cli_fixture", "username"=>"fixture-user", '
    '"name"=>"Fixture desktop", "enabled"=>"1", '
    '"password_enc"=>"secret-ciphertext-must-not-render", '
    '"future_secret"=>"unknown-secret-must-not-render"]]; '
    'include $argv[1];')
render = subprocess.run(
    ['/usr/bin/php', '-r', php, str(VIEW)],
    capture_output=True,
    text=True,
    check=True,
    timeout=10,
    env=ENVIRONMENT,
    cwd=ROOT,
)
require(render.stderr == '', render.stderr)
require(
    'secret-ciphertext-must-not-render' not in render.stdout
    and 'unknown-secret-must-not-render' not in render.stdout,
    'Desktop secrets reached rendered markup.')
require(
    'cli_fixture' in render.stdout and 'Fixture desktop' in render.stdout,
    'Expected desktop identity is missing.')

scripts = re.findall(r'<script[^>]*>(.*?)</script>', render.stdout, re.S)
require(bool(scripts), 'Rendered announcement scripts are missing.')
for script in scripts:
    subprocess.run(
        ['/usr/bin/node', '--check'],
        input=script,
        text=True,
        capture_output=True,
        check=True,
        timeout=10,
        env=ENVIRONMENT,
        cwd=ROOT,
    )

code = r'''
const assert = require('assert');
function node() {
  return {
    style: {}, attrs: {}, children: [], className: '', textContent: '',
    setAttribute(key, value) { this.attrs[key] = value; },
    appendChild(child) { this.children.push(child); },
    set innerHTML(value) {
      assert.strictEqual(value, '');
      this.children = [];
    }
  };
}
var result = node();
var document = {createElement: node};
function instanceActive() { return true; }
function scheduleDashboardLayout() {}
'''
code += function(source, 'setAnnouncementStatus', 'renderMessageCount')
code += function(source, 'renderDeliveryStatus', 'rememberJob')
code += r'''
for (const data of [
  {success: true, queued: true},
  {success: true, state: 'queued'},
  {success: true, state: 'running'}
]) {
  renderDeliveryStatus(data);
  assert(result.className.includes('sls-status-info'));
  assert.strictEqual(result.attrs['aria-busy'], 'true');
  assert(!result.children[0].className.includes('check'));
}
renderDeliveryStatus({
  success: true,
  state: 'submitted',
  sender: '<img src=x onerror=alert(1)>'
});
assert(result.className.includes('sls-status-success'));
assert(result.children[0].className.includes('check-circle'));
assert.strictEqual(
  result.children[1].textContent,
  'Announcement submitted · Sent by <img src=x onerror=alert(1)>'
);
assert.strictEqual(result.attrs['aria-busy'], 'false');
renderDeliveryStatus({
  success: false,
  state: 'partial_or_failed',
  message: 'One channel needs attention'
});
assert(result.className.includes('sls-status-warning'));
assert(!result.children[0].className.includes('check'));
'''
subprocess.run(
    ['/usr/bin/node'],
    input=code,
    text=True,
    capture_output=True,
    check=True,
    timeout=10,
    env=ENVIRONMENT,
    cwd=ROOT,
)
print(
    'Dashboard render/JavaScript, duplicate-submit guard, pending/success/failure '
    'and safe sender text passed.')
