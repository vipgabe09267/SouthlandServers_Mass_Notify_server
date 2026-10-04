<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/IncidentConfig.php';

/** Opt-in device/provider settings; the central AES config owns every secret. */
final class EnterpriseIntegrationsConfig
{
    public static function defaults(): array
    {
        return ['enabled'=>'0','speakers'=>['enabled'=>'0','devices'=>[]],
            'access_control'=>['enabled'=>'0','provider'=>'axis_vapix','origin'=>'','pinned_ipv4'=>'','username'=>'','password'=>'','doors'=>[]],
            'meetings'=>['enabled'=>'0','provider'=>'zoom','zoom_user'=>'me','zoom_access_token'=>'','google_access_token'=>'','webex_access_token'=>''],
            'responses'=>['enabled'=>'0','ttl_seconds'=>900,'bindings'=>[]],
            'sensors'=>['enabled'=>'0','heartbeat_timeout_seconds'=>180],
            'public_warning'=>['enabled'=>'0','ipaws_enabled'=>'0','endpoint'=>'','soap_action'=>'','logon_user'=>'','cog_id'=>'','certificate_pem'=>'','private_key_pem'=>'','private_key_password'=>'','authority_confirmed'=>false]];
    }
    public static function fingerprint(array $value): string
    {
        $canonical=static function($item) use(&$canonical) {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item,SORT_STRING); }
            foreach ($item as &$child) { $child=$canonical($child); } unset($child); return $item;
        };
        return hash('sha256',json_encode($canonical($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    public static function requestId($value): string { return IncidentConfig::identifier($value,'/^[a-f0-9]{32}$/D','Integration request identifier'); }
    public static function flag($value): string
    {
        if (!in_array($value,['0','1',0,1,false,true],true)) { throw new \InvalidArgumentException('Integration Enabled must be on or off.'); }
        return empty($value)?'0':'1';
    }
    private static function integer($value,int $minimum,int $maximum,string $label): int
    {
        if (!is_int($value) || $value<$minimum || $value>$maximum) { throw new \InvalidArgumentException($label.' is outside its permitted range.'); }
        return $value;
    }
    public static function id($value,string $prefix): string { return IncidentConfig::identifier($value,'/^'.preg_quote($prefix,'/').'_[a-f0-9]{24}$/D','Saved integration identifier'); }
    private static function secret($value,int $limit=8192): string
    {
        if (!is_string($value) || strlen($value)>$limit || str_contains($value,"\0")) { throw new \InvalidArgumentException('Provider credential is invalid or too large.'); }
        return $value;
    }
    public static function catalog(): array
    {
        return [
            'algo'=>['brand'=>'Algo','model'=>'8180 G2','docs'=>'https://docs.algosolutions.com/v1/docs/8180-ip-audio-alerter-user-guide'],
            'cyberdata'=>['brand'=>'CyberData','model'=>'011394','docs'=>'https://www.cyberdata.net/products/011394'],
            'axis'=>['brand'=>'Axis','model'=>'SIP-capable network speaker','docs'=>'https://developer.axis.com/vapix/audio-systems/'],
            'valcom'=>['brand'=>'Valcom','model'=>'VIP-120A-V4','docs'=>'https://www.valcom.com/vlc-product/ip-8-inch-round-ceiling-speaker-one-way-vip-120a-v4/'],
            'atlasied'=>['brand'=>'AtlasIED','model'=>'IPX IP-SDM','docs'=>'https://www.atlasied.com/ip-sdm']];
    }
    public static function origin($origin,$ip): void
    {
        if ($origin==='' && $ip==='') { return; }
        if (!is_string($origin) || !preg_match('#^https://([a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)(?::([1-9][0-9]{0,4}))?$#Di',$origin,$parts)
            || (isset($parts[2]) && (int)$parts[2]>65535) || !is_string($ip) || !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)
            || !self::privateAddress($ip)) { throw new \InvalidArgumentException('Use an HTTPS device origin and a pinned private unicast IPv4 address.'); }
        if (filter_var($parts[1],FILTER_VALIDATE_IP) && $parts[1]!==$ip) { throw new \InvalidArgumentException('A literal device origin must match its pinned address.'); }
    }
    public static function privateAddress(string $ip): bool
    {
        $long=ip2long($ip); if ($long===false) { return false; } $n=sprintf('%u',$long);
        foreach ([['10.0.0.0',8],['172.16.0.0',12],['192.168.0.0',16]] as [$net,$bits]) {
            $mask=(0xffffffff << (32-$bits)) & 0xffffffff;
            if (((int)$n & $mask)===(ip2long($net) & $mask)) { return true; }
        }
        return false;
    }
    public static function normalize($value): array
    {
        $defaults=self::defaults(); $value=IncidentConfig::object($value,array_keys($defaults),'Enterprise integrations'); $out=$defaults; $out['enabled']=self::flag($value['enabled']??'0');
        foreach ($defaults as $kind=>$base) {
            if ($kind==='enabled') { continue; }
            $row=IncidentConfig::object($value[$kind]??[],array_keys($base),$kind.' integration'); $out[$kind]=array_replace($base,$row);
            $out[$kind]['enabled']=self::flag($out[$kind]['enabled']);
        }
        $ids=[]; $extensions=[]; $devices=[];
        foreach (IncidentConfig::listOf($out['speakers']['devices'],100,'SIP speakers') as $row) {
            $row=IncidentConfig::object($row,['id','name','enabled','brand','model','extension','telemetry_origin','telemetry_ipv4','telemetry_username','telemetry_password'],'SIP speaker');
            $id=self::id($row['id']??'','spk'); if (isset($ids[$id])) { throw new \InvalidArgumentException('Duplicate speaker identifier.'); } $ids[$id]=true;
            $brand=$row['brand']??''; if (!isset(self::catalog()[$brand])) { throw new \InvalidArgumentException('Choose a documented representative speaker brand.'); }
            $extension=IncidentConfig::identifier($row['extension']??'','/^[0-9]{1,12}$/D','Existing PBX extension');
            if (isset($extensions[$extension])) { throw new \InvalidArgumentException('An extension can belong to only one saved speaker.'); } $extensions[$extension]=true;
            $origin=$row['telemetry_origin']??''; $ip=$row['telemetry_ipv4']??''; self::origin($origin,$ip);
            if ($origin!=='' && $brand!=='axis') { throw new \InvalidArgumentException('This release has a documented read-only telemetry adapter only for Axis speakers.'); }
            $devices[]=['id'=>$id,'name'=>IncidentConfig::text($row['name']??'',100,'Speaker name'),'enabled'=>self::flag($row['enabled']??'0'),'brand'=>$brand,
                'model'=>IncidentConfig::text($row['model']??self::catalog()[$brand]['model'],100,'Speaker model'),'extension'=>$extension,
                'telemetry_origin'=>$origin,'telemetry_ipv4'=>$ip,'telemetry_username'=>self::secret($row['telemetry_username']??'',100),'telemetry_password'=>self::secret($row['telemetry_password']??'')];
        }
        $out['speakers']['devices']=$devices;
        $access=&$out['access_control']; if ($access['provider']!=='axis_vapix') { throw new \InvalidArgumentException('Only the documented Axis VAPIX door adapter is available.'); }
        self::origin($access['origin'],$access['pinned_ipv4']); $access['username']=self::secret($access['username'],100); $access['password']=self::secret($access['password']);
        $doors=[]; $ids=[]; $tokens=[];
        foreach (IncidentConfig::listOf($access['doors'],50,'Allowed doors') as $row) {
            $row=IncidentConfig::object($row,['id','name','token','allow_read','allow_lock','allow_unlock'],'Door permission'); $id=self::id($row['id']??'','door');
            $token=IncidentConfig::text($row['token']??'',256,'Device door token');
            if (isset($ids[$id]) || isset($tokens[$token])) { throw new \InvalidArgumentException('Door identifiers and controller tokens must be distinct.'); } $ids[$id]=true; $tokens[$token]=true;
            $doors[]=['id'=>$id,'name'=>IncidentConfig::text($row['name']??'',100,'Door name'),'token'=>$token,
                'allow_read'=>IncidentConfig::flag($row['allow_read']??true,'Door read permission'),
                'allow_lock'=>IncidentConfig::flag($row['allow_lock']??false,'Door lock permission'),
                'allow_unlock'=>IncidentConfig::flag($row['allow_unlock']??false,'Door unlock permission')];
        } $access['doors']=$doors; unset($access);
        $meeting=&$out['meetings']; if (!in_array($meeting['provider'],['zoom','google_meet','webex'],true)) { throw new \InvalidArgumentException('Choose Zoom, Google Meet or Webex.'); }
        $meeting['zoom_user']=IncidentConfig::identifier($meeting['zoom_user'],'/^[A-Za-z0-9_.@+-]{1,128}$/D','Zoom host');
        foreach (['zoom_access_token','google_access_token','webex_access_token'] as $field) { $meeting[$field]=self::secret($meeting[$field]); if (preg_match('/[\r\n]/',$meeting[$field])) { throw new \InvalidArgumentException('OAuth access tokens cannot contain line breaks.'); } } unset($meeting);
        $response=&$out['responses']; $response['ttl_seconds']=self::integer($response['ttl_seconds'],60,86400,'Human response validity');
        $bindings=[]; $seen=[];
        foreach (IncidentConfig::listOf($response['bindings'],1000,'Participant response bindings') as $row) {
            $row=IncidentConfig::object($row,['person_id','sms_recipient_id','voice_recipient_id'],'Response participant');
            $person=IncidentConfig::identifier($row['person_id']??'','/^[A-Za-z0-9_-]{1,64}$/D','Incident participant');
            $sms=$row['sms_recipient_id']??''; $voice=$row['voice_recipient_id']??'';
            if ($sms!=='') { self::id($sms,'sms'); } if ($voice!=='') { self::id($voice,'voice'); }
            if ($sms==='' && $voice==='') { throw new \InvalidArgumentException('A participant binding needs a saved SMS or voice recipient.'); }
            foreach ([$sms,$voice] as $id) { if ($id!=='') { if (isset($seen[$id])) { throw new \InvalidArgumentException('A saved recipient cannot answer for two participants.'); } $seen[$id]=true; } }
            $bindings[]=['person_id'=>$person,'sms_recipient_id'=>$sms,'voice_recipient_id'=>$voice];
        } $response['bindings']=$bindings; unset($response);
        $out['sensors']['heartbeat_timeout_seconds']=self::integer($out['sensors']['heartbeat_timeout_seconds'],30,3600,'Heartbeat timeout');
        $warning=&$out['public_warning']; $warning['ipaws_enabled']=self::flag($warning['ipaws_enabled']);
        if ($warning['endpoint']!=='' && (!is_string($warning['endpoint']) || !preg_match('#^https://(?:[a-z0-9-]+\.)*(?:fema\.gov)/IPAWS_CAPService/IPAWS$#Di',$warning['endpoint']))) { throw new \InvalidArgumentException('Use the exact FEMA-issued HTTPS IPAWS CAP service endpoint.'); }
        $warning['soap_action']=self::secret($warning['soap_action'],512);
        if (preg_match('/[\x00-\x20\"]/', $warning['soap_action'])) { throw new \InvalidArgumentException('SOAPAction must match the authority-issued WSDL without whitespace or quotes.'); }
        foreach (['logon_user','cog_id'] as $field) { $warning[$field]=IncidentConfig::text($warning[$field],100,'IPAWS identity',true); }
        foreach (['certificate_pem','private_key_pem'] as $field) { $warning[$field]=self::secret($warning[$field],20000); }
        $warning['private_key_password']=self::secret($warning['private_key_password']); $warning['authority_confirmed']=IncidentConfig::flag($warning['authority_confirmed'],'Authorized COG confirmation'); unset($warning);
        return $out;
    }
    public static function redacted(array $value): array
    {
        $value=self::normalize($value);
        $hide=static function(array &$row,string $field): void { $row[$field.'_configured']=$row[$field]!==''; unset($row[$field]); };
        $hide($value['access_control'],'password');
        foreach ($value['speakers']['devices'] as &$row) { $hide($row,'telemetry_password'); } unset($row);
        foreach (['zoom_access_token','google_access_token','webex_access_token'] as $field) { $hide($value['meetings'],$field); }
        foreach (['certificate_pem','private_key_pem','private_key_password'] as $field) { $hide($value['public_warning'],$field); }
        return $value;
    }
}
