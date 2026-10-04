<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Audit classification uses producer metadata; operator and API IDs share a prefix. */
final class SecurityAudit
{
    public const OPERATOR_ACTIONS = [
        'operator_login_unknown_or_disabled'=>'Unknown or disabled operator login',
        'operator_login_failed_password'=>'Operator password rejected',
        'operator_login_password_accepted'=>'Operator password accepted',
        'operator_sign_in_completed'=>'Operator sign-in completed',
        'operator_authenticator_failed'=>'Operator authenticator rejected',
        'operator_reset_link_issued'=>'Operator reset link issued',
        'operator_reset_link_revoked'=>'Operator reset link revoked',
        'operator_reset_link_issue_authorized'=>'Operator reset link issuance authorized',
        'operator_reset_link_revoke_authorized'=>'Operator reset link revocation authorized',
        'operator_password_reset_authorized'=>'Operator password reset authorized',
        'operator_reset_email_reserved'=>'Operator reset email reserved',
        'operator_reset_email_accepted'=>'Operator reset email accepted by Postfix',
        'operator_reset_email_failed'=>'Operator reset email failed',
        'operator_password_reset_failed'=>'Operator password reset failed',
        'operator_password_reset_completed'=>'Operator password reset completed',
        'operator_reset_confirmation_failed'=>'Operator reset confirmation failed',
    ];
    public const EXPORT_ACTIONS = ['config_export_plain'=>'Plain configuration exported',
        'config_export_encrypted'=>'Encrypted configuration exported','config_export_native'=>'Native backup exported'];

    public static function humanRecord(array $row): bool
    {
        $action=$row['action']??null;$actor=$row['actor']??null;
        if(!is_string($action)||!is_string($actor)||!preg_match('/^[\x20-\x7e]{1,160}$/D',$actor)){return false;}
        return (isset(self::OPERATOR_ACTIONS[$action])&&str_starts_with($actor,'operator:'))
            ||(isset(self::EXPORT_ACTIONS[$action])&&($actor==='cli'||str_starts_with($actor,'web:')));
    }

    /** Historic entries without attribution cannot prove whether a key was supplied. */
    public static function keyUsage(array $row): bool
    {
        if(self::humanRecord($row)||($row['actor']??null)==='api:no-key'||($row['credentials_present']??null)===false){return false;}
        return ($row['credentials_present']??null)===true
            ||(is_string($row['credential_id']??null)&&preg_match('/^(?:legacy|api_[a-f0-9]{24})$/D',$row['credential_id']));
    }

    public static function project(array $row): ?array
    {
        $human=self::humanRecord($row);
        if(!$human||!is_string($row['created_at']??null)||strlen($row['created_at'])>40
            ||!is_string($row['ip']??null)||strlen($row['ip'])>64||!is_string($row['action']??null)||strlen($row['action'])>80
            ||!is_int($row['status']??null)||$row['status']<100||$row['status']>599||!is_bool($row['ok']??null)){return null;}
        $action=$row['action'];$operator=isset(self::OPERATOR_ACTIONS[$action])&&$human;
        $out=['created_at'=>$row['created_at'],'ip'=>$row['ip'],'action'=>$action,'status'=>$row['status'],'ok'=>$row['ok'],
            'actor'=>substr($row['actor'],0,160),
            'source'=>$operator?'Operator authentication':'Administrator security',
            'label'=>self::OPERATOR_ACTIONS[$action]??self::EXPORT_ACTIONS[$action]??$action];
        $id=$row['operator_id']??($operator?($row['credential_id']??null):null);
        if(is_string($id)&&preg_match('/^api_[a-f0-9]{24}$/D',$id)){$out['operator_id']=$id;}
        if(is_string($row['operator_username']??null)&&preg_match('/^[\x20-\x7e]{1,140}$/D',$row['operator_username'])){$out['operator_username']=$row['operator_username'];}
        return $out;
    }

    private static function state(string $path): ?array
    {
        clearstatcache(true,$path);$before=@lstat($path);if($before===false){return null;}
        if(($before['mode']&0170000)!==0100000||$before['nlink']!==1||$before['size']>65536||($before['mode']&0022)){return ['active'=>true];}
        $handle=@fopen($path,'r+b');if(!$handle){return ['active'=>true];}
        try{$opened=fstat($handle);clearstatcache(true,$path);$at=@lstat($path);
            if(!$opened||!$at||$opened['ino']!==$before['ino']||$opened['dev']!==$before['dev']||$opened['ino']!==$at['ino']||$opened['dev']!==$at['dev']||!flock($handle,LOCK_SH|LOCK_NB)){return ['active'=>true];}
            $raw=stream_get_contents($handle,65537);$state=is_string($raw)&&strlen($raw)<=65536?json_decode($raw,true,16):null;
            return is_array($state)?$state:['active'=>true];
        }finally{fclose($handle);}
    }

    public static function recent(string $path,bool $forwardingEnabled=false): array
    {
        require_once __DIR__.'/api/sls-mass-notify/event-log.php';
        try{
            $page=EventLog::page($path,50);$rows=[];
            foreach($page['events'] as $row){$event=self::project($row);if($event!==null){$rows[]=$event;}if(count($rows)>=20){break;}}
            $notices=!empty($page['scan_limited'])?['The activity view reached its scan limit. Older security events remain in the protected log.']:[];
            foreach(['security-audit-fault.json'=>'Security audit storage has an unresolved write failure. Review protected audit storage before security changes.']+($forwardingEnabled?['security-audit-forwarding.json'=>'Security audit forwarding cannot confirm local logger acceptance. Review logger health.']:[]) as $name=>$message){$health=self::state(dirname($path).'/'.$name);if($health!==null&&($health['active']??true)!==false){$notices[]=$message;}}
            if(file_exists(dirname($path).'/.audit-separation-required')){$state=self::state(dirname($path).'/audit-separation.json');if(($state['schema']??null)!==1||($state['state']??null)!=='complete'){$notices[]='Audit separation requires recovery. Audit writing and retention remain suspended until its protected evidence is completed.';}}
            return ['events'=>$rows,'notices'=>$notices];
        }catch(\Throwable $error){return ['events'=>[],'notices'=>['Security activity could not be read safely. Check protected audit storage and refresh.']];}
    }
}
