<?php
declare(strict_types=1);
namespace SLS\MassNotify;
if (!class_exists('SlsAnnouncementJobStore',false)) { require_once __DIR__.'/bin/sls_mass_notify/sls_announcement_jobs.php'; }

/** Reuses delivery's guarded, synchronized file primitives without running a worker. */
final class EnterpriseOperationsStore
{
    public const DIRECTORY='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-operations';
    private \SlsAnnouncementJobStore $files;
    private string $parentMarker;
    private const MARKER="SLS_ENTERPRISE_OPERATIONS_V1\n";
    public function __construct(string $directory=self::DIRECTORY)
    {
        $directory=rtrim($directory,'/');$this->parentMarker=dirname($directory).'/.'.basename($directory).'-required';
        $required=$this->parentRequired();
        if($required&&(!is_dir($directory)||is_link($directory))){throw new \RuntimeException('Established Enterprise operations storage is missing. Restore its replay history; do not recreate it.');}
        if(!$required&&(@lstat($directory.'/worker-state.json')||is_link($directory.'/worker-state.json'))){throw new \RuntimeException('Enterprise operations lost its parent initialization marker. Preserve the journal for recovery.');}
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
    private function establishParent(): void
    {
        $mask=umask(0077);try{$h=@fopen($this->parentMarker,'x+b');}finally{umask($mask);}
        if(!$h){throw new \RuntimeException('Enterprise operations parent marker could not be established.');}
        try{if(fwrite($h,self::MARKER)!==strlen(self::MARKER)||!fflush($h)||!fsync($h)){throw new \RuntimeException('Enterprise operations parent marker could not be synchronized.');}}finally{fclose($h);}
        $h=@fopen(dirname($this->parentMarker),'r');try{if(!$h||!fsync($h)){throw new \RuntimeException('Enterprise operations parent directory could not be synchronized.');}}finally{if($h){fclose($h);}}
        $this->parentRequired();
    }
    public function transaction(callable $work)
    {
        $lock=$this->files->admissionLock();
        if (!$lock) { throw new \DomainException('Enterprise operations history is busy. Retry the same request.'); }
        try {
            rewind($lock);$marker=stream_get_contents($lock,65);$state=$this->files->state();
            if (!in_array($marker,['',self::MARKER],true) || ($marker!=='' && !$state) || ($marker==='' && $state)) { throw new \RuntimeException('Enterprise operations history lost its initialization marker or journal. Preserve it for recovery.'); }
            $required=$this->parentRequired();
            if($required&&!$state){throw new \RuntimeException('Established Enterprise operations journal is missing. Preserve replay protection for recovery.');}
            if(!$required&&$state){throw new \RuntimeException('Enterprise operations parent marker is missing. Preserve replay protection for recovery.');}
            if (!$state) {
                $state=['schema'=>1,'clock'=>0,'approvals'=>[],'drills'=>[]];
                $this->files->atomic('worker-state.json',$state);
                rewind($lock);if(fwrite($lock,self::MARKER)!==strlen(self::MARKER)||!fflush($lock)||!fsync($lock)){throw new \RuntimeException('Enterprise operations initialization could not be synchronized.');}
                $this->establishParent();$marker=self::MARKER;
            }
            if (array_diff(array_keys($state),['schema','clock','approvals','drills']) || ($state['schema']??null)!==1
                || !is_int($state['clock']??null) || $state['clock']<0 || !is_array($state['approvals']??null) || !is_array($state['drills']??null)
                || count($state['approvals'])>200 || count($state['drills'])>500) { throw new \RuntimeException('Enterprise operations history is invalid or full. Export it and review retention.'); }
            $result=$work($state);
            if (count($state['approvals'])>200 || count($state['drills'])>500 || strlen(json_encode($state,JSON_THROW_ON_ERROR))>1800000) { throw new \DomainException('Enterprise operations history reached its allocation. Export its permanent history before admitting more records.'); }
            $this->files->atomic('worker-state.json',$state);
            if ($marker==='') { rewind($lock);if(fwrite($lock,self::MARKER)!==strlen(self::MARKER)||!fflush($lock)||!fsync($lock)){throw new \RuntimeException('Enterprise operations initialization could not be synchronized.');} }
            return $result;
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }
}
