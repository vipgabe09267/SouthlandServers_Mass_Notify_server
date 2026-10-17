<?php

declare(strict_types=1);

require dirname(__DIR__) . '/slsmassnotifyserver/identity-libs/vendor/autoload.php';

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Crypt\RSA;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SFTP\Stream as SftpStream;
use phpseclib3\Net\SSH2;
use phpseclib3\System\SSH\Common\Traits\ReadBytes;

function require_ssh(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function require_ssh_rejection(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $expected) {
        return;
    }
    throw new RuntimeException($message);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class SlsShortReadStream
{
    public $context;
    private $data = '';
    private $offset = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->data = (string) parse_url($path, PHP_URL_HOST);
        return true;
    }

    public function stream_read(int $count): string
    {
        return substr($this->data, $this->offset++, 1);
    }

    public function stream_eof(): bool
    {
        return $this->offset >= strlen($this->data);
    }

    public function stream_stat(): array
    {
        return [];
    }
}

final class SlsReadBytesFixture
{
    use ReadBytes;
    public $fsock;
}

final class SlsSftpAttributeFixture extends SFTP
{
    public function __construct()
    {
        parent::__construct(fopen('php://temp', 'r+'));
    }

    public function parseFixture(string &$response): array
    {
        return parent::parseAttributes($response);
    }
}

final class SlsSshPacketFixture extends SSH2
{
    public function __construct()
    {
        parent::__construct(fopen('php://temp', 'r+'));
    }

    public function sendWithZeroPacketSize(): void
    {
        $channel = self::CHANNEL_EXEC;
        $this->packet_size_client_to_server[$channel] = 0;
        $this->send_channel_packet($channel, 'x');
    }

    public function resetFixture(): void
    {
        $this->reset_connection();
    }

    public function inflateFixture(string $compressed): string
    {
        $property = new ReflectionProperty(SSH2::class, 'decompress_context');
        $property->setValue($this, inflate_init(ZLIB_ENCODING_RAW));
        $method = new ReflectionMethod(SSH2::class, 'inflatePacketBounded');
        return $method->invoke($this, $compressed);
    }

    public function appendErrorFixture(string $message): void
    {
        $method = new ReflectionMethod(SSH2::class, 'appendError');
        $method->invoke($this, $message);
    }
}

final class SlsSftpStreamFixture extends SftpStream
{
    public function parseFixture(string $path)
    {
        return $this->parse_path($path);
    }
}

stream_wrapper_register('slsshortread', SlsShortReadStream::class);
try {
    $reader = new SlsReadBytesFixture();
    $reader->fsock = fopen('slsshortread://abcdef', 'r');
    require_ssh($reader->readBytes(6) === 'abcdef', 'SSH agent reads did not tolerate normal partial stream reads.');
    try {
        $reader->readBytes(16777217);
        throw new RuntimeException('An oversized SSH agent message was accepted.');
    } catch (RuntimeException $expected) {
        if ($expected->getMessage() === 'An oversized SSH agent message was accepted.') {
            throw $expected;
        }
    }
} finally {
    stream_wrapper_unregister('slsshortread');
}

$sftp = new SlsSftpAttributeFixture();
$version = new ReflectionProperty(SFTP::class, 'version');
$version->setValue($sftp, 4);
$acl = pack('NCN', 0x00000040, 1, 1) .
    Strings::packSSH2('N3s', 1, 2, 3, 'user');
$sftp->parseFixture($acl);
require_ssh($acl === '', 'SFTP ACL parsing did not consume the supplied response bytes.');
$ownerGroup = pack('NC', 0x00000080, 1) . Strings::packSSH2('ss', 'owner', 'group');
$attributes = $sftp->parseFixture($ownerGroup);
require_ssh(
    ($attributes['owner'] ?? null) === 'owner' && ($attributes['group'] ?? null) === 'group' && !isset($attributes['$group']),
    'SFTP owner/group attributes were parsed under incorrect keys.'
);
$version->setValue($sftp, 6);
$extended = pack('NCN', 0x80000000, 1, 5000);
require_ssh_rejection(
    static fn() => $sftp->parseFixture($extended),
    'SFTP accepted an impossible extended-attribute count.'
);

$packetBuffer = new ReflectionProperty(SFTP::class, 'packet_buffer');
$getSftpPacket = new ReflectionMethod(SFTP::class, 'get_sftp_packet');
$packetBuffer->setValue($sftp, pack('N', 0));
require_ssh_rejection(
    static fn() => $getSftpPacket->invoke($sftp),
    'SFTP accepted a zero-length packet.'
);
$useRequestId = new ReflectionProperty(SFTP::class, 'use_request_id');
$requestBuffer = new ReflectionProperty(SFTP::class, 'requestBuffer');
$useRequestId->setValue($sftp, true);
$pending = [];
for ($index = 100; $index < 164; ++$index) {
    $pending[$index] = ['packet_type' => NET_SFTP_STATUS, 'packet' => ''];
}
$requestBuffer->setValue($sftp, $pending);
$packetBuffer->setValue($sftp, pack('NCN', 5, NET_SFTP_STATUS, 1));
require_ssh_rejection(
    static fn() => $getSftpPacket->invoke($sftp, 999),
    'SFTP retained more than the bounded number of out-of-order responses.'
);

$recursiveFilesize = new ReflectionMethod(SFTP::class, 'recursiveFilesize');
$nested = ['leaf' => (object) ['size' => 1]];
for ($index = 0; $index < 66; ++$index) {
    $nested = ['directory' => $nested];
}
require_ssh_rejection(
    static fn() => $recursiveFilesize->invoke(null, $nested),
    'SFTP recursive size calculation accepted an excessive directory depth.'
);

$logError = new ReflectionMethod(SFTP::class, 'logError');
$logError->invoke($sftp, pack('N', 999) . Strings::packSSH2('s', 'unknown status'));
require_ssh(
    str_contains($sftp->getLastSFTPError(), 'NET_SFTP_STATUS_UNKNOWN (999)'),
    'SFTP did not safely label an unknown server status code.'
);

try {
    (new SlsSshPacketFixture())->sendWithZeroPacketSize();
    throw new RuntimeException('A zero remote SSH channel packet size was accepted.');
} catch (RuntimeException $expected) {
    if ($expected->getMessage() === 'A zero remote SSH channel packet size was accepted.') {
        throw $expected;
    }
}

$sshState = new SlsSshPacketFixture();
require_ssh_rejection(static fn() => $sshState->setTimeout(-1), 'SSH accepted a negative timeout.');
require_ssh_rejection(static fn() => $sshState->setKeepAlive(INF), 'SSH accepted an infinite keep-alive interval.');
require_ssh_rejection(static fn() => new SSH2('invalid host', 22), 'SSH accepted a hostname containing whitespace.');
require_ssh_rejection(static fn() => new SSH2('example.com', 0), 'SSH accepted an invalid port.');

$channelCount = new ReflectionProperty(SSH2::class, 'channelCount');
$errorOnMultipleChannels = new ReflectionProperty(SSH2::class, 'errorOnMultipleChannels');
$openChannel = new ReflectionMethod(SSH2::class, 'open_channel');
$channelCount->setValue($sshState, 1);
$errorOnMultipleChannels->setValue($sshState, true);
require_ssh_rejection(
    static fn() => $openChannel->invoke($sshState, SSH2::CHANNEL_SHELL),
    'SSH accepted a prohibited second channel.'
);
require_ssh($channelCount->getValue($sshState) === 1, 'A rejected SSH channel open corrupted the open-channel count.');

$deflater = deflate_init(ZLIB_ENCODING_RAW);
$compressedBomb = deflate_add($deflater, str_repeat('A', 16777217), ZLIB_FINISH);
require_ssh(is_string($compressedBomb), 'Unable to build the compressed SSH boundary fixture.');
require_ssh_rejection(
    static fn() => $sshState->inflateFixture($compressedBomb),
    'SSH accepted a compressed packet whose expanded form exceeds the retention limit.'
);

for ($index = 0; $index < 300; ++$index) {
    $sshState->appendErrorFixture(str_repeat((string) ($index % 10), 9000));
}
$boundedErrors = $sshState->getErrors();
require_ssh(
    count($boundedErrors) <= 128 && strlen((string) $boundedErrors[array_key_last($boundedErrors)]) === 8192,
    'SSH retained an unbounded remote diagnostic history.'
);
$sshState->appendErrorFixture("forged\n\x1B[2J\0entry");
$escapedErrors = $sshState->getErrors();
require_ssh(
    $escapedErrors[array_key_last($escapedErrors)] === 'forged\\x0A\\x1B[2J\\x00entry',
    'SSH retained terminal or record-separator controls in a remote diagnostic.'
);

$filterDepth = new ReflectionProperty(SSH2::class, 'filterDepth');
$filter = new ReflectionMethod(SSH2::class, 'filter');
$filterDepth->setValue($sshState, 64);
require_ssh_rejection(
    static fn() => $filter->invoke($sshState, chr(NET_SSH2_MSG_UNIMPLEMENTED)),
    'SSH accepted excessive nested control-packet processing.'
);
require_ssh($filterDepth->getValue($sshState) === 64, 'SSH control-packet depth was not restored after rejection.');

$channelBufferBytes = new ReflectionProperty(SSH2::class, 'channelBufferBytes');
$bufferChannel = new ReflectionMethod(SSH2::class, 'buffer_channel_packet');
$channelBufferBytes->setValue($sshState, 16777216);
require_ssh_rejection(
    static fn() => $bufferChannel->invoke($sshState, SSH2::CHANNEL_EXEC, 'x'),
    'SSH accepted data beyond the cross-channel buffer limit.'
);

$channelCount->setValue($sshState, 4);
$channelBufferBytes->setValue($sshState, 99);
$sshState->resetFixture();
require_ssh(
    $channelCount->getValue($sshState) === 0 && $channelBufferBytes->getValue($sshState) === 0,
    'SSH connection reset retained channel counters or buffered-byte state.'
);

$cacheKey = new ReflectionMethod(SftpStream::class, 'credentialCacheKey');
$firstCacheKey = $cacheKey->invoke(null, 'plain-text-secret');
$secondCacheKey = $cacheKey->invoke(null, 'plain-text-secret');
require_ssh(
    is_string($firstCacheKey) && $firstCacheKey === $secondCacheKey &&
        !str_contains($firstCacheKey, 'plain-text-secret'),
    'The SFTP stream cache exposed or inconsistently keyed a plaintext password.'
);
$firstPrivateKey = RSA::createKey(1024);
$secondPrivateKey = RSA::createKey(1024);
$firstObjectCacheKey = $cacheKey->invoke(null, $firstPrivateKey);
require_ssh(
    $firstObjectCacheKey === $cacheKey->invoke(null, $firstPrivateKey) &&
        $firstObjectCacheKey !== $cacheKey->invoke(null, $secondPrivateKey) &&
        !str_contains($firstObjectCacheKey, 'BEGIN PRIVATE KEY'),
    'The SFTP stream cache did not bind private-key credentials by secret key material.'
);
$stream = new SlsSftpStreamFixture();
require_ssh(
    $stream->parseFixture('') === false && $stream->parseFixture(str_repeat('x', 8193)) === false,
    'The SFTP stream wrapper accepted an empty or oversized path.'
);
$streamPosition = new ReflectionProperty(SftpStream::class, 'pos');
$streamSize = new ReflectionProperty(SftpStream::class, 'size');
$streamPosition->setValue($stream, 0);
$streamSize->setValue($stream, 10);
require_ssh(
    $stream->stream_seek(-1, SEEK_CUR) === false && $stream->stream_seek(0, 9999) === false,
    'The SFTP stream wrapper accepted a negative or invalid seek.'
);
require_ssh($stream->stream_truncate(-1) === false, 'The SFTP stream wrapper accepted a negative truncate size.');

restore_error_handler();
echo "phpseclib SSH/SFTP framing and parser boundary checks passed.\n";
