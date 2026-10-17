<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityConfig.php';

final class SubscriberConfig
{
    public static function defaults(): array { return ['schema'=>1,'enabled'=>false,'public_base_url'=>'','link_ttl_seconds'=>900,'people'=>[], 'push'=>['enabled'=>false,'private_key_pem'=>'','subject'=>'']]; }
    private static function pushSubject($value): string
    {
        if (!is_string($value) || strlen($value)>254 || preg_match('/[\x00-\x20\x7f]/',$value)) {
            throw new \DomainException('Browser push contact is invalid.');
        }
        if ($value==='') { return ''; }
        if (str_starts_with($value,'mailto:')) {
            $email=substr($value,7);
            if ($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/',$email)) {
                throw new \DomainException('Browser push contact is invalid.');
            }
            return 'mailto:'.$email;
        }
        try { return EnterpriseIdentityConfig::url($value,'Browser push contact'); }
        catch (\DomainException $error) { throw new \DomainException('Browser push contact is invalid.',0,$error); }
    }
    public static function normalize($v): array
    {
        if(!is_array($v)||($v&&array_is_list($v))||array_diff(array_keys($v),['schema','enabled','public_base_url','link_ttl_seconds','people','push'])||($v['schema']??1)!==1||!is_bool($v['enabled']??false)){throw new \DomainException('Subscriber browser settings must use schema 1.');}
        $o=self::defaults();$o['enabled']=$v['enabled']??false;$o['public_base_url']=$v['public_base_url']??'';$o['link_ttl_seconds']=$v['link_ttl_seconds']??900;
        if(!is_string($o['public_base_url'])||($o['public_base_url']!==''&&!str_ends_with(EnterpriseIdentityConfig::url($o['public_base_url'],'Subscriber URL'),'/mass-notify/subscriber.php'))||($o['enabled']&&$o['public_base_url']==='')||!is_int($o['link_ttl_seconds'])||$o['link_ttl_seconds']<300||$o['link_ttl_seconds']>3600){throw new \DomainException('Use an explicit HTTPS /mass-notify/subscriber.php URL and link expiry from five through sixty minutes.');}
        $people=$v['people']??[];$ids=[];$persons=[];
        if(!is_array($people)||!array_is_list($people)||count($people)>1000){throw new \DomainException('At most 1000 subscriber people are supported.');}
        foreach($people as $p){if(!is_array($p)||array_diff(array_keys($p),['id','person_id','name','email','enabled','version','registered_at'])||!is_string($p['id']??null)||!preg_match('/^sub_[a-f0-9]{24}$/D',$p['id'])||isset($ids[$p['id']])
                ||!is_string($p['person_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$p['person_id'])||isset($persons[$p['person_id']])||!is_bool($p['enabled']??null)
                ||!is_string($p['version']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['version'])||!is_int($p['registered_at']??null)||$p['registered_at']<1||$p['registered_at']>time()+30
                ||!is_string($p['email']??null)||strlen($p['email'])>254||!filter_var($p['email'],FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n\x00]/',$p['email'])){throw new \DomainException('Subscriber identity, email, enrollment time or enabled flag invalid.');}
            $p['name']=EnterpriseIdentityConfig::text($p['name']??'',100,'Subscriber name');$o['people'][]=$p;$ids[$p['id']]=true;$persons[$p['person_id']]=true;
        }
        $push=$v['push']??[];if(!is_array($push)||array_diff(array_keys($push),['enabled','private_key_pem','subject'])||!is_bool($push['enabled']??false)){throw new \DomainException('Browser push settings are invalid.');}
        $o['push']=array_replace($o['push'],$push);
        if(!is_string($o['push']['private_key_pem'])||strlen($o['push']['private_key_pem'])>4096){throw new \DomainException('Browser push key/contact is invalid.');}
        $o['push']['subject']=self::pushSubject($o['push']['subject']);
        if($o['push']['private_key_pem']!==''){require_once __DIR__.'/BrowserPush.php';BrowserPush::publicPoint($o['push']['private_key_pem']);}
        if($o['push']['enabled']&&(!$o['enabled']||$o['push']['private_key_pem']===''||$o['push']['subject']==='')){throw new \DomainException('Enable subscribers and configure a VAPID key/contact before enabling browser push.');}
        return $o;
    }
    public static function person(array $settings,string $id): array
    {
        $c=self::normalize($settings['subscriber_browser']??[]);if(!$c['enabled']){throw new \DomainException('Subscriber browser access is disabled.');}
        foreach($c['people'] as $p){if($p['id']===$id&&$p['enabled']){return $p;}}throw new \DomainException('Subscriber access is unavailable.');
    }
    public static function identity(array $p): string {return hash('sha256',json_encode([$p['id'],$p['person_id'],$p['name'],strtolower($p['email']),$p['version'],$p['registered_at']],JSON_THROW_ON_ERROR));}
    public static function publicConfig(array $config): array
    {
        $config=self::normalize($config);$key=$config['push']['private_key_pem'];unset($config['push']['private_key_pem']);$config['push']['private_key_configured']=$key!=='';
        $config['push']['public_key']=$key!==''?BrowserPush::encode(BrowserPush::publicPoint($key)):'';return $config;
    }
}
