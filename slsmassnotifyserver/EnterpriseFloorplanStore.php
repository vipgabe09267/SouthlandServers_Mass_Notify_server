<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Images are decoded, re-encoded and kept outside every HTTP document root. */
final class EnterpriseFloorplanStore
{
    public const DIRECTORY='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-floorplans';
    public const MAX_BYTES=5242880;
    private string $directory;
    public function __construct(string $directory=self::DIRECTORY)
    {
        $this->directory=rtrim($directory,'/');
        $parent=@lstat(dirname($this->directory));
        if(!$parent||($parent['mode']&0170000)!==0040000||($parent['mode']&0022)||realpath(dirname($this->directory))!==dirname($this->directory)){throw new \RuntimeException('Private floor plan storage parent is unsafe.');}
        if(!file_exists($this->directory)&&!is_link($this->directory)){$mask=umask(0077);try{if(!mkdir($this->directory,0700)){throw new \RuntimeException('Private floor plan storage cannot be created.');}}finally{umask($mask);}}
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
    public function put(string $bytes): array
    {
        if(strlen($bytes)<8||strlen($bytes)>self::MAX_BYTES){throw new \InvalidArgumentException('Upload a PNG or JPEG image of at most 5 MiB.');}
        $info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true)||$info[0]>4096||$info[1]>4096||$info[0]*$info[1]>8388608){throw new \InvalidArgumentException('Floor plans must be PNG/JPEG, at most 4096 pixels per side and eight megapixels. SVG and animated images are not accepted.');}
        if(!function_exists('imagecreatefromstring')){throw new \DomainException('Install the PHP GD extension before uploading floor plans. Existing private images remain available.');}
        $image=@imagecreatefromstring($bytes);if(!$image){throw new \InvalidArgumentException('This image could not be decoded. Export a standard PNG or JPEG and try again.');}
        ob_start();try{$ok=$info[2]===IMAGETYPE_PNG?imagepng($image,null,6):imagejpeg($image,null,90);$clean=ob_get_contents();}finally{ob_end_clean();imagedestroy($image);}
        if(!$ok||!is_string($clean)||strlen($clean)>self::MAX_BYTES){throw new \InvalidArgumentException('The normalized image exceeds 5 MiB. Reduce its resolution.');}
        $lock=$this->open($this->directory.'/.storage.lock','c+b');
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new \DomainException('Another floor plan is being saved. Retry this upload shortly.');}
        try {
        $id='img_'.hash('sha256',$clean);$path=$this->directory.'/'.$id;
        if(file_exists($path)||is_link($path)){$existing=$this->read($id);if(!hash_equals($clean,$existing['bytes'])){throw new \RuntimeException('An image digest conflicts with existing content.');}return ['image_id'=>$id,'width'=>$info[0],'height'=>$info[1]];}
        $count=0;$allocated=0;
        foreach(new \DirectoryIterator($this->directory) as $file){
            if($file->isDot()||$file->getFilename()==='.storage.lock'){continue;}
            if($file->isLink()||!$file->isFile()){throw new \RuntimeException('Unexpected files are present in private image storage. Preserve and review them.');}
            $allocated+=$file->getSize();
            if(++$count>=100||$allocated+strlen($clean)>33554432){throw new \DomainException('Private floor plans reached the 100-image or 32 MiB allocation. Export and review old images before uploading more.');}
        }
        $temp=$this->directory.'/.upload-'.bin2hex(random_bytes(16));$h=$this->open($temp,'x+b');
        try{if(fwrite($h,$clean)!==strlen($clean)||!fflush($h)||!fsync($h)){throw new \RuntimeException('Floor plan image could not be synchronized.');}}catch(\Throwable $error){fclose($h);unlink($temp);throw $error;}
        fclose($h);
        try{if(!rename($temp,$path)){throw new \RuntimeException('Floor plan image could not be saved.');}$dir=@fopen($this->directory,'r');try{if(!$dir||!fsync($dir)){throw new \RuntimeException('Floor plan directory could not be synchronized.');}}finally{if($dir){fclose($dir);}}}finally{if(file_exists($temp)){unlink($temp);}}
        return ['image_id'=>$id,'width'=>$info[0],'height'=>$info[1]];
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    public function read(string $id): array
    {
        if(!preg_match('/^img_[a-f0-9]{64}$/D',$id)){throw new \InvalidArgumentException('Invalid private floor plan image identifier.');}
        $h=$this->open($this->directory.'/'.$id,'r+b');try{$bytes=stream_get_contents($h,self::MAX_BYTES+1);}finally{fclose($h);}
        if(!is_string($bytes)||strlen($bytes)>self::MAX_BYTES||!hash_equals(substr($id,4),hash('sha256',$bytes))){throw new \RuntimeException('Floor plan image failed its content-integrity check.');}
        $info=@getimagesizefromstring($bytes);if(!$info||!in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true)){throw new \RuntimeException('Stored floor plan image is invalid.');}
        return ['bytes'=>$bytes,'type'=>$info[2]===IMAGETYPE_PNG?'image/png':'image/jpeg','width'=>$info[0],'height'=>$info[1]];
    }
}
