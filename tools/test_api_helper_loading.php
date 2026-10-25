<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sls-api-copies-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    foreach(['deployed','module'] as $copy){
        mkdir($root.'/'.$copy,0700);
        foreach(['security.php','person-profiles.php','desktop-feed-cache.php'] as $file){copy(dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/'.$file,$root.'/'.$copy.'/'.$file);require_once $root.'/'.$copy.'/'.$file;}
    }
    $profile=\SLS\MassNotify\PersonProfiles::normalize(['directory_person_id'=>'','language'=>'en','ownership_reviewed'=>true,'reviewed_at'=>1800000000]);
    if($profile['language']!=='en'||!class_exists(\SLS\MassNotify\DesktopFeedCache::class,false)){throw new RuntimeException('Reused helper definitions are unavailable.');}
    echo "API helper loading: distinct deployed/module copies coexist without class redeclaration or a framework bootstrap.\n";
}finally{
    foreach(glob($root.'/*/*') as $file){unlink($file);}foreach(glob($root.'/*') as $directory){rmdir($directory);}rmdir($root);
}
