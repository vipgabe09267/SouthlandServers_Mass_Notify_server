<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Bounded catalogue of administrator-created FreePBX recordings. */
final class SystemRecordings
{
    public const FORMATS = ['wav', 'wav49', 'ulaw', 'alaw', 'gsm', 'sln', 'sln16', 'g722'];

    public static function validPath(string $relative): bool
    {
        if (strlen($relative) > 512 || !preg_match('#^(?:[A-Za-z0-9_-]+/)?custom/(?:[A-Za-z0-9_. -]+/){0,4}[A-Za-z0-9_. -]+\.(wav|wav49|ulaw|alaw|gsm|sln|sln16|g722)$#iD', $relative)) { return false; }
        foreach (explode('/', $relative) as $part) { if ($part === '.' || $part === '..') { return false; } }
        return true;
    }

    public static function catalogue(string $directory, array $labels = []): array
    {
        $root = realpath($directory);
        if ($root === false) { return []; }
        $root .= '/'; $seen = []; $count = 0; $deadline = microtime(true) + 2;
        $roots = array_merge(glob($root.'custom', GLOB_ONLYDIR) ?: [], glob($root.'*/custom', GLOB_ONLYDIR) ?: []);
        foreach ($roots as $custom) {
            if (is_link($custom)) { continue; }
            try {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($custom, \FilesystemIterator::SKIP_DOTS));
                $iterator->setMaxDepth(4);
                foreach ($iterator as $file) {
                    if (++$count > 5000 || microtime(true) > $deadline) { break 2; }
                    $relative = substr($file->getPathname(), strlen($root));
                    if (!self::validPath($relative) || $file->isLink() || !$file->isFile() || !$file->isReadable() || $file->getSize() < 1 || $file->getSize() > 20*1024*1024) { continue; }
                    $resolved = $file->getRealPath();
                    if (!$resolved || !str_starts_with($resolved, $root) || ($file->getExtension() === 'g722' && !is_executable('/usr/bin/ffmpeg'))) { continue; }
                    $base = preg_replace('/\.[^.]+$/', '', $relative); $key = strtolower($base);
                    $rank = array_search(strtolower($file->getExtension()), self::FORMATS, true);
                    if (isset($seen[$key]) && $seen[$key]['rank'] <= $rank) { continue; }
                    $seen[$key] = ['value'=>'system:'.$relative, 'label'=>$labels[$base] ?? $base, 'rank'=>$rank];
                }
            } catch (\UnexpectedValueException $error) { continue; }
        }
        uasort($seen, static fn($a,$b) => strnatcasecmp($a['label'], $b['label']));
        return array_map(static fn($r) => ['value'=>$r['value'], 'label'=>$r['label']], array_slice(array_values($seen), 0, 500));
    }

    /** Freeze source bytes: later System Recording uploads cannot alter a queued page. */
    public static function import(string $selection, string $directory, string $tones): string
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
        if (!is_dir($tones) || is_link($tones) || !is_writable($tones)) { throw new \InvalidArgumentException('The SLS tone directory is not writable. Run Repair Installation.'); }
        $base = $tones.'/.import-'.bin2hex(random_bytes(12)); $input = $base.'.source.'.$format; $output = $base.'.wav';
        try {
            $handle = fopen($input, 'x');
            if (!$handle) { throw new \RuntimeException('System Recording import could not allocate a private file.'); }
            chmod($input, 0600);
            try { if (fwrite($handle, $bytes) !== strlen($bytes)) { throw new \RuntimeException('System Recording import ran out of storage.'); } } finally { fclose($handle); }
            if ($format === 'g722') {
                if (!is_executable('/usr/bin/ffmpeg')) { throw new \InvalidArgumentException('G.722-only recordings need FFmpeg. Save a WAV version in System Recordings instead.'); }
                $command = '/usr/bin/timeout --kill-after=2 30 /usr/bin/ffmpeg -nostdin -v error -f g722 -i '.escapeshellarg($input).' -ac 1 -ar 8000 -c:a pcm_s16le '.escapeshellarg($output);
            } else {
                $raw = ['gsm'=>'-t gsm', 'ulaw'=>'-t ul -r 8000 -c 1', 'alaw'=>'-t al -r 8000 -c 1', 'sln'=>'-t raw -r 8000 -c 1 -e signed-integer -b 16 -L', 'sln16'=>'-t raw -r 16000 -c 1 -e signed-integer -b 16 -L'];
                $command = '/usr/bin/timeout --kill-after=2 30 /usr/bin/sox '.($raw[$format] ?? '-t wav').' '.escapeshellarg($input).' -r 8000 -c 1 -b 16 '.escapeshellarg($output);
            }
            exec($command.' 2>/dev/null', $unused, $code);
            if ($code !== 0 || !self::validCache($output)) { throw new \InvalidArgumentException('The System Recording could not be decoded. Upload a valid WAV recording and try again.'); }
            if (is_link($target) || !rename($output, $target)) { throw new \RuntimeException('The imported System Recording could not be saved. Check available storage and directory permissions.'); }
            chmod($target, 0644);
            return $name;
        } finally { @unlink($input); @unlink($output); }
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
