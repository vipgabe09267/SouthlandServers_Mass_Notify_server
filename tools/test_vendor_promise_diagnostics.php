<?php
declare(strict_types=1);

require dirname(__DIR__).'/slsmassnotifyserver/identity-libs/vendor/autoload.php';

use GuzzleHttp\Promise\RejectionException;

$control=new RejectionException("first\nforged\0record");
if($control->getMessage()!=='The promise was rejected with reason: first\\x0Aforged\\x00record'){
    throw new RuntimeException('Promise rejection message retained raw control bytes.');
}
$description=new RejectionException('reason',"description\rline");
if($description->getMessage()!=='The promise was rejected with reason: description\\x0Dline'){
    throw new RuntimeException('Explicit rejection description was not escaped.');
}
$throwing=new class {public function __toString():string{throw new RuntimeException('private formatter failure');}};
$failure=new RejectionException($throwing);
if($failure->getReason()!==$throwing||$failure->getMessage()!=='The promise was rejected with an unprintable reason'){
    throw new RuntimeException('Throwing rejection formatter escaped or replaced the original reason.');
}
$jsonFailure=new RejectionException(new class implements JsonSerializable {public function jsonSerialize(): mixed{throw new RuntimeException('private JSON failure');}});
if($jsonFailure->getMessage()!=='The promise was rejected with an unprintable reason'){
    throw new RuntimeException('Throwing rejection JSON formatter escaped the diagnostic boundary.');
}
$bytes=new RejectionException("utf8-\xc3\xa9-invalid-\xff");
if($bytes->getMessage()!=='The promise was rejected with reason: utf8-\\xC3\\xA9-invalid-\\xFF'){
    throw new RuntimeException('Promise rejection message retained non-ASCII or invalid bytes.');
}
$long=new RejectionException(str_repeat('x',1025));
if(strlen($long->getMessage())!==strlen('The promise was rejected with reason: ')+1027||substr($long->getMessage(),-3)!=='...'){
    throw new RuntimeException('Promise rejection diagnostic bound changed.');
}

echo "Vendored promise diagnostics: controls, formatter failures and length bounds passed.\n";
