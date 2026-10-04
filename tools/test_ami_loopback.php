<?php
declare(strict_types=1);

// Never bootstrap FreePBX, read protected configuration, or execute commands.
namespace FreePBX\modules {
    function is_readable($path) { return false; }
    function is_executable($path) { return false; }
    function file_get_contents(...$args) { throw new \RuntimeException('Unexpected live read.'); }
    function file_put_contents(...$args) { throw new \RuntimeException('Unexpected live write.'); }
    function exec(...$args) { throw new \RuntimeException('Unexpected command execution.'); }
}
namespace {
    if (!interface_exists('BMO')) { interface BMO {} }
    if (!function_exists('_')) { function _($message) { return $message; } }
    final class AmiManagerFixture {
        public $added = [];
        public $fail = false;
        public function isExist_manager(...$arguments) { return false; }
        public function del_manager(...$arguments) { throw new RuntimeException('Unexpected deletion.'); }
        public function add_manager(...$arguments) {
            if ($this->fail) { throw new RuntimeException('Synthetic Manager database failure.'); }
            $this->added[] = $arguments;
        }
    }
    final class FreePBX {
        public static $values = ['ASTMANAGERHOST' => '::1', 'ASTMANAGERPORT' => '5041'];
        public static $manager;
        public static function Config() { return new class { public function get($key) { return FreePBX::$values[$key] ?? ''; } }; }
        public static function Manager() {
            if (self::$manager === null) { throw new RuntimeException('Synthetic missing Manager module.'); }
            return self::$manager;
        }
    }
    require dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
    final class AmiSettingsFixture extends \FreePBX\modules\Slsmassnotifyserver {
        public function getConfiguredPjsipExtensionNumbers() { return []; }
        public function getAllPjsipExtensions() { return []; }
        public function getAvailableTones() { return []; }
        public function getAvailablePiperVoices() { return []; }
    }
    $reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
    $module = (new ReflectionClass(AmiSettingsFixture::class))->newInstanceWithoutConstructor();
    $call = static function ($name, ...$arguments) use ($reflection, $module) {
        $method = $reflection->getMethod($name); $method->setAccessible(true);
        return $method->invokeArgs($module, $arguments);
    };
    $checks = 0;
    $assert = static function ($condition, $message) use (&$checks) {
        $checks++; if (!$condition) { throw new RuntimeException($message); }
    };
    $_SERVER['HTTP_HOST'] = 'pbx.example.test';
    $settings = $call('getDefaultSettings');
    $settings['ami']['password'] = 'synthetic-secret-do-not-log';
    $settings['ami']['username'] = 'synthetic-user-do-not-log';
    $settings['desktop_clients'] = [];
    $assert($settings['ami']['host'] === '::1' && $settings['ami']['port'] === 5041, 'Fresh defaults lost FreePBX IPv6 loopback.');
    foreach (['::1', '127.0.0.1', 'localhost'] as $host) {
        $settings['ami']['host'] = $host;
        $settings['ami']['port'] = 5038;
        $before = serialize($settings);
        $normalized = $call('normalizeSettings', $settings);
        $assert($normalized['ami'] === $settings['ami'], 'Normalization changed an explicit endpoint or credential.');
        $assert(serialize($settings) === $before, 'Normalization mutated the supplied configuration.');
    }
    unset($settings['ami']['host']);
    $assert($call('normalizeSettings', $settings)['ami']['host'] === '::1', 'Missing host did not inherit the safe IPv6 default.');
    foreach (['', 'localhost', '127.0.0.1'] as $host) {
        FreePBX::$values['ASTMANAGERHOST'] = $host;
        $assert($call('detectAmiHost') === '127.0.0.1', 'IPv4/localhost default changed family.');
    }
    foreach (['192.0.2.1', '2001:db8::1', '::', '[::1]', 'localhost.example.test', '', null] as $host) {
        $rejected = false;
        try { $call('normalizeAmiHost', $host); } catch (DomainException $error) { $rejected = true; }
        $assert($rejected, 'Unsafe or ambiguous AMI endpoint was accepted.');
    }
    FreePBX::$values['ASTMANAGERHOST'] = '::1';
    $settings['ami']['host'] = '127.0.0.1';
    $diagnostic = $call('amiEndpointDiagnostic', $settings['ami']);
    $assert(strpos($diagnostic, '127.0.0.1:5038') !== false && strpos($diagnostic, '[::1]:5041') !== false,
        'Endpoint mismatch diagnostic omitted the explicit settings.');
    $assert(strpos($diagnostic, 'localhost uses IPv4') !== false, 'IPv6-only localhost limitation is not actionable.');
    $assert(strpos($diagnostic, 'synthetic-secret') === false && strpos($diagnostic, 'synthetic-user') === false,
        'AMI diagnostic disclosed credentials.');
    FreePBX::$values['ASTMANAGERHOST'] = 'secret-user:secret-value@remote.example.test';
    $assert(strpos($call('amiEndpointDiagnostic', ['host' => 'private-secret-host']), 'secret') === false,
        'Invalid endpoint diagnostic echoed untrusted secret-like values.');
    FreePBX::$values['ASTMANAGERHOST'] = '::1';

    // Execute the real ensureAmiUser body with captured integration operations.
    // Only the dependency boundaries are fake: no actual Manager, file or CLI.
    $source = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php');
    $methods = '';
    foreach (['ensureAmiUser', 'normalizeAmiHost', 'detectAmiHost', 'getFreePbxConfigValue', 'normalizeEndpointUsername', 'normalizeEndpointPassword'] as $name) {
        $start = strpos($source, "\tprivate function {$name}(");
        $end = strpos($source, "\n\tprivate function ", $start + 1);
        $methods .= str_replace('private function', 'public function', substr($source, $start, $end - $start)) . "\n";
    }
    eval('class AmiIntegrationFixture {' . $methods . '
        public $settings = []; public $blocks = []; public $commands = []; public $removed = [];
        public function getActiveSettings() { return $this->settings; }
        public function writeManagedBlock($path, $name, $block) { $this->blocks[] = [$path, $name, $block]; }
        public function removeManagedBlock($path, $name) { $this->removed[] = [$path, $name]; }
        public function runCommand($command) { $this->commands[] = $command; }
    }');
    $fixture = new AmiIntegrationFixture(); $fixture->settings = ['ami' => $settings['ami']];
    $original = serialize($fixture->settings);
    FreePBX::$manager = new AmiManagerFixture();
    $fixture->ensureAmiUser();
    $row = FreePBX::$manager->added[0];
    $assert(explode('&', $row[2]) === ['0.0.0.0/0.0.0.0', '::/0'], 'Manager ACL does not deny both address families.');
    $assert(explode('&', $row[3]) === ['127.0.0.1/255.255.255.255', '::1/128'], 'Manager ACL permits more than the two loopback addresses.');
    $assert($row[4] === 'system,call,originate,reporting' && $row[5] === $row[4], 'AMI capability permissions changed.');
    $assert(serialize($fixture->settings) === $original, 'Account installation rewrote protected endpoint settings.');
    $assert($fixture->blocks === [], 'Working Manager integration also emitted a competing configuration block.');
    foreach (['database-failure', 'missing-manager'] as $mode) {
        if ($mode === 'database-failure') { FreePBX::$manager->fail = true; } else { FreePBX::$manager = null; }
        $fixture->blocks = []; $fixture->ensureAmiUser();
        $block = $fixture->blocks[0][2];
        preg_match_all('/^(deny|permit) = (.+)$/m', $block, $rules, PREG_SET_ORDER);
        $assert(array_map(static function ($r) { return [$r[1], $r[2]]; }, $rules) === [
            ['deny', '0.0.0.0/0.0.0.0'], ['deny', '::/0'],
            ['permit', '127.0.0.1/255.255.255.255'], ['permit', '::1/128'],
        ], 'Fallback AMI ACL differs from Manager-generated dual loopback ACL.');
        $assert(strpos($block, 'bindaddr') === false && strpos($block, '[general]') === false, 'Integration changed listener configuration.');
    }
    $assert(count(array_unique($fixture->commands)) === 1 && strpos($fixture->commands[0], 'manager reload') !== false,
        'Unexpected integration command would run.');
    $before = count($fixture->commands);
    FreePBX::$values['ASTMANAGERHOST'] = '192.0.2.1';
    $rejected = false;
    try { $fixture->ensureAmiUser(); } catch (RuntimeException $error) { $rejected = true; }
    $assert($rejected && count($fixture->commands) === $before, 'Remote FreePBX Manager host was accepted.');
    echo "AMI loopback: {$checks} isolated normalization, endpoint-preservation, ACL and secret-free diagnostic checks passed.\n";
}
