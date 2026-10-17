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
        foreach($sources as $s){
            if(!is_array($s)||!in_array($s['type']??'',['csv','ldap','scim'],true)){throw new \DomainException('Directory source fields invalid.');}
            $base=['id','name','type','enabled','template_ids','sync_subscribers','records','maximum_deactivation_percent'];
            $specific=$s['type']==='ldap'?['ldap_url','ldap_starttls','ldap_bind_dn','ldap_password','ldap_base_dn','ldap_filter','ldap_attributes','ldap_inactive_values','ldap_inactive_mask']
                :($s['type']==='scim'?['scim_base_url','scim_token','scim_attributes']:[]);
            if(array_diff(array_keys($s),array_merge($base,$specific))||!is_string($s['id']??null)||!preg_match('/^dir_[a-f0-9]{24}$/D',$s['id'])||isset($seen[$s['id']])||!is_bool($s['enabled']??false)||!is_bool($s['sync_subscribers']??false)){throw new \DomainException('Directory source fields invalid.');}
            $r=['id'=>$s['id'],'name'=>EnterpriseIdentityConfig::text($s['name']??'',80,'Source name'),'type'=>$s['type'],'enabled'=>$s['enabled']??false,'sync_subscribers'=>$s['sync_subscribers']??false,'template_ids'=>$s['template_ids']??[],'records'=>self::records($s['records']??[])];
            $r['maximum_deactivation_percent']=$s['maximum_deactivation_percent']??25;if(!is_int($r['maximum_deactivation_percent'])||$r['maximum_deactivation_percent']<1||$r['maximum_deactivation_percent']>100){throw new \DomainException('Directory deactivation review threshold must be 1–100 percent.');}
            if(!is_array($r['template_ids'])||!array_is_list($r['template_ids'])||count($r['template_ids'])>50){throw new \DomainException('Directory template list invalid.');}
            foreach($r['template_ids'] as $id){if(!is_string($id)||!preg_match('/^tpl_[a-f0-9]{24}$/D',$id)){throw new \DomainException('Select saved incident templates.');}}$r['template_ids']=array_values(array_unique($r['template_ids']));
            if($r['type']==='ldap'){
                $url=EnterpriseIdentityConfig::text($s['ldap_url']??'',2048,'LDAP URL');$u=parse_url($url);
                $host=is_array($u)&&is_string($u['host']??null)?$u['host']:'';$ipHost=str_starts_with($host,'[')&&str_ends_with($host,']')?substr($host,1,-1):$host;
                $validHost=filter_var($ipHost,FILTER_VALIDATE_IP)!==false;
                if(!$validHost&&$host!==''&&strlen($host)<=253&&$host===trim($host,'.')){$validHost=true;foreach(explode('.',$host) as $label){if(!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D',$label)){$validHost=false;break;}}}
                $startTls=$s['ldap_starttls']??false;
                if(!$u||!in_array($u['scheme']??'',['ldap','ldaps'],true)||!$validHost||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])
                    ||!in_array($u['path']??'',['','/'],true)||(isset($u['port'])&&($u['port']<1||$u['port']>65535))||!is_bool($startTls)
                    ||($u['scheme']==='ldap'&&!$startTls)||($u['scheme']==='ldaps'&&$startTls)){throw new \DomainException('Use LDAPS, or use LDAP with StartTLS, and provide a valid directory host and port.');}
                $r+=['ldap_url'=>$url,'ldap_starttls'=>$startTls,'ldap_bind_dn'=>EnterpriseIdentityConfig::text($s['ldap_bind_dn']??'',512,'LDAP bind DN'),
                    'ldap_password'=>EnterpriseIdentityConfig::text($s['ldap_password']??'',2048,'LDAP bind password'),'ldap_base_dn'=>EnterpriseIdentityConfig::text($s['ldap_base_dn']??'',512,'LDAP search base'),
                    'ldap_filter'=>EnterpriseIdentityConfig::text($s['ldap_filter']??'(objectClass=person)',1024,'LDAP search filter'),'ldap_attributes'=>$s['ldap_attributes']??['external_id'=>'entryUUID','name'=>'displayName','email'=>'mail','location'=>'l','desktop_username'=>'uid','active'=>''],'ldap_inactive_values'=>$s['ldap_inactive_values']??['false','0']];
                if(!is_array($r['ldap_attributes'])||array_diff(array_keys($r['ldap_attributes']),['external_id','name','email','location','desktop_username','active'])){throw new \DomainException('LDAP attribute mapping invalid.');}
                foreach(['external_id','name','email','location','desktop_username','active'] as $k){$a=$r['ldap_attributes'][$k]??'';if(!is_string($a)||!preg_match('/^[A-Za-z][A-Za-z0-9;-]{0,63}$|^$/D',$a)||($a===''&&in_array($k,['external_id','name'],true))){throw new \DomainException('LDAP requires stable ID/name attribute mappings.');}$r['ldap_attributes'][$k]=$a;}
                if(!is_array($r['ldap_inactive_values'])||!array_is_list($r['ldap_inactive_values'])||count($r['ldap_inactive_values'])>10){throw new \DomainException('LDAP inactive values invalid.');}
                $inactive=[];foreach($r['ldap_inactive_values'] as $x){$x=strtolower(trim(EnterpriseIdentityConfig::text($x,80,'LDAP inactive value')));if($x===''||isset($inactive[$x])){throw new \DomainException('LDAP inactive values must be nonempty and distinct after normalization.');}$inactive[$x]=true;}$r['ldap_inactive_values']=array_keys($inactive);
                $r['ldap_inactive_mask']=$s['ldap_inactive_mask']??0;if(!is_int($r['ldap_inactive_mask'])||$r['ldap_inactive_mask']<0||$r['ldap_inactive_mask']>2147483647){throw new \DomainException('LDAP inactive mask must be a non-negative integer.');}
            }elseif($r['type']==='scim'){$r['scim_base_url']=rtrim(EnterpriseIdentityConfig::url($s['scim_base_url']??'','SCIM base URL'),'/');$r['scim_token']=EnterpriseIdentityConfig::text($s['scim_token']??'',2048,'SCIM read token');
                if(isset($s['scim_attributes'])&&!is_array($s['scim_attributes'])){throw new \DomainException('SCIM mapping must be an object.');}
                $r['scim_attributes']=array_replace(['external_id'=>'/id','name'=>'/displayName','location'=>'','desktop_username'=>''],$s['scim_attributes']??[]);
                if(array_diff(array_keys($r['scim_attributes']),['external_id','name','location','desktop_username'])){throw new \DomainException('Unsupported SCIM mapping.');}
                $paths=['','/id','/externalId','/userName','/displayName','/name/formatted','/urn:ietf:params:scim:schemas:extension:enterprise:2.0:User/department','/urn:ietf:params:scim:schemas:extension:enterprise:2.0:User/organization','/urn:ietf:params:scim:schemas:extension:enterprise:2.0:User/employeeNumber'];
                foreach($r['scim_attributes'] as $key=>$path){if(!is_string($path)||!in_array($path,$paths,true)||($path===''&&in_array($key,['external_id','name'],true))){throw new \DomainException('Choose a documented SCIM user mapping.');}}
            }
            $out['sources'][]=$r;$seen[$r['id']]=true;
        }return $out;
    }
    public static function personId(string $source,string $external): string { return 'dirp_'.substr(hash('sha256',$source."\0".$external),0,40); }
    public static function publicConfig(array $config): array
    {
        $config = self::normalize($config);
        foreach ($config['sources'] as &$source) {
            foreach (['ldap_password', 'scim_token'] as $key) {
                if (array_key_exists($key, $source)) {
                    $source[$key . '_configured'] = is_string($source[$key]) && $source[$key] !== '';
                    $source[$key] = '';
                }
            }
        }
        unset($source);
        return $config;
    }
}
