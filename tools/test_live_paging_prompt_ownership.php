<?php
declare(strict_types=1);

// Repository classes only: never construct the FreePBX module or read PBX paths.
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !($account = posix_getpwnam('asterisk'))) {
    echo "SKIP: paging prompt ownership requires root and an asterisk account for a temporary-file privilege-drop fixture.\n";
    exit(0);
}

function paging_ownership_check($condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function paging_readiness_rejects(callable $operation, string $message): void
{
    try { $operation(); } catch (DomainException $expected) {
        paging_ownership_check(strpos($expected->getMessage(), 'Apply Config') !== false, 'Readiness failure did not explain staging/Apply Config');
        return;
    }
    throw new RuntimeException($message);
}

define('PAGING_OWNERSHIP_FIXTURE_ROOT', sys_get_temp_dir() . '/sls-paging-ownership-' . bin2hex(random_bytes(8)));
mkdir(PAGING_OWNERSHIP_FIXTURE_ROOT, 0700);
register_shutdown_function(static function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PAGING_OWNERSHIP_FIXTURE_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
    rmdir(PAGING_OWNERSHIP_FIXTURE_ROOT);
});
chgrp(PAGING_OWNERSHIP_FIXTURE_ROOT, $account['gid']);
chmod(PAGING_OWNERSHIP_FIXTURE_ROOT, 0750);

class PagingPromptOwnershipFixture
{
    use \FreePBX\modules\SlsLivePaging;
    const SOUNDS_DIR = PAGING_OWNERSHIP_FIXTURE_ROOT . '/sounds';
    const TTS_DIR = self::SOUNDS_DIR . '/tts';
    const SETTINGS_JSON = PAGING_OWNERSHIP_FIXTURE_ROOT . '/active.config';
    const PENDING_SETTINGS_JSON = PAGING_OWNERSHIP_FIXTURE_ROOT . '/pending.config';
    public $generated = 0;
    private $module;
    public function __construct()
    {
        $this->module = (new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class))->newInstanceWithoutConstructor();
        $this->ensureOwnedDirectory(self::SOUNDS_DIR, 0750);
        $this->ensureOwnedDirectory(self::TTS_DIR, 0750);
    }
    private function realOwnershipHelper(string $name, array $arguments): void
    {
        $method = new ReflectionMethod($this->module, $name);
        $method->setAccessible(true);
        $method->invokeArgs($this->module, $arguments);
    }
    private function ensureOwnedDirectory($directory, $mode): void
    {
        $this->realOwnershipHelper('ensureOwnedDirectory', [$directory, $mode]);
    }
    private function setPrivateOwnership($file): void
    {
        $this->realOwnershipHelper('setPrivateOwnership', [$file]);
    }
    private function generateAnnouncementTtsFile($text, $settings): string
    {
        paging_ownership_check(($settings['_tts_prompt_only'] ?? false) === true, 'Paging prompt retained the announcement prefix');
        $basename = 'fixture_' . (++$this->generated);
        // Silent fixture WAV, produced without a speech engine or PBX services.
        $wav = 'RIFF' . pack('V', 52) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            . 'data' . pack('V', 16) . str_repeat("\0", 16);
        file_put_contents(self::TTS_DIR . '/' . $basename . '.wav', $wav);
        chmod(self::TTS_DIR . '/' . $basename . '.wav', 0600);
        return $basename;
    }
    public function prepare(array $settings): void { $this->ensureLivePagingPrompts($settings); }
    public function assertReady(array $settings): void { $this->assertLivePagingPromptsReady($settings); }
}

$settings = ['live_paging' => ['enabled' => '1', 'extension' => '700', 'allowed_callers' => ['1000'],
    'groups' => [['group_id' => 'grp_office', 'menu_number' => 1, 'require_pin' => '0']]],
    'announcement_groups' => [['id' => 'grp_office', 'name' => 'Office', 'extensions' => ['1001']]]];
file_put_contents(PagingPromptOwnershipFixture::SETTINGS_JSON, json_encode($settings));
chmod(PagingPromptOwnershipFixture::SETTINGS_JSON, 0640);
$fixture = new PagingPromptOwnershipFixture();
paging_readiness_rejects(static function () use ($fixture, $settings): void { $fixture->assertReady($settings); }, 'Missing paging prompts passed the activation guard');
$disabled = $settings; $disabled['live_paging']['enabled'] = '0';
$fixture->assertReady($disabled);
$fixture->prepare($settings);
$fixture->assertReady($settings);
$changedVoice = $settings; $changedVoice['announcement_piper_voice'] = 'changed-voice';
paging_readiness_rejects(static function () use ($fixture, $changedVoice): void { $fixture->assertReady($changedVoice); }, 'Changed speech voice bypassed paging preparation');
$files = glob(PagingPromptOwnershipFixture::SOUNDS_DIR . '/paging/*.wav');
paging_ownership_check(count($files) === 9 && $fixture->generated === 9, 'Prepared prompts are incomplete');
// Root-owned cached audio must also be repaired before dialplan activation.
chown($files[0], 0); chgrp($files[0], 0); chmod($files[0], 0600);
paging_readiness_rejects(static function () use ($fixture, $settings): void { $fixture->assertReady($settings); }, 'Root-only cached prompt passed the activation guard');
$fixture->prepare($settings);
$fixture->assertReady($settings);
paging_ownership_check($fixture->generated === 9, 'Cached prompts were unnecessarily regenerated');
foreach ($files as $file) {
    clearstatcache(true, $file);
    $metadata = lstat($file);
    paging_ownership_check(($metadata['mode'] & 0777) === 0640 && $metadata['uid'] === $account['uid'] && $metadata['gid'] === $account['gid'], 'Prompt is not privately owned by asterisk');
}
$program = <<<'PY'
import glob, os, pwd, sys
account = pwd.getpwnam('asterisk')
os.setgroups([])
os.setgid(account.pw_gid)
os.setuid(account.pw_uid)
paths = glob.glob(sys.argv[1] + '/paging/*.wav')
assert len(paths) == 9
for path in paths:
    with open(path, 'rb') as stream:
        header = stream.read(12)
    assert header[:4] == b'RIFF' and header[8:12] == b'WAVE'
PY;
$output = []; $status = 1;
exec('/usr/bin/python3 -c ' . escapeshellarg($program) . ' ' . escapeshellarg(PagingPromptOwnershipFixture::SOUNDS_DIR) . ' 2>&1', $output, $status);
paging_ownership_check($status === 0, 'The asterisk account cannot read prepared prompts: ' . implode("\n", $output));

file_put_contents($files[0], str_repeat('invalid header ', 8));
paging_readiness_rejects(static function () use ($fixture, $settings): void { $fixture->assertReady($settings); }, 'Invalid cached WAV passed the activation guard');
$fixture->prepare($settings);
paging_ownership_check($fixture->generated === 10, 'Applying configuration did not regenerate a corrupt cached prompt');
$fixture->assertReady($settings);
$extraLink = PAGING_OWNERSHIP_FIXTURE_ROOT . '/linked-prompt'; link($files[0], $extraLink);
paging_readiness_rejects(static function () use ($fixture, $settings): void { $fixture->assertReady($settings); }, 'Hardlinked cached WAV passed the activation guard');
unlink($extraLink);
$temporaryPrompt = PAGING_OWNERSHIP_FIXTURE_ROOT . '/temporary-prompt.wav'; rename($files[0], $temporaryPrompt); symlink($temporaryPrompt, $files[0]);
paging_readiness_rejects(static function () use ($fixture, $settings): void { $fixture->assertReady($settings); }, 'Symlinked cached WAV passed the activation guard');
unlink($files[0]); rename($temporaryPrompt, $files[0]);
$fixture->assertReady($settings);

$directory = PagingPromptOwnershipFixture::SOUNDS_DIR . '/paging';
$old = time() - 90000;
$copyPrompt = static function (string $name, int $mtime) use ($directory, $files): string {
    $path = $directory . '/' . $name;
    copy($files[0], $path); touch($path, $mtime);
    return $path;
};
$active = $settings; $active['live_paging']['menu_prompt'] = 'Existing active menu.';
$pending = $settings; $pending['live_paging']['menu_prompt'] = 'Pending menu.';
file_put_contents(PagingPromptOwnershipFixture::SETTINGS_JSON, json_encode($active));
file_put_contents(PagingPromptOwnershipFixture::PENDING_SETTINGS_JSON, json_encode($pending));
chmod(PagingPromptOwnershipFixture::PENDING_SETTINGS_JSON, 0640);
$activePrompt = $copyPrompt(basename(\SLS\MassNotify\LivePagingConfig::promptFiles($active)['menu']) . '.wav', $old);
$pendingPrompt = $copyPrompt(basename(\SLS\MassNotify\LivePagingConfig::promptFiles($pending)['menu']) . '.wav', $old);
$stale = $copyPrompt(hash('sha256', 'stale') . '.wav', $old);
$recent = $copyPrompt(hash('sha256', 'recent') . '.wav', time() - 60);
$custom = $copyPrompt('operator-recording.wav', $old);
$upper = $copyPrompt(strtoupper(hash('sha256', 'uppercase')) . '.wav', $old);
$staging = $copyPrompt('.prompt-fixture', $old);
$hardlink = $directory . '/' . hash('sha256', 'hardlink') . '.wav'; link($custom, $hardlink);
$symlink = $directory . '/' . hash('sha256', 'symlink') . '.wav'; symlink($custom, $symlink);
$subdirectory = $directory . '/' . hash('sha256', 'directory') . '.wav'; mkdir($subdirectory, 0700); touch($subdirectory, $old);
foreach ($files as $file) { touch($file, $old); }
$fixture->prepare($settings);
clearstatcache();
paging_ownership_check(!file_exists($stale), 'Unused old generated prompt was not pruned');
foreach ([$activePrompt, $pendingPrompt, $recent, $custom, $upper, $staging, $hardlink, $symlink, $subdirectory] as $protected) {
    paging_ownership_check(file_exists($protected), 'Prompt cleanup removed a protected, recent, linked or non-generated path');
}
foreach ($files as $file) { paging_ownership_check(is_file($file) && filemtime($file) >= time() - 10, 'Prospective prompt use time was not refreshed'); }
$skipped = $copyPrompt(hash('sha256', 'unreadable-state') . '.wav', $old);
file_put_contents(PagingPromptOwnershipFixture::SETTINGS_JSON, '{');
$fixture->prepare($settings);
paging_ownership_check(file_exists($skipped), 'Malformed active config did not skip cleanup');
file_put_contents(PagingPromptOwnershipFixture::SETTINGS_JSON, json_encode($active));
file_put_contents(PagingPromptOwnershipFixture::PENDING_SETTINGS_JSON, '{');
$fixture->prepare($settings);
paging_ownership_check(file_exists($skipped), 'Malformed pending config did not skip cleanup');
unlink(PagingPromptOwnershipFixture::PENDING_SETTINGS_JSON);
symlink(PagingPromptOwnershipFixture::SETTINGS_JSON, PagingPromptOwnershipFixture::PENDING_SETTINGS_JSON);
$fixture->prepare($settings);
paging_ownership_check(file_exists($skipped), 'Unsafe pending config did not skip cleanup');
unlink(PagingPromptOwnershipFixture::PENDING_SETTINGS_JSON);
$fixture->prepare($settings);
clearstatcache();
paging_ownership_check(!file_exists($skipped), 'Absent pending config prevented safe cleanup');
echo "Temporary paging prompts passed asterisk ownership/read access and age/reference/link-safe cache cleanup checks; no speech engine or PBX services were used.\n";
