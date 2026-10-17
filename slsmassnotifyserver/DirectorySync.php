<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/DirectoryConfig.php';
require_once __DIR__.'/EnterpriseIdentityHttp.php';
require_once __DIR__.'/IncidentConfig.php';
require_once __DIR__.'/LocationDirectory.php';

/** Pull-only directory connectors. No login provisioning or remote directory writes. */
final class DirectorySync
{
    public static function csv(string $csv): array
    {
        if($csv===''||strlen($csv)>524288||str_contains($csv,"\0")){throw new \DomainException('Upload UTF-8 CSV of at most 512 KiB.');}
        $stream=fopen('php://temp','w+');fwrite($stream,$csv);rewind($stream);$header=fgetcsv($stream,4096,',','"','');$fields=['external_id','name','email','location','location_id','desktop_username','active'];
        if(!$header||count($header)!==count(array_unique($header))||array_diff($header,$fields)||!in_array('external_id',$header,true)||!in_array('name',$header,true)){fclose($stream);throw new \DomainException('CSV needs external_id,name and optional email,location,location_id,desktop_username,active columns.');}
        $rows=[];try{while(($values=fgetcsv($stream,4096,',','"',''))!==false){if($values===[null]){continue;}if(count($values)!==count($header)){throw new \DomainException('CSV row column count does not match its header.');}$r=array_combine($header,$values);if(isset($r['active'])){if(!in_array(strtolower($r['active']),['true','false','1','0'],true)){throw new \DomainException('CSV active values must be true,false,1 or 0.');}$r['active']=in_array(strtolower($r['active']),['true','1'],true);}$rows[]=$r;if(count($rows)>1000){throw new \DomainException('Directory import exceeds 1000 people.');}}}finally{fclose($stream);}return DirectoryConfig::records($rows);
    }
    public static function ldapActive(array $source,string $value): bool
    {
        $attribute=$source['ldap_attributes']['active']??'';
        if($attribute===''){if(($source['ldap_inactive_mask']??0)>0){throw new \DomainException('LDAP inactive mask requires an account-control attribute.');}return true;}
        if(strlen($value)>80||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value)){throw new \DomainException('LDAP active/account-control attribute is invalid. No import was made.');}
        $value=trim($value);
        if($value===''){throw new \DomainException('LDAP active/account-control attribute is missing. No import was made.');}
        if(($source['ldap_inactive_mask']??0)>0){
            if(!preg_match('/^[0-9]{1,10}$/D',$value)||(float)$value>4294967295){throw new \DomainException('LDAP account-control attribute is not a valid unsigned integer. No import was made.');}
            return ((int)$value&$source['ldap_inactive_mask'])===0;
        }
        return !in_array(strtolower($value),$source['ldap_inactive_values'],true);
    }
    public static function ldap(array $s): array
    {
        if(!extension_loaded('ldap')){throw new \DomainException('PHP LDAP is required for this source.');}
        $conn=ldap_connect($s['ldap_url']);if(!$conn){throw new \DomainException('LDAP connection failed.');}
        try{
            foreach([LDAP_OPT_PROTOCOL_VERSION=>3,LDAP_OPT_REFERRALS=>0,LDAP_OPT_NETWORK_TIMEOUT=>5,LDAP_OPT_TIMELIMIT=>10,LDAP_OPT_X_TLS_REQUIRE_CERT=>LDAP_OPT_X_TLS_DEMAND] as $k=>$v){if(!ldap_set_option($conn,$k,$v)){throw new \DomainException('LDAP TLS/network safeguards unavailable.');}}
            if(defined('LDAP_OPT_X_TLS_PROTOCOL_MIN')&&defined('LDAP_OPT_X_TLS_PROTOCOL_TLS1_2')&&!ldap_set_option($conn,LDAP_OPT_X_TLS_PROTOCOL_MIN,LDAP_OPT_X_TLS_PROTOCOL_TLS1_2)){throw new \DomainException('LDAP TLS 1.2 minimum unavailable.');}
            if($s['ldap_starttls']&&!@ldap_start_tls($conn)){throw new \DomainException('LDAP StartTLS certificate validation failed.');}
            if(!@ldap_bind($conn,$s['ldap_bind_dn'],$s['ldap_password'])){throw new \DomainException('LDAP TLS bind failed.');}
            $attributes=array_values(array_filter(array_unique($s['ldap_attributes'])));$rows=[];$cookie='';$pages=0;
            do{$controls=[['oid'=>LDAP_CONTROL_PAGEDRESULTS,'iscritical'=>true,'value'=>['size'=>200,'cookie'=>$cookie]]];
                $result=@ldap_search($conn,$s['ldap_base_dn'],$s['ldap_filter'],$attributes,0,1001,10,LDAP_DEREF_NEVER,$controls);
                if(!$result){throw new \DomainException('LDAP paged search failed.');}$code=0;$error='';$returned=[];
                if(!ldap_parse_result($conn,$result,$code,$matched,$error,$referrals,$returned)||$code!==0){throw new \DomainException('LDAP returned a partial or failed result. No deprovisioning is permitted.');}
                $entries=ldap_get_entries($conn,$result);ldap_free_result($result);
                for($i=0;$i<$entries['count'];$i++){$r=[];foreach($s['ldap_attributes'] as $key=>$attribute){
                        if($attribute===''){$value='';}else{$values=$entries[$i][strtolower($attribute)]??null;
                            if($values===null){$value='';}else{if(!is_array($values)||!is_int($values['count']??null)||$values['count']<0||$values['count']>1||($values['count']===1&&!array_key_exists(0,$values))){throw new \DomainException('LDAP returned an ambiguous mapped attribute. No import was made.');}$value=$values['count']===1?$values[0]:'';}}
                        if(!is_string($value)){throw new \DomainException('LDAP returned an invalid attribute value. No import was made.');}
                        if($key==='external_id'&&$value===''){throw new \DomainException('LDAP person is missing its stable ID attribute. No import or deprovisioning was made. Review the saved attribute mapping and directory read permissions.');}
                        if($key==='external_id'&&(strtolower($attribute)==='objectguid'||!preg_match('//u',$value)||preg_match('/[\x00-\x1f]/',$value))){$value='binary:'.base64_encode($value);}
                        if($key==='active'){
                            $r['active']=self::ldapActive($s,$value);
                        }else{$r[$key]=$value;}}
                    $rows[]=$r;if(count($rows)>1000){throw new \DomainException('LDAP result exceeds 1000 people; no partial import was made.');}}
                $pageControl=$returned[LDAP_CONTROL_PAGEDRESULTS]??null;$pageValue=is_array($pageControl)?($pageControl['value']??null):null;$nextCookie=is_array($pageValue)?($pageValue['cookie']??null):null;
                if(!is_string($nextCookie)||strlen($nextCookie)>4096){throw new \DomainException('LDAP server did not honor mandatory bounded paging.');}$cookie=$nextCookie;if(++$pages>10){throw new \DomainException('LDAP paging failed to terminate.');}
            }while($cookie!=='');return DirectoryConfig::records($rows);
        }finally{ldap_unbind($conn);}
    }
    public static function scim(array $s,?callable $http=null): array
    {
        $http??=[EnterpriseIdentityHttp::class,'request'];$rows=[];$start=1;$total=null;$host=strtolower(parse_url($s['scim_base_url'],PHP_URL_HOST));
        for($page=0;$page<10;$page++){
            // EnterpriseIdentityHttp permits query-free endpoints only. The
            // query is supplied via a narrowly constructed SCIM request below.
            $url=$s['scim_base_url'].'/Users?startIndex='.$start.'&count=200';
            $d=$http($url,[$host],'GET',[],['Accept: application/scim+json','Authorization: Bearer '.$s['scim_token']]);
            if(!is_array($d['schemas']??null)||!array_is_list($d['schemas'])||count($d['schemas'])>16
                ||!in_array('urn:ietf:params:scim:api:messages:2.0:ListResponse',$d['schemas'],true)||!is_int($d['totalResults']??null)||$d['totalResults']<0||$d['totalResults']>1000
                ||!is_array($d['Resources']??null)||!array_is_list($d['Resources'])||($d['startIndex']??1)!==$start||count($d['Resources'])>200){throw new \DomainException('SCIM returned an invalid or oversized complete snapshot.');}
            if($total!==null&&$total!==$d['totalResults']){throw new \DomainException('SCIM changed during paging; retry the dry run.');}$total=$d['totalResults'];
            foreach($d['Resources'] as $u){if(!is_array($u)||!is_bool($u['active']??true)||!is_array($u['name']??[])||!is_string($u['name']['givenName']??'')||!is_string($u['name']['familyName']??'')){throw new \DomainException('SCIM user activity or name is invalid. No import was made.');}
                $emails=$u['emails']??[];if(!is_array($emails)||!array_is_list($emails)){throw new \DomainException('SCIM email values must be a list. No import was made.');}$email='';$primary=0;
                foreach($emails as $e){if(!is_array($e)||!is_string($e['value']??'')||!is_bool($e['primary']??false)){throw new \DomainException('SCIM email entry is invalid. No import was made.');}if(($e['primary']??false)===true){$email=$e['value']??'';$primary++;}}
                if($primary>1){throw new \DomainException('SCIM primary email is ambiguous. No import was made.');}if($email===''&&count($emails)===1){$email=$emails[0]['value']??'';}
                $record=['external_id'=>$u['id']??'','name'=>$u['displayName']??trim(($u['name']['givenName']??'').' '.($u['name']['familyName']??'')),'email'=>$email,'active'=>$u['active']??true];
                foreach($s['scim_attributes']??[] as $field=>$pointer){if($pointer===''){$record[$field]='';continue;}$value=$u;foreach(explode('/',substr($pointer,1)) as $part){$value=is_array($value)?($value[$part]??''):'';}if(!is_string($value)){throw new \DomainException('SCIM mapped user attribute is not text. No import was made.');}$record[$field]=$field==='name'&&$pointer==='/displayName'&&$value===''?$record['name']:$value;}
                $rows[]=$record;}
            $start+=count($d['Resources']);if(count($rows)===$total){return DirectoryConfig::records($rows);}if(!$d['Resources']||count($rows)>$total){throw new \DomainException('SCIM paging was partial. No import was made.');}
        }throw new \DomainException('SCIM paging exceeded its limit.');
    }
    public static function revision(array $settings): string
    {
        return hash('sha256',json_encode([$settings['directory_sync']??[],$settings['incident_workflows']??[],$settings['subscriber_browser']??[]],JSON_THROW_ON_ERROR));
    }
    public static function plan(array $settings,array $source,array $incoming): array
    {
        $incoming=DirectoryConfig::records($incoming);$old=[];$new=[];
        foreach($source['records'] as $record){$old[hash('sha256',$record['external_id'])]=$record;}
        foreach($incoming as $record){$new[hash('sha256',$record['external_id'])]=$record;}
        $changes=[];
        foreach($new as $identity=>$row){$id=$row['external_id'];if(!isset($old[$identity])){$changes[]=['kind'=>$row['active']?'add':'inactive','external_id'=>$id,'person_id'=>DirectoryConfig::personId($source['id'],$id),'name'=>$row['name']];}
            elseif($row!==$old[$identity]){$changes[]=['kind'=>$old[$identity]['active']&&!$row['active']?'deactivate':'update','external_id'=>$id,'person_id'=>DirectoryConfig::personId($source['id'],$id),'name'=>$row['name']];}}
        foreach($old as $identity=>$row){if(!isset($new[$identity])){$id=$row['external_id'];$changes[]=['kind'=>$row['active']?'deactivate':'remove_inactive','external_id'=>$id,'person_id'=>DirectoryConfig::personId($source['id'],$id),'name'=>$row['name']];}}
        // Reconcile now, so collisions and missing templates fail in preview.
        self::apply($settings,$source,$incoming);
        $deactivated=count(array_filter($changes,static fn($row)=>$row['kind']==='deactivate'));$previous=count(array_filter($source['records'],static fn($row)=>$row['active']));
        $percent=$previous?min(100,(int)ceil(100*$deactivated/$previous)):0;
        return ['source_id'=>$source['id'],'revision'=>self::revision($settings),'records'=>$incoming,'changes'=>$changes,'active'=>count(array_filter($incoming,static fn($r)=>$r['active'])),'template_ids'=>$source['template_ids'],
            'deactivation_count'=>$deactivated,'deactivation_percent'=>$percent,'requires_deactivation_confirmation'=>$deactivated>0&&$percent>=($source['maximum_deactivation_percent']??25)];
    }
    public static function apply(array $settings,array $source,array $incoming): array
    {
        $incoming=DirectoryConfig::records($incoming);
        $locations=LocationDirectory::effective($settings['location_directory']??[]);$locationIds=array_fill_keys(array_column($locations['nodes'],'id'),true);
        foreach($incoming as $record){if($record['location_id']!==''&&!isset($locationIds[$record['location_id']])){throw new \DomainException('An imported person references a location that no longer exists. Review the directory mapping before applying it.');}}
        $prior=$source['records'];foreach($settings['directory_sync']['sources']??[] as $savedSource){if($savedSource['id']===$source['id']){$prior=array_merge($prior,$savedSource['records']);}}$owned=array_fill_keys(array_map(static fn($r)=>DirectoryConfig::personId($source['id'],$r['external_id']),$prior),true);$new=[];
        foreach($incoming as $r){if(!$r['active']){continue;}$row=['id'=>DirectoryConfig::personId($source['id'],$r['external_id']),'name'=>$r['name'],'location'=>$r['location'],'desktop_username'=>$r['desktop_username']];if($r['location_id']!==''){$row['location_id']=$r['location_id'];}$new[]=$row;}
        $newIds=array_fill_keys(array_column($new,'id'),true);
        $settings['incident_workflows']=IncidentConfig::normalize($settings['incident_workflows']??[]);$found=[];
        foreach($settings['incident_workflows']['templates'] as &$template){if(!in_array($template['id'],$source['template_ids'],true)){continue;}$found[]=$template['id'];$keep=array_values(array_filter($template['roster'],static fn($r)=>!isset($owned[$r['id']])));
            foreach($keep as $row){if(isset($newIds[$row['id']])){throw new \DomainException('An imported person ID collides with a local roster entry.');}}$template['roster']=array_merge($keep,$new);$template=IncidentConfig::template($template);}unset($template);
        if(array_diff($source['template_ids'],$found)){throw new \DomainException('A selected incident template no longer exists.');}
        foreach($settings['directory_sync']['sources'] as &$s){if($s['id']===$source['id']){$s['records']=$incoming;}}unset($s);
        if($source['sync_subscribers']){
            require_once __DIR__.'/SubscriberConfig.php';$subscriber=SubscriberConfig::normalize($settings['subscriber_browser']??[]);$map=array_column($subscriber['people'],null,'person_id');
            $now=time();$incomingByPerson=[];foreach($incoming as $r){$incomingByPerson[DirectoryConfig::personId($source['id'],$r['external_id'])]=$r;}
            foreach($source['records'] as $r){$pid=DirectoryConfig::personId($source['id'],$r['external_id']);if(!isset($map[$pid])){continue;}$next=$incomingByPerson[$pid]??null;$willRemainEnabled=$next!==null&&$next['active']&&$next['email']!=='';
                if(!$willRemainEnabled){if($map[$pid]['enabled']){$map[$pid]['version']=bin2hex(random_bytes(32));$map[$pid]['registered_at']=$now;}$map[$pid]['enabled']=false;}}
            foreach($incoming as $r){$pid=DirectoryConfig::personId($source['id'],$r['external_id']);if($r['email']===''){continue;}$prior=$map[$pid]??null;
                $same=$prior&&$prior['email']===$r['email']&&$prior['name']===$r['name']&&$prior['enabled']===$r['active'];
                $map[$pid]=['id'=>$prior['id']??'sub_'.substr(hash('sha256',$pid),0,24),'person_id'=>$pid,'name'=>$r['name'],'email'=>$r['email'],'enabled'=>$r['active'],
                    'version'=>$same?$prior['version']:bin2hex(random_bytes(32)),'registered_at'=>$same?$prior['registered_at']:$now];}
            $subscriber['people']=array_values($map);$settings['subscriber_browser']=SubscriberConfig::normalize($subscriber);
        }return $settings;
    }
}
