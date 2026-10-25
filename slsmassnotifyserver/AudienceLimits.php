<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/api/sls-mass-notify/security.php';
require_once __DIR__.'/api/sls-mass-notify/config-crypto.php';

final class AudienceLimits
{
    public const SAVED=ApiSecurity::MAX_SAVED_AUDIENCES;
    public const CONFIG_BYTES=\FreePBX\modules\SlsConfigCrypto::MAX_PLAIN_BYTES;

    public static function bytes(array $groups): int
    {
        return strlen(json_encode($groups,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    public static function budget(array $settings): array
    {
        $used=strlen(json_encode($settings,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return ['used_bytes'=>$used,'maximum_bytes'=>self::CONFIG_BYTES,
            'audience_bytes'=>self::bytes($settings['announcement_groups']??[]),
            'remaining_bytes'=>max(0,self::CONFIG_BYTES-$used),'within_limit'=>$used<=self::CONFIG_BYTES];
    }

    public static function assertGrowth(array $groups,array $previous=[]): void
    {
        if(count($groups)>self::SAVED){throw new \DomainException('Up to 200 saved audiences are supported. Remove an unused audience before adding another.');}
        $bytes=self::bytes($groups);
        if($bytes>self::CONFIG_BYTES&&$bytes>self::bytes($previous)){
            throw new \DomainException('Saved audiences exceed their 2 MiB configuration budget. Reduce duplicate memberships or use location groups before adding more recipients. Existing larger configurations can be reduced without losing saved audiences.');
        }
    }
}
