<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Bounded catalogue of administrator-created FreePBX recordings. */
final class SystemRecordings
{
    public const FORMATS = ['wav', 'wav49', 'ulaw', 'alaw', 'gsm', 'sln', 'sln16', 'g722'];
    private const MAX_DECODED_BYTES = 20 * 1024 * 1024;

    private static function unlinkSame(string $path, $identity): bool
    {
        if (!is_array($identity) || !isset($identity['dev'], $identity['ino'])) { return false; }
        clearstatcache(true, $path); $current = @lstat($path);
        return is_array($current) && ($current['mode'] & 0170000) === 0100000 && $current['nlink'] === 1
            && $current['dev'] === $identity['dev'] && $current['ino'] === $identity['ino'] && @unlink($path);
    }

    public static function validPath(string $relative): bool
    {
        if (strlen($relative) > 512 || !preg_match('#^(?:[A-Za-z0-9_-]+/)?custom/(?:[A-Za-z0-9_. -]+/){0,4}[A-Za-z0-9_. -]+\.(wav|wav49|ulaw|alaw|gsm|sln|sln16|g722)$#iD', $relative)) { return false; }
        foreach (explode('/', $relative) as $part) { if ($part === '.' || $part === '..') { return false; } }
        return true;
    }

    public static function catalogue(string $directory, array $labels = []): array
    {
        return self::cataloguePage($directory, $labels, '', 0, 500)['items'];
    }

    /** Read a bounded page; truncation is explicit instead of hiding recordings. */
    public static function cataloguePage(string $directory, array $labels = [], string $query = '', int $offset = 0, int $limit = 100, ?string $cacheFile = null): array
    {
        if ($offset < 0 || $offset > 5000 || $limit < 1 || $limit > 500 || strlen($query) > 200) {
            throw new \InvalidArgumentException('Recording search or page limits are invalid.');
        }
        $identity = hash('sha256', $directory . "\0" . json_encode($labels, JSON_THROW_ON_ERROR));
        $index = self::cachedCatalogue($cacheFile, $identity);
        if ($index === null) {
            $index = self::scanCatalogue($directory, $labels);
            self::saveCatalogue($cacheFile, $identity, $index);
        }
        $rows = $index['items']; $query = trim($query);
        if ($query !== '') {
            $rows = array_values(array_filter($rows, static fn($row) => stripos($row['label'], $query) !== false || stripos($row['value'], $query) !== false));
        }
        $total = count($rows); $end = min($total, $offset + $limit);
        return array_merge($index, ['items'=>array_slice($rows, $offset, $limit), 'total'=>$total,
            'offset'=>$offset, 'limit'=>$limit, 'has_more'=>$end < $total,
            'next_offset'=>$end < $total ? $end : null]);
    }

    private static function scanCatalogue(string $directory, array $labels): array
    {
        $root = realpath($directory);
        $result = ['items'=>[], 'scan_complete'=>false, 'truncated'=>false, 'observed_at'=>gmdate('c'), 'warning'=>''];
        if ($root === false) { $result['warning'] = 'The System Recordings directory is unavailable.'; return $result; }
        $root .= '/'; $seen = []; $count = 0; $deadline = microtime(true) + 2; $complete = true;
        $roots = array_merge(glob($root.'custom', GLOB_ONLYDIR) ?: [], glob($root.'*/custom', GLOB_ONLYDIR) ?: []);
        foreach ($roots as $custom) {
            if (is_link($custom)) { continue; }
            try {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($custom, \FilesystemIterator::SKIP_DOTS));
                $iterator->setMaxDepth(4);
                foreach ($iterator as $file) {
                    if (++$count > 5000 || microtime(true) > $deadline) { $complete = false; break 2; }
                    $relative = substr($file->getPathname(), strlen($root));
                    if (!self::validPath($relative) || $file->isLink() || !$file->isFile() || !$file->isReadable() || $file->getSize() < 1 || $file->getSize() > 20*1024*1024) { continue; }
                    $resolved = $file->getRealPath();
                    if (!$resolved || !str_starts_with($resolved, $root) || ($file->getExtension() === 'g722' && !is_executable('/usr/bin/ffmpeg'))) { continue; }
                    $base = preg_replace('/\.[^.]+$/', '', $relative); $key = strtolower($base);
                    $rank = array_search(strtolower($file->getExtension()), self::FORMATS, true);
                    if (isset($seen[$key]) && $seen[$key]['rank'] <= $rank) { continue; }
                    $label = $labels[$base] ?? $base;
                    if (!is_string($label) || !preg_match('//u', $label)) { $label = $base; }
                    $label = trim((string)preg_replace('/[^\P{C}\t]/u', '', $label));
                    if ($label === '') { $label = $base; }
                    $label = mb_strcut($label, 0, 240, 'UTF-8');
                    $seen[$key] = ['value'=>'system:'.$relative, 'label'=>$label, 'rank'=>$rank];
                }
            } catch (\UnexpectedValueException $error) { $complete = false; continue; }
        }
        uasort($seen, static fn($a,$b) => strnatcasecmp($a['label'], $b['label']));
        $result['items'] = array_map(static fn($r) => ['value'=>$r['value'], 'label'=>$r['label']], array_values($seen));
        $result['scan_complete'] = $complete; $result['truncated'] = !$complete;
        if (!$complete) { $result['warning'] = 'The recording scan reached its time or file limit. Some recordings are not listed; refine the recordings directory or retry after checking permissions.'; }
        return $result;
    }

    private static function cachedCatalogue(?string $path, string $identity): ?array
    {
        if ($path === null) { return null; }
        $info = @lstat($path);
        if (!$info || ($info['mode'] & 0170000) !== 0100000 || $info['nlink'] !== 1 || $info['size'] > 2*1024*1024 || ($info['mode'] & 0007) !== 0 || $info['mtime'] < time()-30 || $info['mtime'] > time()+1) { return null; }
        try { $raw = self::readSource($path); }
        catch (\InvalidArgumentException $error) { return null; }
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($value) || ($value['identity'] ?? '') !== $identity || !is_array($value['catalogue']['items'] ?? null)
            || count($value['catalogue']['items']) > 5000 || !is_bool($value['catalogue']['scan_complete'] ?? null)
            || !is_bool($value['catalogue']['truncated'] ?? null) || !is_string($value['catalogue']['observed_at'] ?? null)
            || !is_string($value['catalogue']['warning'] ?? null)) { return null; }
        foreach ($value['catalogue']['items'] as $row) {
            if (!is_array($row) || !is_string($row['value'] ?? null) || !is_string($row['label'] ?? null)
                || !str_starts_with($row['value'], 'system:') || !self::validPath(substr($row['value'], 7)) || strlen($row['label']) > 1000) { return null; }
        }
        return $value['catalogue'];
    }

    private static function saveCatalogue(?string $path, string $identity, array $index): void
    {
        if ($path === null) { return; }
        $directory = dirname($path); clearstatcache(true, $directory); $parent = @lstat($directory);
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        if (!is_array($parent) || ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0022)
            || !in_array($parent['uid'], [0, $euid], true) || realpath($directory) !== $directory) { return; }
        clearstatcache(true, $path); $existing = @lstat($path);
        if ($existing !== false && (($existing['mode'] & 0170000) !== 0100000 || $existing['nlink'] !== 1
            || ($existing['mode'] & 0027) || !in_array($existing['uid'], [0, $euid], true))) { return; }
        $raw = json_encode(['identity'=>$identity, 'catalogue'=>$index], JSON_THROW_ON_ERROR);
        if (strlen($raw) > 2*1024*1024) { return; }
        $temporary = $directory.'/.recordings-index-'.bin2hex(random_bytes(16));
        $mask = umask(0027);
        try { $handle = @fopen($temporary, 'x+b'); } finally { umask($mask); }
        if (!$handle) { return; }
        $temporaryIdentity = fstat($handle);
        try {
            if (fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) { return; }
            $opened = fstat($handle); clearstatcache(true, $temporary); $named = @lstat($temporary);
            clearstatcache(true, $path); $current = @lstat($path);
            if (!is_array($temporaryIdentity) || !is_array($opened) || !is_array($named)
                || ($opened['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0777) !== 0640
                || $opened['nlink'] !== 1 || $named['nlink'] !== 1
                || $opened['dev'] !== $temporaryIdentity['dev'] || $opened['ino'] !== $temporaryIdentity['ino']
                || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino'] || $opened['size'] !== strlen($raw)
                || (($existing === false) !== ($current === false))
                || ($existing !== false && ($existing['dev'] !== $current['dev'] || $existing['ino'] !== $current['ino']))) { return; }
            if (!@rename($temporary, $path)) { return; }
            clearstatcache(true, $path); $committed = @lstat($path);
            if (!is_array($committed) || $committed['dev'] !== $temporaryIdentity['dev']
                || $committed['ino'] !== $temporaryIdentity['ino'] || $committed['nlink'] !== 1
                || $committed['size'] !== strlen($raw)) { return; }
            $directoryHandle = @fopen($directory, 'r');
            if ($directoryHandle) { try { if (function_exists('fsync')) { @fsync($directoryHandle); } } finally { fclose($directoryHandle); } }
        } finally { fclose($handle); self::unlinkSame($temporary, $temporaryIdentity); }
    }

    /** Freeze source bytes: later System Recording uploads cannot alter a queued page. */
    public static function import(string $selection, string $directory, string $tones, ?callable $admission = null): string
    {
        if (!str_starts_with($selection, 'system:') || !self::validPath($relative = substr($selection, 7))) { throw new \InvalidArgumentException('The selected System Recording path is invalid.'); }
        $root = realpath($directory); $source = realpath($directory.'/'.$relative);
        if (!$root || !$source || !str_starts_with($source, $root.'/') || is_link($directory.'/'.$relative)) { throw new \InvalidArgumentException('The selected System Recording is unavailable. Refresh the recordings list.'); }
        $bytes = self::readSource($source);
        $format = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $name = 'recording_'.substr(hash('sha256', $relative."\0".$bytes), 0, 32);
        $target = $tones.'/'.$name.'.wav';
        clearstatcache(true, $target);
        $cached = @lstat($target);
        if ($cached && ($cached['mode'] & 0170000) === 0100000 && $cached['nlink'] === 1
            && $cached['size'] >= 44 && $cached['size'] <= 20*1024*1024 && self::validCache($target)) { return $name; }
        clearstatcache(true, $tones); $tonesMeta = @lstat($tones); $euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        if (!is_array($tonesMeta) || ($tonesMeta['mode'] & 0170000) !== 0040000 || ($tonesMeta['mode'] & 0022)
            || !in_array($tonesMeta['uid'], [0, $euid], true) || realpath($tones) !== $tones || !is_writable($tones)) {
            throw new \InvalidArgumentException('The SLS tone directory is not writable or privately owned. Run Repair Installation.');
        }
        // The optional callback is a trusted local dependency for disposable
        // media tests. No request/API input supplies this argument.
        $workspace = strlen($bytes) + self::MAX_DECODED_BYTES;
        if ($admission !== null) { $admission($workspace); }
        else { self::reserveGeneratedRecording($workspace); }
        $base = $tones.'/.import-'.bin2hex(random_bytes(16)); $input = $base.'.source.'.$format; $output = $base.'.wav';
        $inputIdentity = null; $outputIdentity = null;
        try {
            $mask = umask(0077);
            try { $handle = fopen($input, 'x+b'); } finally { umask($mask); }
            if (!$handle) { throw new \RuntimeException('System Recording import could not allocate a private file.'); }
            $inputIdentity = fstat($handle);
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)
                    || (function_exists('fsync') && !fsync($handle))) { throw new \RuntimeException('System Recording import ran out of storage.'); }
            } finally { fclose($handle); }
            clearstatcache(true, $input); $stagedInput = @lstat($input);
            if (!is_array($inputIdentity) || !is_array($stagedInput) || ($stagedInput['mode'] & 0170000) !== 0100000
                || ($stagedInput['mode'] & 0777) !== 0600 || $stagedInput['nlink'] !== 1 || $stagedInput['dev'] !== $inputIdentity['dev']
                || $stagedInput['ino'] !== $inputIdentity['ino'] || $stagedInput['size'] !== strlen($bytes)) {
                throw new \RuntimeException('System Recording import staging changed unexpectedly.');
            }
            if ($format === 'g722') {
                if (!is_executable('/usr/bin/ffmpeg')) { throw new \InvalidArgumentException('G.722-only recordings need FFmpeg. Save a WAV version in System Recordings instead.'); }
                $command = ['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-f', 'g722', '-i', $input, '-ac', '1', '-ar', '8000', '-c:a', 'pcm_s16le', $output];
            } else {
                $raw = ['gsm'=>['-t','gsm'], 'ulaw'=>['-t','ul','-r','8000','-c','1'], 'alaw'=>['-t','al','-r','8000','-c','1'],
                    'sln'=>['-t','raw','-r','8000','-c','1','-e','signed-integer','-b','16','-L'],
                    'sln16'=>['-t','raw','-r','16000','-c','1','-e','signed-integer','-b','16','-L']];
                $command = array_merge(['/usr/bin/sox'], $raw[$format] ?? ['-t','wav'], [$input,'-r','8000','-c','1','-b','16',$output]);
            }
            $code = self::convertBounded($command);
            clearstatcache(true, $output); $outputIdentity = @lstat($output);
            if (is_array($outputIdentity) && ($outputIdentity['mode'] & 0170000) === 0100000
                && $outputIdentity['nlink'] === 1 && $outputIdentity['size'] >= self::MAX_DECODED_BYTES) {
                throw new \InvalidArgumentException('Decoded System Recording exceeds the 20 MiB audio limit. Shorten the recording before importing it. No partial recording was saved.');
            }
            if ($code !== 0 || !is_array($outputIdentity) || ($outputIdentity['mode'] & 0170000) !== 0100000
                || $outputIdentity['nlink'] !== 1 || $outputIdentity['size'] < 44 || $outputIdentity['size'] >= self::MAX_DECODED_BYTES
                || !self::validCache($output)) { throw new \InvalidArgumentException('The System Recording could not be decoded. Upload a valid WAV recording and try again.'); }
            clearstatcache(true, $output); $verifiedOutput = @lstat($output);
            clearstatcache(true, $target); $targetAtCommit = @lstat($target);
            if (!is_array($verifiedOutput) || $verifiedOutput['dev'] !== $outputIdentity['dev'] || $verifiedOutput['ino'] !== $outputIdentity['ino']
                || (($cached === false) !== ($targetAtCommit === false))
                || ($cached !== false && ($cached['dev'] !== $targetAtCommit['dev'] || $cached['ino'] !== $targetAtCommit['ino']))
                || is_link($target) || !chmod($output, 0644) || !rename($output, $target)) {
                throw new \RuntimeException('The imported System Recording could not be saved. Check available storage and directory permissions.');
            }
            clearstatcache(true, $target); $committed = @lstat($target);
            if (!is_array($committed) || $committed['dev'] !== $outputIdentity['dev'] || $committed['ino'] !== $outputIdentity['ino']
                || $committed['nlink'] !== 1 || ($committed['mode'] & 0777) !== 0644) {
                throw new \RuntimeException('The imported System Recording changed during activation. Preserve the tone directory for review.');
            }
            $directoryHandle = @fopen($tones, 'r');
            try { if (!$directoryHandle || (function_exists('fsync') && !fsync($directoryHandle))) { throw new \RuntimeException('The imported System Recording directory could not be synchronized.'); } }
            finally { if ($directoryHandle) { fclose($directoryHandle); } }
            return $name;
        } finally { self::unlinkSame($input, $inputIdentity); self::unlinkSame($output, $outputIdentity); }
    }

    private static function convertBounded(array $command): int
    {
        // The kernel limit applies while decoding, including compressed inputs.
        // Both codecs write one output file; timeout also bounds their process group.
        $program = <<<'PY'
import os, resource, signal, sys
limit = int(sys.argv[1])
resource.setrlimit(resource.RLIMIT_FSIZE, (limit, limit))
resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
resource.setrlimit(resource.RLIMIT_CPU, (25, 25))
nofile_soft, nofile_hard = resource.getrlimit(resource.RLIMIT_NOFILE)
nofile = min(64, nofile_hard) if nofile_hard != resource.RLIM_INFINITY else 64
resource.setrlimit(resource.RLIMIT_NOFILE, (nofile, nofile))
if hasattr(resource, 'RLIMIT_AS'):
    memory_soft, memory_hard = resource.getrlimit(resource.RLIMIT_AS)
    memory = 1024 * 1024 * 1024
    if memory_hard != resource.RLIM_INFINITY:
        memory = min(memory, memory_hard)
    resource.setrlimit(resource.RLIMIT_AS, (memory, memory))
signal.signal(signal.SIGXFSZ, signal.SIG_DFL)
os.execv(sys.argv[2], sys.argv[2:])
PY;
        $process = @proc_open(array_merge(['/usr/bin/timeout', '--kill-after=2', '30', '/usr/bin/python3', '-I', '-c', $program, (string)self::MAX_DECODED_BYTES], $command),
            [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes, null, null, ['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new \RuntimeException('Bounded recording conversion could not start. Check Python, SoX and web PHP process permissions.'); }
        $code = proc_close($process);
        $output = end($command); clearstatcache(true, $output);
        // Some codec versions report success after an EFBIG write. A reached
        // kernel bound is always rejected, never accepted as a shorter recording.
        return is_file($output) && filesize($output) >= self::MAX_DECODED_BYTES ? 65 : $code;
    }

    private static function reserveGeneratedRecording(int $bytes): void
    {
        $helper = '/usr/local/bin/sls_mass_notify/sls_speech_admission.py'; $info = @lstat($helper);
        if (!$info || ($info['mode'] & 0170000) !== 0100000 || $info['nlink'] !== 1 || $info['uid'] !== 0 || ($info['mode'] & 0022)) {
            throw new \RuntimeException('The protected generated-media admission helper is unavailable. Run Repair Installation before importing recordings.');
        }
        $process = @proc_open(['/usr/bin/timeout', '--kill-after=1', '8', '/usr/bin/python3', '-I', $helper, '--reserve-media-bytes', (string)$bytes],
            [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new \RuntimeException('Generated-media admission could not start. Check the protected runtime installation.'); }
        stream_get_contents($pipes[2], 4096); fclose($pipes[2]); $code = proc_close($process);
        if ($code !== 0) {
            throw new \RuntimeException($code === 73 ? 'The recording import needs more generated-media budget or free disk space. Review Storage settings and queued alerts.'
                : ($code === 75 ? 'Generated-media reservation capacity is full. Wait for current renders to finish.' : 'Generated-media admission failed. Check Storage diagnostics and runtime permissions.'));
        }
    }

    private static function validCache(string $path): bool
    {
        try { $bytes = self::readSource($path); }
        catch (\InvalidArgumentException $error) { return false; }
        $length = strlen($bytes);
        if ($length < 44 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE'
            || unpack('V', substr($bytes, 4, 4))[1] + 8 !== $length) { return false; }
        $offset = 12; $format = false; $data = false; $chunks = 0;
        while ($offset + 8 <= $length && ++$chunks <= 64) {
            $kind = substr($bytes, $offset, 4); $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $start = $offset + 8; $end = $start + $size;
            if ($end > $length) { return false; }
            if ($kind === 'fmt ') {
                if ($format || $size < 16) { return false; }
                $pcm = unpack('vformat/vchannels/Vrate/Vbytes/vblock/vbits', substr($bytes, $start, 16));
                if ($pcm !== ['format'=>1, 'channels'=>1, 'rate'=>8000, 'bytes'=>16000, 'block'=>2, 'bits'=>16]) { return false; }
                $format = true;
            } elseif ($kind === 'data') {
                if ($data || $size < 2 || $size % 2 !== 0) { return false; }
                $data = true;
            }
            $offset = $end + ($size % 2);
        }
        return $format && $data && $offset === $length;
    }

    /** Nonblocking, read-only descriptor capture also supports administrator-owned recordings. */
    private static function readSource(string $source): string
    {
        $program = <<<'PY'
import fcntl
import os
import stat
import sys

parent = fd = None
try:
    parts = sys.argv[1].split('/')[1:]
    parent = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    for part in parts[:-1]:
        child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
        os.close(parent)
        parent = child
    fd = os.open(parts[-1], os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
    before = os.fstat(fd)
    if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or not 0 < before.st_size <= 20 * 1024 * 1024:
        raise ValueError('Invalid recording file')
    fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
    chunks = []
    remaining = before.st_size
    while remaining:
        block = os.read(fd, min(65536, remaining))
        if not block:
            raise ValueError('Recording read incomplete')
        chunks.append(block)
        remaining -= len(block)
    after = os.fstat(fd)
    named = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
    fields = ('st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    if os.read(fd, 1) or any(getattr(before, key) != getattr(after, key) or getattr(after, key) != getattr(named, key) for key in fields):
        raise ValueError('Recording changed during capture')
    sys.stdout.buffer.write(b''.join(chunks))
    sys.stdout.buffer.flush()
finally:
    for handle in (fd, parent):
        if handle is not None:
            os.close(handle)
PY;
        $process = @proc_open(['/usr/bin/timeout', '--kill-after=1', '5', '/usr/bin/python3', '-I', '-c', $program, $source],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']], $pipes, null, null, ['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new \InvalidArgumentException('System Recording capture is unavailable. Check Python and file permissions.'); }
        $bytes = stream_get_contents($pipes[1], 20 * 1024 * 1024 + 1); fclose($pipes[1]); $status = proc_close($process);
        if ($status !== 0 || !is_string($bytes) || strlen($bytes) < 1 || strlen($bytes) > 20 * 1024 * 1024) {
            throw new \InvalidArgumentException('The System Recording could not be read safely. It must be a regular, single-link file between 1 byte and 20 MiB; refresh after checking permissions, locks or storage.');
        }
        return $bytes;
    }
}
