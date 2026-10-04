<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Short-lived, authenticated read capability for one test's exact recipients. */
final class TestDeliveryReport
{
    private static function key(array $settings): string
    {
        $key=base64_decode($settings['desktop_auth_key']??'',true);
        if (!is_string($key) || strlen($key)!==32) { throw new \DomainException('The protected delivery-report key is unavailable. Check module readiness.'); }
        return hash_hkdf('sha256',$key,32,'sls-test-delivery-report-v1');
    }

    public static function ticket(array $context, array $settings): string
    {
        $json=json_encode($context,JSON_THROW_ON_ERROR);
        if (strlen($json)>1048576) { throw new \DomainException('This test exceeds the delivery report size limit. Test fewer areas at once.'); }
        $body=rtrim(strtr(base64_encode($json),'+/','-_'),'=');
        return $body.'.'.hash_hmac('sha256',$body,self::key($settings));
    }

    public static function context($ticket, array $settings, int $now): array
    {
        if (!is_string($ticket) || strlen($ticket)>1398168 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$ticket,$parts)
            || !hash_equals(hash_hmac('sha256',$parts[1],self::key($settings)),$parts[2])) {
            throw new \DomainException('The test delivery report is invalid. Run a new test from this page.');
        }
        $raw=base64_decode(strtr($parts[1],'-_','+/'),true);
        $value=is_string($raw)?json_decode($raw,true,16,JSON_THROW_ON_ERROR):null;
        if (!is_array($value) || array_diff(array_keys($value),['correlation','created_at','phones','desktops'])
            || !is_string($value['correlation']??null) || !preg_match('/^test_[a-f0-9]{32}$/D',$value['correlation'])
            || !is_int($value['created_at']??null) || $value['created_at']>$now || $now-$value['created_at']>=1800
            || !is_array($value['phones']??null) || !array_is_list($value['phones']) || count($value['phones'])>1000
            || !is_array($value['desktops']??null) || !array_is_list($value['desktops']) || count($value['desktops'])>3000) {
            throw new \DomainException('The test delivery report expired or has an unsupported format.');
        }
        foreach ($value['phones'] as $phone) {
            if (!is_string($phone) || !preg_match('/^[0-9]{1,20}$/D',$phone)) { throw new \DomainException('Invalid test phone recipient.'); }
        }
        foreach ($value['desktops'] as $row) {
            if (!is_array($row) || array_diff(array_keys($row),['event_id','target','client_id'])
                || !is_string($row['event_id']??null) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$row['event_id'])
                || !is_string($row['target']??null) || !preg_match('/^[A-Za-z0-9_.@-]{1,80}$/D',$row['target'])
                || !is_string($row['client_id']??null) || !preg_match('/^[A-Za-z0-9_-]{0,80}$/D',$row['client_id'])) {
                throw new \DomainException('Invalid test desktop recipient.');
            }
        }
        return $value;
    }

    public static function phoneRows(array $phones, array $evidence): array
    {
        $rows=[];
        foreach ($phones as $phone) {
            $contacts=array_values(array_filter($evidence['targets']??[],static fn($r)=>($r['recipient_id']??'')===$phone));
            $answered=(bool)array_filter($contacts,static fn($r)=>!empty($r['answered']));
            $joined=(bool)array_filter($contacts,static fn($r)=>!empty($r['joined']));
            $failed=$contacts && empty($evidence['active']) && !array_filter($contacts,static fn($r)=>!empty($r['uncertain']) || empty($r['ended']) || !in_array($r['dial_status']??'',['BUSY','NOANSWER','CANCEL','CONGESTION','CHANUNAVAIL','DONTCALL','TORTURE','INVALIDARGS'],true));
            $rows[]=['channel'=>'audio','target'=>$phone,'state'=>$answered?'answered':($failed?'failed':'pending'),
                'detail'=>$answered?($joined?'Phone answered; Asterisk confirmed conference entry.':'Phone answered.'):
                    ($failed?'The call ended without a confirmed answer.':'Awaiting phone answer evidence.'),
                'answered'=>$answered,'joined'=>$joined,'playback_confirmed'=>false];
        }
        return $rows;
    }
}
