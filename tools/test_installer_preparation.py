#!/usr/bin/python3
"""Installer inspection and path-boundary regressions in disposable namespaces."""
import json
import os
import pwd
import re
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest

if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or any(
    os.environ.get('SLS_TEST_PARENT_' + label + '_NS') in
    (None, '', os.readlink('/proc/self/ns/' + kind))
    for label, kind in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')]
):
    raise SystemExit('Use the private fixture runner; no spool or permission changes were made.')

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT/'tools/install_release.sh').read_text()


class Preparation(unittest.TestCase):
    def test_inspection_never_runs_repairs_locks_freepbx_or_failure_writes(self):
        with tempfile.TemporaryDirectory(dir='/run') as name:
            root = Path(name)
            (root/'freepbx.conf').write_text('inspection sentinel')
            (root/'framework').mkdir(); (root/'framework/module.xml').write_text('<module/>')
            installer = root/'installer.sh'
            installer.write_text(SOURCE.replace('/etc/freepbx.conf', str(root/'freepbx.conf'))
                .replace('/var/www/html/admin/modules/framework', str(root/'framework')))
            harness = r'''
source "$1"
fixture="$2"; CONFIG_FILE="$fixture/config"; SLS_MASS_NOTIFY_INVENTORY_ONLY=1
bootstrap_resource_capacity() { printf '{"requirements":{}}'; }
select_install_temp_root() { INSTALL_TEMP_ROOT="$fixture"; }
download_tgz() { DOWNLOAD_DIR="$fixture/download"; mkdir "$DOWNLOAD_DIR"; printf 'download\n' >>"$fixture/trace"; }
verify_tgz() { printf 'verified\n' >>"$fixture/trace"; }
stage_module_directory() { STAGING_DIR="$fixture/stage"; mkdir "$STAGING_DIR"; printf 'staged\n' >>"$fixture/trace"; }
prepare_installer_bootstrap() { INSTALL_BOOTSTRAP_DIR="$fixture/bootstrap"; mkdir "$INSTALL_BOOTSTRAP_DIR"; printf 'bootstrap\n' >>"$fixture/trace"; }
prepare_authenticated_installer() { printf 'approved\n' >>"$fixture/trace"; UPGRADE_TRUST_DIR="$fixture/evidence"; mkdir "$UPGRADE_TRUST_DIR"; }
for forbidden in ensure_installer_log_prerequisites acquire_update_coordination acquire_maintenance_coordination require_freepbx fwconsole install_dependencies initialize_configuration_keyring acquire_worker_coordination acquire_settings_coordination record_install_failure commit_authenticated_installer protect_freepbx_chown_permissions repair_authenticated_legacy_permissions restore_previous_phone_collector; do
 eval "$forbidden() { printf 'forbidden' >\"\$fixture/forbidden\"; return 99; }"
done
main
'''
            result = subprocess.run(['bash','-c',harness,'fixture',str(installer),str(root)], capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertFalse((root/'forbidden').exists())
            self.assertEqual((root/'trace').read_text().splitlines(), ['download','verified','staged','bootstrap','approved'])
            self.assertEqual((root/'freepbx.conf').read_text(), 'inspection sentinel')
            self.assertTrue((root/'evidence').is_dir())
            self.assertFalse((root/'download').exists())
            self.assertFalse((root/'stage').exists())
            self.assertFalse((root/'bootstrap').exists())

    def test_failed_inspection_removes_transient_release_workspaces(self):
        with tempfile.TemporaryDirectory(dir='/run') as name:
            root = Path(name)
            (root/'freepbx.conf').write_text('inspection sentinel')
            (root/'framework').mkdir(); (root/'framework/module.xml').write_text('<module/>')
            installer = root/'installer.sh'
            installer.write_text(SOURCE.replace('/etc/freepbx.conf', str(root/'freepbx.conf'))
                .replace('/var/www/html/admin/modules/framework', str(root/'framework')))
            harness = r'''
source "$1"
fixture="$2"; CONFIG_FILE="$fixture/config"; SLS_MASS_NOTIFY_INVENTORY_ONLY=1
bootstrap_resource_capacity() { printf '{"requirements":{}}'; }
select_install_temp_root() { INSTALL_TEMP_ROOT="$fixture"; }
download_tgz() { DOWNLOAD_DIR="$fixture/download"; mkdir "$DOWNLOAD_DIR"; }
verify_tgz() { :; }
stage_module_directory() { STAGING_DIR="$fixture/stage"; mkdir "$STAGING_DIR"; }
prepare_installer_bootstrap() { INSTALL_BOOTSTRAP_DIR="$fixture/bootstrap"; mkdir "$INSTALL_BOOTSTRAP_DIR"; }
prepare_authenticated_installer() { UPGRADE_TRUST_DIR="$fixture/evidence"; mkdir "$UPGRADE_TRUST_DIR"; return 23; }
main
'''
            result = subprocess.run(['bash','-c',harness,'fixture',str(installer),str(root)], capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 23, result.stdout + result.stderr)
            self.assertTrue((root/'evidence').is_dir())
            self.assertFalse((root/'download').exists())
            self.assertFalse((root/'stage').exists())
            self.assertFalse((root/'bootstrap').exists())

    def test_optional_repairs_follow_approval_and_recovery(self):
        main = SOURCE.split('main() {',1)[1].split('\nif [[',1)[0]
        self.assertLess(main.index('preflight_platform check'),main.index('install_dependencies'))
        self.assertLess(main.index('prepare_authenticated_installer'),main.index('commit_authenticated_installer'))
        self.assertLess(main.index('commit_authenticated_installer'),main.index('install_dependencies'))
        bootstrap = re.search(r'^prepare_installer_bootstrap\(\) \{.*?(?=^[a-z_]+\(\) \{)', SOURCE, re.M | re.S).group(0)
        self.assertNotIn('initialize_configuration_keyring',bootstrap)

    def test_preparation_failures_report_the_actual_stage(self):
        cases=[('prepare_authenticated_installer','publisher and stock approval','install_command_failed'),
               ('repair_authenticated_legacy_permissions','legacy configuration and runtime permissions','legacy_permission_recovery_failed'),
               ('restore_previous_phone_collector','previous phone collector recovery','previous_collector_recovery_failed'),
               ('acquire_worker_coordination','notification worker coordination','worker_coordination_failed'),
               ('acquire_settings_coordination','configuration coordination','protected_config_validation_failed')]
        for operation,stage,category in cases:
            with self.subTest(operation=operation), tempfile.TemporaryDirectory() as name:
                root=Path(name)
                harness=r'''
source "$1"
fixture="$2"; failure="$3"
while read -r unused unused function; do
  case "$function" in main|set_install_stage) continue;; esac
  eval "$function() { :; }"
done < <(declare -F)
LOG_FILE="$fixture/log"
open_root_owned_file() { exec {INSTALL_LOG_FD}>>"$LOG_FILE"; }
guard_config_on_exit() {
  printf '%s\n%s\n%s\n' "$INSTALL_STAGE" "$INSTALL_ERROR_CATEGORY" "$INSTALL_EXIT_CODE" >"$fixture/failure"
}
eval "$failure() { return 17; }"
main
'''
                result=subprocess.run(['bash','-c',harness,'fixture',str(ROOT/'tools/install_release.sh'),str(root),operation],
                                      capture_output=True,text=True,timeout=5)
                self.assertEqual(result.returncode,17,result.stdout+result.stderr)
                lines=(root/'failure').read_text().splitlines()
                self.assertEqual(lines[:2],[stage,category])

    def php_profile(self, root, handler, user='asterisk', extra_pool='', custom_include=False,
                    listen='/run/php/php8.2-fpm.sock', extra_pools=None, binary_version='8.2', warning='', pool_mode=0o644,
                    apache_owner='root',pool_owner='root'):
        apache=root/'apache';apache.mkdir(exist_ok=True)
        config=apache/'apache.conf';config.write_text(handler)
        owner=pwd.getpwnam(apache_owner);os.chown(apache,owner.pw_uid,owner.pw_gid);os.chown(config,owner.pw_uid,owner.pw_gid)
        (root/'mods-enabled').mkdir(exist_ok=True)
        (root/'envvars').write_text('export APACHE_RUN_USER='+user+'\n')
        php=root/'php/8.2/fpm';(php/'pool.d').mkdir(parents=True,exist_ok=True)
        (php/'php-fpm.conf').write_text('include = '+str(php/('other/*.conf' if custom_include else 'pool.d/*.conf'))+'\n')
        pool=php/'pool.d/asterisk.conf'
        pool.write_text('[asterisk]\nuser = '+user+'\nlisten = '+listen+'\n'+extra_pool);pool.chmod(pool_mode)
        owner=pwd.getpwnam(pool_owner);os.chown(pool,owner.pw_uid,owner.pw_gid)
        for name,body in (extra_pools or {}).items():
            (php/'pool.d'/name).write_text(body)
        binaries=root/'binaries';binaries.mkdir(exist_ok=True)
        binary=binaries/'php-fpm8.2'
        binary.write_text('#!/bin/sh\nprintf \'PHP '+binary_version+'.33 (fpm-fcgi)\\n\'\n');binary.chmod(0o755)
        code=SOURCE.split("<<'PYPHPHANDLER'\n",1)[1].split('\nPYPHPHANDLER',1)[0]
        code=re.sub(r'^result = subprocess.run\(.*$',lambda unused:"result = type('Result', (), {'returncode':0,'stdout':"+repr(str(config)) + ",'stderr':"+repr(warning)+"})()",code,flags=re.M)
        code=code.replace('/etc/apache2/mods-enabled',str(root/'mods-enabled')).replace('/etc/apache2/envvars',str(root/'envvars')).replace("Path('/etc/php')","Path("+repr(str(root/'php'))+")")
        code=code.replace("Path('/usr/sbin')","Path("+repr(str(binaries))+")")
        return subprocess.run(['python3','-I','-c',code],capture_output=True,text=True,timeout=5)

    def test_mod_php_user_is_checked_without_changing_apache(self):
        with tempfile.TemporaryDirectory(dir='/run') as name:
            root=Path(name);handler='LoadModule php_module /usr/lib/apache2/modules/libphp8.2.so\nUser ${APACHE_RUN_USER}\n'
            result=self.php_profile(root,handler);self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(result.stdout.strip(),'8.2:apache2')
            for user in ['root','www-data','nonexistent_fixture_user']:
                result=self.php_profile(root,handler,user);self.assertNotEqual(result.returncode,0)
                self.assertIn('must run as the asterisk',result.stderr)
                self.assertEqual((root/'envvars').read_text(),'export APACHE_RUN_USER='+user+'\n')

    def test_fpm_served_pool_identity_and_disabled_functions_are_checked(self):
        with tempfile.TemporaryDirectory(dir='/run') as name:
            root=Path(name);handler='SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost"\n'
            result=self.php_profile(root,handler);self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(result.stdout.strip(),'8.2:fpm')
            result=self.php_profile(root,handler,'root');self.assertNotEqual(result.returncode,0);self.assertIn('must run as asterisk',result.stderr)
            result=self.php_profile(root,handler,extra_pool='php_admin_value[disable_functions] = exec,proc_open\n')
            self.assertNotEqual(result.returncode,0);self.assertIn('disables required functions: exec, proc_open',result.stderr)
            result=self.php_profile(root,handler,custom_include=True);self.assertNotEqual(result.returncode,0);self.assertIn('custom pool include layout',result.stderr)
            self.assertFalse((root/'trace').exists())

    def test_named_fpm_sockets_are_bound_to_the_actual_pool_and_binary_abi(self):
        with tempfile.TemporaryDirectory(dir='/run') as name:
            root=Path(name)
            for listen in ('/run/php/php8.2-fpm-sls-desktop.sock','/run/php/desktop-notifications.sock'):
                handler='SetHandler "proxy:unix:'+listen+'|fcgi://localhost"\n'
                result=self.php_profile(root,handler,listen=listen)
                self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stdout.strip(),'8.2:fpm')
                result=self.php_profile(root,handler,listen=listen,binary_version='8.3')
                self.assertNotEqual(result.returncode,0);self.assertIn('binary ABI disagree',result.stderr)
            result=self.php_profile(root,'SetHandler "proxy:unix:/var/run/php/php8.2-fpm-sls-desktop.sock|fcgi://localhost"\n',listen='/run/php/php8.2-fpm-sls-desktop.sock')
            self.assertEqual(result.returncode,0,result.stderr)
            listen='/run/php/php8.3-fpm-named.sock'
            result=self.php_profile(root,'SetHandler "proxy:unix:'+listen+'|fcgi://localhost"\n',listen=listen)
            self.assertNotEqual(result.returncode,0);self.assertIn('socket name and configured pool ABI disagree',result.stderr)

    def test_unrelated_coexisting_pool_is_allowed_but_duplicate_socket_is_not(self):
        with tempfile.TemporaryDirectory(dir='/run') as name:
            root=Path(name);listen='/run/php/php8.2-fpm-sls-desktop.sock'
            handler='SetHandler "proxy:unix:'+listen+'|fcgi://localhost"\n'
            extra={'other.conf':'[unrelated]\nuser=www-data\nlisten=/run/php/php8.2-fpm-other.sock\nphp_admin_value[disable_functions]=exec,proc_open\n'}
            result=self.php_profile(root,handler,listen=listen,extra_pools=extra)
            self.assertEqual(result.returncode,0,result.stderr)
            extra['other.conf']='[duplicate]\nuser=asterisk\nlisten='+listen+'\n'
            result=self.php_profile(root,handler,listen=listen,extra_pools=extra)
            self.assertNotEqual(result.returncode,0);self.assertIn('exactly one configured local pool',result.stderr)

    def test_cross_version_socket_collision_is_rejected(self):
        with tempfile.TemporaryDirectory(dir='/run') as name:
            root=Path(name);listen='/run/php/desktop-notifications.sock'
            php=root/'php/8.3/fpm';(php/'pool.d').mkdir(parents=True)
            (php/'php-fpm.conf').write_text('include='+str(php/'pool.d/*.conf')+'\n')
            (php/'pool.d/collision.conf').write_text('[collision]\nuser=asterisk\nlisten='+listen+'\n')
            result=self.php_profile(root,'SetHandler "proxy:unix:'+listen+'|fcgi://localhost"\n',listen=listen)
            self.assertNotEqual(result.returncode,0);self.assertIn('exactly one configured local pool',result.stderr)

    def test_remote_directive_cannot_hide_beside_a_valid_local_handler(self):
        with tempfile.TemporaryDirectory(dir='/run') as name:
            root=Path(name);local='SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost"\n'
            for remote in ('SetHandler "proxy:fcgi://192.0.2.1:9000"\n','ProxyPassMatch ^/(.*) fcgi://backend.example.test:9000/var/www/\n'):
                result=self.php_profile(root,local+remote)
                self.assertNotEqual(result.returncode,0);self.assertIn('remote FastCGI handler',result.stderr)
            result=self.php_profile(root,local,warning='AH00112: Warning: DocumentRoot [/invalid/folder/name] does not exist\n')
            self.assertEqual(result.returncode,0,result.stderr)

    def test_pool_permission_and_named_pool_function_failures_stay_fail_closed(self):
        with tempfile.TemporaryDirectory(dir='/run') as name:
            root=Path(name);listen='/run/php/php8.2-fpm-sls-desktop.sock'
            handler='SetHandler "proxy:unix:'+listen+'|fcgi://localhost"\n'
            result=self.php_profile(root,handler,listen=listen,pool_mode=0o666)
            self.assertNotEqual(result.returncode,0);self.assertIn('writable parent or source',result.stderr)
            result=self.php_profile(root,handler,listen=listen,extra_pool='php_admin_value[disable_functions]=sodium_crypto_pwhash_str\n')
            self.assertNotEqual(result.returncode,0);self.assertIn('sodium_crypto_pwhash_str',result.stderr)

    def test_freepbx_managed_apache_ownership_does_not_weaken_pool_authority(self):
        with tempfile.TemporaryDirectory(dir='/run') as name:
            root=Path(name);handler='SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost"\n'
            result=self.php_profile(root,handler,apache_owner='asterisk')
            self.assertEqual(result.returncode,0,result.stderr)
            result=self.php_profile(root,handler,apache_owner='www-data')
            self.assertNotEqual(result.returncode,0);self.assertIn('unexpected owner',result.stderr)
            result=self.php_profile(root,handler,apache_owner='asterisk',pool_owner='asterisk')
            self.assertNotEqual(result.returncode,0);self.assertIn('unexpected owner',result.stderr)

    def test_spool_symlink_is_rejected_before_chown(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered':
            self.fail('Spool fixture requires the isolation wrapper')
        spool = Path('/var/spool/asterisk')
        with tempfile.TemporaryDirectory(dir='/run') as name:
            sentinel = Path(name)/'sentinel'; sentinel.mkdir(mode=0o700)
            for child in ('tmp','outgoing','outgoing_done'):
                path = spool/child
                if path.is_dir(): shutil.rmtree(path)
                elif path.is_symlink(): path.unlink()
            (spool/'tmp').mkdir(mode=0o700)
            (spool/'outgoing').symlink_to(sentinel)
            before = sentinel.stat()
            result = subprocess.run(['bash','-c','source "$1"; secure_asterisk_spool','fixture',str(ROOT/'tools/install_release.sh')],capture_output=True,text=True,timeout=10)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual((sentinel.stat().st_uid,sentinel.stat().st_mode),(before.st_uid,before.st_mode))
            self.assertEqual((spool/'tmp').stat().st_uid,0)
            (spool/'outgoing').unlink()

    def test_spool_unknown_metadata_is_preserved_without_partial_repair(self):
        self.assertEqual(os.environ.get('SLS_TEST_NAMESPACE'), 'entered')
        spool = Path('/var/spool/asterisk')
        for unsafe in ('foreign_group', 'world_writable'):
            with self.subTest(unsafe=unsafe):
                for child in ('tmp', 'outgoing', 'outgoing_done'):
                    path = spool/child
                    if path.is_symlink(): path.unlink()
                    elif path.exists(): shutil.rmtree(path)
                    path.mkdir(mode=0o700)
                suspect = spool/'outgoing_done'
                if unsafe == 'foreign_group': os.chown(suspect, 0, 65000)
                else: suspect.chmod(0o777)
                before = {child: (spool/child).stat() for child in ('tmp', 'outgoing', 'outgoing_done')}
                result = subprocess.run(['bash', '-c', 'source "$1"; secure_asterisk_spool', 'fixture', str(ROOT/'tools/install_release.sh')], capture_output=True, text=True, timeout=10)
                self.assertNotEqual(result.returncode, 0, result.stderr)
                for child, old in before.items():
                    current = (spool/child).stat()
                    self.assertEqual((current.st_ino, current.st_uid, current.st_gid, current.st_mode), (old.st_ino, old.st_uid, old.st_gid, old.st_mode))
        shutil.rmtree(spool/'tmp')
        (spool/'outgoing_done').chmod(0o777)
        result = subprocess.run(['bash', '-c', 'source "$1"; secure_asterisk_spool', 'fixture', str(ROOT/'tools/install_release.sh')], capture_output=True, text=True, timeout=10)
        self.assertNotEqual(result.returncode, 0, result.stderr)
        self.assertFalse((spool/'tmp').exists(), 'Unsafe existing spool metadata caused another directory to be created')

    def test_spool_recognized_layout_preserves_directory_inodes(self):
        self.assertEqual(os.environ.get('SLS_TEST_NAMESPACE'), 'entered')
        spool = Path('/var/spool/asterisk'); account = pwd.getpwnam('asterisk')
        for child in ('tmp', 'outgoing', 'outgoing_done'):
            path = spool/child
            if path.is_symlink(): path.unlink()
            elif path.exists(): shutil.rmtree(path)
            path.mkdir(mode=0o755); os.chown(path, 0, 0)
        before = {child: (spool/child).stat().st_ino for child in ('tmp', 'outgoing', 'outgoing_done')}
        result = subprocess.run(['bash', '-c', 'source "$1"; secure_asterisk_spool', 'fixture', str(ROOT/'tools/install_release.sh')], capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        for child, mode in (('tmp', 0o775), ('outgoing', 0o775), ('outgoing_done', 0o750)):
            current = (spool/child).stat()
            self.assertEqual((current.st_ino, current.st_uid, current.st_gid, stat.S_IMODE(current.st_mode)), (before[child], account.pw_uid, account.pw_gid, mode))


if __name__ == '__main__': unittest.main()
