<?php
declare(strict_types=1);
namespace SLS\MassNotify;

final class PasswordBlocklist
{
    private const DIGEST = '95cfb7f57f448c9b351fab60c4c153c9c72532b3e718d9d36d9d77e41dc9e149';
    private static ?string $hashes = null;

    public static function contains(string $password): bool
    {
        if (self::$hashes === null) {
            $path=__DIR__.'/resources/common-passwords.sha256';
            if (!is_file($path) || is_link($path)) { throw new \DomainException('The local password policy is missing. Run Repair Installation before changing operator passwords.'); }
            $raw=file_get_contents($path,false,null,0,700001);
            if (!is_string($raw) || strlen($raw)>700000 || !hash_equals(self::DIGEST,hash('sha256',$raw))) {
                throw new \DomainException('The local password policy failed verification. Run Repair Installation before changing operator passwords.');
            }
            self::$hashes="\n".$raw;
        }
        return str_contains(self::$hashes,"\n".hash('sha256',$password)."\n");
    }
}
