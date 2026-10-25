<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/api/sls-mass-notify/security.php';
final class RegionalVoices
{
    public static function catalogue(): array
    {
        static $catalogue=null;if($catalogue!==null){return $catalogue;}
        $path=__DIR__.'/bin/sls_mass_notify/piper-optional-voices.json';$raw=file_get_contents($path,false,null,0,32769);
        if(!is_string($raw)||strlen($raw)>32768){throw new \DomainException('Regional voice catalogue is unavailable.');}
        $value=ApiSecurity::jsonObject($raw);$voices=$value['voices']??null;
        if(($value['schema']??null)!==1||!is_array($voices)||!array_is_list($voices)||count($voices)!==2){throw new \DomainException('Regional voice catalogue is invalid.');}
        $out=[];
        foreach($voices as $voice){
            $id=$voice['id']??null;
            if(!in_array($id,['en_GB-cori-high','es_MX-ald-medium'],true)||isset($out[$id])||count($voice['files']??[])!==2){throw new \DomainException('Regional voice identity is invalid.');}
            foreach($voice['files'] as $file=>$meta){
                if(!in_array($file,[$id.'.onnx',$id.'.onnx.json'],true)||!is_array($meta)||!is_int($meta['size']??null)||$meta['size']<1||$meta['size']>134217728
                    ||!is_string($meta['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$meta['sha256'])||!is_string($meta['url']??null)
                    ||!str_starts_with($meta['url'],'https://huggingface.co/rhasspy/piper-voices/resolve/e21c7de8d4eab79b902f0d61e662b3f21664b8d2/')||basename($meta['url'])!==$file){throw new \DomainException('Regional voice artifact is invalid.');}
            }
            $out[$id]=$voice;
        }
        return $catalogue=$out;
    }
    public static function normalize($packs): array
    {
        if(!is_array($packs)||!array_is_list($packs)||count($packs)>2||count(array_unique($packs,SORT_REGULAR))!==count($packs)){throw new \DomainException('Select at most two distinct regional voice packs.');}
        $known=self::catalogue();foreach($packs as $id){if(!is_string($id)||!isset($known[$id])){throw new \DomainException('Choose a listed regional voice pack.');}}
        sort($packs,SORT_STRING);return $packs;
    }
    public static function files(?array $selected=null): array
    {
        $out=[];foreach(self::catalogue() as $id=>$voice){if($selected===null||in_array($id,$selected,true)){$out+=$voice['files'];}}return $out;
    }
}
