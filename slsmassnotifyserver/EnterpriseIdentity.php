<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityConfig.php';
require_once __DIR__.'/OperatorAccess.php';

/** Federation authenticates; the existing local account grants every permission. */
final class EnterpriseIdentity
{
    public static function grant(array $settings,array $assertion): array
    {
        $provider=EnterpriseIdentityConfig::provider($settings,$assertion['provider_id']??'');$config=EnterpriseIdentityConfig::normalize($settings['enterprise_identity']??[]);
        foreach($config['grants'] as $g){if(!$g['enabled']||$g['provider_id']!==$provider['id']||!hash_equals($g['subject'],$assertion['subject']??'')){continue;}
            foreach(OperatorAccess::normalize($settings['operator_access']??[])['accounts'] as $a){if($a['id']!==$g['operator_account_id']||$a['source']!=='portal'||!$a['enabled']||!OperatorAuth::ready($a)){continue;}
                if(!OperatorAccess::principal($settings,$a['id'])){break;}
                return ['account'=>$a,'binding'=>$assertion+['grant_revision'=>hash('sha256',json_encode($g,JSON_THROW_ON_ERROR)),'provider_revision'=>EnterpriseIdentityConfig::revision($provider)]];}
        }throw new \DomainException('This authenticated subject has no enabled local operator grant. Ask an administrator to bind its stable subject to an existing operator account.');
    }
    public static function current(array $settings,array $binding,string $accountId,int $now): bool
    {
        try{if(!is_int($binding['authenticated_at']??null)||!is_int($binding['expires_at']??null)||$binding['authenticated_at']>$now||$binding['expires_at']<=$now){return false;}$grant=self::grant($settings,$binding);
            return $grant['account']['id']===$accountId&&hash_equals($binding['grant_revision']??'',$grant['binding']['grant_revision'])&&hash_equals($binding['provider_revision']??'',$grant['binding']['provider_revision']);}catch(\Throwable $e){return false;}
    }
}
