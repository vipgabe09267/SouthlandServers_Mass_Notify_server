<?php
declare(strict_types=1);
require dirname(__DIR__) . '/slsmassnotifyserver/OperatorRecoveryService.php';
class OperatorMailFixture {
    use \FreePBX\modules\SlsOperatorRecovery;
    public string $helper, $mode = 'accepted'; public int $submissions = 0;
    protected function operatorRecoveryEmailTransportAvailable(): bool { return true; }
    protected function startOperatorMailAdmission(&$pipes) {
        if ($this->mode === 'missing') { return false; }
        return proc_open(['/usr/bin/timeout', '--kill-after=0.1', '0.25', '/usr/bin/php', $this->helper, $this->mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, [], ['bypass_shell' => true]);
    }
    protected function startOperatorRecoveryEmailProcess(&$pipes) {
        $this->submissions++;
        return proc_open(['/usr/bin/timeout', '--signal=TERM', '--kill-after=1s', '5s', '/usr/bin/php', $this->helper, 'mailer'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, [], ['bypass_shell' => true]);
    }
    public function send(): string { return $this->sendOperatorRecoveryEmail(['mail_from_addr' => 'notify@example.test'], 'owner@example.test', 'Reset', 'Private reset credential'); }
}
$root = sys_get_temp_dir() . '/sls-operator-mail-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) { throw new RuntimeException('Unable to create the private operator-mail fixture.'); }
$helper = $root . '/helper.php';
$helperSource=<<<'PHP'
<?php
$request = stream_get_contents(STDIN); $mode = $argv[1];
if ($mode === 'mailer') { file_put_contents(__DIR__ . '/email.txt', $request); exit(0); }
file_put_contents(__DIR__ . '/admission.txt', $request);
if ($mode === 'timeout') { sleep(20); exit(0); }
if ($mode === 'oversized') { echo str_repeat('x', 5000); sleep(20); exit(0); }
if ($mode === 'malformed') { echo 'broken'; exit(0); }
if ($mode === 'nonboolean') { echo '{"ok":"true"}'; exit(0); }
if ($mode === 'nonzero') { echo '{"ok":true}'; exit(1); }
if ($mode !== 'accepted') { echo json_encode(['ok' => false, 'error_code' => $mode]); exit(1); }
echo '{"ok":true}';
PHP
;
try {
    if (file_put_contents($helper,$helperSource,LOCK_EX)!==strlen($helperSource)) { throw new RuntimeException('Unable to create the operator-mail helper.'); }
    $fixture = new OperatorMailFixture(); $fixture->helper = $helper; $checks = 0;
    foreach (['mail_queue_full', 'mail_queue_unavailable', 'mail_rate_limited', 'mail_admission_unavailable', 'missing', 'timeout', 'oversized', 'malformed', 'nonboolean', 'nonzero'] as $mode) {
        $fixture->mode = $mode; $start = microtime(true);
        if ($fixture->send() !== 'failed' || $fixture->submissions !== 0 || microtime(true) - $start > 3.5) { throw new RuntimeException('Unsafe or unbounded recovery mail admission: ' . $mode); }
        $checks++;
    }
    $fixture->mode = 'accepted';
    if ($fixture->send() !== 'accepted' || $fixture->submissions !== 1) { throw new RuntimeException('Reserved recovery email was not submitted exactly once'); }
    $admission=file_get_contents($root . '/admission.txt');$email=file_get_contents($root . '/email.txt');
    if (!is_string($admission)||!is_string($email)||json_decode($admission,true,4,JSON_THROW_ON_ERROR)!==['category'=>'account','recipients'=>1]
        || !str_contains($email, 'Private reset credential')) { throw new RuntimeException('Admission leaked the reset credential or altered its mail'); }
    echo ($checks + 2) . " operator mail refusal, timeout, output bounds and private reservation checks passed; no real MTA invoked.\n";
} finally {
    $files=glob($root.'/*');if($files!==false){foreach($files as $file){if(is_file($file)||is_link($file)){unlink($file);}}}rmdir($root);
}
