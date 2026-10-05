#!/usr/bin/python3
"""Prepare upgrade approvals from signed packages, without changing live trust.

Only authenticated upstream bytes, authenticated SLS overlays and explicitly
reviewed protected inventories establish expectations. Unknown live differences
are reported for review; their hashes never become an approval automatically.
"""
from __future__ import annotations

import argparse
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import tarfile

sys.dont_write_bytecode = True
HERE = Path(__file__).absolute().parent
WEB = Path('/var/www/html')
RUNTIME = '/usr/local/bin/sls_mass_notify'
UPSTREAM_SIGNER = '456D051E9204C27C37D4811BB53D215A755231A3'
UPSTREAM_KEY_SHA256 = '41f5c332ca0e667db72ac4c4500f3d5ad9f010f43f51a19bf07b01c9741ebd7a'
VERSION = re.compile(r'17\.[0-9]+(?:\.[0-9]+){1,3}\Z')
SLS_VERSION = re.compile(r'[0-9]+\.[0-9]+\.[0-9]+(?:-beta)?\Z')
WIDGETS = ('sections/SlsMassNotifyAnnouncement.class.php', 'views/sections/sls-mass-notify-announcement.php')
MENU_NAME = 'amp_conf/htdocs/admin/views/menu_items.php'
SPINNER = 'amp_conf/htdocs/admin/images/spinner.gif'
MENU = b'''\t// SLS Mass Notifications menu placement: keep Mass Notify after UCP/User Panel.
\telse if (in_array($a, ['mass notifications', 'mass notify'], true) && $b == 'other')
\t\treturn -1;
\telse if ($a == 'other' && in_array($b, ['mass notifications', 'mass notify'], true))
\t\treturn 1;
\telse if (in_array($a, ['mass notifications', 'mass notify'], true) && in_array($b, ['user panel', 'ucp'], true))
\t\treturn 1;
\telse if (in_array($a, ['user panel', 'ucp'], true) && in_array($b, ['mass notifications', 'mass notify'], true))
\t\treturn -1;
\telse if (in_array($a, ['mass notifications', 'mass notify'], true))
\t\treturn 1;
\telse if (in_array($b, ['mass notifications', 'mass notify'], true))
\t\treturn -1;
'''
ROOT_JOB = re.compile(rb'(?:\* \* \* \* \* /usr/bin/timeout 900 ' + RUNTIME.encode() +
                      rb'/sls_mass_notify_maintenance\.sh|17 (?:\*|\*/6) \* \* \* /usr/bin/timeout 1800 ' +
                      RUNTIME.encode() + rb'/sls_mass_notify_update\.sh)\Z')


class UpgradeError(RuntimeError):
    pass


def helper(name):
    spec = importlib.util.spec_from_file_location('_sls_upgrade_' + name, HERE / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


TRUST = helper('sls_module_trust')


def write_json(path, data):
    body = (json.dumps(data, sort_keys=True, separators=(',', ':')) + '\n').encode()
    TRUST.atomic(path, body)
    return TRUST.digest(body)


def identity(web, module):
    body = TRUST.read(web / 'admin/modules' / module / 'module.xml', 1024 * 1024)
    if b'<!DOCTYPE' in body.upper() or b'<!ENTITY' in body.upper():
        raise UpgradeError('Unsupported module XML declarations: ' + module)
    xml = ET.fromstring(body)
    value = xml.findtext('version', '')
    pattern = SLS_VERSION if module == 'slsmassnotifyserver' else VERSION
    if xml.findtext('rawname') != module or not pattern.fullmatch(value):
        raise UpgradeError('Unsupported installed module identity: ' + module)
    return value


class Redirects(urllib.request.HTTPRedirectHandler):
    max_redirections = 5

    def redirect_request(self, request, response, code, message, headers, new_url):
        target = urllib.parse.urlsplit(new_url)
        if (target.scheme != 'https' or target.username or target.password or target.port not in (None, 443)
                or target.fragment or target.hostname not in {'mirror.freepbx.org', 'github.com',
                                                            'objects.githubusercontent.com', 'release-assets.githubusercontent.com'}
                or request.host == 'mirror.freepbx.org' and target.hostname != 'mirror.freepbx.org'):
            raise UpgradeError('Upgrade package redirected outside its approved HTTPS host')
        return super().redirect_request(request, response, code, message, headers, new_url)


def download(url, destination, limit):
    upstream = r'https://mirror\.freepbx\.org/modules/packages/(dashboard|framework)/\1-(17\.[0-9]+(?:\.[0-9]+){1,3})\.tgz\.gpg'
    sls = (r'https://github\.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/'
           r'slsmassnotifyserver-([0-9]+\.[0-9]+\.[0-9]+(?:-beta)?)/(?:slsmassnotifyserver-\1\.tgz|release-manifest\.(?:json|sig))')
    if not re.fullmatch(upstream, url) and not re.fullmatch(sls, url):
        raise UpgradeError('Unsupported upgrade package URL')
    request = urllib.request.Request(url, headers={'User-Agent': 'SLS-Upgrade-Inventory', 'Accept-Encoding': 'identity'})
    opener = urllib.request.build_opener(Redirects())
    deadline = time.monotonic() + 180
    try:
        descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
        with os.fdopen(descriptor, 'wb') as output, opener.open(request, timeout=20) as response:
            length = response.headers.get('Content-Length')
            if length is not None and (not length.isdigit() or int(length) > limit):
                raise UpgradeError('Upgrade package exceeds its size limit')
            total = 0
            while True:
                if time.monotonic() >= deadline:
                    raise UpgradeError('Upgrade package download exceeded three minutes')
                chunk = response.read(min(65536, limit - total + 1))
                if not chunk:
                    break
                total += len(chunk)
                if total > limit:
                    raise UpgradeError('Upgrade package exceeds its size limit')
                output.write(chunk)
            if not total or length is not None and total != int(length):
                raise UpgradeError('Upgrade package was empty or incomplete')
            output.flush()
            os.fsync(output.fileno())
    except (OSError, ValueError) as error:
        raise UpgradeError('Unable to download the required signed package. Check HTTPS access or supply the offline packages documented in INSTALL.md.') from error


def upstream_contents(package, module, version, workspace):
    """Verify a whole official GPG package in an isolated keyring, then parse it."""
    signed = TRUST.read(package, TRUST.MAX_ARCHIVE, protected=True)
    key = TRUST.read(HERE / 'freepbx-mirror-signing.pub', 16384, protected=True)
    if TRUST.digest(key) != UPSTREAM_KEY_SHA256:
        raise UpgradeError('Bundled FreePBX mirror verification key changed')
    with tempfile.TemporaryDirectory(prefix='.upstream-gpg-', dir=workspace) as temporary:
        private = Path(temporary)
        (private / 'key.asc').write_bytes(key)
        (private / 'package.gpg').write_bytes(signed)
        command = ['/usr/bin/gpg', '--no-options', '--homedir', str(private), '--batch', '--no-tty', '--no-auto-key-retrieve']
        subprocess.run(command + ['--import', str(private / 'key.asc')], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20)
        plain = private / 'package.tgz'
        result = subprocess.run(command + ['--status-fd', '1', '--output', str(plain), '--decrypt', str(private / 'package.gpg')],
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=90)
        lines = result.stdout.decode('ascii', errors='strict').splitlines()
        valid = [line.split() for line in lines if line.startswith('[GNUPG:] VALIDSIG ')]
        if (result.returncode or len(valid) != 1 or valid[0][2] != UPSTREAM_SIGNER or valid[0][-1] != UPSTREAM_SIGNER
                or any(re.match(r'\[GNUPG:\] (?:BADSIG|ERRSIG|EXPKEYSIG|EXPSIG|REVKEYSIG|KEYREVOKED)\b', line) for line in lines)):
            raise UpgradeError('Official ' + module + ' package signature is invalid, expired, revoked or signed by an unsupported key')
        body = TRUST.read(plain, TRUST.MAX_ARCHIVE, protected=True)
    contents = {}
    total = 0
    with tarfile.open(fileobj=io.BytesIO(body), mode='r:gz') as archive:
        count = 0
        for member in archive:
            count += 1
            if count > TRUST.MAX_FILES * 2:
                raise UpgradeError('Upstream archive has too many members')
            name = member.name.rstrip('/') if member.isdir() else member.name
            TRUST.relative(name)
            if name == module and member.isdir():
                continue
            if not name.startswith(module + '/') or not (member.isdir() or member.isfile()) or member.mode & 0o6000:
                raise UpgradeError('Unsafe upstream archive entry')
            if not member.isfile():
                continue
            name = name[len(module) + 1:]
            total += member.size
            if name in contents or member.size > TRUST.MAX_ARCHIVE or total > 512 * 1024 * 1024:
                raise UpgradeError('Duplicate or oversized upstream archive entry')
            contents[name] = archive.extractfile(member).read(member.size + 1)
    xml = ET.fromstring(contents.get('module.xml', b''))
    if xml.findtext('rawname') != module or xml.findtext('version') != version:
        raise UpgradeError('Signed upstream package does not match the installed ' + module + ' version')
    return contents, {'kind': 'reviewed-upstream-and-overlays', 'archive_sha256': TRUST.digest(body),
                      'signed_archive_sha256': TRUST.digest(signed), 'upstream_signer': UPSTREAM_SIGNER}


def menu_overlay(body):
    needles = [b"\telse if ($a == 'other')\n\t\treturn " + value + b';\n' for value in (b'1', b'true')]
    found = [needle for needle in needles if body.count(needle) == 1]
    if len(found) != 1 or b'SLS Mass Notifications menu placement:' in body:
        raise UpgradeError('Framework menu layout needs review; no menu bytes were changed')
    return body.replace(found[0], MENU + found[0], 1)


def deployed_framework(name):
    return not (name.startswith(('amp_conf/astetc/', 'amp_conf/moh/', 'upgrades/', 'installlib/'))
                or name in ('install', 'start_asterisk')
                or name.startswith('amp_conf/') and not name.startswith('amp_conf/htdocs/'))


def upstream_inventory(contents, source, module, version, candidate, previous, web):
    files = {}
    omitted = []
    for name, body in contents.items():
        if name == 'module.sig':
            continue
        if module == 'framework' and not deployed_framework(name):
            omitted.append(name)
            continue
        target = name[16:] if module == 'framework' and name.startswith('amp_conf/htdocs/') else 'admin/modules/' + module + '/' + name
        files[name] = {'sha256': TRUST.digest(body), 'target': target}
    data = {'schema': 1, 'module': module, 'version': version, 'source': {**source, 'intentionally_unmapped_upstream_paths': sorted(omitted)},
            'files': files, 'uninstall': {'remove': [], 'replace': {}}}
    prior = copy.deepcopy(data)
    if module == 'dashboard':
        for name in WIDGETS:
            target = 'admin/modules/dashboard/' + name
            files[name] = {'sha256': TRUST.digest(candidate['dashboard/' + name]), 'target': target}
            if previous and 'dashboard/' + name in previous:
                prior['files'][name] = {'sha256': TRUST.digest(previous['dashboard/' + name]), 'target': target}
        data['uninstall']['remove'] = list(WIDGETS)
    else:
        body = contents[MENU_NAME]
        files[MENU_NAME]['sha256'] = TRUST.digest(menu_overlay(body))
        data['uninstall']['replace'][MENU_NAME] = TRUST.digest(body)
        # Both a plain asset and FreePBX's single supported Core alias are valid;
        # choosing the alias still requires its target's upstream GIF digest.
        spinner = web / files[SPINNER]['target']
        if spinner.is_symlink():
            files[SPINNER]['link_target'] = 'admin/modules/core/images/spinner.gif'
    return data, prior


def authenticated_generation(root):
    data, generation = TRUST.load(root, 'slsmassnotifyserver')
    if data['source']['kind'] != 'publisher-ed25519':
        raise UpgradeError('Previous SLS generation lacks publisher authentication')
    publisher = helper('sls_release_trust')
    manifest = publisher.authenticate_manifest(TRUST.read(generation / 'release-manifest.json', 16384, protected=True),
                                               TRUST.read(generation / 'release-manifest.sig', 64, protected=True),
                                               anchor=TRUST.PUBLIC_KEY, directory=root)
    if manifest.get('package_sha256') != data['source']['archive_sha256'] or manifest.get('version') != data['version']:
        raise UpgradeError('Previous signed release metadata does not match its protected generation')
    source = generation / 'module'
    if TRUST.inventory_tree(source, 'slsmassnotifyserver') != set(data['files']):
        raise UpgradeError('Authenticated source generation has missing or unexpected files')
    contents = {}
    for name, entry in data['files'].items():
        body = TRUST.read(source / name, TRUST.MAX_ARCHIVE, protected=True)
        if TRUST.digest(body) != entry['sha256']:
            raise UpgradeError('Authenticated source generation changed: ' + name)
        contents[name] = body
    return data, contents


def runtime_files(contents):
    result = {}
    for name, body in contents.items():
        if name == 'bin/sign_sls_mass_notify_local_sig.sh':
            result['/usr/local/sbin/sign_sls_mass_notify_local_sig.sh'] = TRUST.digest(body)
        elif name == 'bin/slsconsole':
            result['/usr/local/bin/slsconsole'] = TRUST.digest(body)
        elif name.startswith('bin/sls_mass_notify/'):
            result[RUNTIME + '/' + name[len('bin/sls_mass_notify/'):]] = TRUST.digest(body)
        elif name.startswith('bin/') and '/' not in name[4:]:
            result[RUNTIME + '/' + name[4:]] = TRUST.digest(body)
    return result


def runtime_inventory(root):
    """Inspect root code separately from the existing protected Piper venv."""
    found = set()
    parent = TRUST.directory(root, protected=True)
    count = 0
    def visit(descriptor, prefix=''):
        nonlocal count
        for name in os.listdir(descriptor):
            count += 1
            if count > TRUST.MAX_FILES:
                raise UpgradeError('Root runtime exceeds its inspection limit')
            relative = TRUST.relative(prefix + name)
            info = os.stat(name, dir_fd=descriptor, follow_symlinks=False)
            if info.st_uid != 0 or info.st_mode & 0o022:
                raise UpgradeError('Unprotected previous root runtime entry: ' + relative)
            if stat.S_ISDIR(info.st_mode):
                child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=descriptor)
                try:
                    if (os.fstat(child).st_dev, os.fstat(child).st_ino) != (info.st_dev, info.st_ino):
                        raise UpgradeError('Root runtime directory changed during inspection')
                    if relative != 'piper': visit(child, relative + '/')
                finally: os.close(child)
            elif not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                raise UpgradeError('Unsafe link or special previous root runtime file: ' + relative)
            else:
                found.add(relative)
    try: visit(parent)
    finally: os.close(parent)
    return found


def previous_root_approval(data, contents, *, prefix=Path('/'), cron=None):
    """Authorize restoration of exact authenticated prior root code, never hooks."""
    expected = runtime_files(contents)
    runtime = prefix / RUNTIME[1:]
    actual = runtime_inventory(runtime)
    own = {name[len(RUNTIME) + 1:] for name in expected if name.startswith(RUNTIME + '/')}
    # The root-owned dependency tree has separate validation; no other
    # executable leftovers may become part of a recovered root environment.
    if actual != own:
        raise UpgradeError('Previous root runtime contains missing or unapproved files; review before upgrading')
    for name, digest in expected.items():
        target = prefix / name[1:]
        descriptor = TRUST.directory(target.parent, protected=True)
        try:
            info = os.stat(target.name, dir_fd=descriptor, follow_symlinks=False)
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != 0 or info.st_mode & 0o022:
                raise UpgradeError('Previous root runtime is unsafe: ' + name)
        finally:
            os.close(descriptor)
        if TRUST.digest(TRUST.read(target, TRUST.MAX_ARCHIVE, protected=True)) != digest:
            raise UpgradeError('Previous root runtime differs from its signed release: ' + name)
    if cron is None:
        result = subprocess.run(['/usr/bin/crontab', '-u', 'root', '-l'], capture_output=True, timeout=15,
                                env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C'})
        if result.returncode and not (result.returncode == 1 and result.stderr.strip() == b'no crontab for root'):
            raise UpgradeError('Unable to inspect previous root maintenance schedule')
        cron = result.stdout
    if len(cron) > 1024 * 1024:
        raise UpgradeError('Root schedule exceeds its inspection limit')
    recovery = helper('sls_installer_recovery')
    jobs = [line for line in cron.splitlines() if any(marker.encode() in line for marker in recovery.CRON_MARKERS)]
    if any(not ROOT_JOB.fullmatch(line) for line in jobs) or len(jobs) != len(set(jobs)):
        raise UpgradeError('Previous SLS root schedule needs independent review; no maintenance was disabled')
    return {'schema': 1, 'source': 'publisher-verified-previous-release', 'version': data['version'],
            'archive_sha256': data['source']['archive_sha256'], 'files': expected,
            'root_jobs': [line.decode('ascii') for line in jobs]}


def restored_web_inventory(data, old, web):
    """Select the already verified prior/candidate/clean transition, never extras."""
    result = copy.deepcopy(data)
    for name, entry in list(result['files'].items()):
        target = web / entry['target']
        if data['module'] == 'dashboard' and name in WIDGETS and not target.exists():
            del result['files'][name]
            continue
        observed = TRUST.digest(TRUST.read(web / entry.get('link_target', entry['target']), TRUST.MAX_ARCHIVE))
        allowed = {entry['sha256']}
        if name in WIDGETS or data['module'] == 'framework' and name == MENU_NAME:
            allowed.add(old.get('files', {}).get(name, {}).get('sha256'))
            allowed.add(data.get('uninstall', {}).get('replace', {}).get(name))
        if observed not in allowed:
            raise UpgradeError('Stock file changed after approved preflight: ' + name)
        entry['sha256'] = observed
    result.pop('uninstall', None)
    return result


def verify_previous(workspace):
    path = workspace / 'previous-web-approval.json'
    approved = json.loads(TRUST.read(path, TRUST.MAX_MANIFEST * 4, protected=True))
    store = workspace / 'rollback-registry'
    for module in ('slsmassnotifyserver', 'dashboard', 'framework'):
        data = approved.get(module)
        if data is None:
            if (WEB / 'admin/modules' / module).exists():
                raise UpgradeError('Unexpected recovered module: ' + module)
            continue
        body = (json.dumps(data, sort_keys=True, separators=(',', ':')) + '\n').encode()
        TRUST.parse_manifest(body)
        # This private registry consumes pre-install approvals only. Publisher
        # provenance is already authenticated; no executable generation is used.
        TRUST.store_manifest(store, data, body)
        TRUST.check(store, module, WEB)
    print(json.dumps({'ok': True, 'previous_web_approval_verified': True}))


def compare_stock(data, prior, web, candidate):
    """Return all mismatches, keeping observed hashes out of expected inventories."""
    module = data['module']
    actual = TRUST.inventory_tree(web / 'admin/modules' / module, module)
    widgets = set(WIDGETS) if module == 'dashboard' else set()
    expected = {name for name, entry in data['files'].items() if entry['target'].startswith('admin/modules/' + module + '/')}
    issues = [{'module': module, 'path': name, 'reason': 'unexpected file'} for name in sorted(actual - expected)]
    issues += [{'module': module, 'path': name, 'reason': 'missing file'} for name in sorted(expected - actual - widgets)]
    total = 0
    for name, entry in data['files'].items():
        if name in widgets and name not in actual:
            continue
        try:
            target = web / entry['target']
            if 'link_target' in entry:
                before = target.lstat()
                if not stat.S_ISLNK(before.st_mode) or before.st_nlink != 1 or os.readlink(target) != str(web / entry['link_target']):
                    raise UpgradeError('unsupported asset link')
                body = TRUST.read(web / entry['link_target'], TRUST.MAX_ARCHIVE)
                after = target.lstat()
                if (before.st_dev, before.st_ino, before.st_ctime_ns) != (after.st_dev, after.st_ino, after.st_ctime_ns):
                    raise UpgradeError('asset link changed during inspection')
            else:
                body = TRUST.read(target, TRUST.MAX_ARCHIVE)
            total += len(body)
            if total > 1024 * 1024 * 1024:
                raise UpgradeError('stock inspection exceeds 1 GiB')
            allowed = {entry['sha256']}
            if name in widgets or module == 'framework' and name == MENU_NAME:
                old = prior.get('files', {}).get(name, {}).get('sha256')
                if old:
                    allowed.add(old)
                clean = data.get('uninstall', {}).get('replace', {}).get(name)
                if clean:
                    allowed.add(clean)
            if TRUST.digest(body) not in allowed:
                issues.append({'module': module, 'path': name, 'reason': 'bytes differ from signed/reviewed source'})
        except (OSError, UpgradeError, TRUST.TrustError) as error:
            issues.append({'module': module, 'path': name, 'reason': 'unsafe, missing or changing file'})
    for name in widgets:
        if data['files'].get(name, {}).get('sha256') != TRUST.digest(candidate['dashboard/' + name]):
            raise UpgradeError('Candidate Dashboard overlay does not match its approval')
    return issues


def prepare(args):
    workspace = args.workspace
    TRUST.prepare_root(workspace)
    registry = workspace / 'registry'
    TRUST.enroll_sls(registry, args.archive, args.manifest, args.signature)
    candidate_data, candidate = authenticated_generation(registry)
    previous_data = previous = None
    sls_path = WEB / 'admin/modules/slsmassnotifyserver'
    if sls_path.exists() or sls_path.is_symlink():
        version = identity(WEB, 'slsmassnotifyserver')
        pointer = args.trust_root / 'slsmassnotifyserver.active.json'
        if pointer.exists() or pointer.is_symlink():
            previous_data, previous = authenticated_generation(args.trust_root)
            if previous_data['version'] != version:
                raise UpgradeError('Installed SLS version differs from protected prior approval; retain recovery files and review before retrying')
        else:
            release = workspace / 'previous-release'
            release.mkdir(mode=0o700)
            paths = [os.environ.get('SLS_MASS_NOTIFY_PREVIOUS_' + key, '') for key in ('TGZ', 'MANIFEST', 'MANIFEST_SIGNATURE')]
            names = ['slsmassnotifyserver-' + version + '.tgz', 'release-manifest.json', 'release-manifest.sig']
            if any(paths) and not all(paths):
                raise UpgradeError('Supply all three previous SLS package, manifest and signature paths')
            for source, name, limit in zip(paths, names, (TRUST.MAX_ARCHIVE, 16384, 64)):
                if source:
                    TRUST.atomic(release / name, TRUST.read(Path(source), limit, protected=True))
                else:
                    download('https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-' + version + '/' + name, release / name, limit)
            prior_registry = workspace / 'previous-registry'
            TRUST.enroll_sls(prior_registry, release / names[0], release / names[1], release / names[2])
            previous_data, previous = authenticated_generation(prior_registry)
            if previous_data['version'] != version:
                raise UpgradeError('Previous signed SLS package has the wrong installed version')
        actual = TRUST.inventory_tree(sls_path, 'slsmassnotifyserver')
        if actual != set(previous_data['files']):
            raise UpgradeError('Installed previous SLS file list differs from its signed release; review local changes before upgrading')
        for name, entry in previous_data['files'].items():
            if TRUST.digest(TRUST.read(sls_path / name, TRUST.MAX_ARCHIVE)) != entry['sha256']:
                raise UpgradeError('Installed previous SLS file differs from its signed release: ' + name + '. Review the change before upgrading; no maintenance was disabled.')
    approved = {}
    prior = {}
    issues = []
    for module in ('dashboard', 'framework'):
        version = identity(WEB, module)
        path = os.environ.get('SLS_MASS_NOTIFY_' + module.upper() + '_INVENTORY', '')
        digest = os.environ.get('SLS_MASS_NOTIFY_' + module.upper() + '_INVENTORY_SHA256', '')
        pointer = args.trust_root / (module + '.active.json')
        if path or digest:
            body = TRUST.read(Path(path), TRUST.MAX_MANIFEST, protected=True)
            if not TRUST.DIGEST.fullmatch(digest) or TRUST.digest(body) != digest:
                raise UpgradeError('Reviewed ' + module + ' inventory digest mismatch')
            data = TRUST.parse_manifest(body)
            old = TRUST.load(args.trust_root, module)[0] if pointer.exists() or pointer.is_symlink() else copy.deepcopy(data)
        elif pointer.exists() or pointer.is_symlink():
            data = copy.deepcopy(TRUST.load(args.trust_root, module)[0])
            old = copy.deepcopy(data)
        else:
            package = workspace / (module + '-' + version + '.tgz.gpg')
            offline = os.environ.get('SLS_MASS_NOTIFY_UPSTREAM_PACKAGES', '')
            if offline:
                TRUST.atomic(package, TRUST.read(Path(offline) / package.name, TRUST.MAX_ARCHIVE, protected=True))
            else:
                download('https://mirror.freepbx.org/modules/packages/' + module + '/' + package.name, package, TRUST.MAX_ARCHIVE)
            contents, source = upstream_contents(package, module, version, workspace)
            data, old = upstream_inventory(contents, source, module, version, candidate, previous, WEB)
        if data['module'] != module or data['version'] != version or data['source']['kind'] != 'reviewed-upstream-and-overlays':
            raise UpgradeError('Approved inventory must match the installed ' + module + ' version')
        if module == 'dashboard':
            for name in WIDGETS:
                target = 'admin/modules/dashboard/' + name
                if previous and 'dashboard/' + name in previous:
                    old['files'][name] = {'sha256': TRUST.digest(previous['dashboard/' + name]), 'target': target}
                data['files'][name] = {'sha256': TRUST.digest(candidate['dashboard/' + name]), 'target': target}
            data['uninstall'] = {'remove': list(WIDGETS), 'replace': {}}
        TRUST.parse_manifest(json.dumps(data).encode())
        approved[module], prior[module] = data, old
        issues += compare_stock(data, old, WEB, candidate)
    if issues:
        report = workspace / 'review-required.json'
        write_json(report, {'schema': 1, 'approved': False, 'differences': issues})
        raise UpgradeError('Unreviewed PBX differences: ' + str(len(issues)) + '. Review ' + str(report) + ' and supply independently reviewed inventories; local files and maintenance were preserved. See docs/privileged-trust.md.')
    write_json(workspace / 'approvals.json', {'prior': prior, 'approved': approved})
    write_json(workspace / 'previous-web-approval.json',
               {'slsmassnotifyserver': previous_data,
                **{module: restored_web_inventory(approved[module], prior[module], WEB) for module in ('dashboard', 'framework')}})
    for module in ('dashboard', 'framework'):
        path = workspace / (module + '.json')
        digest = write_json(path, approved[module])
        TRUST.enroll_reviewed(registry, path, digest)
    if previous_data:
        write_json(workspace / 'previous-root-approval.json', previous_root_approval(previous_data, previous))
    else:
        write_json(workspace / 'previous-root-approval.json', {'schema': 1, 'source': 'fresh-install', 'files': {}, 'root_jobs': []})
    print(json.dumps({'ok': True, 'review_directory': str(workspace), 'previous_version': previous_data['version'] if previous_data else None,
                      'candidate_version': candidate_data['version'], 'live_trust_changed': False}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--workspace', type=Path, required=True)
    parser.add_argument('--trust-root', type=Path, default=Path('/var/lib/sls-mass-notify-trust'))
    parser.add_argument('--archive', type=Path)
    parser.add_argument('--manifest', type=Path)
    parser.add_argument('--signature', type=Path)
    parser.add_argument('--verify-previous', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise UpgradeError('Upgrade trust preparation requires root')
    TRUST.read(HERE / 'sls_upgrade_trust.py', TRUST.MAX_ARCHIVE, protected=True)
    if args.verify_previous:
        verify_previous(args.workspace)
    else:
        if args.archive is None or args.manifest is None or args.signature is None:
            parser.error('Signed candidate archive, manifest and signature are required')
        prepare(args)


if __name__ == '__main__':
    try:
        main()
    except (UpgradeError, TRUST.TrustError, OSError, ValueError, KeyError, ET.ParseError, tarfile.TarError, subprocess.SubprocessError) as error:
        print('SLS upgrade preflight: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
