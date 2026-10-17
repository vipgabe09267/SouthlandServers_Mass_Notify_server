#!/usr/bin/python3
"""Ensure newly packaged workers cannot be omitted from install/repair lists."""
import ast
import os
from pathlib import Path
import re
import stat
import subprocess

ROOT = Path(__file__).resolve().parents[1]


def require(condition: bool, message: object) -> None:
    if not condition:
        raise RuntimeError(message)


def source_text(path: Path) -> str:
    before = path.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_size <= 8 * 1024 * 1024,
            f'unsafe candidate source: {path}')
    descriptor = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        opened = os.fstat(descriptor)
        require(stat.S_ISREG(opened.st_mode) and opened.st_nlink == 1 and opened.st_size <= 8 * 1024 * 1024,
                f'unsafe candidate source: {path}')
        require((before.st_dev, before.st_ino, before.st_mode, before.st_nlink, before.st_size,
                 before.st_mtime_ns, before.st_ctime_ns) ==
                (opened.st_dev, opened.st_ino, opened.st_mode, opened.st_nlink, opened.st_size,
                 opened.st_mtime_ns, opened.st_ctime_ns), f'candidate source changed before open: {path}')
        chunks = []
        remaining = opened.st_size
        while remaining:
            chunk = os.read(descriptor, min(remaining, 1024 * 1024))
            require(chunk != b'', f'candidate source ended early: {path}')
            chunks.append(chunk)
            remaining -= len(chunk)
        require(os.read(descriptor, 1) == b'', f'candidate source grew while reading: {path}')
        after = os.fstat(descriptor)
        current = path.lstat()
        require((opened.st_dev, opened.st_ino, opened.st_mode, opened.st_nlink, opened.st_size,
                 opened.st_mtime_ns, opened.st_ctime_ns) ==
                (after.st_dev, after.st_ino, after.st_mode, after.st_nlink, after.st_size,
                 after.st_mtime_ns, after.st_ctime_ns) ==
                (current.st_dev, current.st_ino, current.st_mode, current.st_nlink, current.st_size,
                 current.st_mtime_ns, current.st_ctime_ns), f'candidate source changed while reading: {path}')
        return b''.join(chunks).decode('utf-8')
    finally:
        os.close(descriptor)


def regular_matches(directory: Path, pattern: str, allowed_directories: frozenset[str] = frozenset()) -> list[Path]:
    matches = []
    for path in sorted(directory.glob(pattern)):
        info = path.lstat()
        if stat.S_ISDIR(info.st_mode):
            require(path.name in allowed_directories, f'unexpected candidate runtime directory: {path}')
            continue
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1,
                f'unsafe candidate runtime entry: {path}')
        matches.append(path)
    return matches


module = ROOT / 'slsmassnotifyserver'
installer = source_text(ROOT / 'tools/install_release.sh')
maintenance = source_text(module / 'bin/sls_mass_notify_maintenance.sh')
source = source_text(module / 'Slsmassnotifyserver.class.php')


def shell_python_block(contents: str, marker: str) -> str:
    start = marker + '() {'
    require(contents.count(start) == 1, f'missing or ambiguous installer function: {marker}')
    remainder = contents.split(start, 1)[1]
    require('\nPY\n' in remainder, f'unterminated installer Python block: {marker}')
    return remainder.split('\nPY\n', 1)[0]


parity = shell_python_block(installer, 'verify_installed_payload_parity')
require('(module / "portal", pathlib.Path("/var/www/html/mass-notify"), "operator portal")' in parity,
        'installed parity verification omits the operator portal tree')
workers = {path.name for path in regular_matches(module / 'bin', 'sls_mass_notify*', frozenset({'sls_mass_notify'}))}
helpers = {path.name for path in regular_matches(module / 'bin/sls_mass_notify', '*.py')}
runtime_python = helpers | {path.name for path in regular_matches(module / 'bin', '*.py')}
require(bool(workers) and bool(helpers), 'candidate worker or helper inventory is empty')
for function in ('runtime_install_postconditions_available', 'verify_installed_payload_parity'):
    block = shell_python_block(installer, function)
    matches = re.findall(r'for name in (\(.*?\)):', block, re.S)
    require(len(matches) == 1, f'missing or ambiguous worker inventory: {function}')
    declared_values = ast.literal_eval(matches[0])
    require(isinstance(declared_values, tuple) and len(declared_values) == len(set(declared_values)),
            f'duplicate or malformed worker inventory: {function}')
    declared = set(declared_values)
    require(declared == workers, (function, 'worker inventory mismatch', declared ^ workers))
permission_marker = 'private function secureExecutableRuntimeTree()'
require(source.count(permission_marker) == 1, 'module permission repair function is missing or ambiguous')
permissions = source.split(permission_marker, 1)[1]
matches = re.findall(r'executables = (\{.*?\})', permissions, re.S)
require(len(matches) == 1, 'module executable inventory is missing or ambiguous')
executables = ast.literal_eval(matches[0])
require(workers | helpers <= executables, ('module permission repair omits', (workers | helpers) - executables))
# Protected promotion now owns executable modes; mutable PHP helpers no
# longer recursively repair root runtime permissions.
protected = source_text(module / 'bin/sls_mass_notify/sls_privileged_install.py')
for mapping in (
        "result.append((name, RUNTIME + '/' + name[len('bin/sls_mass_notify/'):], 0o755, False, False))",
        "result.append((name, RUNTIME + '/' + name[4:], 0o755, False, False))"):
    require(protected.count(mapping) == 1,
            'protected promotion does not own the expected runtime executable mapping')
require('protected_install_phase prepare --apply' in installer,
        'installer omits protected preparation')
require('"$PRIVILEGED_HELPER" "$phase" --apply' in maintenance,
        'maintenance helper omits protected apply mode')
matches = re.findall(r'runtime_python_files=\((.*?)\n  \)', installer, re.S)
require(len(matches) == 1, 'installer runtime verification inventory is missing or ambiguous')
runtime_check = matches[0]
runtime_entries = [line.strip() for line in runtime_check.splitlines() if line.strip()]
require(all(re.fullmatch(r'/usr/local/bin/sls_mass_notify/[A-Za-z0-9_.-]+\.py', entry)
            for entry in runtime_entries), 'installer runtime verification inventory contains malformed entries')
require(len(runtime_entries) == len(set(runtime_entries)),
        'installer runtime verification inventory contains duplicates')
expected_runtime_entries = {'/usr/local/bin/sls_mass_notify/' + helper for helper in runtime_python}
require(set(runtime_entries) == expected_runtime_entries,
        ('installer runtime verification mismatch', set(runtime_entries) ^ expected_runtime_entries))
for marker in ('$requiredFiles = [', '$parityFiles = ['):
    require(source.count(marker) == 1, f'module runtime list is missing or ambiguous: {marker}')
    require('\n\t\t];' in source.split(marker, 1)[1], f'module runtime list is unterminated: {marker}')
restore_required = source.split('$requiredFiles = [', 1)[1].split('\n\t\t];', 1)[0]
restore_parity = source.split('$parityFiles = [', 1)[1].split('\n\t\t];', 1)[0]
for helper in regular_matches(module / 'bin/sls_mass_notify', '*.php'):
    require("self::RUNTIME_DIR . '/" + helper.name + "' => false" in restore_required,
            ('post-restore verification omits PHP helper', helper.name))
    require("__DIR__ . '/bin/sls_mass_notify/" + helper.name + "' => self::RUNTIME_DIR . '/" + helper.name + "'" in restore_parity,
            ('post-restore parity omits PHP helper', helper.name))
subprocess.run(
    ['/usr/bin/php', str(ROOT / 'tools/test_runtime_file_install.php')],
    check=True,
    cwd=ROOT,
    env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'},
    timeout=120,
)
print('Every packaged runtime worker/helper is copied and covered by installer manifests and permission repair.')
