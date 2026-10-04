<?php
declare(strict_types=1);
namespace SLS\MassNotify\Sms;

/** All operator settings and provider secrets belong in mass-notifications.config. */
final class Config
{
    public const MAX_RECIPIENTS = 50;
    public static function defaults(): array
    {
        return ['enabled'=>false, 'provider'=>'', 'from'=>'', 'organization'=>'', 'currency'=>'USD',
            'max_segments'=>3, 'daily_segments'=>100, 'monthly_segments'=>1000,
            'segment_cost_micros'=>0, 'message_format'=>'sms', 'mms_cost_micros'=>0,
            'daily_budget_micros'=>10000000, 'monthly_budget_micros'=>100000000,
            'twilio_account_sid'=>'', 'twilio_key_sid'=>'', 'twilio_key_secret'=>'', 'twilio_auth_token'=>'',
            'telnyx_api_key'=>'', 'telnyx_public_key'=>'', 'telnyx_profile_id'=>'',
            'bulkvs_api_username'=>'', 'bulkvs_api_password'=>'', 'recipients'=>[]];
    }
    public static function number($value): bool { return is_string($value) && (bool)preg_match('/^\+[1-9][0-9]{7,14}$/D', $value); }
    public static function id($value): bool { return is_string($value) && (bool)preg_match('/^sms_[a-f0-9]{24}$/D', $value); }
    public static function uuid($value): bool { return is_string($value) && (bool)preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $value); }
    public static function amount($value): int
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,6})?$/D', $value)) {
            throw new \DomainException('SMS amounts must be nonnegative numbers with at most six decimal places.');
        }
        $parts=explode('.', $value);
        return (int)$parts[0]*1000000+(int)str_pad($parts[1]??'',6,'0');
    }
    private static function text($value, int $limit, bool $empty = true): bool
    {
        return is_string($value) && preg_match('//u',$value) && mb_strlen($value)<=$limit
            && ($empty || trim($value)!=='') && !preg_match('/[\p{C}]/u',$value);
    }
    public static function normalize($value, bool $new = false): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value),array_keys(self::defaults()))) {
            throw new \DomainException('SMS settings must be an object containing supported settings only.');
        }
        $v=array_replace(self::defaults(),$value);
        if (!in_array($v['enabled'],[false,true,0,1,'0','1'],true)) { throw new \DomainException('SMS Enabled must be on or off.'); }
        $v['enabled']=in_array($v['enabled'],[true,1,'1'],true);
        if (!in_array($v['provider'],['','twilio','telnyx','bulkvs'],true)) { throw new \DomainException('Select Twilio, Telnyx or BulkVS as the SMS provider.'); }
        if (!in_array($v['message_format'],['sms','mms'],true)) { throw new \DomainException('Select SMS text or MMS with an alert image.'); }
        if (($v['from']!=='' && !self::number($v['from'])) || !self::text($v['organization'],40)
            || !is_string($v['currency']) || !preg_match('/^[A-Z]{3}$/D',$v['currency'])) {
            throw new \DomainException('SMS needs a sender in +countrycode format, an organization name up to 40 characters and a three-letter account currency.');
        }
        foreach (['max_segments'=>[1,10], 'daily_segments'=>[1,1000000], 'monthly_segments'=>[1,10000000],
            'segment_cost_micros'=>[0,100000000], 'mms_cost_micros'=>[0,100000000], 'daily_budget_micros'=>[1,999999999999], 'monthly_budget_micros'=>[1,999999999999]] as $key=>$range) {
            if (!is_int($v[$key]) || $v[$key]<$range[0] || $v[$key]>$range[1]) { throw new \DomainException('Invalid SMS limit: '.$key.'. Enter a whole number within the displayed range.'); }
        }
        foreach (['twilio_account_sid','twilio_key_sid','twilio_key_secret','twilio_auth_token','telnyx_api_key','telnyx_public_key','telnyx_profile_id','bulkvs_api_username','bulkvs_api_password'] as $key) {
            if (!is_string($v[$key]) || strlen($v[$key])>512 || ($v[$key]!=='' && preg_match('/[^\x21-\x7e]/',$v[$key]))) {
                throw new \DomainException('SMS provider credentials must be bounded text without spaces or control characters: '.$key.'.');
            }
        }
        if (str_contains($v['bulkvs_api_username'], ':')) { throw new \DomainException('The BulkVS API username cannot contain a colon. Use the API credentials from the provider portal.'); }
        if (($v['twilio_account_sid']!=='' && !preg_match('/^AC[a-f0-9]{32}$/Di',$v['twilio_account_sid']))
            || ($v['twilio_key_sid']!=='' && !preg_match('/^SK[a-f0-9]{32}$/Di',$v['twilio_key_sid']))
            || ($v['twilio_auth_token']!=='' && !preg_match('/^[a-f0-9]{32}$/Di',$v['twilio_auth_token']))
            || ($v['telnyx_profile_id']!=='' && !self::uuid($v['telnyx_profile_id']))
            || ($v['telnyx_public_key']!=='' && strlen((string)base64_decode($v['telnyx_public_key'],true))!==32)) {
            throw new \DomainException('An SMS account SID, key SID, auth token, messaging profile or verification key has an invalid format.');
        }
        if (!is_array($v['recipients']) || !array_is_list($v['recipients']) || count($v['recipients'])>self::MAX_RECIPIENTS) {
            throw new \DomainException('Save at most 50 SMS recipients.');
        }
        $ids=[]; $numbers=[];
        foreach ($v['recipients'] as &$row) {
            if (!is_array($row) || array_diff(array_keys($row),['id','name','number','enabled','consent','consent_note','consent_at'])) {
                throw new \DomainException('SMS recipient fields are invalid.');
            }
            $id=$row['id']??'';
            if (($id==='' && !$new) || ($id!=='' && !self::id($id)) || !self::text($row['name']??null,80,false)
                || !self::number($row['number']??null) || !is_bool($row['consent']??null)
                || !is_bool($row['enabled']??null) || !self::text($row['consent_note']??'',160)) {
                throw new \DomainException('Each SMS recipient needs a name, a unique +countrycode number, Enabled and Consent checkboxes, and a valid saved ID.');
            }
            if (($id!=='' && isset($ids[$id])) || isset($numbers[$row['number']])) { throw new \DomainException('An SMS recipient ID or number is duplicated.'); }
            if ($row['consent'] && trim($row['consent_note']??'')==='') { throw new \DomainException('Record how the SMS recipient agreed to receive alerts in the consent note.'); }
            $at=$row['consent_at']??'';
            if (!is_string($at) || ($at!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$at) || strtotime($at)===false))) {
                throw new \DomainException('The SMS consent timestamp is invalid.');
            }
            if ($row['consent'] && $at==='') {
                if (!$new) { throw new \DomainException('An SMS recipient with consent needs its recorded consent timestamp.'); }
                $at=gmdate('c');
            }
            $row['id']=$id!==''?$id:'sms_'.bin2hex(random_bytes(12));
            $row['name']=trim($row['name']); $row['consent_at']=$at;
            $row['consent_note']=trim($row['consent_note']??'');
            $ids[$row['id']]=true; $numbers[$row['number']]=true;
        }
        unset($row);
        if ($v['enabled']) {
            if ($v['provider']==='' || $v['from']==='' || trim($v['organization'])==='' || ($v['message_format']==='sms' && $v['segment_cost_micros']<1)) {
                throw new \DomainException('Before enabling SMS, select a provider, sender, organization and budgeted cost per segment.');
            }
            $keys=match ($v['provider']) {
                'twilio'=>['twilio_account_sid','twilio_key_sid','twilio_key_secret','twilio_auth_token'],
                'bulkvs'=>['bulkvs_api_username','bulkvs_api_password'],
                default=>['telnyx_api_key','telnyx_public_key','telnyx_profile_id'],
            };
            foreach ($keys as $key) { if ($v[$key]==='') { throw new \DomainException('Complete the SMS provider setting: '.$key.'.'); } }
            if ($v['message_format']==='mms' && $v['mms_cost_micros']<1) { throw new \DomainException('Set a budgeted cost per MMS message before selecting MMS. Include provider and carrier fees.'); }
        }
        return $v;
    }
    public static function route(array $config, string $callback): string
    {
        $account=match ($config['provider']) {
            'twilio'=>$config['twilio_account_sid'], 'bulkvs'=>$config['bulkvs_api_username'],
            default=>[$config['telnyx_profile_id'],$config['telnyx_public_key']],
        };
        return hash('sha256',json_encode([$config['provider'],$account,$config['from'],$callback],JSON_THROW_ON_ERROR));
    }
    public static function body(array $config, string $title, string $message, bool $test): string
    {
        $body=($test?'[TEST] ':'').$config['organization'].": ".$title."\n".$message;
        // These providers have authenticated inbound STOP handling in SLS.
        // Do not promise a working reply route for an unsupported integration.
        return $body.(in_array($config['provider']??'', ['twilio','telnyx'], true)?"\nReply STOP to opt out.":'');
    }
    public static function units(string $body, string $from, string $type): array
    {
        $sms=self::segments($body,$from); // Both formats retain the full valid text.
        if (!in_array($type,['SMS','MMS'],true)) { throw new \DomainException('Unsupported messaging format.'); }
        return $type==='MMS'?['segments'=>1,'encoding'=>$sms['encoding']]:$sms;
    }
    public static function messageHash(string $body, string $type='SMS', array $media=[]): string
    {
        return $type==='SMS'?hash('sha256',$body):hash('sha256',json_encode([$type,$body,$media],JSON_THROW_ON_ERROR));
    }
    public static function validMediaUrl(string $url, string $callback): bool
    {
        $media=parse_url($url); $origin=parse_url($callback);
        return strlen($url)<=2048 && is_array($media) && is_array($origin) && ($media['scheme']??'')==='https' && ($origin['scheme']??'')==='https'
            && strtolower($media['host']??'')===strtolower($origin['host']??'') && ($media['port']??443)===($origin['port']??443)
            && !isset($media['user']) && !isset($media['pass']) && !isset($media['query']) && !isset($media['fragment'])
            && preg_match('#^/sls_mass_notify/mms_[0-9]{14}_[a-f0-9]{32}\.png$#D',$media['path']??'');
    }
    public static function segments(string $body, string $from = ''): array
    {
        if (!preg_match('//u',$body) || mb_strlen($body)<1 || mb_strlen($body)>1600 || preg_match('/[^\P{C}\r\n\t]/u',$body)) {
            throw new \DomainException('SMS text must contain 1–1600 valid characters without control characters.');
        }
        $basic="@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $extension="\f^{}\\[~]|€"; $units=[]; $gsm=true;
        foreach (mb_str_split($body,1,'UTF-8') as $char) {
            if (mb_strpos($basic,$char,0,'UTF-8')!==false) { $units[]=1; }
            elseif (mb_strpos($extension,$char,0,'UTF-8')!==false) { $units[]=2; }
            else { $gsm=false; break; }
        }
        if (!$gsm) { $units=array_map(static fn($char)=>strlen(mb_convert_encoding($char,'UTF-16BE','UTF-8'))/2,mb_str_split($body,1,'UTF-8')); }
        $total=(int)array_sum($units); $single=$gsm?160:70;
        $tollFree=(bool)preg_match('/^\+18(?:00|33|44|55|66|77|88)[0-9]{7}$/D',$from);
        $capacity=$gsm?($tollFree?152:153):($tollFree?66:67);
        $count=1;
        if ($total>$single) { $used=0; foreach ($units as $unit) { if ($used+$unit>$capacity) { $count++; $used=0; } $used+=$unit; } }
        return ['encoding'=>$gsm?'GSM-7':'UCS-2', 'units'=>$total, 'characters'=>mb_strlen($body), 'segments'=>$count,
            'single_segment_units'=>$single, 'multipart_segment_units'=>$capacity];
    }
}
