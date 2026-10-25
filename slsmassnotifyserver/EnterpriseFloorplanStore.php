<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/InitializationJournal.php';

/** Images are decoded, re-encoded and kept outside every HTTP document root. */
final class EnterpriseFloorplanRetirementUncertain extends \RuntimeException {}

final class EnterpriseFloorplanStore
{
    public const DIRECTORY='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-floorplans';
    public const MAX_BYTES=5242880;
    public const MAX_WIDTH=4096;
    public const MAX_HEIGHT=4096;
    public const MAX_PIXELS=8388608;
    public const MAX_IMAGES=100;
    public const MAX_TOTAL_BYTES=33554432;
    private string $directory;
    public function __construct(string $directory=self::DIRECTORY)
    {
        $this->directory=rtrim($directory,'/');
        $parent=@lstat(dirname($this->directory));
        if(!$parent||($parent['mode']&0170000)!==0040000||($parent['mode']&0022)||realpath(dirname($this->directory))!==dirname($this->directory)){throw new \RuntimeException('Private floor plan storage parent is unsafe.');}
        if(!file_exists($this->directory)&&!is_link($this->directory)){$mask=umask(0077);try{if(!@mkdir($this->directory,0700)&&!is_dir($this->directory)){throw new \RuntimeException('Private floor plan storage cannot be created.');}}finally{umask($mask);}}
        $this->directory();
    }
    private function directory(): void
    {
        clearstatcache(true,$this->directory);$m=@lstat($this->directory);
        if(!$m||($m['mode']&0170000)!==0040000||($m['mode']&0077)||$m['uid']!==posix_geteuid()||realpath($this->directory)!==$this->directory){throw new \RuntimeException('Floor plan images require a runtime-owned private directory without links.');}
    }
    private function open(string $path,string $mode)
    {
        $this->directory();clearstatcache(true,$path);$before=@lstat($path);
        $safe=static fn($m):bool=>is_array($m)&&($m['mode']&0170000)===0100000&&($m['mode']&0077)===0&&$m['uid']===posix_geteuid()&&$m['nlink']===1&&$m['size']<=self::MAX_BYTES;
        if($before&&!$safe($before)){throw new \RuntimeException('Floor plan image is not a safe regular file.');}
        $mask=umask(0077);try{$h=@fopen($path,$mode);}finally{umask($mask);}
        if(!$h){throw new \RuntimeException('Floor plan image storage is unavailable.');}$m=fstat($h);clearstatcache(true,$path);$at=@lstat($path);
        if(!$safe($m)||!$safe($at)||$m['ino']!==$at['ino']||$m['dev']!==$at['dev']||($before&&($m['ino']!==$before['ino']||$m['dev']!==$before['dev']))){fclose($h);throw new \RuntimeException('Floor plan image changed while opening.');}
        return $h;
    }
    private static function unlinkIdentity(string $path,array $identity): void
    {
        clearstatcache(true,$path);$current=@lstat($path);
        if(is_array($current)&&($current['mode']&0170000)===0100000&&$current['nlink']===1
            &&$current['dev']===$identity['dev']&&$current['ino']===$identity['ino']){@unlink($path);}
    }

    private static function validateJpeg(string $bytes): void
    {
        $length=strlen($bytes);
        $position=2;
        $seenFrame=false;
        $seenScan=false;
        $seenEnd=false;
        $frameMarkers=[0xc0,0xc1,0xc2,0xc3,0xc5,0xc6,0xc7,0xc9,0xca,0xcb,0xcd,0xce,0xcf];
        while($position<$length){
            if(ord($bytes[$position])!==0xff){
                if(!$seenScan){throw new \InvalidArgumentException('JPEG marker structure is invalid.');}
                $position++;
                continue;
            }
            while($position<$length&&ord($bytes[$position])===0xff){$position++;}
            if($position>=$length){throw new \InvalidArgumentException('JPEG marker is truncated.');}
            $marker=ord($bytes[$position++]);
            if($marker===0x00){
                if(!$seenScan){throw new \InvalidArgumentException('JPEG byte stuffing is invalid.');}
                continue;
            }
            if($marker===0xd9){
                if($position!==$length){throw new \InvalidArgumentException('JPEG contains data after its end marker.');}
                $seenEnd=true;
                break;
            }
            if(($marker>=0xd0&&$marker<=0xd7)||$marker===0x01){
                if(!$seenScan&&$marker>=0xd0&&$marker<=0xd7){throw new \InvalidArgumentException('JPEG restart marker appears before image data.');}
                continue;
            }
            if($marker===0xd8){throw new \InvalidArgumentException('JPEG contains a duplicate start marker.');}
            if($position+2>$length){throw new \InvalidArgumentException('JPEG segment is truncated.');}
            $segmentLength=unpack('n',substr($bytes,$position,2))[1];
            if($segmentLength<2||$position+$segmentLength>$length){throw new \InvalidArgumentException('JPEG segment length is invalid.');}
            $payload=substr($bytes,$position+2,$segmentLength-2);
            if(in_array($marker,$frameMarkers,true)){
                if($seenFrame||strlen($payload)<6){throw new \InvalidArgumentException('JPEG frame is invalid.');}
                $components=ord($payload[5]);
                if($components<1||$components>4||strlen($payload)!==6+3*$components){throw new \InvalidArgumentException('JPEG frame components are invalid.');}
                $seenFrame=true;
            }
            if($marker===0xda){
                if(!$seenFrame||strlen($payload)<6){throw new \InvalidArgumentException('JPEG scan appears before a valid frame.');}
                $components=ord($payload[0]);
                if($components<1||$components>4||strlen($payload)!==4+2*$components){throw new \InvalidArgumentException('JPEG scan components are invalid.');}
                $seenScan=true;
            }
            $position+=$segmentLength;
        }
        if(!$seenFrame||!$seenScan||!$seenEnd){throw new \InvalidArgumentException('JPEG image is incomplete.');}
    }

    private static function imageInfo(string $bytes): array
    {
        $length=strlen($bytes);
        if($length<8||$length>self::MAX_BYTES){throw new \InvalidArgumentException('Floor plan image size is invalid.');}
        $png=str_starts_with($bytes,"\x89PNG\r\n\x1a\n");
        $jpeg=str_starts_with($bytes,"\xff\xd8\xff");
        if(!$png&&!$jpeg){throw new \InvalidArgumentException('Floor plans must be PNG or JPEG images.');}
        if($png){
            $position=8;$ihdr=false;$idat=false;$iend=false;
            while($position<$length){
                if($position+12>$length){throw new \InvalidArgumentException('PNG data is truncated.');}
                $chunkLength=unpack('N',substr($bytes,$position,4))[1];$type=substr($bytes,$position+4,4);
                if($chunkLength>self::MAX_BYTES||!preg_match('/^[A-Za-z]{4}$/D',$type)||$position+12+$chunkLength>$length){throw new \InvalidArgumentException('PNG chunk structure is invalid.');}
                $data=substr($bytes,$position+8,$chunkLength);$crc=substr($bytes,$position+8+$chunkLength,4);
                if(!hash_equals(hash('crc32b',$type.$data),bin2hex($crc))){throw new \InvalidArgumentException('PNG integrity check failed.');}
                if(!$ihdr){
                    if($type!=='IHDR'||$chunkLength!==13){throw new \InvalidArgumentException('PNG header is missing or invalid.');}
                    $ihdr=true;
                }elseif($type==='IHDR'){throw new \InvalidArgumentException('PNG contains more than one header.');}
                if($type==='IDAT'){$idat=true;}
                if($type==='IEND'){
                    if($chunkLength!==0||!$idat||$position+12!==$length){throw new \InvalidArgumentException('PNG ending is invalid or has trailing data.');}
                    $iend=true;
                }
                $position+=12+$chunkLength;
                if($iend){break;}
            }
            if(!$ihdr||!$idat||!$iend||$position!==$length){throw new \InvalidArgumentException('PNG image is incomplete.');}
        }else{
            self::validateJpeg($bytes);
        }
        $info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true)
            ||$info[0]<1||$info[1]<1||$info[0]>self::MAX_WIDTH||$info[1]>self::MAX_HEIGHT
            ||$info[0]*$info[1]>self::MAX_PIXELS){throw new \InvalidArgumentException('Floor plans must be PNG/JPEG, at most 4096 pixels per side and eight megapixels.');}
        if(!function_exists('imagecreatefromstring')){throw new \DomainException('Install the PHP GD extension before using private floor plans.');}
        $image=@imagecreatefromstring($bytes);
        if(!$image||imagesx($image)!==$info[0]||imagesy($image)!==$info[1]){
            if($image){imagedestroy($image);}
            throw new \InvalidArgumentException('The complete floor plan image could not be decoded.');
        }
        imagedestroy($image);
        return ['type'=>$info[2],'width'=>$info[0],'height'=>$info[1]];
    }
    public function usage(): array
    {
        $this->directory();
        $result=['image_count'=>0,'image_bytes'=>0,'pending_upload_count'=>0,'pending_upload_bytes'=>0,
            'maximum_images'=>self::MAX_IMAGES,'maximum_bytes'=>self::MAX_TOTAL_BYTES,'images'=>[]];
        foreach (new \DirectoryIterator($this->directory) as $file) {
            $name=$file->getFilename();
            if ($file->isDot()) { continue; }
            if ($name==='.storage.lock') {
                $handle=$this->open($file->getPathname(),'rb');
                try {
                    if(fstat($handle)['size']!==0){throw new \RuntimeException('Floor plan storage lock contains unexpected data.');}
                } finally { fclose($handle); }
                continue;
            }
            if (!preg_match('/^(?:img_[a-f0-9]{64}|\.upload-[a-f0-9]{32})$/D',$name)) {
                throw new \RuntimeException('Unexpected floor plan storage entry; preserve it for review.');
            }
            $handle=$this->open($file->getPathname(),'rb');
            try { $size=fstat($handle)['size']; } finally { fclose($handle); }
            $prefix=str_starts_with($name,'img_') ? 'image' : 'pending_upload';
            $result[$prefix.'_count']++; $result[$prefix.'_bytes']+=$size;
            if($prefix==='image'){$result['images'][]=['image_id'=>$name,'bytes'=>$size];}
        }
        usort($result['images'],static fn(array $left,array $right):int=>strcmp($left['image_id'],$right['image_id']));
        return $result;
    }
    private function cleanupInterruptedUploads(): void
    {
        // Only unpublished temporary names are removable. Published image IDs
        // can occur in staged settings, backups or archived evidence.
        $removed=false;
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if (!preg_match('/^\.upload-[a-f0-9]{32}$/D',$file->getFilename())) { continue; }
            $path=$file->getPathname(); $handle=$this->open($path,'rb');
            try {
                $before=fstat($handle); clearstatcache(true,$path); $named=lstat($path);
                if ($before['mtime']<time()-86400 && is_array($named)
                    && $before['dev']===$named['dev'] && $before['ino']===$named['ino']) {
                    if (!unlink($path)) { throw new \RuntimeException('Interrupted floor plan upload could not be removed.'); }
                    $removed=true;
                }
            } finally { fclose($handle); }
        }
        if ($removed) { \SLS\MassNotify\InitializationJournal::sync($this->directory); }
    }
    public function put(string $bytes): array
    {
        if(strlen($bytes)<8||strlen($bytes)>self::MAX_BYTES){throw new \InvalidArgumentException('Upload a PNG or JPEG image of at most 5 MiB.');}
        $info=self::imageInfo($bytes);
        $image=@imagecreatefromstring($bytes);if(!$image){throw new \InvalidArgumentException('This image could not be decoded. Export a standard PNG or JPEG and try again.');}
        ob_start();try{$ok=$info['type']===IMAGETYPE_PNG?imagepng($image,null,6):imagejpeg($image,null,90);$clean=ob_get_contents();}finally{ob_end_clean();imagedestroy($image);}
        if(!$ok||!is_string($clean)||strlen($clean)>self::MAX_BYTES){throw new \InvalidArgumentException('The normalized image exceeds 5 MiB. Reduce its resolution.');}
        $lock=$this->open($this->directory.'/.storage.lock','c+b');$lockMeta=fstat($lock);
        if(($lockMeta['mode']&0777)!==0600||$lockMeta['size']!==0){fclose($lock);throw new \RuntimeException('Floor plan storage lock has unsafe permissions or content.');}
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new \DomainException('Another floor plan is being saved. Retry this upload shortly.');}
        try {
        $this->usage();
        $this->cleanupInterruptedUploads();
        $id='img_'.hash('sha256',$clean);$path=$this->directory.'/'.$id;
        if(file_exists($path)||is_link($path)){$existing=$this->read($id);if(!hash_equals($clean,$existing['bytes'])){throw new \RuntimeException('An image digest conflicts with existing content.');}return ['image_id'=>$id,'width'=>$info['width'],'height'=>$info['height']];}
        $usage=$this->usage();$count=$usage['image_count']+$usage['pending_upload_count'];
        $allocated=$usage['image_bytes']+$usage['pending_upload_bytes'];
        if($count>=self::MAX_IMAGES||$allocated+strlen($clean)>self::MAX_TOTAL_BYTES){throw new \DomainException('Private floor plans reached the 100-image or 32 MiB allocation. Export and retire unreferenced images before uploading more.');}
        $temp=$this->directory.'/.upload-'.bin2hex(random_bytes(16));$h=$this->open($temp,'x+b');$identity=fstat($h);$published=false;
        try{
            $length=strlen($clean);$written=0;
            while($written<$length){
                $chunk=substr($clean,$written,min(1048576,$length-$written));$count=fwrite($h,$chunk);
                if(!is_int($count)||$count<1){throw new \RuntimeException('Floor plan image write made no progress.');}
                $written+=$count;
            }
            if(!fflush($h)||!fsync($h)){throw new \RuntimeException('Floor plan image could not be synchronized.');}
            $opened=fstat($h);clearstatcache(true,$temp);$named=@lstat($temp);
            $staged=rewind($h)?stream_get_contents($h,self::MAX_BYTES+1):false;
            if(!is_array($identity)||!is_array($opened)||!is_array($named)||$opened['dev']!==$identity['dev']||$opened['ino']!==$identity['ino']
                ||$opened['dev']!==$named['dev']||$opened['ino']!==$named['ino']||$opened['nlink']!==1||$named['nlink']!==1
                ||$opened['size']!==$length||!is_string($staged)||strlen($staged)!==$length||!hash_equals(hash('sha256',$clean),hash('sha256',$staged))){
                throw new \RuntimeException('Floor plan staging changed before publication.');
            }
            if(!rename($temp,$path)){throw new \RuntimeException('Floor plan image could not be saved.');}
            clearstatcache(true,$path);$committed=@lstat($path);$after=fstat($h);
            if(!is_array($committed)||!is_array($after)||$committed['dev']!==$identity['dev']||$committed['ino']!==$identity['ino']
                ||$after['dev']!==$identity['dev']||$after['ino']!==$identity['ino']||$committed['nlink']!==1||$after['nlink']!==1
                ||$committed['size']!==$length||$after['size']!==$length){throw new \RuntimeException('Floor plan publication changed unexpectedly. Preserve the storage directory for review.');}
            $published=true;
            $dir=@fopen($this->directory,'r');try{if(!$dir||!fsync($dir)){throw new \RuntimeException('Floor plan directory could not be synchronized.');}}finally{if($dir){fclose($dir);}}
        }finally{fclose($h);if(!$published&&is_array($identity)){self::unlinkIdentity($temp,$identity);}}
        return ['image_id'=>$id,'width'=>$info['width'],'height'=>$info['height']];
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    public function read(string $id): array
    {
        if(!preg_match('/^img_[a-f0-9]{64}$/D',$id)){throw new \InvalidArgumentException('Invalid private floor plan image identifier.');}
        $h=$this->open($this->directory.'/'.$id,'rb');try{$bytes=stream_get_contents($h,self::MAX_BYTES+1);}finally{fclose($h);}
        if(!is_string($bytes)||strlen($bytes)>self::MAX_BYTES||!hash_equals(substr($id,4),hash('sha256',$bytes))){throw new \RuntimeException('Floor plan image failed its content-integrity check.');}
        try{$info=self::imageInfo($bytes);}catch(\InvalidArgumentException|\DomainException $error){throw new \RuntimeException('Stored floor plan image is invalid: '.$error->getMessage(),0,$error);}
        return ['bytes'=>$bytes,'type'=>$info['type']===IMAGETYPE_PNG?'image/png':'image/jpeg','width'=>$info['width'],'height'=>$info['height']];
    }

    public function retire(string $id,callable $authorize): array
    {
        if(!preg_match('/^img_[a-f0-9]{64}$/D',$id)){throw new \InvalidArgumentException('Invalid private floor plan image identifier.');}
        $lock=$this->open($this->directory.'/.storage.lock','c+b');$lockMeta=fstat($lock);
        if(($lockMeta['mode']&0777)!==0600||$lockMeta['size']!==0){fclose($lock);throw new \RuntimeException('Floor plan storage lock has unsafe permissions or content.');}
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new \DomainException('Floor plan storage is busy. Retry after the current upload or retirement finishes.');}
        $image=null;
        try{
            $this->usage();$path=$this->directory.'/'.$id;$handle=$this->open($path,'rb');
            try{
                $opened=fstat($handle);$bytes=stream_get_contents($handle,self::MAX_BYTES+1);
                if(!is_string($bytes)||strlen($bytes)!==$opened['size']||!hash_equals(substr($id,4),hash('sha256',$bytes))){throw new \RuntimeException('Floor plan image failed its content-integrity check.');}
                self::imageInfo($bytes);
                $image=['image_id'=>$id,'bytes'=>$opened['size'],'sha256'=>hash('sha256',$bytes)];
                $authorization=$authorize($image);
                if(!is_array($authorization)||!is_string($authorization['request_id']??null)||!preg_match('/^fpa_[a-f0-9]{32}$/D',$authorization['request_id'])){
                    throw new \RuntimeException('Floor plan retirement authorization was not recorded.');
                }
                clearstatcache(true,$path);$named=@lstat($path);$current=fstat($handle);
                foreach(['dev','ino','mode','uid','nlink','size','mtime','ctime'] as $field){
                    if(!is_array($named)||$opened[$field]!==$current[$field]||$current[$field]!==$named[$field]){
                        throw new \RuntimeException('Floor plan image changed before retirement. Nothing was deleted.');
                    }
                }
                if(!unlink($path)){throw new \RuntimeException('Floor plan image could not be retired.');}
                try{InitializationJournal::sync($this->directory);}
                catch(\Throwable $error){throw new EnterpriseFloorplanRetirementUncertain('The image name was removed, but storage could not confirm durability. Reload the inventory and preserve the retirement audit before retrying.',0,$error);}
                return $image+['request_id'=>$authorization['request_id']];
            }finally{fclose($handle);}
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
