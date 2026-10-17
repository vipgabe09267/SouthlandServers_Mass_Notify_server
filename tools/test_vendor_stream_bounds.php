<?php
declare(strict_types=1);

require dirname(__DIR__).'/slsmassnotifyserver/identity-libs/vendor/autoload.php';

use GuzzleHttp\Psr7\DroppingStream;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;

$writes=0;
$unknown=FnStream::decorate(Utils::streamFor(''),[
    'getSize'=>static fn(): ?int=>null,
    'write'=>static function(string $value)use(&$writes):int{$writes+=strlen($value);return strlen($value);},
]);
$bounded=new DroppingStream($unknown,2);
try {
    $bounded->write('abc');
    throw new RuntimeException('DroppingStream accepted a target whose size is unknown.');
} catch (RuntimeException $error) {
    if (strpos($error->getMessage(),'unknown size')===false) { throw $error; }
}
if ($writes!==0) { throw new RuntimeException('DroppingStream wrote before proving its current size.'); }

$known=Utils::streamFor('a');$known->seek(0,SEEK_END);$bounded=new DroppingStream($known,3);
if ($bounded->write('bcde')!==2 || (string)$known!=='abc' || $bounded->write('z')!==0) {
    throw new RuntimeException('DroppingStream changed its known-size truncation contract.');
}

echo "Vendored stream bounds: unknown sizes fail closed and known sizes remain capped.\n";
