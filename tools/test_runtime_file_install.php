<?php
/** Execute the real install copy/prune methods in a private filesystem fixture. */
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$source = file($reflection->getFileName());
$module = dirname($reflection->getFileName());
$root = sys_get_temp_dir() . '/sls-runtime-install-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) { throw new RuntimeException('Cannot create private runtime fixture'); }
$previousUmask = umask(0027);
$methods = '';
foreach (['installRuntimeFiles', 'copyRuntimeFile', 'copyRuntimeDirectory', 'pruneRuntimeDirectory'] as $name) {
    $method = $reflection->getMethod($name);
    $methods .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
// Only destinations and the source __DIR__ boundary are redirected. The actual
// copy calls, preserve list, recursion, pruning and chmod implementation execute.
$methods = str_replace('__DIR__', var_export($module, true), $methods);
$methods = str_replace("'/usr/local/sbin/", "'" . $root . '/sbin/', $methods);
$methods = str_replace("'/usr/local/bin/", "'" . $root . '/bin/', $methods);
$methods = str_replace("'/var/www/html/", "'" . $root . '/web/', $methods);
if (strpos($methods, "'/usr/") !== false || strpos($methods, "'/var/") !== false) {
    throw new RuntimeException('Runtime fixture encountered an unmapped production destination');
}
eval('class RuntimeFileInstallFixture {
    const RUNTIME_DIR = ' . var_export($root . '/runtime', true) . ';
    const SOUNDS_DIR = ' . var_export($root . '/sounds', true) . ';
    public $secured = 0;
    private function secureExecutableRuntimeTree() { $this->secured++; }
    private function runCommand($command) { throw new RuntimeException("Unexpected shell operation in copy fixture"); }
    public function install() { $this->installRuntimeFiles(); }
' . $methods . '}');
function runtime_install_assert($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
function runtime_fixture_remove($path) {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') { runtime_fixture_remove($path . '/' . $name); }
        }
        rmdir($path);
    } else { unlink($path); }
}
try {
    $workers = array_values(array_filter(glob($module . '/bin/*'), 'is_file'));
    $fixture = new RuntimeFileInstallFixture();
    foreach (['fresh', 'repair'] as $phase) {
        if ($phase === 'repair') {
            // Existing files must be replaced, new workers must survive pruning,
            // and a previously installed Piper environment must remain intact.
            foreach ($workers as $worker) {
                if (basename($worker) !== 'sign_sls_mass_notify_local_sig.sh') {
                    $target = $root . (basename($worker) === 'slsconsole' ? '/bin/' : '/runtime/') . basename($worker);
                    file_put_contents($target, 'old runtime bytes');
                    chmod($target, 0600);
                }
            }
            mkdir($root . '/runtime/piper');
            file_put_contents($root . '/runtime/piper/keep', 'preserve installed Piper');
            file_put_contents($root . '/runtime/obsolete-worker.py', 'remove obsolete runtime');
            foreach (['index.php', 'sso.php', 'subscriber.php', '.htaccess'] as $name) {
                file_put_contents($root . '/web/mass-notify/' . $name, 'old portal bytes');
                chmod($root . '/web/mass-notify/' . $name, 0600);
            }
            file_put_contents($root . '/web/mass-notify/obsolete.php', 'remove obsolete portal route');
        }
        $fixture->install();
        clearstatcache();
        foreach ($workers as $worker) {
            $name = basename($worker);
            $target = $name === 'sign_sls_mass_notify_local_sig.sh'
                ? $root . '/sbin/' . $name : $root . ($name === 'slsconsole' ? '/bin/' : '/runtime/') . $name;
            runtime_install_assert(is_file($target), $phase . ' install omitted or pruned worker ' . $name);
            runtime_install_assert(hash_file('sha256', $worker) === hash_file('sha256', $target), $phase . ' worker differs from package: ' . $name);
            runtime_install_assert((fileperms($target) & 0777) === 0755, $phase . ' worker execute mode is incorrect: ' . $name);
        }
        foreach (glob($module . '/bin/sls_mass_notify/*') as $helper) {
            if (is_file($helper)) {
                $target = $root . '/runtime/' . basename($helper);
                runtime_install_assert(is_file($target) && hash_file('sha256', $helper) === hash_file('sha256', $target), $phase . ' nested helper omitted or changed: ' . basename($helper));
            }
        }
        foreach (['sipnotify', 'sls-mass-notify'] as $api) {
            runtime_install_assert((fileperms($root . '/web/api/' . $api) & 0777) === 0755, 'Public API directory mode was filtered by umask');
            runtime_install_assert((fileperms($root . '/web/api/' . $api . '/index.php') & 0777) === 0644, 'Public API file mode changed');
        }
        $portalSource = $module . '/portal';
        $portalTarget = $root . '/web/mass-notify';
        runtime_install_assert((fileperms($portalTarget) & 0777) === 0755, 'Portal directory mode was filtered by umask');
        $portalFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($portalSource, FilesystemIterator::SKIP_DOTS));
        foreach ($portalFiles as $file) {
            if (!$file->isFile()) { continue; }
            $relative = substr($file->getPathname(), strlen($portalSource) + 1);
            $target = $portalTarget . '/' . $relative;
            runtime_install_assert(is_file($target) && !is_link($target), $phase . ' portal route omitted: ' . $relative);
            runtime_install_assert(hash_file('sha256', $file->getPathname()) === hash_file('sha256', $target), $phase . ' portal bytes differ: ' . $relative);
            runtime_install_assert((fileperms($target) & 0777) === 0644, $phase . ' portal file mode is incorrect: ' . $relative);
        }
        if ($phase === 'repair') {
            runtime_install_assert(!file_exists($root . '/runtime/obsolete-worker.py'), 'Repair did not prune obsolete runtime file');
            runtime_install_assert(file_get_contents($root . '/runtime/piper/keep') === 'preserve installed Piper', 'Repair removed the existing Piper environment');
            runtime_install_assert(!file_exists($portalTarget . '/obsolete.php'), 'Repair did not prune obsolete portal route');
        }
    }
    runtime_install_assert($fixture->secured === 2, 'Runtime ownership-hardening boundary was skipped');
    echo 'Actual fresh/repair runtime copies preserve all ' . count($workers) . " top-level workers, package bytes, execute modes, nested helpers and Piper state.\n";
} finally {
    runtime_fixture_remove($root);
    umask($previousUmask);
}
