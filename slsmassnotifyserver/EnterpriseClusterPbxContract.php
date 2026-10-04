<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseClusterProtocol.php';

/** Read-only matching prerequisites, never a claim of PBX failover qualification. */
final class EnterpriseClusterPbxContract
{
    public const FILES=['dialplan'=>'/etc/asterisk/extensions_additional.conf',
        'endpoints'=>'/etc/asterisk/pjsip.endpoint.conf','aors'=>'/etc/asterisk/pjsip.aor.conf',
        'transports'=>'/etc/asterisk/pjsip.transports.conf',
        'framework'=>'/var/www/html/admin/modules/framework/module.xml'];
    public static function measure(?array $paths=null): array
    {
        $paths ??= self::FILES; $hashes=[];
        if (array_keys($paths)!==array_keys(self::FILES)) { throw new \DomainException('PBX matching evidence is incomplete.'); }
        foreach ($paths as $name=>$path) {
            clearstatcache(true,$path); $before=@lstat($path);
            if (!$before || ($before['mode']&0170000)!==0100000 || $before['nlink']!==1
                || ($before['mode']&0022) || realpath($path)!==$path || $before['size']>8388608) { throw new \RuntimeException('PBX matching evidence is unavailable or unsafe: '.$name); }
            $handle=@fopen($path,'rb'); if (!$handle) { throw new \RuntimeException('PBX evidence cannot be read.'); }
            try {
                $opened=fstat($handle); if ($opened['dev']!==$before['dev'] || $opened['ino']!==$before['ino']) { throw new \RuntimeException('PBX evidence changed while opened.'); }
                $data=stream_get_contents($handle,8388609); $after=fstat($handle);
                clearstatcache(true,$path); $named=@lstat($path);
                foreach (['dev','ino','size','mtime','ctime'] as $field) {
                    if (!$named || $after[$field]!==$before[$field] || $named[$field]!==$before[$field]) { throw new \RuntimeException('PBX evidence changed while measured.'); }
                }
                if (!is_string($data) || strlen($data)!==$before['size'] || strlen($data)>8388608) { throw new \RuntimeException('PBX evidence read exceeded its limit.'); }
                $hashes[$name]=hash('sha256',$data);
            } finally { fclose($handle); }
        }
        return ['contract'=>hash('sha256',EnterpriseClusterProtocol::canonical($hashes)),'file_sha256'=>$hashes,
            'pbx_failover_qualified'=>false,'requirements'=>'Matching operator-managed dialplan, endpoint/AOR/transport and FreePBX framework files; registrar, phone routing, trunks and network failover require separate qualification.'];
    }
}
