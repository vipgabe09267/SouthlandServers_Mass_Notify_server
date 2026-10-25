<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
ini_set('display_errors','0');
require dirname(__DIR__).'/OperatorPasskeys.php';
require dirname(__DIR__).'/api/sls-mass-notify/runtime-security.php';
try{
    if(!\SLS\MassNotify\PhpSecurityPolicy::evaluate(PHP_VERSION)['ok']){throw new RuntimeException('The PHP CLI security floor is not met.');}
    $raw=stream_get_contents(STDIN,65537);
    if(!is_string($raw)||strlen($raw)>65536){throw new RuntimeException('Verifier input exceeded its allocation.');}
    $input=\SLS\MassNotify\ApiSecurity::jsonObject($raw,false,24);
    echo json_encode(\SLS\MassNotify\OperatorPasskeys::workerVerification($input),JSON_THROW_ON_ERROR);
}catch(Throwable $error){fwrite(STDERR,"WebAuthn verification failed.\n");exit(1);}
