<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/PhoneInventory.php';
use SLS\MassNotify\PhoneInventory;
$root=sys_get_temp_dir().'/sls-phone-display-'.bin2hex(random_bytes(8));mkdir($root,0700);$path=$root.'/inventory.json';$now=1800000000;$checks=0;
function check_inventory($condition):void {++$GLOBALS['checks'];if(!$condition){throw new RuntimeException('Phone display snapshot validation failed.');}}
$value=['schema'=>1,'display_only'=>true,'observed_at'=>gmdate('c',$now),'expires_at'=>$now+90,
    'endpoints'=>['1000'=>['format'=>'yealink','formats'=>['yealink','cisco'],'user_agent'=>'Mixed devices','contacts'=>[
        ['format'=>'yealink','user_agent'=>'Yealink T48G'],['format'=>'cisco','user_agent'=>'Cisco']]]]];
$write=static function($v)use($path){file_put_contents($path,json_encode($v,JSON_THROW_ON_ERROR));chmod($path,0640);clearstatcache();};
try {
    check_inventory(!PhoneInventory::read($path,$now)['available']);$write($value);
    check_inventory(PhoneInventory::read($path,$now)['available']);
    check_inventory(count(PhoneInventory::read($path,$now)['endpoints'][1000]['contacts'])===2);
    check_inventory(!PhoneInventory::read($path,$now+90)['available']);
    foreach([['expires_at'=>NAN],['expires_at'=>$now+1000],['observed_at'=>gmdate('c',$now+10)],['display_only'=>false],
        ['endpoints'=>['bad'=>$value['endpoints'][1000]]],['endpoints'=>['1000'=>['contacts'=>'bad']]]]as $patch){
        if(isset($patch['expires_at'])&&is_nan((float)$patch['expires_at'])){file_put_contents($path,'{"expires_at":NaN}');}else{$write(array_replace($value,$patch));}
        check_inventory(!PhoneInventory::read($path,$now)['available']);
    }
    $write($value);chmod($path,0644);clearstatcache();check_inventory(!PhoneInventory::read($path,$now)['available']);
    chmod($path,0640);link($path,$root.'/hardlink');clearstatcache();check_inventory(!PhoneInventory::read($path,$now)['available']);unlink($root.'/hardlink');
    rename($path,$root.'/target');symlink($root.'/target',$path);clearstatcache();check_inventory(!PhoneInventory::read($path,$now)['available']);
}finally{foreach(glob($root.'/*')as$file){unlink($file);}rmdir($root);}
echo "Phone display snapshots: {$checks} availability, mixed-device, freshness and filesystem checks passed.\n";
