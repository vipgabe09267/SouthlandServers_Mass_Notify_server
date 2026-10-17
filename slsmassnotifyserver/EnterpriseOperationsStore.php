<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityStore.php';
if (!class_exists('SlsAnnouncementJobStore',false)) { require_once __DIR__.'/bin/sls_mass_notify/sls_announcement_jobs.php'; }

/** Reuses delivery's guarded, synchronized file primitives without running a worker. */
final class EnterpriseOperationsStore
{
    public const DIRECTORY='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-operations';
    private \SlsAnnouncementJobStore $files;
    private string $parentMarker;
    private string $directory;
    private const MARKER="SLS_ENTERPRISE_OPERATIONS_V1\n";
    public function __construct(string $directory=self::DIRECTORY)
    {
        $directory=rtrim($directory,'/');$this->directory=$directory;$this->parentMarker=dirname($directory).'/.'.basename($directory).'-required';
        $required=$this->parentRequired();
        if($required&&(!is_dir($directory)||is_link($directory))){throw new \RuntimeException('Established Enterprise operations storage is missing. Restore its replay history; do not recreate it.');}
        if(!$required&&InitializationJournal::read($directory.'/initialization.pending')===null&&(@lstat($directory.'/worker-state.json')||is_link($directory.'/worker-state.json'))){throw new \RuntimeException('Enterprise operations lost its parent initialization marker. Preserve the journal for recovery.');}
        $this->files=new \SlsAnnouncementJobStore($directory);
    }
    private function parentRequired(): bool
    {
        clearstatcache(true,$this->parentMarker);$before=@lstat($this->parentMarker);if(!$before){return false;}
        $parent=dirname($this->parentMarker);$pm=@lstat($parent);
        $safe=static fn($m):bool=>is_array($m)&&($m['mode']&0170000)===0100000&&($m['mode']&07077)===0&&$m['uid']===posix_geteuid()&&$m['nlink']===1&&$m['size']===strlen(self::MARKER);
        if(!$pm||($pm['mode']&0170000)!==0040000||($pm['mode']&0022)||realpath($parent)!==$parent||!$safe($before)){throw new \RuntimeException('Enterprise operations parent marker is unsafe.');}
        $h=@fopen($this->parentMarker,'rb');if(!$h){throw new \RuntimeException('Enterprise operations parent marker is unreadable.');}
        try{$opened=fstat($h);clearstatcache(true,$this->parentMarker);$after=@lstat($this->parentMarker);
            if(!$safe($opened)||!$safe($after)||$opened['dev']!==$before['dev']||$opened['ino']!==$before['ino']||$opened['dev']!==$after['dev']||$opened['ino']!==$after['ino']||stream_get_contents($h,65)!==self::MARKER){throw new \RuntimeException('Enterprise operations parent marker changed or is damaged.');}
        }finally{fclose($h);}return true;
    }
    private static function bounded($value,int $depth,int &$nodes): void
    {
        if($depth>24||++$nodes>30000){throw new \RuntimeException('Enterprise operations history exceeds its structural bound. Preserve it for recovery.');}
        if(is_string($value)){if(strlen($value)>1100000||preg_match('//u',$value)!==1){throw new \RuntimeException('Enterprise operations history contains invalid or oversized text. Preserve it for recovery.');}return;}
        if(is_int($value)||is_bool($value)||$value===null||(is_float($value)&&is_finite($value))){return;}
        if(!is_array($value)){throw new \RuntimeException('Enterprise operations history contains an unsupported value. Preserve it for recovery.');}
        foreach($value as $key=>$child){if(is_string($key)&&(strlen($key)>128||preg_match('/[\x00-\x1f\x7f]/',$key))){throw new \RuntimeException('Enterprise operations history contains an invalid field. Preserve it for recovery.');}self::bounded($child,$depth+1,$nodes);}
    }
    private static function validateState(array $state): void
    {
        if(array_diff(array_keys($state),['schema','clock','approvals','drills'])||count($state)!==4||($state['schema']??null)!==1
            ||!is_int($state['clock']??null)||$state['clock']<0||!is_array($state['approvals']??null)||!is_array($state['drills']??null)
            ||count($state['approvals'])>200||count($state['drills'])>500){throw new \RuntimeException('Enterprise operations history is invalid or full. Export it and review retention.');}
        $nodes=0;self::bounded($state,0,$nodes);
        if(!class_exists(EnterpriseApprovalService::class,false)){require_once __DIR__.'/EnterpriseApprovalService.php';}
        foreach($state['approvals'] as $id=>$row){
            if(!is_string($id)||!preg_match('/^review_[a-f0-9]{32}$/D',$id)||!is_array($row)||($row['id']??null)!==$id){throw new \RuntimeException('Enterprise approval history is damaged. Preserve it for recovery.');}
            EnterpriseApprovalService::validateRecord($row);
        }
        foreach($state['drills'] as $id=>$row){
            if(!is_string($id)||!preg_match('/^run_[a-f0-9]{64}$/D',$id)||!is_array($row)||($row['id']??null)!==$id
                ||!is_array($row['assignment']??null)||!is_array($row['reviews']??null)||!array_is_list($row['reviews'])||count($row['reviews'])>100
                ||!in_array($row['state']??null,['submitting','planned','awaiting_approval','queued','submitted','not_submitted','uncertain'],true)
                ||(isset($row['fingerprint'])&&(!is_string($row['fingerprint'])||!preg_match('/^[a-f0-9]{64}$/D',$row['fingerprint'])))
                ||(isset($row['incident_id'])&&$row['incident_id']!==''&&(!is_string($row['incident_id'])||!preg_match('/^inc_[a-f0-9]{32}$/D',$row['incident_id'])))
                ||(isset($row['result'])&&!is_array($row['result']))){throw new \RuntimeException('Enterprise drill history is damaged. Preserve it for recovery.');}
        }
    }
    public function transaction(callable $work)
    {
        $lock=$this->files->admissionLock();
        if (!$lock) { throw new \DomainException('Enterprise operations history is busy. Retry the same request.'); }
        try {
            $state=InitializationJournal::initializeStore($this->files, 'enterprise-operations',
                ['schema'=>1,'clock'=>0,'approvals'=>[],'drills'=>[]], $this->parentMarker, self::MARKER, $lock);
            self::validateState($state);
            $original=$state;
            $result=$work($state);
            if (count($state['approvals'])>200 || count($state['drills'])>500 || strlen(json_encode($state,JSON_THROW_ON_ERROR))>1800000) { throw new \DomainException('Enterprise operations history reached its allocation. Export its permanent history before admitting more records.'); }
            self::validateState($state);
            if($state!==$original) { $this->files->atomic('worker-state.json',$state); }
            return $result;
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }
    public function archived(string $kind,string $id): ?array
    {
        if(!in_array($kind,['approvals','drills'],true)){throw new \InvalidArgumentException('Invalid operations archive kind.');}
        if(!is_dir($this->directory.'/archive')&&!is_link($this->directory.'/archive')&&!file_exists($this->directory.'/.archive-required')){return null;}
        return (new EnterpriseIdentityStore($this->directory.'/archive'))->read($kind)[$id]??null;
    }
    public function archiveReport(string $kind): array
    {
        if(!in_array($kind,['approvals','drills'],true)){throw new \InvalidArgumentException('Invalid operations report kind.');}
        if(!is_dir($this->directory.'/archive')&&!is_link($this->directory.'/archive')&&!file_exists($this->directory.'/.archive-required')){return [];}
        return (new EnterpriseIdentityStore($this->directory.'/archive'))->read($kind);
    }
    public function maintain(int $now,int $retentionSeconds=2592000): array
    {
        if($now<1||$retentionSeconds<86400||$retentionSeconds>31536000){throw new \InvalidArgumentException('Archive retention must be one day through one year.');}
        return $this->transaction(function(array &$state)use($now,$retentionSeconds):array{
            $archive=new EnterpriseIdentityStore($this->directory.'/archive');$counts=[];
            foreach(['approvals','drills'] as $kind){
                $eligible=array_filter($state[$kind],static function(array $row)use($kind,$now,$retentionSeconds):bool{
                    $created=is_int($row['created_at']??null)?$row['created_at']:strtotime($row['created_at']??'');
                    return $created!==false&&$created<=$now-$retentionSeconds&&($kind==='approvals'
                        ?in_array($row['state']??'',['submitted','rejected','not_submitted'],true)&&($row['expires_at']??PHP_INT_MAX)<=$now
                        :!empty($row['reviews'])&&in_array($row['state']??'',['queued','submitted','not_submitted'],true));
                });
                if($eligible){$archive->transaction($kind,static function(array &$rows)use($eligible):void{foreach($eligible as $id=>$row){if(isset($rows[$id])&&$rows[$id]!==$row){throw new \RuntimeException('Operations archive conflict. Live history was preserved.');}$rows[$id]=$row;}});foreach($eligible as $id=>$row){unset($state[$kind][$id]);}}
                $counts[$kind]=count($eligible);
            }
            return ['success'=>true,'archived'=>$counts,'meaning'=>'Permanent review and drill history retained privately; unfinished and uncertain work remains active.'];
        });
    }
}
