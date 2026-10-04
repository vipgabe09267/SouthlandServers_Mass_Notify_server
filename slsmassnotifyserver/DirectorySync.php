<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/DirectoryConfig.php';
require_once __DIR__.'/EnterpriseIdentityHttp.php';
require_once __DIR__.'/IncidentConfig.php';

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
                for($i=0;$i<$entries['count'];$i++){$r=[];foreach($s['ldap_attributes'] as $key=>$attribute){$value=$attribute===''?'':($entries[$i][strtolower($attribute)][0]??'');
                        if($key==='external_id'&&(strtolower($attribute)==='objectguid'||!preg_match('//u',$value)||preg_match('/[\x00-\x1f]/',$value))){$value='binary:'.base64_encode($value);}
                        if($key==='active'){$r['active']=$attribute===''||!in_array(strtolower($value),array_map('strtolower',$s['ldap_inactive_values']),true);}else{$r[$key]=$value;}}
                    $rows[]=$r;if(count($rows)>1000){throw new \DomainException('LDAP result exceeds 1000 people; no partial import was made.');}}
                if(!isset($returned[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'])){throw new \DomainException('LDAP server did not honor mandatory paging.');}$cookie=$returned[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'];if(++$pages>10){throw new \DomainException('LDAP paging failed to terminate.');}
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
            if(!in_array('urn:ietf:params:scim:api:messages:2.0:ListResponse',$d['schemas']??[],true)||!is_int($d['totalResults']??null)||$d['totalResults']<0||$d['totalResults']>1000
                ||!is_array($d['Resources']??null)||!array_is_list($d['Resources'])||($d['startIndex']??1)!==$start||count($d['Resources'])>200){throw new \DomainException('SCIM returned an invalid or oversized complete snapshot.');}
            if($total!==null&&$total!==$d['totalResults']){throw new \DomainException('SCIM changed during paging; retry the dry run.');}$total=$d['totalResults'];
            foreach($d['Resources'] as $u){if(!is_array($u)||!is_bool($u['active']??true)){throw new \DomainException('SCIM active flag invalid.');}$emails=$u['emails']??[];$email='';foreach($emails as $e){if(($e['primary']??false)===true){$email=$e['value']??'';break;}}if($email===''&&count($emails)===1){$email=$emails[0]['value']??'';}
                $rows[]=['external_id'=>$u['id']??'','name'=>$u['displayName']??trim(($u['name']['givenName']??'').' '.($u['name']['familyName']??'')),'email'=>$email,'active'=>$u['active']??true];}
            $start+=count($d['Resources']);if(count($rows)===$total){return DirectoryConfig::records($rows);}if(!$d['Resources']||count($rows)>$total){throw new \DomainException('SCIM paging was partial. No import was made.');}
        }throw new \DomainException('SCIM paging exceeded its limit.');
    }
    public static function revision(array $settings): string
    {
        return hash('sha256',json_encode([$settings['directory_sync']??[],$settings['incident_workflows']??[],$settings['subscriber_browser']??[]],JSON_THROW_ON_ERROR));
    }
    public static function plan(array $settings,array $source,array $incoming): array
    {
        $incoming=DirectoryConfig::records($incoming);$old=array_column($source['records'],null,'external_id');$new=array_column($incoming,null,'external_id');$changes=[];
        foreach($new as $id=>$row){if(!isset($old[$id])){$changes[]=['kind'=>$row['active']?'add':'inactive','external_id'=>$id,'person_id'=>DirectoryConfig::personId($source['id'],(string)$id),'name'=>$row['name']];}
            elseif($row!==$old[$id]){$changes[]=['kind'=>!$row['active']?'deactivate':'update','external_id'=>$id,'person_id'=>DirectoryConfig::personId($source['id'],(string)$id),'name'=>$row['name']];}}
        foreach($old as $id=>$row){if(!isset($new[$id])){$changes[]=['kind'=>'deactivate','external_id'=>$id,'person_id'=>DirectoryConfig::personId($source['id'],(string)$id),'name'=>$row['name']];}}
        // Reconcile now, so collisions and missing templates fail in preview.
        self::apply($settings,$source,$incoming);
        return ['source_id'=>$source['id'],'revision'=>self::revision($settings),'records'=>$incoming,'changes'=>$changes,'active'=>count(array_filter($incoming,static fn($r)=>$r['active'])),'template_ids'=>$source['template_ids']];
    }
    public static function apply(array $settings,array $source,array $incoming): array
    {
        $incoming=DirectoryConfig::records($incoming);$prior=$source['records'];foreach($settings['directory_sync']['sources']??[] as $savedSource){if($savedSource['id']===$source['id']){$prior=array_merge($prior,$savedSource['records']);}}$owned=array_values(array_unique(array_map(static fn($r)=>DirectoryConfig::personId($source['id'],$r['external_id']),$prior)));$new=[];
        foreach($incoming as $r){if(!$r['active']){continue;}$row=['id'=>DirectoryConfig::personId($source['id'],$r['external_id']),'name'=>$r['name'],'location'=>$r['location'],'desktop_username'=>$r['desktop_username']];if($r['location_id']!==''){$row['location_id']=$r['location_id'];}$new[]=$row;}
        $settings['incident_workflows']=IncidentConfig::normalize($settings['incident_workflows']??[]);$found=[];
        foreach($settings['incident_workflows']['templates'] as &$template){if(!in_array($template['id'],$source['template_ids'],true)){continue;}$found[]=$template['id'];$keep=array_values(array_filter($template['roster'],static fn($r)=>!in_array($r['id'],$owned,true)));
            if(array_intersect(array_column($keep,'id'),array_column($new,'id'))){throw new \DomainException('An imported person ID collides with a local roster entry.');}$template['roster']=array_merge($keep,$new);$template=IncidentConfig::template($template);}unset($template);
        if(array_diff($source['template_ids'],$found)){throw new \DomainException('A selected incident template no longer exists.');}
        foreach($settings['directory_sync']['sources'] as &$s){if($s['id']===$source['id']){$s['records']=$incoming;}}unset($s);
        if($source['sync_subscribers']){
            require_once __DIR__.'/SubscriberConfig.php';$subscriber=SubscriberConfig::normalize($settings['subscriber_browser']??[]);$map=array_column($subscriber['people'],null,'person_id');
            foreach($source['records'] as $r){$pid=DirectoryConfig::personId($source['id'],$r['external_id']);if(isset($map[$pid])){$map[$pid]['enabled']=false;}}
            foreach($incoming as $r){$pid=DirectoryConfig::personId($source['id'],$r['external_id']);if($r['email']===''){continue;}$prior=$map[$pid]??null;$map[$pid]=['id'=>$prior['id']??'sub_'.substr(hash('sha256',$pid),0,24),'person_id'=>$pid,'name'=>$r['name'],'email'=>$r['email'],'enabled'=>$r['active'],
                    'version'=>$prior&&$prior['email']===$r['email']&&$prior['name']===$r['name']?$prior['version']:bin2hex(random_bytes(32)),'registered_at'=>$prior&&$prior['email']===$r['email']&&$prior['name']===$r['name']?$prior['registered_at']:time()];}
            $subscriber['people']=array_values($map);$settings['subscriber_browser']=SubscriberConfig::normalize($subscriber);
        }return $settings;
    }
}
