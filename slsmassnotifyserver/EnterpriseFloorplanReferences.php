<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/bin/sls_mass_notify/sls_config_crypto.php';

/** Produces a bounded, fail-closed reference report before image retirement. */
final class EnterpriseFloorplanReferences
{
    private const BACKUP_LIMIT=100;

    private static function contains($value,string $imageId): bool
    {
        if(is_string($value)){return hash_equals($imageId,$value);}
        if(!is_array($value)){return false;}
        foreach($value as $item){if(self::contains($item,$imageId)){return true;}}
        return false;
    }

    private static function plans(array $settings,string $source,string $imageId): array
    {
        $references=[];
        $plans=$settings['enterprise_operations']['floorplans']['plans']??[];
        if(is_array($plans)){
            foreach($plans as $plan){
                if(!is_array($plan)||!is_string($plan['image_id']??null)||!hash_equals($imageId,$plan['image_id'])){continue;}
                $references[]=['source'=>$source,'plan_id'=>is_string($plan['id']??null)?substr($plan['id'],0,80):'',
                    'name'=>is_string($plan['name']??null)?substr($plan['name'],0,80):'Saved floor plan'];
            }
        }
        if(!$references&&self::contains($settings['enterprise_operations']??[],$imageId)){
            $references[]=['source'=>$source,'plan_id'=>'','name'=>'Enterprise operations settings'];
        }
        return $references;
    }

    private static function backupReferences(string $directory,string $imageId): array
    {
        clearstatcache(true,$directory);$metadata=@lstat($directory);
        if($metadata===false){return [];}
        if(($metadata['mode']&0170000)!==0040000||($metadata['mode']&0022)||realpath($directory)!==$directory){
            throw new \RuntimeException('Configuration backup storage is unsafe. Review it before retiring a floor plan.');
        }
        $references=[];$count=0;
        foreach(new \DirectoryIterator($directory) as $entry){
            if($entry->isDot()){continue;}
            $name=$entry->getFilename();
            if(!preg_match('/^mass-notifications-[A-Za-z0-9_-]{1,96}\.config$/D',$name)){
                throw new \RuntimeException('Configuration backup storage contains an unexpected entry. Preserve and review it before retiring a floor plan.');
            }
            if(++$count>self::BACKUP_LIMIT){throw new \RuntimeException('Configuration backup storage exceeds its review limit. Archive old backups before retiring a floor plan.');}
            $settings=\FreePBX\modules\SlsConfigCrypto::readFile($entry->getPathname());
            if(self::contains($settings['enterprise_operations']??[],$imageId)){
                $references[]=['source'=>'configuration_backup','plan_id'=>'','name'=>$name];
            }
        }
        return $references;
    }

    public static function report(string $imageId,array $active,?array $pending,string $backupDirectory,bool $includeBackups): array
    {
        if(!preg_match('/^img_[a-f0-9]{64}$/D',$imageId)){throw new \InvalidArgumentException('Invalid private floor plan image identifier.');}
        $references=self::plans($active,'active_configuration',$imageId);
        if($pending!==null){$references=array_merge($references,self::plans($pending,'staged_configuration',$imageId));}
        if($includeBackups){$references=array_merge($references,self::backupReferences($backupDirectory,$imageId));}
        $seen=[];$unique=[];
        foreach($references as $reference){
            $key=json_encode($reference,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if(isset($seen[$key])){continue;}$seen[$key]=true;$unique[]=$reference;
        }
        usort($unique,static fn(array $left,array $right):int=>[$left['source'],$left['plan_id'],$left['name']]<=>[$right['source'],$right['plan_id'],$right['name']]);
        $report=['image_id'=>$imageId,'references'=>$unique,'blocking_count'=>count($unique),'backups_checked'=>$includeBackups];
        $report['reference_hash']=hash('sha256',json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return $report;
    }
}
