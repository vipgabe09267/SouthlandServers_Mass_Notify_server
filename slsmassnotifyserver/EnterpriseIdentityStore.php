<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/InitializationJournal.php';
require_once __DIR__ . '/EnterpriseIdentityConfig.php';
require_once __DIR__ . '/DirectoryConfig.php';

/** A bounded, locked operational ledger; configuration stays in encrypted .config. */
final class EnterpriseIdentityStore
{
    private string $directory;
    public function __construct(string $directory)
    {
        $this->directory=rtrim($directory,'/');
        $parent=@lstat(dirname($this->directory));
        if (!$parent || ($parent['mode']&0022) || realpath(dirname($this->directory))!==dirname($this->directory)) { throw new \RuntimeException('Enterprise state parent is unsafe.'); }
        $marker=dirname($this->directory).'/.'.basename($this->directory).'-required';
        $required=InitializationJournal::read($marker);
        if ($required!==null) {
            if ($required!=="SLS_ENTERPRISE_STATE_V1\n" || !is_dir($this->directory) || is_link($this->directory)) {
                throw new \RuntimeException('Established enterprise state is missing or damaged. Restore it; do not recreate replay history.');
            }
        } elseif (is_dir($this->directory) && count(scandir($this->directory))>2) {
            throw new \RuntimeException('Enterprise state initialization marker is missing. Preserve the existing ledger for recovery.');
        }
        if (!file_exists($this->directory) && !is_link($this->directory)) {
            $mask=umask(0077);
            try {
                if (!@mkdir($this->directory,0700) && !is_dir($this->directory)) { throw new \RuntimeException('Enterprise state cannot be initialized.'); }
            } finally { umask($mask); }
        }
        $this->safeDirectory();
        if ($required===null) { InitializationJournal::write($marker,"SLS_ENTERPRISE_STATE_V1\n"); }
    }

    private function safeDirectory(): void
    {
        clearstatcache(true,$this->directory);$m=@lstat($this->directory);
        if (!$m || ($m['mode']&0170000)!==0040000 || ($m['mode']&0077) || $m['uid']!==posix_geteuid() || realpath($this->directory)!==$this->directory) { throw new \RuntimeException('Enterprise state must be owned by its runtime account, mode 0700, without links.'); }
    }
    private function syncDirectory(string $path): void
    {
        $h=@fopen($path,'r');
        if(!$h){throw new \RuntimeException('Enterprise directory durability unavailable.');}
        try{if(!fsync($h)){throw new \RuntimeException('Enterprise directory durability unavailable.');}}
        finally{fclose($h);}
    }
    private function regular(array $m): bool {return ($m['mode']&0170000)===0100000&&!($m['mode']&0077)&&$m['uid']===posix_geteuid()&&$m['nlink']===1;}
    private function open(string $path,string $mode)
    {
        $parent=dirname($path);clearstatcache(true,$parent);$parentBefore=@lstat($parent);
        if(!$parentBefore||($parentBefore['mode']&0170000)!==0040000||($parentBefore['mode']&0022)||realpath($parent)!==$parent){throw new \RuntimeException('Enterprise state parent changed.');}
        clearstatcache(true,$path);$before=@lstat($path);if($before&&(!$this->regular($before)||is_link($path))){throw new \RuntimeException('Enterprise state contains an unsafe file.');}$mask=umask(0077);try{$h=@fopen($path,$mode);}finally{umask($mask);}
        if (!$h) { throw new \RuntimeException('Enterprise state unavailable.'); }$m=fstat($h);clearstatcache(true,$path);$at=@lstat($path);clearstatcache(true,$parent);$parentAfter=@lstat($parent);
        if(!$parentAfter||$parentBefore['dev']!==$parentAfter['dev']||$parentBefore['ino']!==$parentAfter['ino']){fclose($h);throw new \RuntimeException('Enterprise state directory replaced.');}
        if (!$m || !$at || ($m['mode']&0170000)!==0100000 || ($m['mode']&0077) || $m['uid']!==posix_geteuid() || $m['nlink']!==1
            || is_link($path) || $m['ino']!==$at['ino'] || $m['dev']!==$at['dev'] || ($before && ($m['ino']!==$before['ino'] || $m['dev']!==$before['dev']))) { fclose($h);throw new \RuntimeException('Enterprise state contains an unsafe file.'); }return $h;
    }
    private static function value($value,int $depth,int &$nodes): void
    {
        if($depth>32||++$nodes>100000){throw new \RuntimeException('Enterprise ledger exceeds its structural bound. Preserve it for recovery.');}
        if(is_string($value)){if(strlen($value)>1048576||preg_match('//u',$value)!==1){throw new \RuntimeException('Enterprise ledger contains invalid or oversized text.');}return;}
        if(is_int($value)||is_bool($value)||$value===null||(is_float($value)&&is_finite($value))){return;}
        if(!is_array($value)){throw new \RuntimeException('Enterprise ledger contains an unsupported value.');}
        foreach($value as $key=>$child){if(is_string($key)&&(strlen($key)>256||preg_match('/[\x00-\x1f\x7f]/',$key))){throw new \RuntimeException('Enterprise ledger contains an invalid field name.');}self::value($child,$depth+1,$nodes);}
    }
    private static function exact(array $row,array $required,array $optional=[]): bool
    {
        $keys=array_keys($row);return !array_diff($required,$keys)&&!array_diff($keys,array_merge($required,$optional));
    }
    private static function hashValue($value,int $length=64): bool
    { return is_string($value)&&(bool)preg_match('/^[a-f0-9]{'.$length.'}$/D',$value); }
    private static function textValue($value,int $maximum,bool $empty=false): bool
    { return is_string($value)&&preg_match('//u',$value)===1&&strlen($value)<=$maximum&&($empty||$value!=='')&&!preg_match('/[\x00-\x1f\x7f]/',$value); }
    private static function endpointSubscription($value): bool
    {
        if(!is_array($value)||array_is_list($value)||!self::exact($value,['endpoint','keys'])||!is_array($value['keys'])||array_is_list($value['keys'])||!self::exact($value['keys'],['p256dh','auth'])){return false;}
        $url=$value['endpoint'];$parts=is_string($url)?parse_url($url):false;$host=is_array($parts)?strtolower((string)($parts['host']??'')):'';
        $allowed=in_array($host,['fcm.googleapis.com','updates.push.services.mozilla.com','web.push.apple.com'],true)
            ||(bool)preg_match('/^[a-z0-9-]+\.push\.services\.mozilla\.com$/D',$host)||(bool)preg_match('/^[a-z0-9-]+\.notify\.windows\.com$/D',$host);
        if(!is_array($parts)||strlen($url)>2048||preg_match('/[\x00-\x20]/',$url)||!$allowed||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||($parts['port']??443)!==443||empty($parts['path'])){return false;}
        foreach(['p256dh'=>65,'auth'=>16] as $key=>$length){$encoded=$value['keys'][$key]??null;if(!is_string($encoded)||!preg_match('/^[A-Za-z0-9_-]+$/D',$encoded)){return false;}$decoded=base64_decode(strtr($encoded,'-_','+/').str_repeat('=',(4-strlen($encoded)%4)%4),true);if(!is_string($decoded)||strlen($decoded)!==$length||($key==='p256dh'&&$decoded[0]!=="\x04")||!hash_equals($encoded,rtrim(strtr(base64_encode($decoded),'+/','-_'),'='))){return false;}}
        return true;
    }
    private static function resultValue($value,int $depth,int &$nodes): void
    {
        if(++$nodes>4096||$depth>16){throw new \RuntimeException('Announcement idempotency result exceeds its structural limit. Preserve it for recovery.');}
        if(is_string($value)){if(strlen($value)>16384||preg_match('//u',$value)!==1){throw new \RuntimeException('Announcement idempotency result contains invalid text. Preserve it for recovery.');}return;}
        if(is_int($value)||is_bool($value)||$value===null||(is_float($value)&&is_finite($value))){return;}
        if(!is_array($value)){throw new \RuntimeException('Announcement idempotency result contains an unsupported value. Preserve it for recovery.');}
        foreach($value as $key=>$child){if(is_string($key)&&(strlen($key)>128||preg_match('/[\x00-\x1f\x7f]/',$key))){throw new \RuntimeException('Announcement idempotency result contains an invalid field. Preserve it for recovery.');}self::resultValue($child,$depth+1,$nodes);}
    }
    private static function validateKnownRow(string $name,string $key,array $row): void
    {
        if($name==='login'){
            $common=['provider_id','revision','protocol','browser','created_at','expires_at'];$protocol=$row['protocol']??null;
            $keys=$protocol==='oidc'?array_merge($common,['nonce','verifier','discovery']):array_merge($common,['request_id']);
            if(!self::exact($row,$keys)||!preg_match('/^idp_[a-f0-9]{24}$/D',$row['provider_id']??'')||!self::hashValue($row['revision']??null)
                ||!in_array($protocol,['oidc','saml'],true)||!self::hashValue($row['browser']??null)||!is_int($row['created_at']??null)||$row['created_at']<1
                ||!is_int($row['expires_at']??null)||$row['expires_at']!==$row['created_at']+300){throw new \RuntimeException('Enterprise login transaction history is invalid. Preserve it for recovery.');}
            if($protocol==='oidc'){
                $discovery=$row['discovery']??null;if(!self::hashValue($row['nonce']??null)||!is_string($row['verifier']??null)||!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$row['verifier'])
                    ||!is_array($discovery)||array_is_list($discovery)||!self::textValue($discovery['issuer']??null,2048)){throw new \RuntimeException('Enterprise OIDC transaction history is invalid. Preserve it for recovery.');}
                foreach(['authorization_endpoint','token_endpoint','jwks_uri'] as $field){try{EnterpriseIdentityConfig::url($discovery[$field]??'','Stored OIDC endpoint');}catch(\Throwable $error){throw new \RuntimeException('Enterprise OIDC transaction history is invalid. Preserve it for recovery.',0,$error);}}
            }elseif(!self::textValue($row['request_id']??null,512)){throw new \RuntimeException('Enterprise SAML transaction history is invalid. Preserve it for recovery.');}
            return;
        }
        if($name==='identity_audit'){
            $base=!is_int($row['at']??null)||$row['at']<1||!preg_match('/^idp_[a-f0-9]{24}$/D',$row['provider_id']??'')||!in_array($row['protocol']??null,['oidc','saml'],true);
            $verified=($row['kind']??null)==='enterprise_identity_verified'&&self::exact($row,['at','kind','provider_id','protocol','operator_account_id'])&&preg_match('/^api_[a-f0-9]{24}$/D',$row['operator_account_id']??'');
            $rejected=($row['kind']??null)==='enterprise_identity_rejected'&&self::exact($row,['at','kind','provider_id','protocol','reason'])&&in_array($row['reason']??null,['transaction','provider_response','signing_key','signature','claims','replay','assertion','grant'],true);
            if($base||(!$verified&&!$rejected)){throw new \RuntimeException('Enterprise identity audit history is invalid. Preserve it for recovery.');}return;
        }
        if($name==='directory_plans'){
            $required=['source_id','revision','records','changes','active','template_ids','deactivation_count','deactivation_percent','requires_deactivation_confirmation','deprovision_all','created_at','expires_at'];
            if(!self::exact($row,$required)||!preg_match('/^dir_[a-f0-9]{24}$/D',$row['source_id']??'')||!self::hashValue($row['revision']??null)
                ||!is_array($row['records']??null)||DirectoryConfig::records($row['records'])!==$row['records']||!is_array($row['changes']??null)||!array_is_list($row['changes'])||count($row['changes'])>2000
                ||!is_int($row['active']??null)||$row['active']<0||$row['active']>1000||$row['active']!==count(array_filter($row['records'],static fn($record)=>$record['active']))
                ||!is_array($row['template_ids']??null)||!array_is_list($row['template_ids'])||count($row['template_ids'])>50||count(array_unique($row['template_ids']))!==count($row['template_ids'])
                ||!is_int($row['deactivation_count']??null)||$row['deactivation_count']<0||$row['deactivation_count']>1000||!is_int($row['deactivation_percent']??null)||$row['deactivation_percent']<0||$row['deactivation_percent']>100
                ||!is_bool($row['requires_deactivation_confirmation']??null)||!is_bool($row['deprovision_all']??null)||!is_int($row['created_at']??null)||$row['created_at']<1||($row['expires_at']??null)!==$row['created_at']+900){throw new \RuntimeException('Directory preview history is invalid. Preserve it for recovery.');}
            foreach($row['template_ids'] as $id){if(!is_string($id)||!preg_match('/^tpl_[a-f0-9]{24}$/D',$id)){throw new \RuntimeException('Directory preview template history is invalid. Preserve it for recovery.');}}
            foreach($row['changes'] as $change){$external=$change['external_id']??null;$legacyNumeric=is_int($external)&&$external>=0;
                if(!is_array($change)||array_is_list($change)||!self::exact($change,['kind','external_id','person_id','name'])||!in_array($change['kind']??null,['add','inactive','deactivate','update','remove_inactive'],true)||(!$legacyNumeric&&!self::textValue($external,256))||!preg_match('/^dirp_[a-f0-9]{40}$/D',$change['person_id']??'')||!self::textValue($change['name']??null,100)){throw new \RuntimeException('Directory preview change history is invalid. Preserve it for recovery.');}}return;
        }
        if($name==='subscriber_tokens'){
            $required=['subscriber_id','identity','incident_id','person_id','roster_identity','created_at','expires_at','delivery'];
            if(!self::exact($row,$required,['revoked','used'])||!preg_match('/^sub_[a-f0-9]{24}$/D',$row['subscriber_id']??'')||!self::hashValue($row['identity']??null)
                ||!preg_match('/^inc_[a-f0-9]{32}$/D',$row['incident_id']??'')||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$row['person_id']??'')||!self::hashValue($row['roster_identity']??null)
                ||!is_int($row['created_at']??null)||$row['created_at']<1||!is_int($row['expires_at']??null)||$row['expires_at']<=$row['created_at']||$row['expires_at']>$row['created_at']+3600
                ||!in_array($row['delivery']??null,['reserved','accepted','unconfirmed','failed'],true)||(isset($row['revoked'])&&!is_bool($row['revoked']))||(isset($row['used'])&&!is_bool($row['used']))){throw new \RuntimeException('Subscriber proof history is invalid. Preserve it for recovery.');}return;
        }
        if($name==='browser_subscriptions'){
            if(!self::exact($row,['subscriber_id','identity','subscription','created_at','enabled'])||!preg_match('/^sub_[a-f0-9]{24}$/D',$row['subscriber_id']??'')||!self::hashValue($row['identity']??null)
                ||!self::endpointSubscription($row['subscription']??null)||!is_int($row['created_at']??null)||$row['created_at']<1||!is_bool($row['enabled']??null)){throw new \RuntimeException('Browser subscription history is invalid. Preserve it for recovery.');}return;
        }
        if($name==='browser_push_claims'){
            if(!self::exact($row,['fingerprint','expires_at'],['receipt'])||!self::hashValue($row['fingerprint']??null)||!is_int($row['expires_at']??null)||$row['expires_at']<1){throw new \RuntimeException('Browser push claim history is invalid. Preserve it for recovery.');}
            if(isset($row['receipt'])){$receipt=$row['receipt'];if(!is_array($receipt)||array_is_list($receipt)||!self::exact($receipt,['state','detail'],['http_status'])||!in_array($receipt['state']??null,['accepted','revoked','unconfirmed'],true)||!self::textValue($receipt['detail']??null,1000)
                    ||(isset($receipt['http_status'])&&(!is_int($receipt['http_status'])||$receipt['http_status']<0||$receipt['http_status']>599))
                    ||(in_array($receipt['state']??null,['accepted','revoked'],true)&&!isset($receipt['http_status']))){throw new \RuntimeException('Browser push receipt history is invalid. Preserve it for recovery.');}}return;
        }
        if($name==='browser_test_events'){
            if(!self::exact($row,['event_id','title','message','is_test','expires_at','subscriber_id'])||($row['event_id']??null)!=='push_'.$key||!self::textValue($row['title']??null,320)
                ||!is_string($row['message']??null)||preg_match('//u',$row['message'])!==1||strlen($row['message'])>3000||($row['is_test']??null)!==true||!is_int($row['expires_at']??null)||$row['expires_at']<1||!preg_match('/^sub_[a-f0-9]{24}$/D',$row['subscriber_id']??'')){throw new \RuntimeException('Browser test event history is invalid. Preserve it for recovery.');}return;
        }
        if($name==='connector_claims'){
            if(!self::exact($row,['expires_at'],['receipt'])||!is_int($row['expires_at']??null)||$row['expires_at']<1){throw new \RuntimeException('Connector claim history is invalid. Preserve it for recovery.');}
            if(isset($row['receipt'])){$receipt=$row['receipt'];if(!is_array($receipt)||array_is_list($receipt)||!self::exact($receipt,['state','detail'],['http_status','provider_message_id'])||!in_array($receipt['state']??null,['accepted','failed','uncertain','cancelled'],true)||!self::textValue($receipt['detail']??null,1000)
                    ||(isset($receipt['http_status'])&&(!is_int($receipt['http_status'])||$receipt['http_status']<0||$receipt['http_status']>599))||(isset($receipt['provider_message_id'])&&!self::textValue($receipt['provider_message_id'],256))
                    ||(($receipt['state']==='accepted')!==isset($receipt['provider_message_id']))){throw new \RuntimeException('Connector receipt history is invalid. Preserve it for recovery.');}}return;
        }
        if($name==='oauth_refresh'){
            $saved=($row['state']??null)==='saved';if(!self::exact($row,$saved?['state','created_at','finished_at']:['state','created_at'])||!in_array($row['state']??null,['claimed','saved'],true)||!is_int($row['created_at']??null)||$row['created_at']<1||($saved&&(!is_int($row['finished_at']??null)||$row['finished_at']<$row['created_at']))){throw new \RuntimeException('OAuth refresh audit is invalid. Preserve it for recovery.');}return;
        }
        if($name==='announcement_claims'){
            $complete=($row['state']??null)==='complete';$required=$complete?['state','fingerprint','created_at','expires_at','result']:['state','fingerprint','created_at','expires_at'];
            if(!self::exact($row,$required)||!in_array($row['state']??null,['claimed','complete'],true)||!self::hashValue($row['fingerprint']??null)||!is_int($row['created_at']??null)||$row['created_at']<1||!is_int($row['expires_at']??null)||$row['expires_at']<=$row['created_at']||$row['expires_at']>$row['created_at']+2592000){throw new \RuntimeException('Announcement idempotency history is invalid. Preserve it for recovery.');}
            if($complete){if(!is_array($row['result'])){throw new \RuntimeException('Announcement idempotency result is invalid. Preserve it for recovery.');}$resultNodes=0;self::resultValue($row['result'],0,$resultNodes);if(strlen(json_encode($row['result'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>65536){throw new \RuntimeException('Announcement idempotency result exceeds 64 KiB. Preserve it for recovery.');}}return;
        }
    }
    private static function validateRows(string $name,array $rows): void
    {
        $limits=['login'=>1000,'replay'=>10000,'identity_audit'=>1000,'directory_plans'=>20,'subscriber_tokens'=>2000,
            'browser_subscriptions'=>1000,'browser_push_claims'=>10000,'browser_test_events'=>100,'connector_claims'=>10000,
            'oauth_refresh'=>1000,'announcement_claims'=>10000,'floorplan_retirements'=>5000];
        if(($rows&&array_is_list($rows))||count($rows)>($limits[$name]??20000)){throw new \RuntimeException('Enterprise ledger root or row allocation is invalid. Preserve it for recovery.');}
        $patterns=['login'=>'/^[a-f0-9]{64}$/D','replay'=>'/^[a-f0-9]{64}$/D','identity_audit'=>'/^event_[a-f0-9]{32}$/D',
            'directory_plans'=>'/^[a-f0-9]{64}$/D','subscriber_tokens'=>'/^[a-f0-9]{64}$/D','browser_subscriptions'=>'/^[a-f0-9]{64}$/D',
            'browser_push_claims'=>'/^[a-f0-9]{64}$/D','browser_test_events'=>'/^[a-f0-9]{32}$/D','connector_claims'=>'/^[a-f0-9]{64}$/D',
            'oauth_refresh'=>'/^[a-f0-9]{64}$/D','announcement_claims'=>'/^[a-f0-9]{64}$/D'];
        $nodes=0;self::value($rows,0,$nodes);
        foreach($rows as $key=>$row){if(isset($patterns[$name])&&(!is_string($key)||!preg_match($patterns[$name],$key))){throw new \RuntimeException('Enterprise ledger record identity is invalid. Preserve it for recovery.');}
            if($name==='replay'){if(!is_int($row)||$row<1){throw new \RuntimeException('Enterprise replay history is invalid. Preserve it for recovery.');}continue;}
            if(in_array($name,['login','identity_audit','directory_plans','subscriber_tokens','browser_subscriptions','browser_push_claims','browser_test_events','connector_claims','oauth_refresh','announcement_claims'],true)){
                if(!is_array($row)||array_is_list($row)){throw new \RuntimeException('Enterprise ledger record is invalid. Preserve it for recovery.');}
                self::validateKnownRow($name,(string)$key,$row);
            }
        }
    }
    private static function compactRows(string $name,array &$rows,int $now): int
    {
        $removed=0;foreach($rows as $key=>$row){$expired=match($name){
                'login','directory_plans','browser_push_claims','browser_test_events','connector_claims','announcement_claims'=>is_array($row)&&is_int($row['expires_at']??null)&&$row['expires_at']<=$now,
                'subscriber_tokens'=>is_array($row)&&is_int($row['created_at']??null)&&is_int($row['expires_at']??null)&&max($row['expires_at'],$row['created_at']+3600)<=$now,
                'replay'=>is_int($row)&&$row<=$now,
                default=>false};
            if($expired){unset($rows[$key]);$removed++;}}
        return $removed;
    }
    private function ledgerExists(string $name): bool
    {
        if(!preg_match('/^[a-z0-9_-]{1,32}$/D',$name)){throw new \LogicException('Invalid ledger name.');}
        $path=$this->directory.'/'.$name.'.json';$marker=$this->directory.'/'.$name.'.required';
        return file_exists($path)||is_link($path)||file_exists($marker)||is_link($marker);
    }
    public function read(string $name): array
    {
        if(!$this->ledgerExists($name)){return [];}
        return $this->transaction($name,static function(array &$rows)use($name):array{self::compactRows($name,$rows,time());return $rows;},false,false);
    }
    public function compact(int $now): array
    {
        if($now<1){throw new \InvalidArgumentException('Enterprise retention time is invalid.');}$counts=[];
        foreach(['login','replay','directory_plans','subscriber_tokens','browser_push_claims','browser_test_events','connector_claims','announcement_claims'] as $name){
            $counts[$name]=$this->ledgerExists($name)?$this->transaction($name,static fn(array &$rows):int=>self::compactRows($name,$rows,$now),false,false):0;
        }
        return $counts;
    }
    private function atomicLedger(string $path, string $bytes, ?callable $verify=null): void
    {
        $temporary=$path.'.'.bin2hex(random_bytes(12));
        $handle=$this->open($temporary,'x+b');
        try {
            $length=strlen($bytes);$written=0;
            while($written<$length){
                $count=fwrite($handle,substr($bytes,$written,min(1048576,$length-$written)));
                if(!is_int($count)||$count<1){throw new \RuntimeException('Enterprise state write made no progress.');}
                $written+=$count;
            }
            if (!fflush($handle) || !fsync($handle)) { throw new \RuntimeException('Enterprise state write failed.'); }
            if ($verify!==null) { $verify(); }
            if (!rename($temporary,$path)) { throw new \RuntimeException('Enterprise state replace failed.'); }
            $this->syncDirectory($this->directory);
        } finally { fclose($handle); if (file_exists($temporary)) { @unlink($temporary); } }
    }

    public function transaction(string $name, callable $work, bool $readOnly=false, bool $initialize=true)
    {
        if (!preg_match('/^[a-z0-9_-]{1,32}$/D',$name)) { throw new \LogicException('Invalid ledger name.'); }
        $this->safeDirectory();
        $lockPath=$this->directory.'/'.$name.'.lock';
        $directoryBefore=lstat($this->directory);
        $lock=$this->open($lockPath,'c+b');
        $lockBefore=fstat($lock);
        $verify=function()use($lock,$lockPath,$directoryBefore,$lockBefore):void{
            $this->safeDirectory();clearstatcache(true,$this->directory);$directoryNow=lstat($this->directory);
            clearstatcache(true,$lockPath);$named=@lstat($lockPath);$opened=fstat($lock);
            if(!$named||!$opened||!$this->regular($opened)||!$this->regular($named)||$opened['size']!==0
                ||$opened['dev']!==$lockBefore['dev']||$opened['ino']!==$lockBefore['ino']
                ||$opened['dev']!==$named['dev']||$opened['ino']!==$named['ino']
                ||$directoryNow['dev']!==$directoryBefore['dev']||$directoryNow['ino']!==$directoryBefore['ino']){
                throw new \RuntimeException('Enterprise ledger lock or directory changed. Preserve its history before retrying.');
            }
        };
        try {
            $deadline=hrtime(true)+2000000000;
            while (!flock($lock,LOCK_EX|LOCK_NB)) {
                if (hrtime(true)>=$deadline) { throw new \RuntimeException('Enterprise state busy.'); }
                usleep(10000);
            }
            $verify();
            $path=$this->directory.'/'.$name.'.json';
            $marker=$this->directory.'/'.$name.'.required';
            $intentPath=$this->directory.'/'.$name.'.initializing';
            $intentBytes="SLS_ENTERPRISE_LEDGER_INIT_V1\n".$name."\n";
            $intent=InitializationJournal::read($intentPath);
            $required=InitializationJournal::read($marker);
            $present=file_exists($path)||is_link($path);
            if ($intent!==null) {
                if ($intent!==$intentBytes || ($required!==null && $required!=="SLS_ENTERPRISE_LEDGER_V1\n")) {
                    throw new \RuntimeException('Enterprise initialization evidence conflicts. Preserve its history.');
                }
                if ($present) {
                    $handle=$this->open($path,'r+b');
                    try { if (stream_get_contents($handle,3)!=='{}' || fstat($handle)['size']!==2) { throw new \RuntimeException('Enterprise initialization contains application history. Reviewed recovery is required.'); } }
                    finally { fclose($handle); }
                } else { $this->atomicLedger($path,'{}',$verify); }
                InitializationJournal::write($marker,"SLS_ENTERPRISE_LEDGER_V1\n");
                InitializationJournal::finish($intentPath,$intentBytes);
                $required="SLS_ENTERPRISE_LEDGER_V1\n";$present=true;
            }
            if ($required!==null && ($required!=="SLS_ENTERPRISE_LEDGER_V1\n" || !$present)) {
                throw new \RuntimeException('Established enterprise ledger missing or damaged.');
            }
            if ($required===null && $present) { throw new \RuntimeException('Enterprise ledger initialization incomplete. Preserve its history.'); }
            if (!$present && !$readOnly && $initialize) {
                InitializationJournal::write($intentPath,$intentBytes);
                $this->atomicLedger($path,'{}',$verify);
                InitializationJournal::write($marker,"SLS_ENTERPRISE_LEDGER_V1\n");
                InitializationJournal::finish($intentPath,$intentBytes);
                $present=true;
            }
            $rows=[];
            if ($present) {
                $handle=$this->open($path,'r+b');
                try {
                    if (fstat($handle)['size']>4194304) { throw new \RuntimeException('Enterprise state allocation exceeded.'); }
                    $raw=stream_get_contents($handle,4194305);$rows=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
                    if (json_encode((object)$rows,JSON_THROW_ON_ERROR)!==$raw) { throw new \RuntimeException('Enterprise ledger is noncanonical or has repeated keys.'); }
                } finally { fclose($handle); }
            }
            if (!is_array($rows)) { throw new \RuntimeException('Enterprise state damaged.'); }self::validateRows($name,$rows);$original=$rows;
            $verify();
            $result=$work($rows);
            $verify();
            if ($readOnly) { return $result; }
            self::validateRows($name,$rows);
            if(!$present||$rows===$original){return $result;}
            $bytes=json_encode((object)$rows,JSON_THROW_ON_ERROR);
            if (strlen($bytes)>4194304) { throw new \RuntimeException('Enterprise state allocation exceeded.'); }
            $this->atomicLedger($path,$bytes,$verify);
            return $result;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }

    public function reserve(array $record,string $browser,int $now): string
    {
        if($now<1){throw new \DomainException('Enterprise login time is invalid.');}
        return $this->transaction('login',static function(array &$rows)use($record,$browser,$now):string{foreach($rows as $k=>$r){if(($r['expires_at']??0)<=$now){unset($rows[$k]);}}if(count($rows)>=1000){throw new \DomainException('Enterprise login capacity reached.');}$state=bin2hex(random_bytes(32));$rows[hash('sha256',$state)]=array_replace($record,['browser'=>hash('sha256',$browser),'created_at'=>$now,'expires_at'=>$now+300]);return $state;});
    }
    public function take(string $state,string $browser,int $now): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$state)||!preg_match('/^[a-f0-9]{64}$/D',$browser)){throw new \DomainException('Enterprise login transaction invalid.');}
        return $this->transaction('login',static function(array &$rows)use($state,$browser,$now):array{$key=hash('sha256',$state);$r=$rows[$key]??null;unset($rows[$key]);if(!$r||$now<$r['created_at']||$now>=$r['expires_at']||!hash_equals($r['browser'],hash('sha256',$browser))){return ['invalid'=>true];}return $r;});
    }
    public function replay(string $key,int $expires,int $now): void
    {
        if($now<1||$expires<=$now){throw new \DomainException('Enterprise replay expiry is invalid.');}
        $ok=$this->transaction('replay',static function(array &$rows)use($key,$expires,$now):bool{foreach($rows as $k=>$e){if(!is_int($e)){throw new \RuntimeException('Replay ledger damaged.');}if($e<=$now){unset($rows[$k]);}}$k=hash('sha256',$key);if(isset($rows[$k])){return false;}if(count($rows)>=10000){throw new \RuntimeException('Replay ledger capacity reached.');}$rows[$k]=$expires;return true;});if(!$ok){throw new \DomainException('This authentication assertion was already used.');}
    }

    /** Store a bounded private rejection category; never store tokens or exception text. */
    public function auditRejection(string $providerId,string $protocol,string $reason,int $now): void
    {
        $record=['at'=>$now,'kind'=>'enterprise_identity_rejected','provider_id'=>$providerId,'protocol'=>$protocol,'reason'=>$reason];
        $this->transaction('identity_audit',static function(array &$rows)use($record):void{
            if(count($rows)>=1000){$rows=array_slice($rows,-999,null,true);}
            $rows['event_'.bin2hex(random_bytes(16))]=$record;
        });
    }
}
