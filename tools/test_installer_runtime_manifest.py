#!/usr/bin/python3
"""Ensure newly packaged workers cannot be omitted from install/repair lists."""
import ast
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
module = ROOT / 'slsmassnotifyserver'
installer = (ROOT / 'tools/install_release.sh').read_text()
maintenance = (module / 'bin/sls_mass_notify_maintenance.sh').read_text()
source = (module / 'Slsmassnotifyserver.class.php').read_text()
workers = {path.name for path in (module / 'bin').glob('sls_mass_notify*') if path.is_file()}
helpers = {path.name for path in (module / 'bin/sls_mass_notify').glob('*.py')}
for function in ('runtime_install_postconditions_available', 'verify_installed_payload_parity'):
    block = installer.split(function + '() {', 1)[1].split("\nPY\n", 1)[0]
    declared = set(ast.literal_eval(re.search(r'for name in (\(.*?\)):', block, re.S)[1]))
    assert declared == workers, (function, 'worker inventory mismatch', declared ^ workers)
permissions = source.split('private function secureExecutableRuntimeTree()', 1)[1]
executables = ast.literal_eval(re.search(r'executables = (\{.*?\})', permissions, re.S)[1])
assert workers | helpers <= executables, ('module permission repair omits', (workers | helpers) - executables)
for worker in workers:
    if worker.endswith('.php'):
        for text in (installer, maintenance):
            assert 'child_relative == "' + worker + '"' in text, ('PHP worker loses execute permission', worker)
runtime_check = re.search(r'runtime_python_files=\((.*?)\n  \)', installer, re.S)[1]
for helper in helpers:
    assert '/usr/local/bin/sls_mass_notify/' + helper + '\n' in runtime_check + '\n', ('missing runtime verification', helper)
print('Every packaged runtime worker/helper is covered by installer manifests and permission repair.')
