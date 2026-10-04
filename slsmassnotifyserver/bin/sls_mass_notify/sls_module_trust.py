#!/usr/bin/python3
"""Root-only artifact generations and exact, explicitly approved signing inventories.

No command learns trust from the installed web tree. Review enrollment requires
an independently supplied digest; publisher enrollment verifies Ed25519 itself.
This module never bootstraps PHP or executes a FreePBX module.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import io
import json
import os
import pwd
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import xml.etree.ElementTree as ET

ROOT = Path('/var/lib/sls-mass-notify-trust')
PUBLIC_KEY = b'-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAFMDOgOaBGcaI8d+v0w/NX4RbwGlBsoktNc2V2F8UkUQ=\n-----END PUBLIC KEY-----\n'
MAX_MANIFEST = 8 * 1024 * 1024
MAX_ARCHIVE = 64 * 1024 * 1024
MAX_FILES = 20000
DIGEST = re.compile(r'[a-f0-9]{64}\Z')
MODULE = re.compile(r'[a-z][a-z0-9_]{0,63}\Z')


class TrustError(RuntimeError):
    pass


def relative(value: str) -> str:
    if (not isinstance(value, str) or not value or len(value) > 240
            or any(ord(c) < 32 or ord(c) == 127 for c in value)
            or not re.fullmatch(r'[A-Za-z0-9_./@+ -]+', value)):
        raise TrustError('unrepresentable inventory path')
    p = PurePosixPath(value)
    if p.is_absolute() or str(p) != value or any(x in ('', '.', '..') for x in p.parts):
        raise TrustError('unsafe inventory path')
    return value


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def directory(path: Path, *, protected=False) -> int:
    """Use descriptor-relative lookup; reject every symlink in the path."""
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise TrustError('absolute canonical directory required')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        for part in path.parts[1:]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
            os.close(fd)
            fd = child
            info = os.fstat(fd)
            # A root-owned sticky parent such as /tmp is safe for a root-owned
            # private generation. The generation itself must not be writable.
            if protected and (info.st_uid != 0 or (info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX)):
                raise TrustError('trust path has an unprotected parent')
        result, fd = fd, -1
        return result
    finally:
        if fd >= 0:
            os.close(fd)


def read(path: Path, limit: int, *, protected=False) -> bytes:
    parent = directory(path.parent, protected=protected)
    fd = -1
    try:
        fd = os.open(path.name, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        before = os.fstat(fd)
        current = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_size > limit
                or (before.st_dev, before.st_ino) != (current.st_dev, current.st_ino)
                or protected and (before.st_uid != 0 or before.st_mode & 0o022)):
            raise TrustError('unsafe or oversized trust input: ' + path.name)
        with os.fdopen(fd, 'rb') as stream:
            fd = -1
            body = stream.read(limit + 1)
            after = os.fstat(stream.fileno())
        current = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
        if (len(body) > limit or (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
                != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
                or (current.st_dev, current.st_ino, current.st_nlink) != (after.st_dev, after.st_ino, 1)):
            raise TrustError('trust input changed while reading: ' + path.name)
        return body
    finally:
        if fd >= 0:
            os.close(fd)
        os.close(parent)


def atomic(path: Path, body: bytes) -> None:
    parent = directory(path.parent, protected=True)
    name = '.new-' + os.urandom(12).hex()
    fd = -1
    try:
        fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
        with os.fdopen(fd, 'wb') as stream:
            fd = -1
            stream.write(body)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path.name, src_dir_fd=parent, dst_dir_fd=parent)
        os.fsync(parent)
    finally:
        if fd >= 0:
            os.close(fd)
        try:
            os.unlink(name, dir_fd=parent)
        except FileNotFoundError:
            pass
        os.close(parent)


def prepare_root(root: Path) -> None:
    parent = directory(root.parent, protected=True)
    try:
        try:
            os.mkdir(root.name, 0o700, dir_fd=parent)
        except FileExistsError:
            pass
    finally:
        os.close(parent)
    fd = directory(root, protected=True)
    try:
        if os.fstat(fd).st_mode & 0o077:
            raise TrustError('trust store must be root-only mode 0700')
    finally:
        os.close(fd)


def private_generation(path: Path) -> None:
    fd = directory(path, protected=True)
    try:
        if os.fstat(fd).st_mode & 0o077:
            raise TrustError('approved generation permissions must remain root-only')
    finally:
        os.close(fd)


def parse_manifest(body: bytes) -> dict:
    def unique(pairs):
        out = {}
        for key, value in pairs:
            if key in out:
                raise TrustError('duplicate manifest key')
            out[key] = value
        return out
    data = json.loads(body, object_pairs_hook=unique)
    if (not isinstance(data, dict) or data.get('schema') != 1
            or not isinstance(data.get('module'), str) or not MODULE.fullmatch(data['module'])
            or not isinstance(data.get('version'), str) or not re.fullmatch(r'[A-Za-z0-9_.-]{1,64}', data['version'])
            or not isinstance(data.get('files'), dict) or not 1 <= len(data['files']) <= MAX_FILES):
        raise TrustError('invalid approved inventory schema')
    source = data.get('source', {})
    if (not isinstance(source, dict) or source.get('kind') not in ('publisher-ed25519', 'reviewed-upstream-and-overlays')
            or not isinstance(source.get('archive_sha256'), str) or not DIGEST.fullmatch(source['archive_sha256'])):
        raise TrustError('approved inventory lacks authenticated artifact provenance')
    targets = set()
    for name, entry in data['files'].items():
        relative(name)
        if (not isinstance(entry, dict) or set(entry) not in ({'sha256', 'target'}, {'sha256', 'target', 'link_target'})
                or not isinstance(entry['sha256'], str) or not DIGEST.fullmatch(entry['sha256'])):
            raise TrustError('invalid expected file digest')
        relative(entry['target'])
        if entry['target'] in targets:
            raise TrustError('duplicate installed destination')
        targets.add(entry['target'])
        # The signing helper reads only files under a configured web root.
        # General root path inventories must use a separate reviewed operation.
        prefix = 'admin/modules/' + data['module'] + '/'
        if not entry['target'].startswith(prefix):
            if data['module'] != 'framework' or not name.startswith('amp_conf/htdocs/') or entry['target'] != name[16:]:
                raise TrustError('inventory destination escapes its module')
        elif name != entry['target'][len(prefix):]:
            raise TrustError('module signature name does not match destination')
        if 'link_target' in entry and (data['module'] != 'framework'
                or name != 'amp_conf/htdocs/admin/images/spinner.gif'
                or entry['target'] != 'admin/images/spinner.gif'
                or entry['link_target'] != 'admin/modules/core/images/spinner.gif'):
            raise TrustError('only the exact reviewed Framework/Core spinner asset link is supported')
    uninstall = data.get('uninstall')
    if uninstall is not None:
        allowed_remove = {'sections/SlsMassNotifyAnnouncement.class.php', 'views/sections/sls-mass-notify-announcement.php'} if data['module'] == 'dashboard' else set()
        allowed_replace = {'amp_conf/htdocs/admin/views/menu_items.php'} if data['module'] == 'framework' else set()
        if (not isinstance(uninstall, dict) or set(uninstall) != {'remove', 'replace'}
                or not isinstance(uninstall['remove'], list) or not isinstance(uninstall['replace'], dict)
                or any(not isinstance(name, str) or name not in allowed_remove or name not in data['files'] for name in uninstall['remove'])
                or any(name not in allowed_replace or name not in data['files'] or not isinstance(value, str) or not DIGEST.fullmatch(value) for name, value in uninstall['replace'].items())):
            raise TrustError('uninstall inventory may change only the exact approved SLS integration files')
    return data


def enroll_reviewed(root: Path, path: Path, expected: str) -> str:
    """Only an explicit, reviewed inventory hash authorizes local overlays.

    This is an administrative import, never called automatically on a live
    filesystem mismatch. An upstream version transition requires new review.
    """
    body = read(path, MAX_MANIFEST, protected=True)
    if not DIGEST.fullmatch(expected) or digest(body) != expected:
        raise TrustError('reviewed inventory hash mismatch; prior trust retained')
    data = parse_manifest(body)
    if data['source']['kind'] != 'reviewed-upstream-and-overlays':
        raise TrustError('publisher inventories require publisher artifact enrollment')
    return store_manifest(root, data, body)


def store_manifest(root: Path, data: dict, body: bytes) -> str:
    prepare_root(root)
    name = data['module'] + '-' + digest(body)
    generation = root / name
    try:
        generation.mkdir(mode=0o700)
    except FileExistsError:
        private_generation(generation)
        if read(generation / 'inventory.json', MAX_MANIFEST, protected=True) != body:
            raise TrustError('existing generation differs from approved inventory')
    else:
        atomic(generation / 'inventory.json', body)
    atomic(root / (data['module'] + '.active.json'), json.dumps({'generation': name, 'sha256': digest(body)}).encode())
    return name


def load(root: Path, module: str) -> tuple[dict, Path]:
    if not MODULE.fullmatch(module):
        raise TrustError('unsafe module name')
    fd = directory(root, protected=True)
    try:
        if os.fstat(fd).st_mode & 0o077:
            raise TrustError('trust store permissions must remain root-only')
    finally:
        os.close(fd)
    reference = json.loads(read(root / (module + '.active.json'), 1024, protected=True))
    if (not isinstance(reference, dict) or set(reference) != {'generation', 'sha256'}
            or not isinstance(reference['sha256'], str) or not DIGEST.fullmatch(reference['sha256'])
            or reference['generation'] != module + '-' + reference['sha256']):
        raise TrustError('invalid protected generation reference')
    generation = root / reference['generation']
    private_generation(generation)
    body = read(generation / 'inventory.json', MAX_MANIFEST, protected=True)
    if digest(body) != reference['sha256']:
        raise TrustError('protected approved inventory digest mismatch')
    data = parse_manifest(body)
    if data['module'] != module:
        raise TrustError('protected inventory module identity mismatch')
    return data, generation


def ignored_generated(module: str, path: str) -> bool:
    # Never accept PHP in a cache folder. Python bytecode can execute during
    # imports, so it is not treated as harmless generated signing data either.
    if path == 'module.sig':
        return True
    return module == 'dashboard' and bool(re.fullmatch(r'assets/less/cache/lessphp_[a-zA-Z0-9]+\.(?:css|list|lesscache)', path))


def inventory_tree(root: Path, module: str) -> set[str]:
    found = set()
    visited = 0
    fd = directory(root)
    def visit(parent, prefix=''):
        nonlocal visited
        for name in os.listdir(parent):
            visited += 1
            if visited > MAX_FILES * 2:
                raise TrustError('module tree exceeds inspection limit')
            rel = relative(prefix + name)
            info = os.stat(name, dir_fd=parent, follow_symlinks=False)
            if stat.S_ISDIR(info.st_mode):
                child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
                try:
                    if (os.fstat(child).st_dev, os.fstat(child).st_ino) != (info.st_dev, info.st_ino):
                        raise TrustError('module directory changed during inventory')
                    visit(child, rel + '/')
                finally:
                    os.close(child)
            elif stat.S_ISREG(info.st_mode) and info.st_nlink == 1:
                if not ignored_generated(module, rel):
                    found.add(rel)
                    if len(found) > MAX_FILES:
                        raise TrustError('module tree exceeds inventory limit')
            else:
                raise TrustError('unsafe link or special module file: ' + rel)
    try:
        visit(fd)
    finally:
        os.close(fd)
    return found


def check(root: Path, module: str, webroot: Path, *, uninstalled=False) -> dict:
    data, generation = load(root, module)
    if uninstalled:
        if 'uninstall' not in data:
            raise TrustError('reviewed post-uninstall inventory is unavailable; previous signature retained')
        data = json.loads(json.dumps(data))
        for name in data['uninstall']['remove']:
            del data['files'][name]
        for name, expected in data['uninstall']['replace'].items():
            data['files'][name]['sha256'] = expected
    module_path = webroot / 'admin/modules' / module
    actual = inventory_tree(module_path, module)
    expected = {name for name, entry in data['files'].items() if entry['target'].startswith('admin/modules/' + module + '/')}
    if actual != expected:
        extra, missing = sorted(actual - expected), sorted(expected - actual)
        raise TrustError('approved module inventory mismatch; unexpected=' + repr(extra[:8]) + ', missing=' + repr(missing[:8]))
    total = 0
    for name, entry in data['files'].items():
        if 'link_target' in entry:
            path = webroot / entry['target']
            parent = directory(path.parent)
            try:
                before = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
                expected = str(webroot / entry['link_target'])
                if (not stat.S_ISLNK(before.st_mode) or before.st_nlink != 1
                        or os.readlink(path.name, dir_fd=parent) != expected):
                    raise TrustError('reviewed asset link target changed')
                # Read the exact approved target through NOFOLLOW parents,
                # never follow the installed link as root.
                body = read(Path(expected), MAX_ARCHIVE)
                after = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
                if (before.st_dev, before.st_ino, before.st_mtime_ns, before.st_ctime_ns) != (after.st_dev, after.st_ino, after.st_mtime_ns, after.st_ctime_ns):
                    raise TrustError('reviewed asset link changed during inspection')
            finally:
                os.close(parent)
        else:
            body = read(webroot / entry['target'], MAX_ARCHIVE)
        total += len(body)
        if total > 1024 * 1024 * 1024:
            raise TrustError('module contents exceed inspection byte limit')
        if digest(body) != entry['sha256']:
            raise TrustError('file differs from approved inventory: ' + name)
    return data


def emit_hashes(root: Path, module: str, webroot: Path, *, uninstalled=False) -> str:
    data = check(root, module, webroot, uninstalled=uninstalled)
    return ''.join(name + ' = ' + data['files'][name]['sha256'] + '\n' for name in sorted(data['files']))


def signature_file(module: str, webroot: Path, action: str, path: Path | None, user: str) -> str:
    """Replace only module.sig using a pinned directory descriptor.

    Neither a signature symlink nor replacing a web-owned parent can redirect
    a privileged signature write. The caller owns transaction rollback.
    """
    if not MODULE.fullmatch(module) or action not in ('backup', 'publish', 'restore', 'remove'):
        raise TrustError('invalid signature operation')
    account = pwd.getpwnam(user)
    if account.pw_uid == 0:
        raise TrustError('signature owner must be unprivileged')
    module_path = webroot / 'admin/modules' / module
    parent = directory(module_path)
    temporary = None
    fd = -1
    try:
        if action == 'backup':
            try:
                fd = os.open('module.sig', os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
            except FileNotFoundError:
                return 'absent'
            info = os.fstat(fd)
            current = os.stat('module.sig', dir_fd=parent, follow_symlinks=False)
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > MAX_MANIFEST
                    or (info.st_dev, info.st_ino) != (current.st_dev, current.st_ino)):
                raise TrustError('existing module signature is unsafe')
            with os.fdopen(fd, 'rb') as stream:
                fd = -1
                body = stream.read(MAX_MANIFEST + 1)
                after = os.fstat(stream.fileno())
            if (len(body) > MAX_MANIFEST or (info.st_size, info.st_mtime_ns, info.st_ctime_ns)
                    != (after.st_size, after.st_mtime_ns, after.st_ctime_ns)):
                raise TrustError('existing signature changed during backup')
            atomic(path, body)
            return 'present'
        if action == 'remove':
            try:
                os.unlink('module.sig', dir_fd=parent)
                os.fsync(parent)
            except FileNotFoundError:
                pass
            return 'removed'
        body = read(path, MAX_MANIFEST, protected=True)
        if not body:
            raise TrustError('candidate signature is empty')
        temporary = '.module.sig.sls-new-' + os.urandom(12).hex()
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
        with os.fdopen(fd, 'wb') as stream:
            fd = -1
            stream.write(body)
            stream.flush()
            os.fchown(stream.fileno(), account.pw_uid, account.pw_gid)
            os.fchmod(stream.fileno(), 0o644)
            os.fsync(stream.fileno())
        os.replace(temporary, 'module.sig', src_dir_fd=parent, dst_dir_fd=parent)
        temporary = None
        os.fsync(parent)
        return 'published'
    finally:
        if fd >= 0:
            os.close(fd)
        if temporary is not None:
            try:
                os.unlink(temporary, dir_fd=parent)
            except FileNotFoundError:
                pass
        os.close(parent)


def enroll_sls(root: Path, archive: Path, manifest_path: Path, signature_path: Path) -> str:
    package = read(archive, MAX_ARCHIVE)
    manifest = read(manifest_path, 16384)
    signature = read(signature_path, 64)
    if len(signature) != 64:
        raise TrustError('publisher signature has an invalid size')
    # This helper is executed only from protected installed/bootstrap bytes.
    # Enrollment must use the same authority as the updater and installer;
    # otherwise a legitimate key transition would fail later during promotion.
    sys.dont_write_bytecode = True
    spec = importlib.util.spec_from_file_location('sls_publisher_enrollment', Path(__file__).with_name('sls_release_trust.py'))
    publisher = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(publisher)
    try:
        release = publisher.authenticate_manifest(manifest, signature, anchor=PUBLIC_KEY, directory=root)
    except (ValueError, OSError, subprocess.SubprocessError) as error:
        raise TrustError('publisher signature verification failed: ' + str(error)) from error
    if not isinstance(release, dict):
        raise TrustError('publisher manifest root must be an object')
    version = release.get('version', '')
    if (release.get('schema') != 1 or not isinstance(version, str) or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-beta)?', version)
            or release.get('tag') != 'slsmassnotifyserver-' + version
            or release.get('package') != 'slsmassnotifyserver-' + version + '.tgz'
            or release.get('package_sha256') != digest(package)):
        raise TrustError('publisher release identity or archive hash mismatch')
    contents = {}
    total = 0
    with tarfile.open(fileobj=io.BytesIO(package), mode='r:gz') as tar:
        members = tar.getmembers()
        if len(members) > 2000:
            raise TrustError('publisher archive has too many members')
        for member in members:
            name = member.name.rstrip('/') if member.isdir() else member.name
            relative(name)
            if name == 'slsmassnotifyserver' and member.isdir():
                continue
            if not name.startswith('slsmassnotifyserver/') or member.mode & 0o6000 or not (member.isdir() or member.isfile()):
                raise TrustError('unsafe publisher archive member')
            if not member.isfile():
                continue
            name = name.split('/', 1)[1]
            if name in contents or ignored_generated('slsmassnotifyserver', name):
                raise TrustError('unexpected generated or duplicate publisher archive file')
            total += member.size
            if total > 50 * 1024 * 1024:
                raise TrustError('publisher archive exceeds expanded size limit')
            contents[name] = tar.extractfile(member).read()
    module_xml = ET.fromstring(contents.get('module.xml', b''))
    if module_xml.findtext('rawname') != 'slsmassnotifyserver' or module_xml.findtext('version') != version:
        raise TrustError('publisher archive module identity mismatch')
    data = {'schema': 1, 'module': 'slsmassnotifyserver', 'version': version, 'source': {'kind': 'publisher-ed25519', 'archive_sha256': digest(package)}, 'files': {name: {'sha256': digest(body), 'target': 'admin/modules/slsmassnotifyserver/' + name} for name, body in contents.items()}}
    body = (json.dumps(data, sort_keys=True, separators=(',', ':')) + '\n').encode()
    parse_manifest(body)
    prepare_root(root)
    name = data['module'] + '-' + digest(body)
    generation = root / name
    if generation.exists():
        private_generation(generation)
        if read(generation / 'inventory.json', MAX_MANIFEST, protected=True) != body:
            raise TrustError('publisher generation already exists with unexpected inventory')
        for item, expected_body in contents.items():
            if read(generation / 'module' / item, MAX_ARCHIVE, protected=True) != expected_body:
                raise TrustError('publisher generation code differs from authenticated archive')
    else:
        staging = Path(tempfile.mkdtemp(prefix='.publisher-', dir=root))
        try:
            for item, item_body in contents.items():
                destination = staging / 'module' / item
                destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                atomic(destination, item_body)
            atomic(staging / 'inventory.json', body)
            atomic(staging / 'release-manifest.json', manifest)
            atomic(staging / 'release-manifest.sig', signature)
            os.rename(staging, generation)
        finally:
            if staging.exists():
                shutil.rmtree(staging)
    atomic(root / (data['module'] + '.active.json'), json.dumps({'generation': name, 'sha256': digest(body)}).encode())
    return name


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=ROOT)
    commands = parser.add_subparsers(dest='command', required=True)
    enrollment = commands.add_parser('enroll-reviewed')
    enrollment.add_argument('--inventory', type=Path, required=True)
    enrollment.add_argument('--sha256', required=True)
    publisher = commands.add_parser('enroll-sls')
    publisher.add_argument('--archive', type=Path, required=True)
    publisher.add_argument('--manifest', type=Path, required=True)
    publisher.add_argument('--signature', type=Path, required=True)
    signature = commands.add_parser('signature-file')
    signature.add_argument('--module', required=True)
    signature.add_argument('--web-root', type=Path, required=True)
    signature.add_argument('--web-user', required=True)
    signature.add_argument('--action', choices=('backup', 'publish', 'restore', 'remove'), required=True)
    signature.add_argument('--path', type=Path)
    for operation in ('check', 'hashes'):
        command = commands.add_parser(operation)
        command.add_argument('--module', required=True)
        command.add_argument('--web-root', type=Path, required=True)
        command.add_argument('--uninstalled', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise TrustError('module trust operations require root')
    if args.command == 'signature-file':
        if args.action != 'remove' and args.path is None:
            raise TrustError('signature operation requires a protected input/output path')
        print(signature_file(args.module, args.web_root, args.action, args.path, args.web_user))
    elif args.command == 'enroll-reviewed':
        print(enroll_reviewed(args.root, args.inventory, args.sha256))
    elif args.command == 'enroll-sls':
        print(enroll_sls(args.root, args.archive, args.manifest, args.signature))
    elif args.command == 'hashes':
        print(emit_hashes(args.root, args.module, args.web_root, uninstalled=args.uninstalled), end='')
    else:
        check(args.root, args.module, args.web_root, uninstalled=args.uninstalled)
        print('Approved module inventory matches.')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (TrustError, OSError, ValueError, KeyError, RecursionError, ET.ParseError, subprocess.SubprocessError, tarfile.TarError) as error:
        print('SLS module trust: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
