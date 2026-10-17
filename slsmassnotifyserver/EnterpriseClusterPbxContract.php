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
        foreach ($paths as $path) {
            if (!is_string($path) || $path==='' || strlen($path)>4096 || $path[0]!=='/' || str_contains($path,"\0")) {
                throw new \DomainException('PBX matching evidence contains an invalid path.');
            }
        }
        if (count(array_unique($paths,SORT_STRING))!==count($paths)) { throw new \DomainException('PBX matching evidence paths must be distinct.'); }
        foreach ($paths as $name=>$path) {
            clearstatcache(true,$path); $before=@lstat($path);
            if (!$before || ($before['mode']&0170000)!==0100000 || $before['nlink']!==1
                || ($before['mode']&0022) || realpath($path)!==$path || $before['size']>8388608) { throw new \RuntimeException('PBX matching evidence is unavailable or unsafe: '.$name); }
            $handle=@fopen($path,'rb'); if (!$handle) { throw new \RuntimeException('PBX evidence cannot be read.'); }
            try {
                $opened=fstat($handle);
                if (!is_array($opened) || $opened['dev']!==$before['dev'] || $opened['ino']!==$before['ino']) { throw new \RuntimeException('PBX evidence changed while opened.'); }
                $data=stream_get_contents($handle,8388609); $after=fstat($handle);
                clearstatcache(true,$path); $named=@lstat($path);
                if (!is_array($after) || !is_array($named)) { throw new \RuntimeException('PBX evidence changed while measured.'); }
                foreach (['dev','ino','size','mtime','ctime'] as $field) {
                    if ($after[$field]!==$before[$field] || $named[$field]!==$before[$field]) { throw new \RuntimeException('PBX evidence changed while measured.'); }
                }
                if (!is_string($data) || strlen($data)!==$before['size'] || strlen($data)>8388608) { throw new \RuntimeException('PBX evidence read exceeded its limit.'); }
                $hashes[$name]=hash('sha256',$data);
            } finally { fclose($handle); }
        }
        $result=['schema'=>1,'contract'=>hash('sha256',EnterpriseClusterProtocol::canonical(['schema'=>1,'files'=>$hashes])),'file_sha256'=>$hashes,
            'pbx_failover_qualified'=>false,'requirements'=>'Matching operator-managed dialplan, endpoint/AOR/transport and FreePBX framework files; registrar, phone routing, trunks and network failover require separate qualification.'];
        return $result;
    }
}
