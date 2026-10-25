<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Pure validation; normal password sign-ins do not load the WebAuthn library. */
final class PasskeyConfig
{
    public static function normalize($rows): array
    {
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>5){throw new \DomainException('An operator supports at most five passkeys.');}
        $seen=[];
        foreach($rows as $row){
            if(!is_array($row)||array_diff(array_keys($row),['credential_id','name','record','created_at','last_used_at'])||count($row)!==5
                ||!is_string($row['credential_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,1364}$/D',$row['credential_id'])||isset($seen[$row['credential_id']])
                ||!is_string($row['name']??null)||!preg_match('//u',$row['name'])||trim($row['name'])===''||mb_strlen($row['name'],'UTF-8')>60||preg_match('/[\x00-\x1f\x7f]/',$row['name'])
                ||!is_string($row['record']??null)||strlen($row['record'])>16384||!preg_match('//u',$row['record'])
                ||!is_int($row['created_at']??null)||$row['created_at']<1||$row['created_at']>253402300799
                ||!is_int($row['last_used_at']??null)||$row['last_used_at']<0||$row['last_used_at']>253402300799){throw new \DomainException('The saved operator passkey record is invalid.');}
            $decoded=base64_decode(strtr($row['credential_id'],'-_','+/').str_repeat('=',(4-strlen($row['credential_id'])%4)%4),true);
            if(!is_string($decoded)||strlen($decoded)<1||strlen($decoded)>1023||rtrim(strtr(base64_encode($decoded),'+/','-_'),'=')!==$row['credential_id']){throw new \DomainException('The passkey credential ID is invalid.');}
            $record=json_decode($row['record'],true,16,JSON_THROW_ON_ERROR);
            if(!is_array($record)||($record['publicKeyCredentialId']??null)!==$row['credential_id']||($record['type']??null)!=='public-key'
                ||!is_int($record['counter']??null)||$record['counter']<0||$record['counter']>4294967295
                ||!is_string($record['credentialPublicKey']??null)||strlen($record['credentialPublicKey'])>4096
                ||!is_string($record['userHandle']??null)||strlen($record['userHandle'])>128){throw new \DomainException('The passkey public credential record is invalid.');}
            $seen[$row['credential_id']]=true;
        }
        return $rows;
    }
    public static function publicRows(array $rows): array
    {
        return array_map(static fn($row)=>array_intersect_key($row,array_flip(['credential_id','name','created_at','last_used_at'])),self::normalize($rows));
    }
}
