<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/PasskeyConfig.php';
require_once __DIR__.'/AdvertisedAddress.php';
require_once __DIR__.'/api/sls-mass-notify/security.php';

/** Optional verified-user second factor for the SLS operator portal only. */
final class OperatorPasskeys
{
    private static function serializer(): \Symfony\Component\Serializer\SerializerInterface
    {
        require_once __DIR__.'/passkey-libs/vendor/autoload.php';
        $support=new \Webauthn\AttestationStatement\AttestationStatementSupportManager([new \Webauthn\AttestationStatement\NoneAttestationStatementSupport()]);
        return (new \Webauthn\Denormalizer\WebauthnSerializerFactory($support))->create();
    }
    public static function origin(array $settings): array
    {
        $address=\FreePBX\modules\SlsAdvertisedAddress::fields($settings);
        $host=\FreePBX\modules\SlsAdvertisedAddress::host($address['host']);
        if(filter_var($host,FILTER_VALIDATE_IP)||!str_contains($host,'.')){throw new \DomainException('Operator passkeys require a saved DNS hostname and HTTPS advertised address.');}
        return ['origin'=>\FreePBX\modules\SlsAdvertisedAddress::url('https',$host,$address['api_port'],''),'rp_id'=>$host];
    }
    private static function handle(array $account): string {return hash('sha256','SLS_OPERATOR_PASSKEY_V1|'.$account['id'],true);}
    private static function encoded(string $value): string {return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
    private static function decoded($value,int $maximum): string
    {
        if(!is_string($value)||$value===''||strlen($value)>intdiv($maximum*8+5,6)||!preg_match('/^[A-Za-z0-9_-]+$/D',$value)){throw new \DomainException('The passkey response has invalid base64url data.');}
        $raw=base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);
        if(!is_string($raw)||strlen($raw)>$maximum||self::encoded($raw)!==$value){throw new \DomainException('The passkey response encoding is invalid.');}
        return $raw;
    }
    public static function begin(array $settings,array $account,string $purpose,int $now): array
    {
        if(($settings['operator_access']['portal_enabled']??false)!==true||($settings['operator_access']['passkeys_enabled']??false)!==true||($account['source']??'')!=='portal'
            ||!OperatorAuth::ready($account)||!OperatorAccess::activeAccount($account,$now)||empty($account['enabled'])
            ||!in_array($purpose,['register','authenticate'],true)){throw new \DomainException('Passkeys are unavailable for this operator login.');}
        $keys=PasskeyConfig::normalize($account['auth']['passkeys']??[]);
        if(($purpose==='register'&&count($keys)>=5)||($purpose==='authenticate'&&!$keys)){throw new \DomainException('Enroll an available passkey or remove an old one before continuing.');}
        $origin=self::origin($settings);$challenge=self::encoded(random_bytes(32));
        $descriptors=array_map(static fn($row)=>['type'=>'public-key','id'=>$row['credential_id']],$keys);
        $options=['challenge'=>$challenge,'timeout'=>120000];
        if($purpose==='register'){
            $options+=['rp'=>['name'=>'SLS Mass Notify','id'=>$origin['rp_id']],
                'user'=>['name'=>$account['username'],'displayName'=>$account['name'],'id'=>self::encoded(self::handle($account))],
                'pubKeyCredParams'=>[['type'=>'public-key','alg'=>-7],['type'=>'public-key','alg'=>-257]],
                'attestation'=>'none','excludeCredentials'=>$descriptors,'authenticatorSelection'=>['userVerification'=>'required','residentKey'=>'discouraged']];
        }else{$options+=['rpId'=>$origin['rp_id'],'userVerification'=>'required','allowCredentials'=>$descriptors];}
        return ['public_key'=>$options,'state'=>['purpose'=>$purpose,'account_id'=>$account['id'],'identity'=>OperatorAuth::identity($account),
            'origin'=>$origin['origin'],'rp_id'=>$origin['rp_id'],'created_at'=>$now,'expires_at'=>$now+120,'options'=>json_encode($options,JSON_THROW_ON_ERROR)]];
    }
    private static function check(array $settings,array $account,array $state,string $purpose,int $now): void
    {
        $origin=self::origin($settings);
        if(($settings['operator_access']['portal_enabled']??false)!==true||($settings['operator_access']['passkeys_enabled']??false)!==true||($account['source']??'')!=='portal'
            ||empty($account['enabled'])||!OperatorAccess::activeAccount($account,$now)||!OperatorAuth::ready($account)
            ||($state['purpose']??'')!==$purpose||($state['account_id']??'')!==$account['id']
            ||!is_string($state['identity']??null)||!hash_equals(OperatorAuth::identity($account),$state['identity'])
            ||($state['origin']??'')!==$origin['origin']||($state['rp_id']??'')!==$origin['rp_id']
            ||!is_int($state['created_at']??null)||$state['created_at']>$now||($state['expires_at']??0)!==$state['created_at']+120||$state['expires_at']<=$now
            ||!is_string($state['options']??null)||strlen($state['options'])>16384){throw new \DomainException('The passkey challenge expired or this login changed. Start again.');}
    }
    private static function response(string $json,array $state,string $type): array
    {
        if(strlen($json)>32768){throw new \DomainException('The passkey response exceeds 32 KiB.');}
        $row=ApiSecurity::jsonObject($json,false,16);
        if(array_diff(array_keys($row),['id','rawId','type','response','clientExtensionResults','authenticatorAttachment'])
            ||($row['type']??'')!=='public-key'||!is_string($row['id']??null)||($row['rawId']??null)!==$row['id']
            ||!is_array($row['response']??null)||!is_array($row['clientExtensionResults']??[])||($row['clientExtensionResults']??[])!==[]){throw new \DomainException('The passkey response has unsupported credential fields.');}
        self::decoded($row['id'],1023);
        $allowed=$type==='webauthn.create'?['clientDataJSON','attestationObject','transports']:['clientDataJSON','authenticatorData','signature','userHandle'];
        if(array_diff(array_keys($row['response']),$allowed)){throw new \DomainException('The passkey response contains unexpected authenticator fields.');}
        if($type==='webauthn.create'){
            self::decoded($row['response']['attestationObject']??null,16384);
            $transports=$row['response']['transports']??[];
            if(!is_array($transports)||!array_is_list($transports)||count($transports)>6||array_diff($transports,['usb','nfc','ble','internal','hybrid','smart-card'])){throw new \DomainException('The passkey transport list is invalid.');}
        }else{
            if(strlen(self::decoded($row['response']['authenticatorData']??null,16384))<37){throw new \DomainException('The passkey authenticator data is incomplete.');}
            self::decoded($row['response']['signature']??null,1024);
            if(isset($row['response']['userHandle'])){self::decoded($row['response']['userHandle'],64);}
        }
        $client=ApiSecurity::jsonObject(self::decoded($row['response']['clientDataJSON']??null,8192),false,8);
        $options=ApiSecurity::jsonObject($state['options'],false,16);
        if(($client['type']??'')!==$type||($client['origin']??'')!==$state['origin']||($client['challenge']??'')!==($options['challenge']??null)
            ||(array_key_exists('crossOrigin',$client)&&$client['crossOrigin']!==false)||array_key_exists('topOrigin',$client)||array_key_exists('tokenBinding',$client)){
            throw new \DomainException('The passkey origin, challenge or browser context is invalid.');
        }
        return $row;
    }
    private static function factory(array $state): \Webauthn\CeremonyStep\CeremonyStepManagerFactory
    {
        $factory=new \Webauthn\CeremonyStep\CeremonyStepManagerFactory();$factory->setAllowedOrigins([$state['origin']],false);return $factory;
    }
    private static function verifyInWorker(array $input): array
    {
        // FreePBX and its modules can load older PSR/Symfony classes. Keep the
        // pinned verifier in its own process rather than replacing their classes.
        if(!function_exists('proc_open')){throw new \DomainException('This PHP runtime cannot start the isolated passkey verifier. Use your authenticator code.');}
        $php=realpath('/usr/bin/php');$metadata=$php?@lstat($php):false;
        if(!$metadata||($metadata['mode']&0170000)!==0100000||($metadata['mode']&0022)||$metadata['uid']!==0||!is_executable($php)){
            throw new \DomainException('The trusted PHP CLI verifier is unavailable. Use your authenticator code and ask an administrator to repair PHP CLI.');
        }
        $command=[$php,'-d','auto_prepend_file=','-d','auto_append_file=','-d','opcache.enable_cli=0','-d','memory_limit=96M',
            '-d','allow_url_fopen=0','-d','allow_url_include=0','-d','open_basedir='.__DIR__,
            '-d','disable_functions=exec,system,passthru,shell_exec,proc_open,popen,pcntl_exec',__DIR__.'/passkey-libs/verify.php'];
        $process=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8']);
        if(!is_resource($process)){throw new \DomainException('The passkey verifier could not start. Use your authenticator code.');}
        $wire=json_encode($input,JSON_THROW_ON_ERROR);$out='';$error='';$offset=0;$deadline=microtime(true)+5;$exit=null;
        try{
            if(strlen($wire)>65536){throw new \DomainException('Passkey verification input exceeds its allocation.');}
            foreach($pipes as $pipe){stream_set_blocking($pipe,false);}
            while(true){
                if(microtime(true)>=$deadline){throw new \DomainException('Passkey verification exceeded its five-second deadline. Try your authenticator code.');}
                if(isset($pipes[0])){
                    $written=fwrite($pipes[0],substr($wire,$offset));
                    if($written===false){throw new \DomainException('Passkey verifier input failed.');}$offset+=$written;
                    if($offset===strlen($wire)){fclose($pipes[0]);unset($pipes[0]);}
                }
                foreach([1,2] as $number){$chunk=stream_get_contents($pipes[$number],32769);if(!is_string($chunk)){throw new \DomainException('Passkey verifier output failed.');}if($number===1){$out.=$chunk;}else{$error.=$chunk;}}
                if(strlen($out)>32768||strlen($error)>8192){throw new \DomainException('Passkey verifier output exceeded its allocation.');}
                $status=proc_get_status($process);
                if(!$status['running']){
                    $exit=$status['exitcode'];$out.=stream_get_contents($pipes[1],32769);$error.=stream_get_contents($pipes[2],8193);break;
                }
                usleep(10000);
            }
        }finally{
            $status=proc_get_status($process);if($status['running']){proc_terminate($process,9);}
            foreach($pipes as $pipe){fclose($pipe);}proc_close($process);
        }
        if($exit!==0||strlen($out)>32768||strlen($error)>8192){throw new \DomainException('The passkey signature or authenticator response could not be verified. Use a current challenge or your authenticator code.');}
        return ApiSecurity::jsonObject($out,false,16);
    }
    public static function workerVerification(array $input): array
    {
        if(PHP_SAPI!=='cli'||array_diff(array_keys($input),['purpose','state','json','user_handle','record'])){throw new \DomainException('Invalid verifier invocation.');}
        if(($input['purpose']??'')==='health'){self::serializer();return ['available'=>true];}
        $state=$input['state'];$serializer=self::serializer();
        $credential=$serializer->deserialize($input['json'],\Webauthn\PublicKeyCredential::class,'json');
        if($input['purpose']==='register'){
            if(!$credential->response instanceof \Webauthn\AuthenticatorAttestationResponse){throw new \DomainException('Expected registration.');}
            $options=$serializer->deserialize($state['options'],\Webauthn\PublicKeyCredentialCreationOptions::class,'json');
            $record=\Webauthn\AuthenticatorAttestationResponseValidator::create(self::factory($state)->creationCeremony())->check($credential->response,$options,$state['rp_id']);
        }elseif($input['purpose']==='authenticate'){
            if(!$credential->response instanceof \Webauthn\AuthenticatorAssertionResponse){throw new \DomainException('Expected assertion.');}
            $record=$serializer->deserialize($input['record'],\Webauthn\CredentialRecord::class,'json');
            $options=$serializer->deserialize($state['options'],\Webauthn\PublicKeyCredentialRequestOptions::class,'json');
            $record=\Webauthn\AuthenticatorAssertionResponseValidator::create(self::factory($state)->requestCeremony())->check($record,$credential->response,$options,$state['rp_id'],self::decoded($input['user_handle'],64));
        }else{throw new \DomainException('Unsupported verifier purpose.');}
        return ['record'=>$serializer->serialize($record,'json'),'credential_id'=>self::encoded($record->publicKeyCredentialId),'user_handle'=>self::encoded($record->userHandle)];
    }
    public static function verifyRuntime(): void
    {
        if((self::verifyInWorker(['purpose'=>'health'])['available']??false)!==true){throw new \DomainException('The isolated passkey verifier is unavailable.');}
    }
    public static function register(array $settings,array $account,array $state,string $json,string $name,int $now): array
    {
        self::check($settings,$account,$state,'register',$now);$raw=self::response($json,$state,'webauthn.create');
        foreach($account['auth']['passkeys']??[] as $row){if(hash_equals($row['credential_id'],$raw['id'])){throw new \DomainException('This passkey is already enrolled.');}}
        if(count($account['auth']['passkeys']??[])>=5){throw new \DomainException('This operator already has five passkeys.');}
        $verified=self::verifyInWorker(['purpose'=>'register','state'=>$state,'json'=>$json,'user_handle'=>self::encoded(self::handle($account))]);
        if(!hash_equals($verified['credential_id'],$raw['id'])||!hash_equals($verified['user_handle'],self::encoded(self::handle($account)))){throw new \DomainException('Passkey registration did not match its operator.');}
        $saved=['credential_id'=>$raw['id'],'name'=>trim($name),'record'=>$verified['record'],'created_at'=>$now,'last_used_at'=>0];
        return PasskeyConfig::normalize([$saved])[0];
    }
    public static function authenticate(array $settings,array $account,array $state,string $json,int $now): array
    {
        self::check($settings,$account,$state,'authenticate',$now);$raw=self::response($json,$state,'webauthn.get');$saved=null;
        foreach(PasskeyConfig::normalize($account['auth']['passkeys']??[]) as $key){if(hash_equals($key['credential_id'],$raw['id'])){$saved=$key;break;}}
        if(!$saved){throw new \DomainException('This passkey is not enrolled for the operator who passed the password check.');}
        $record=ApiSecurity::jsonObject($saved['record'],false,16);
        if(($record['userHandle']??null)!==self::encoded(self::handle($account))||($record['publicKeyCredentialId']??null)!==$raw['id']){throw new \DomainException('Stored passkey ownership is invalid.');}
        $verified=self::verifyInWorker(['purpose'=>'authenticate','state'=>$state,'json'=>$json,'user_handle'=>self::encoded(self::handle($account)),'record'=>$saved['record']]);
        if($verified['credential_id']!==$raw['id']||$verified['user_handle']!==self::encoded(self::handle($account))){throw new \DomainException('Passkey verification returned a different operator.');}
        $saved['record']=$verified['record'];$saved['last_used_at']=$now;
        return PasskeyConfig::normalize([$saved])[0];
    }
}
