#!/usr/bin/env python3
"""Read-only admission proof for the supported stock FreePBX outbound path.

Execution remains in FreePBX: this module never selects a winning route, evaluates
its time/CID policy, originates a channel, or installs/replaces an admin hook.
Only bounded AMI Getvar/ShowDialPlan observations enter the proof. Unknown call
control fails closed; raw dialplan/global values never enter operator errors.
"""
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import stat
import time

MAX_ROWS = 10000
MAX_CONTEXTS = 96
MAX_TRUNKS = 32
MAX_BYTES = 4 * 1024 * 1024
MAX_SECONDS = 20
SAFE_CONTEXTS = {'macro-user-callerid', 'macro-outbound-callerid', 'macro-hangupcall',
                 'macro-outisbusy', 'macro-dialout-trunk', 'macro-dialout-trunk-predial-hook',
                 'trunk-dial-with-exten', 'func-apply-sipheaders', 'sub-check-pseries', 'sub-diversion-header'}
SAFE_FUNCTIONS = {'IF', 'LEN', 'DB', 'DB_EXISTS', 'CALLERID', 'CALLERPRES', 'CUT', 'STRREPLACE',
                  'GROUP_COUNT', 'CHANNEL', 'CONNECTEDLINE', 'ISNULL', 'REGEX', 'HASH', 'HASHKEYS',
                  'SHIFT', 'SET', 'MASTER_CHANNEL', 'CDR', 'GROUP', 'DB_DELETE', 'PJSIP_HEADER',
                  'SIPPEER', 'EXTENSION_STATE', 'PJSIP_DIAL_CONTACTS', 'PJSIP_AOR', 'PJSIP_CONTACT',
                  'DIALPLAN_EXISTS', 'FILTER'}
INERT_APPS = {'noop', 'return', 'macroexit', 'hangup', 'playback', 'playtones', 'busy',
              'congestion', 'progress', 'answer', 'wait', 'stopplaytones', 'sipaddheader', 'sipremoveheader'}
DIAL_DESTINATION = '${OUT_${DIAL_TRUNK}}/${OUTNUM}${OUT_${DIAL_TRUNK}_SUFFIX}'
TRUNK_OPTIONS_ASSIGNMENT = '${IF($["${DB_EXISTS(TRUNK/${DIAL_TRUNK}/dialopts)}" = "1"]?${DB_RESULT}:${TRUNK_OPTIONS})}'
# Reviewed vendor-signed FreePBX 17 metadata callbacks. Exact bodies and script
# bytes are required; a matching context name alone never authorizes code.
# Provenance and negative cases: tools/fixtures/freepbx_outbound_callbacks.json.
CALLBACK_CONTEXTS = {
    'app-missedcall-hangup': '21a9e67ba4acee7cb8f8f3fabaf7431d1d33e3ffcc518a6dccd9b362f8faf8e7',
    'crm-hangup': 'eafd76de6c2eba6dc2aeb8482c668864e915b88c58cc4a2ffebff718dde6cccf',
    'sub-send-obroute-email': 'c59e690170245c9aafd7ad5e428128f124d6c562e5a11cd9fc26d2a776f79230',
    'macro-setmusic': '981c49d923dd99a431c06405d422c9af65226aed2090c0b635043b267ee8424f',
}
CALLBACK_FILES = {
    'missedcallnotify.php': '3b068a293a30f583d6243950cc5088a2eff3e8df8862889a9228eaadfd14e78f',
    'sangomacrm.agi': 'c4e440dbb46d7afcf320b2c6afecb199d6acd5295340dd020f4be910707075b7',
    'outboundRouteEmail.php': 'c07838154a0dba14cb906c07c01ad6e6be9a8bd662540fd86c85d42ef086c29b',
    'allowlist-autoadd.agi': '684160575de8a740ba3f7872f3d156e23341165ee63720549a8e71ffa1c2886e',
    'allowlist-common.php': 'cd31204b2edf2ef77576cf783fe2f16e561c1631bbb56e66bc3a6b068e5245a3',
}
AGI_DIRECTORY = Path('/var/lib/asterisk/agi-bin')
EMAIL_DIAL_OPTION = 'U(sub-send-obroute-email^${DIAL_NUMBER}^${MACRO_EXTEN}^${DIAL_TRUNK}^${NOW}^${CALLERID(name)}^${CALLERID(number)},^${DIAL_TRUNK_MOH})'


def callback_file_fingerprint(name):
    """Read a fixed reviewed script; never execute AGI during admission."""
    if name not in CALLBACK_FILES:
        _fail('outbound_callback_version_unsupported')
    try:
        account = pwd.getpwnam('asterisk')
        owner, group = account.pw_uid, account.pw_gid
        directory = AGI_DIRECTORY.lstat()
        if (AGI_DIRECTORY.resolve() != AGI_DIRECTORY or not stat.S_ISDIR(directory.st_mode)
                or directory.st_uid not in {0, owner} or directory.st_mode & 0o002
                or (directory.st_mode & 0o020 and directory.st_gid != group)):
            _fail('outbound_callback_file_unsafe')
        fd = os.open(AGI_DIRECTORY / name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(fd, 'rb') as source:
            before = os.fstat(source.fileno())
            if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_uid not in {0, owner}
                    or before.st_mode & 0o7002 or (before.st_mode & 0o020 and before.st_gid != group)
                    or not 1 <= before.st_size <= 262144):
                _fail('outbound_callback_file_unsafe')
            body = source.read(262145)
            after = os.fstat(source.fileno())
            if len(body) != before.st_size or (before.st_mtime_ns, before.st_ctime_ns) != (after.st_mtime_ns, after.st_ctime_ns):
                _fail('outbound_callback_file_unsafe')
    except (OSError, KeyError):
        _fail('outbound_callback_file_unavailable')
    digest = hashlib.sha256(body).hexdigest()
    if digest != CALLBACK_FILES[name]:
        _fail('outbound_callback_version_unsupported')
    return digest


class OutboundRouteError(RuntimeError):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def _fail(code='outbound_route_unsupported'):
    raise OutboundRouteError(code)


def _split(value, separator=','):
    """Split application arguments outside balanced expressions and parentheses."""
    output, start, stack = [], 0, []
    pairs = {')': '(', ']': '[', '}': '{'}
    quoted = False
    escaped = False
    for index, char in enumerate(value):
        if escaped:
            escaped = False
            continue
        if char == '\\':
            escaped = True
            continue
        if char == '"':
            quoted = not quoted
            continue
        if quoted:
            continue
        if char in '([{':
            stack.append(char)
        elif char in ')]}':
            if not stack or stack.pop() != pairs[char]:
                _fail('outbound_dialplan_malformed')
        elif char == separator and not stack and not quoted:
            output.append(value[start:index])
            start = index + 1
    if stack or quoted or escaped:
        _fail('outbound_dialplan_malformed')
    return output + [value[start:]]


def _dial_options(value):
    """Permit inert ringing/caller-transfer flags and numeric stock time limits.

    In particular t, callbacks, dial strings and variable expansions are never
    accepted. T is safe on the unattended Playback side of the SLS origin.
    """
    if not isinstance(value, str) or len(value) > 128:
        _fail('outbound_dial_options_unsupported')
    tokens = re.findall(r'[TirR]|L\([0-9]{1,10}(?::[0-9]{0,10}){0,2}\)|S\([0-9]{1,8}\)', value)
    if ''.join(tokens) != value or len({token[0] for token in tokens}) != len(tokens):
        _fail('outbound_dial_options_unsupported')
    for token in tokens:
        if token[0] in {'L', 'S'} and int(token[2:-1].split(':')[0]) <= 0:
            _fail('outbound_dial_options_unsupported')
    return value


def channel_trunk_options(proof):
    """Return the sole permitted call-local override for the protected AGI."""
    values = proof.get('channel_variables') if isinstance(proof, dict) else None
    if not isinstance(values, dict) or set(values) != {'TRUNK_OPTIONS'}:
        _fail('outbound_channel_policy_invalid')
    value = _dial_options(values['TRUNK_OPTIONS'])
    if 'i' not in value:
        _fail('outbound_channel_policy_invalid')
    return value


def _matches(pattern, number):
    """Conservative Asterisk numeric pattern matching; never choose route order."""
    if not pattern.startswith('_'):
        return pattern == number
    result, index = '', 1
    while index < len(pattern):
        char = pattern[index]
        if char in 'XZN':
            result += {'X': '[0-9]', 'Z': '[1-9]', 'N': '[2-9]'}[char]
        elif char in '.!':
            result += '.+' if char == '.' else '.*'
        elif char == '[':
            end = pattern.find(']', index + 1)
            if end < 0 or not re.fullmatch(r'[0-9+*#-]+', pattern[index + 1:end]):
                _fail('outbound_route_pattern_unsupported')
            result += pattern[index:end + 1]
            index = end
        elif char in '+0123456789*#':
            result += re.escape(char)
        elif char == '-':
            pass  # Asterisk ignores hyphens outside a character class.
        else:
            _fail('outbound_route_pattern_unsupported')
        index += 1
    try:
        return re.fullmatch(result, number) is not None
    except re.error:
        _fail('outbound_route_pattern_unsupported')


class _Proof:
    def __init__(self, ami, target):
        self.ami, self.target = ami, target
        self.started = time.monotonic()
        self.contexts, self.variables = {}, {}
        self.total_bytes = 0
        self.trunks = set()
        self.seen = set()
        self.saw_dial = False
        self.saw_dispatch = False
        self.channel_variables = {}
        self.callback_files = {}

    def callback_script(self, name):
        self.budget()
        self.callback_files[name] = callback_file_fingerprint(name)

    def callback_context(self, context, rows):
        body = [[r['Extension'], int(r['Priority']), r.get('ExtensionLabel', ''), r['Application'].lower(), r['AppData']]
                for r in sorted([r for r in rows if 'Application' in r], key=lambda r: (r['Extension'], int(r['Priority'])))]
        if hashlib.sha256(json.dumps(body, separators=(',', ':')).encode()).hexdigest() != CALLBACK_CONTEXTS[context]:
            _fail('outbound_callback_context_changed')
        scripts = {'app-missedcall-hangup': 'missedcallnotify.php', 'crm-hangup': 'sangomacrm.agi',
                   'sub-send-obroute-email': 'outboundRouteEmail.php'}
        if context in scripts:
            self.callback_script(scripts[context])
        if context == 'sub-send-obroute-email':
            # The exact stock branch skips macro-confirm for a fresh SLS origin.
            # Other contexts cannot set FORCE_CONFIRM or supply a U callback.
            if self.variable('FORCE_CONFIRM'):
                _fail('outbound_confirmation_callback_unsupported')
            self.context('macro-setmusic')

    def budget(self):
        if time.monotonic() - self.started > MAX_SECONDS:
            _fail('outbound_route_inventory_timeout')

    def variable(self, expression):
        self.budget()
        if expression not in self.variables:
            try:
                value = self.ami.variable(expression)
            except Exception:
                _fail('outbound_route_inventory_unavailable')
            if not isinstance(value, str) or len(value.encode('utf-8')) > 8192 or re.search(r'[\x00-\x1f\x7f]', value):
                _fail('outbound_route_inventory_invalid')
            self.variables[expression] = value
        return self.variables[expression]

    def exists(self, context):
        value = self.variable('DIALPLAN_EXISTS(' + context + ')')
        if value not in {'0', '1'}:
            _fail('outbound_route_inventory_invalid')
        return value == '1'

    def rows(self, context):
        self.budget()
        if context not in self.contexts:
            if len(self.contexts) >= MAX_CONTEXTS:
                _fail('outbound_route_inventory_oversized')
            try:
                raw = self.ami.dialplan(context)
            except Exception:
                _fail('outbound_route_inventory_unavailable')
            if not isinstance(raw, list) or not raw or len(raw) > MAX_ROWS:
                _fail('outbound_dialplan_incomplete')
            rows = []
            for row in raw:
                if not isinstance(row, dict) or row.get('Context') != context:
                    _fail('outbound_dialplan_malformed')
                clean = {key: row[key] for key in ('Context', 'Extension', 'Priority', 'Application', 'AppData',
                         'ExtensionLabel', 'IncludeContext', 'Switch', 'IgnorePattern') if key in row}
                if any(not isinstance(value, str) or len(value.encode()) > 8192 or re.search(r'[\x00-\x1f\x7f]', value) for value in clean.values()):
                    _fail('outbound_dialplan_malformed')
                if 'Switch' in clean or 'IgnorePattern' in clean:
                    _fail('outbound_route_switch_unsupported')
                if 'IncludeContext' in clean:
                    if set(clean) != {'Context', 'IncludeContext'}:
                        _fail('outbound_dialplan_malformed')
                elif (not {'Extension', 'Priority', 'Application', 'AppData'} <= clean.keys()
                      or not re.fullmatch(r'[1-9][0-9]{0,4}', clean['Priority'])
                      or not re.fullmatch(r'[A-Za-z][A-Za-z0-9]*', clean['Application'])):
                    _fail('outbound_dialplan_malformed')
                rows.append(clean)
            self.total_bytes += len(json.dumps(rows).encode())
            if self.total_bytes > MAX_BYTES:
                _fail('outbound_route_inventory_oversized')
            priorities = {}
            for row in rows:
                if 'Priority' in row:
                    priorities.setdefault(row['Extension'], []).append(int(row['Priority']))
            for values in priorities.values():
                if len(set(values)) != len(values):
                    # ShowDialPlan omits caller-ID-match metadata. Ambiguous
                    # duplicate bodies cannot be treated as one complete route.
                    _fail('outbound_dialplan_ambiguous')
                if sorted(values) != list(range(1, max(values) + 1)):
                    _fail('outbound_dialplan_incomplete')
            self.contexts[context] = rows
        return self.contexts[context]

    def includes(self, context, rows):
        for row in rows:
            if 'IncludeContext' not in row:
                continue
            included = row['IncludeContext']
            # FreePBX emits per-context custom include slots. Only absence is
            # accepted; a present admin context is never overwritten or ignored.
            if included != context + '-custom' or self.exists(included):
                _fail('outbound_custom_context_unsupported')

    def expressions(self, data, context=''):
        if '${${' in data:
            _fail('outbound_dialplan_function_unsupported')
        for name in re.findall(r'\$\{([A-Za-z_][A-Za-z0-9_]*)\(', data):
            if name.upper() not in SAFE_FUNCTIONS:
                _fail('outbound_dialplan_function_unsupported')
        # SET is a side-effecting function even inside NoOp/While expressions.
        for lhs in re.findall(r'\$\{SET\(([^=]+)=', data, re.I):
            if lhs not in {'OUTBOUND_ROUTE_NAME', 'sipkey'}:
                _fail('outbound_dialplan_assignment_unsupported')
        for argument in re.findall(r'\$\{SHIFT\(([^)]*)\)', data, re.I):
            if context != 'func-apply-sipheaders' or argument != 'SIPHEADERKEYS':
                _fail('outbound_dialplan_assignment_unsupported')
        if '${DB_DELETE(' in data and not (context == 'macro-hangupcall'
                and data == 'Deleting: RG/${RINGGROUP_INDEX}/${CHANNEL} ${DB_DELETE(RG/${RINGGROUP_INDEX}/${CHANNEL})}'):
            _fail('outbound_dialplan_assignment_unsupported')

    def assignment(self, data, context):
        if '=' not in data:
            _fail('outbound_dialplan_assignment_unsupported')
        lhs, value = data.split('=', 1)
        base = lhs.lstrip('_')
        if not re.fullmatch(r'[A-Za-z][A-Za-z0-9_]*|(?:CALLERID|CALLERPRES|CONNECTEDLINE|CDR|GROUP|HASH|PJSIP_HEADER|CHANNEL)\(.+\)', base):
            _fail('outbound_dialplan_assignment_unsupported')
        if base.startswith(('SLS_', 'OUT_', 'OUTPREFIX_', 'OUTDISABLE_', 'PREFIX_TRUNK_', 'TRUNK_OPTIONS')) or base in {'ARG1', 'ARG2', 'ARG3', 'ARG4', 'DIALSTATUS', 'HANGUPCAUSE'}:
            _fail('outbound_dialplan_assignment_unsupported')
        if base in {'FORCE_CONFIRM', 'PBXMFA', 'DYNAMIC_FEATURES', 'TRANSFER_CONTEXT', 'GOSUB_RETVAL'}:
            _fail('outbound_dialplan_assignment_unsupported')
        if (base == 'CHANNEL(hangup_handler_push)' and context in {'macro-dialout-trunk', 'func-apply-sipheaders'}
                and value == 'crm-hangup,s,1'):
            self.context('crm-hangup')
        elif (base == 'CHANNEL(hangup_handler_push)' and context == 'func-apply-sipheaders'
                and value == 'app-missedcall-hangup,${DialMCEXT},1'):
            self.context('app-missedcall-hangup')
        elif base.startswith('CHANNEL(') and base != 'CHANNEL(language)':
            _fail('outbound_dialplan_assignment_unsupported')
        if base.lower() == 'cdr(accountcode)':
            _fail('outbound_dialplan_assignment_unsupported')
        protected = {
            'DIAL_TRUNK': {'${ARG1}'},
            'DIAL_NUMBER': {'${ARG2}'} | {'${TARGET_FLP_' + trunk + '}' for trunk in self.trunks},
            'OUTNUM': {'${OUTPREFIX_${DIAL_TRUNK}}${DIAL_NUMBER}'},
            'custom': {'${CUT(OUT_${DIAL_TRUNK},:,1)}'},
            'DIAL_TRUNK_OPTIONS': {'${DIAL_OPTIONS}', '${IF($["${DB_EXISTS(TRUNK/${DIAL_TRUNK}/dialopts)}" = "1"]?${DB_RESULT}:${TRUNK_OPTIONS})}',
                                   '${STRREPLACE(DIAL_TRUNK_OPTIONS,T)}'},
        }
        if base in protected and value not in protected[base]:
            _fail('outbound_dialplan_assignment_unsupported')
        if base in protected and context != 'macro-dialout-trunk' and not (base == 'DIAL_NUMBER' and re.fullmatch(r'sub-flp-[1-9][0-9]{0,8}', context)):
            _fail('outbound_dialplan_assignment_unsupported')
        if base.startswith('TARGET_FLP_') and not re.fullmatch(r'[+0-9*#]*\$\{DIAL_NUMBER(?::[0-9]{1,2})?\}', value):
            _fail('outbound_trunk_dialrules_unsupported')

    def hook(self):
        context = 'macro-dialout-trunk-predial-hook'
        if not self.exists(context):
            return
        rows = self.rows(context)
        self.includes(context, rows)
        body = [row for row in rows if 'Application' in row]
        if not body or any(row['Extension'] != 's' or row['Application'].lower() not in {'noop', 'return', 'macroexit', 'set'} for row in body):
            _fail('outbound_predial_hook_unsupported')
        for row in body:
            data = row['AppData']
            if row['Application'].lower() == 'set':
                # Common administrator connected-line hook: preserve it, but
                # accept no route/options changes, extra calls or arbitrary DB writes.
                if data not in {
                    'CIDNAME=${CUT(TRUNKCIDOVERRIDE,"<",1)}',
                    'CIDNAME=${FILTER(abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 ,${CIDNAME})}',
                    'CONNECTEDLINE(name,i)=${CIDNAME}', 'CONNECTEDLINE(num,i)=${DIAL_NUMBER}',
                    'DB(AMPUSER/${AMPUSER}/cidname)=',
                }:
                    _fail('outbound_predial_hook_unsupported')
            elif '${' in data or '$[' in data:
                _fail('outbound_predial_hook_unsupported')

    def call(self, context, data, application):
        if application == 'macro':
            parts = _split(data)
            destination, args = 'macro-' + parts[0], parts[1:]
        else:
            parts = _split(data)
            if len(parts) != 3:
                _fail('outbound_subroutine_unsupported')
            destination = parts[0]
            if context == 'macro-hangupcall' and data == 'app-missedcall-hangup,${EXTEN},1()':
                self.context('app-missedcall-hangup')
                return
            if context == destination == 'macro-user-callerid' and parts[1] in {'${CHANNEL(language)}', 'en'} and parts[2] in {'${ARG1}', '${ARG1}()'}:
                return  # All language handlers are separately checked below.
            match = re.fullmatch(r'([A-Za-z0-9_]+)(?:\((.*)\))?', parts[2])
            if not match:
                _fail('outbound_subroutine_unsupported')
            args = _split(match[2]) if match[2] is not None else []
            if destination != 'macro-user-callerid' and parts[1] not in {'s', '${DIAL_NUMBER}'}:
                _fail('outbound_subroutine_unsupported')
        if destination == 'macro-dialout-trunk':
            if not context.startswith('outrt-') or len(args) not in {2, 3, 4} or not re.fullmatch(r'[1-9][0-9]{0,8}', args[0]):
                _fail('outbound_route_trunk_unsupported')
            if not re.fullmatch(r'[+0-9*#]*\$\{EXTEN(?::[0-9]{1,2})?\}', args[1]):
                _fail('outbound_route_number_unsupported')
            if len(args) >= 3 and args[2]:
                _fail('outbound_route_pin_unsupported')
            if len(args) >= 4 and args[3] not in {'', 'on', 'off'}:
                _fail('outbound_route_trunk_unsupported')
            self.trunks.add(args[0])
            if len(self.trunks) > MAX_TRUNKS:
                _fail('outbound_route_inventory_oversized')
            return  # Validate shared trunk macro after collecting every trunk.
        if destination == 'macro-dialout-trunk-predial-hook':
            self.hook()
            return
        if destination == 'trunk-dial-with-exten':
            if (context != 'macro-dialout-trunk' or application != 'gosub'
                    or parts[1] != '${DIAL_NUMBER}' or parts[2] not in {'1', '1()'}):
                _fail('outbound_trunk_setup_unsupported')
        if destination == 'func-apply-sipheaders':
            _fail('outbound_subroutine_unsupported')  # Only the checked Dial b() may enter it.
        if destination == 'sub-flp-${DIAL_TRUNK}':
            for trunk in sorted(self.trunks):
                if self.variable('PREFIX_TRUNK_' + trunk) not in {'', '0'}:
                    self.context('sub-flp-' + trunk)
            return
        if destination == context and context == 'macro-user-callerid':
            return  # All language/limit handlers in this same context are scanned.
        if destination not in SAFE_CONTEXTS:
            _fail('outbound_subroutine_unsupported')
        self.context(destination)

    def application(self, context, app, data):
        app = app.lower()
        self.expressions(data, context)
        if (context == 'macro-dialout-trunk' and app == 'execif'
                and data == '$[${DB_EXISTS(allowlist/autoadd/${ROUTEID})}]?AGI(allowlist-autoadd.agi,)'):
            self.callback_script('allowlist-autoadd.agi')
            self.callback_script('allowlist-common.php')
            return
        if context == 'macro-hangupcall' and app == 'execif' and data == '$["${PBXMFA}"="1"]?AGI(pbxmfa.agi,noanswer)':
            if self.variable('PBXMFA') not in {'', '0'}:
                _fail('outbound_agi_callback_unsupported')
            return
        if (context == 'macro-hangupcall' and app == 'userevent'
                and data == 'MES,RTPAUDIOQOSMESBRIDGED:${RTPAUDIOQOSMESBRIDGED},RTPAUDIOQOSMES:${RTPAUDIOQOSMES}'):
            return
        if app == 'set':
            self.assignment(data, context)
        elif app in INERT_APPS:
            pass
        elif app in {'macro', 'gosub'}:
            self.call(context, data, app)
        elif app in {'execif', 'gosubif', 'gotoif'}:
            if (context == 'macro-dialout-trunk' and app == 'execif'
                    and data == '$["${FORCE_CONFIRM}"!="" ]?Set(DIAL_TRUNK_OPTIONS=${DIAL_TRUNK_OPTIONS}U(macro-confirm))'):
                if self.variable('FORCE_CONFIRM'):
                    _fail('outbound_confirmation_callback_unsupported')
                return  # New SLS origin has no confirmation flag; assignments are forbidden.
            pieces = _split(data, '?')
            if len(pieces) != 2:
                _fail('outbound_dialplan_control_unsupported')
            branches = _split(pieces[1], ':')
            if len(branches) > 2:
                _fail('outbound_dialplan_control_unsupported')
            for branch in branches:
                if not branch:
                    continue
                if app == 'execif':
                    nested = re.fullmatch(r'([A-Za-z][A-Za-z0-9]*)\((.*)\)', branch)
                    if not nested or nested[1].lower() not in {'set', 'return', 'hangup', 'sipaddheader', 'sipremoveheader'}:
                        _fail('outbound_dialplan_control_unsupported')
                    if nested[1].lower() == 'set' and nested[2].startswith('DIAL_TRUNK_OPTIONS=') and nested[2] != 'DIAL_TRUNK_OPTIONS=${STRREPLACE(DIAL_TRUNK_OPTIONS,T)}':
                        _fail('outbound_dialplan_assignment_unsupported')
                    self.application(context, nested[1], nested[2])
                elif app == 'gosubif':
                    # PIN logic is stock, but every admitted route has empty ARG3.
                    if context == 'macro-dialout-trunk' and branch == 'sub-pincheck,s,1(${ARG3})' and pieces[0] == '$[$["${ARG3}" != ""] & $["${DB(AMPUSER/${AMPUSER}/pinless)}" != "NOPASSWD"]]':
                        continue
                    self.call(context, branch, 'gosub')
                else:
                    self.jump(context, branch)
        elif app == 'goto':
            self.jump(context, data)
        elif app in {'while', 'endwhile'} and context == 'func-apply-sipheaders':
            pass
        elif app == 'dial':
            arguments = _split(data)
            if context == 'macro-dialout-trunk' and arguments == ['${pre_num:4}${the_num}${post_num}', '${TRUNK_RING_TIMER}', '${DIAL_TRUNK_OPTIONS}']:
                return  # Unreachable: every admitted OUT_<id> is exactly PJSIP.
            if context not in {'macro-dialout-trunk', 'trunk-dial-with-exten'} or len(arguments) != 3 or arguments[0] != DIAL_DESTINATION or arguments[1] != '${TRUNK_RING_TIMER}':
                _fail('outbound_parallel_or_custom_dial_unsupported')
            options = arguments[2]
            if context == 'trunk-dial-with-exten' and options == '${DIAL_TRUNK_OPTIONS}b(func-apply-sipheaders^s^1,(${DIAL_TRUNK}))' + EMAIL_DIAL_OPTION:
                self.context('sub-send-obroute-email')
                options = options[:-len(EMAIL_DIAL_OPTION)]
            if options not in {'${DIAL_TRUNK_OPTIONS}', '${DIAL_TRUNK_OPTIONS}b(func-apply-sipheaders^s^1)', '${DIAL_TRUNK_OPTIONS}b(func-apply-sipheaders^s^1,(${DIAL_TRUNK}))'}:
                _fail('outbound_dial_options_unsupported')
            if 'b(' in arguments[2]:
                self.context('func-apply-sipheaders')
            self.saw_dial = True
        elif app in {'agi', 'eagi', 'deadagi'}:
            if context == 'macro-dialout-trunk' and app == 'agi' and data == 'agi://127.0.0.1/sangomacrm.agi':
                self.callback_script('sangomacrm.agi')
            else:
                _fail('outbound_agi_callback_unsupported')
        else:
            _fail('outbound_dialplan_application_unsupported')

    def trunk_flow(self):
        """Prove stock option/number setup dominates every reachable trunk Dial.

        Generic application allowlisting alone is insufficient: a Goto could
        skip the safe options assignment or enter the otherwise unreachable AMP
        custom-trunk branch. Explore both sides of unknown conditions, tracking
        only the setup facts needed for the single-PJSIP-leg guarantee.
        """
        rows = [r for r in self.rows('macro-dialout-trunk') if 'Application' in r]
        nodes = {(r['Extension'], int(r['Priority'])): r for r in rows}
        if len(nodes) != len(rows) or ('s', 1) not in nodes:
            _fail('outbound_dialplan_incomplete')
        labels = {(r['Extension'], r['ExtensionLabel']): int(r['Priority']) for r in rows if r.get('ExtensionLabel')}
        seen, pending = set(), [(('s', 1), 0)]
        # DIAL_TRUNK, DIAL_NUMBER, OUTNUM, safe DIAL_TRUNK_OPTIONS, PJSIP custom marker.
        bits = {'DIAL_TRUNK': 1, 'DIAL_NUMBER': 2, 'OUTNUM': 4, 'DIAL_TRUNK_OPTIONS': 8, 'custom': 16}

        def jumps(extension, text):
            parts = _split(text)
            if len(parts) == 1:
                target_extensions, priority = [extension], parts[0]
            elif len(parts) == 2:
                target, priority = parts
                if target == 's-${DIALSTATUS}':
                    target_extensions = sorted({r['Extension'] for r in rows if r['Extension'].startswith(('s-', '_s-'))})
                elif target == '${RC}':
                    target_extensions = sorted({r['Extension'] for r in rows if r['Extension'].isdigit() or r['Extension'] in {'_X', '_X.'}})
                else:
                    target_extensions = [target]
            else:
                _fail('outbound_dialplan_control_unsupported')
            targets = []
            for ext in target_extensions:
                value = int(priority) if priority.isdigit() else labels.get((ext, priority))
                if value is None or (ext, value) not in nodes:
                    _fail('outbound_dialplan_incomplete')
                targets.append((ext, value))
            if not targets:
                _fail('outbound_dialplan_incomplete')
            return targets

        while pending:
            self.budget()
            node, state = pending.pop()
            if (node, state) in seen:
                continue
            seen.add((node, state))
            if len(seen) > MAX_ROWS * 32:
                _fail('outbound_route_inventory_oversized')
            row = nodes.get(node)
            if row is None:
                continue  # End of an extension cannot originate another leg.
            app, data = row['Application'].lower(), row['AppData']
            following = (node[0], node[1] + 1)
            if app in {'return', 'macroexit', 'hangup', 'busy', 'congestion'}:
                continue
            if app == 'set':
                lhs, value = data.split('=', 1)
                lhs = lhs.lstrip('_')
                if lhs in bits:
                    if lhs == 'DIAL_TRUNK_OPTIONS':
                        state = state | 8 if value == TRUNK_OPTIONS_ASSIGNMENT else state & ~8
                    elif lhs == 'OUTNUM':
                        if state & 3 != 3:
                            _fail('outbound_trunk_setup_unsupported')
                        state |= 4
                    else:
                        state |= bits[lhs]
            if app == 'dial' or (app in {'gosub', 'macro'} and data.startswith('trunk-dial-with-exten,')):
                if app == 'dial' and _split(data)[0] != DIAL_DESTINATION:
                    _fail('outbound_custom_trunk_path_unsupported')
                if state & 15 != 15:
                    _fail('outbound_trunk_setup_unsupported')
            if app == 'goto':
                pending.extend((target, state) for target in jumps(node[0], data))
                continue
            if app == 'gotoif':
                condition, branch_text = _split(data, '?')
                branches = _split(branch_text, ':')
                if condition == '$["${custom}" = "AMP"]' and state & 16:
                    branches = [branches[1] if len(branches) > 1 else '']
                else:
                    branches += [''] if len(branches) == 1 else []
                for branch in branches:
                    pending.extend((target, state) for target in jumps(node[0], branch)) if branch else pending.append((following, state))
                continue
            pending.append((following, state))

    def jump(self, context, data):
        if context.startswith('outrt-'):
            _fail('outbound_route_custom_destination_unsupported')
        parts = _split(data)
        if not 1 <= len(parts) <= 2:
            _fail('outbound_dialplan_control_unsupported')
        if any(not re.fullmatch(r'[A-Za-z0-9_-]+|s-\$\{DIALSTATUS\}|\$\{RC\}', part) for part in parts):
            _fail('outbound_dialplan_control_unsupported')

    def context(self, context):
        if context in self.seen:
            return
        if context not in SAFE_CONTEXTS and context not in CALLBACK_CONTEXTS and not re.fullmatch(r'sub-flp-[1-9][0-9]{0,8}', context):
            _fail('outbound_subroutine_unsupported')
        self.seen.add(context)
        rows = self.rows(context)
        self.includes(context, rows)
        if context in CALLBACK_CONTEXTS:
            self.callback_context(context, rows)
            return
        for row in rows:
            if 'Application' in row:
                self.application(context, row['Application'], row['AppData'])

    def route(self, context):
        rows = self.rows(context)
        self.includes(context, rows)
        selected = [row for row in rows if 'Extension' in row and _matches(row['Extension'], self.target['number'])]
        if not selected:
            return
        found_trunk = False
        for row in selected:
            if row['Application'].lower() in {'macro', 'gosub'} and ('dialout-trunk,' in row['AppData']):
                found_trunk = True
            self.application(context, row['Application'], row['AppData'])
        if not found_trunk:
            _fail('outbound_route_has_no_supported_trunk')

    def trunk(self, trunk):
        if self.variable('OUT_' + trunk) != 'PJSIP':
            _fail('outbound_trunk_technology_unsupported')
        disabled = self.variable('OUTDISABLE_' + trunk)
        if disabled not in {'off', ''}:
            _fail('outbound_trunk_disabled')
        suffix = self.variable('OUT_' + trunk + '_SUFFIX')
        if not re.fullmatch(r'@[A-Za-z0-9_.-]{1,80}', suffix):
            _fail('outbound_trunk_endpoint_invalid')
        endpoint = suffix[1:]
        aors = self.variable('PJSIP_ENDPOINT(' + endpoint + ',aors)')
        if not re.fullmatch(r'[A-Za-z0-9_.-]+(?:,[A-Za-z0-9_.-]+)*', aors):
            _fail('outbound_trunk_endpoint_unavailable')
        if not re.fullmatch(r'[+0-9*#]{0,32}', self.variable('OUTPREFIX_' + trunk)):
            _fail('outbound_trunk_dialrules_unsupported')
        global_options = _dial_options(self.variable('TRUNK_OPTIONS'))
        override = _dial_options(self.variable('DB(TRUNK/' + trunk + '/dialopts)'))
        override_exists = self.variable('DB_EXISTS(TRUNK/' + trunk + '/dialopts)')
        if override_exists not in {'0', '1'}:
            _fail('outbound_route_inventory_invalid')
        if override_exists == '1' and 'i' not in override:
            _fail('outbound_trunk_forwarding_override_unsupported')
        # A channel variable shadows the global only on the SLS origin. The
        # stock per-trunk AstDB override wins, so it must already be safe.
        self.channel_variables['TRUNK_OPTIONS'] = global_options + ('' if 'i' in global_options else 'i')
        if self.variable('OUTFAIL_' + trunk):
            _fail('outbound_trunk_failure_hook_unsupported')
        for name in ('OUTCID_', 'FORCEDOUTCID_', 'OUTKEEPCID_', 'OUTMAXCHANS_', 'PREFIX_TRUNK_'):
            self.variable(name + trunk)
        return endpoint


def validate_route(ami, target):
    fields = {'id', 'number', 'route_mode', 'trunk_id', 'caller_id'}
    if (isinstance(target, dict) and fields <= set(target)
            and not set(target) - fields - {'acknowledgement_required', 'ack_timeout_seconds', 'incident_response'}):
        # Playback policy is frozen by admission; it cannot change route proof.
        target = {key: target[key] for key in fields}
    if (not isinstance(target, dict) or set(target) != {'id', 'number', 'route_mode', 'trunk_id', 'caller_id'}
        or any(not isinstance(value, str) for value in target.values())
        or not re.fullmatch(r'voice_[a-f0-9]{24}', target['id'])
        or not re.fullmatch(r'\+[1-9][0-9]{1,14}', target['number'])
        or target['route_mode'] not in {'pbx_routes', 'trunk'}
        or (target['caller_id'] and not re.fullmatch(r'\+?[1-9][0-9]{1,14}', target['caller_id']))):
        _fail('outbound_target_invalid')
    proof = _Proof(ami, target)
    if target['route_mode'] == 'trunk':
        if not re.fullmatch(r'[1-9][0-9]{0,8}', target['trunk_id']):
            _fail('outbound_target_invalid')
        proof.trunks.add(target['trunk_id'])
    else:
        if target['trunk_id']:
            _fail('outbound_target_invalid')
        rows = proof.rows('outbound-allroutes')
        includes = []
        for row in rows:
            if 'IncludeContext' in row:
                include = row['IncludeContext']
                context = include.split(',', 1)[0]
                if include == 'outbound-allroutes-custom' and not proof.exists(include):
                    continue
                if not re.fullmatch(r'outrt-[1-9][0-9]{0,8}', context) or len(include) > 512 or re.search(r'[${}]', include):
                    _fail('outbound_route_include_unsupported')
                includes.append(context)
            elif row['Extension'] != 'foo' or row['Application'].lower() != 'noop' or row['AppData'] != 'bar':
                _fail('outbound_route_wrapper_unsupported')
        if not includes or len(includes) > MAX_CONTEXTS - 16:
            _fail('outbound_route_include_unsupported')
        for context in dict.fromkeys(includes):
            proof.route(context)
        if not proof.trunks:
            _fail('outbound_route_not_found')
    endpoints = [proof.trunk(trunk) for trunk in sorted(proof.trunks)]
    proof.hook()
    proof.context('macro-dialout-trunk')
    proof.trunk_flow()
    if not proof.saw_dial:
        _fail('outbound_dialplan_incomplete')
    encoded = json.dumps({'target': target, 'contexts': proof.contexts, 'variables': proof.variables,
                          'channel_variables': proof.channel_variables, 'callback_files': proof.callback_files}, sort_keys=True, separators=(',', ':')).encode()
    return {'fingerprint': hashlib.sha256(encoded).hexdigest(), 'trunk_endpoints': sorted(set(endpoints)),
            'channel_variables': proof.channel_variables,
            **{key: target[key] for key in ('route_mode', 'trunk_id', 'number', 'caller_id')}}
