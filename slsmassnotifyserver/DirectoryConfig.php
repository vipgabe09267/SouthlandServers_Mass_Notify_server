<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityConfig.php';

final class DirectoryConfig
{
    public static function defaults(): array { return ['schema'=>1,'enabled'=>false,'sources'=>[]]; }
    public static function records($value): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>1000){throw new \DomainException('Each directory supports at most 1000 people.');}$out=[];$seen=[];
        foreach($value as $r){if(!is_array($r)||array_diff(array_keys($r),['external_id','name','email','location','location_id','desktop_username','active'])){throw new \DomainException('Directory person contains unsupported fields.');}
            $external=EnterpriseIdentityConfig::text($r['external_id']??'',256,'Stable external ID');if(isset($seen[$external])){throw new \DomainException('Duplicate stable external ID in directory result.');}$seen[$external]=true;
            $email=$r['email']??'';if(!is_string($email)||strlen($email)>254||($email!==''&&(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n\x00]/',$email)))){throw new \DomainException('Directory email invalid.');}
            $desktop=$r['desktop_username']??'';$loc=$r['location_id']??'';
            if(!is_string($desktop)||!preg_match('/^[A-Za-z0-9_.@-]{0,80}$/D',$desktop)||!is_string($loc)||($loc!==''&&!preg_match('/^loc_[a-f0-9]{24}$/D',$loc))||!is_bool($r['active']??true)){throw new \DomainException('Directory desktop, location ID or active flag invalid.');}
            $out[]=['external_id'=>$external,'name'=>EnterpriseIdentityConfig::text($r['name']??'',100,'Person name'),'email'=>$email,
                'location'=>EnterpriseIdentityConfig::text($r['location']??'',120,'Location label',true),'location_id'=>$loc,'desktop_username'=>$desktop,'active'=>$r['active']??true];
        }usort($out,static fn($a,$b)=>strcmp($a['external_id'],$b['external_id']));return $out;
    }
    public static function normalize($v): array
    {
        if(!is_array($v)||($v&&array_is_list($v))||array_diff(array_keys($v),['schema','enabled','sources'])||($v['schema']??1)!==1||!is_bool($v['enabled']??false)){throw new \DomainException('Directory settings must use schema 1.');}
        $out=self::defaults();$out['enabled']=$v['enabled']??false;$seen=[];$sources=$v['sources']??[];
        if(!is_array($sources)||!array_is_list($sources)||count($sources)>10){throw new \DomainException('Configure at most ten directory sources.');}
        foreach($sources as $s){$allowed=['id','name','type','enabled','template_ids','sync_subscribers','records','ldap_url','ldap_starttls','ldap_bind_dn','ldap_password','ldap_base_dn','ldap_filter','ldap_attributes','ldap_inactive_values','scim_base_url','scim_token'];
            if(!is_array($s)||array_diff(array_keys($s),$allowed)||!preg_match('/^dir_[a-f0-9]{24}$/D',$s['id']??'')||isset($seen[$s['id']])||!in_array($s['type']??'',['csv','ldap','scim'],true)||!is_bool($s['enabled']??false)||!is_bool($s['sync_subscribers']??false)){throw new \DomainException('Directory source fields invalid.');}
            $r=['id'=>$s['id'],'name'=>EnterpriseIdentityConfig::text($s['name']??'',80,'Source name'),'type'=>$s['type'],'enabled'=>$s['enabled']??false,'sync_subscribers'=>$s['sync_subscribers']??false,'template_ids'=>$s['template_ids']??[],'records'=>self::records($s['records']??[])];
            if(!is_array($r['template_ids'])||!array_is_list($r['template_ids'])||count($r['template_ids'])>50){throw new \DomainException('Directory template list invalid.');}
            foreach($r['template_ids'] as $id){if(!is_string($id)||!preg_match('/^tpl_[a-f0-9]{24}$/D',$id)){throw new \DomainException('Select saved incident templates.');}}$r['template_ids']=array_values(array_unique($r['template_ids']));
            if($r['type']==='ldap'){
                $url=EnterpriseIdentityConfig::text($s['ldap_url']??'',2048,'LDAP URL');$u=parse_url($url);
                if(!$u||!in_array($u['scheme']??'',['ldap','ldaps'],true)||!isset($u['host'])||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||!in_array($u['path']??'',['','/'],true)||!preg_match('/^[A-Za-z0-9.-]+$/D',$u['host'])||!is_bool($s['ldap_starttls']??false)||($u['scheme']==='ldap'&&empty($s['ldap_starttls']))){throw new \DomainException('LDAP requires an explicit LDAPS server or LDAP with StartTLS.');}
                $r+=['ldap_url'=>$url,'ldap_starttls'=>$s['ldap_starttls']??false,'ldap_bind_dn'=>EnterpriseIdentityConfig::text($s['ldap_bind_dn']??'',512,'LDAP bind DN'),
                    'ldap_password'=>EnterpriseIdentityConfig::text($s['ldap_password']??'',2048,'LDAP bind password'),'ldap_base_dn'=>EnterpriseIdentityConfig::text($s['ldap_base_dn']??'',512,'LDAP search base'),
                    'ldap_filter'=>EnterpriseIdentityConfig::text($s['ldap_filter']??'(objectClass=person)',1024,'LDAP search filter'),'ldap_attributes'=>$s['ldap_attributes']??['external_id'=>'entryUUID','name'=>'displayName','email'=>'mail','location'=>'l','desktop_username'=>'uid','active'=>''],'ldap_inactive_values'=>$s['ldap_inactive_values']??['false','0']];
                if(!is_array($r['ldap_attributes'])||array_diff(array_keys($r['ldap_attributes']),['external_id','name','email','location','desktop_username','active'])){throw new \DomainException('LDAP attribute mapping invalid.');}
                foreach(['external_id','name','email','location','desktop_username','active'] as $k){$a=$r['ldap_attributes'][$k]??'';if(!is_string($a)||!preg_match('/^[A-Za-z][A-Za-z0-9;-]{0,63}$|^$/D',$a)||($a===''&&in_array($k,['external_id','name'],true))){throw new \DomainException('LDAP requires stable ID/name attribute mappings.');}$r['ldap_attributes'][$k]=$a;}
                if(!is_array($r['ldap_inactive_values'])||!array_is_list($r['ldap_inactive_values'])||count($r['ldap_inactive_values'])>10){throw new \DomainException('LDAP inactive values invalid.');}foreach($r['ldap_inactive_values'] as $x){EnterpriseIdentityConfig::text($x,80,'LDAP inactive value');}
            }elseif($r['type']==='scim'){$r['scim_base_url']=rtrim(EnterpriseIdentityConfig::url($s['scim_base_url']??'','SCIM base URL'),'/');$r['scim_token']=EnterpriseIdentityConfig::text($s['scim_token']??'',2048,'SCIM read token');}
            $out['sources'][]=$r;$seen[$r['id']]=true;
        }return $out;
    }
    public static function personId(string $source,string $external): string { return 'dirp_'.substr(hash('sha256',$source."\0".$external),0,40); }
    public static function publicConfig(array $c): array {foreach($c['sources'] as &$s){foreach(['ldap_password','scim_token'] as $key){if(isset($s[$key])){$s[$key]='';$s[$key.'_configured']=true;}}}unset($s);return $c;}
}
