<?php
declare(strict_types=1);
namespace FreePBX\modules {
    function exec($command, &$output, &$code=null): void {
        $output=$GLOBALS['cron_fixture']['lines'];$code=$GLOBALS['cron_fixture']['code'];
    }
    function posix_geteuid(): int {return \posix_geteuid();}
    function posix_getpwuid($uid): array {return ['name'=>'private-fixture'];}
}
namespace {
    if (!interface_exists('BMO')) {interface BMO {}}
    require dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
    $reflection=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
    $sourcePath=$reflection->getFileName();
    if(!is_string($sourcePath)||$sourcePath===''){throw new \RuntimeException('Module source path is unavailable.');}
    $source=file($sourcePath);
    if(!is_array($source)){throw new \RuntimeException('Module source is unreadable.');}
    $body='';
    foreach(['unlinkNamedFileIfIdentity','removeLegacyNwsCronJob','readNativeUserCrontab','installNativeUserCrontab']as$name){
        $method=$reflection->getMethod($name);$methodLines=array_slice($source,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1);
        if(!$methodLines){throw new \RuntimeException('Cron helper source is missing: '.$name);}$body.=implode('',$methodLines);
    }
    eval('namespace FreePBX\\modules;class CronFixture {public $commands=[];public $saved="";private function runCommand($command,$timeout=120){$this->commands[]=$command;$this->saved=file_get_contents(trim(substr($command,strlen("/usr/bin/crontab ")),"\'"));return true;}public function clean(){return $this->removeLegacyNwsCronJob();}'.$body.'}');
    foreach ([['code'=>2,'lines'=>['* * * * * /usr/local/bin/nws_weather_alert.sh']],
        ['code'=>124,'lines'=>[]],['code'=>1,'lines'=>['permission denied']]] as $failed) {
        $GLOBALS['cron_fixture']=$failed;$fixture=new \FreePBX\modules\CronFixture;$rejected=false;
        try {$fixture->clean();}catch(\RuntimeException $error){$rejected=true;}
        if(!$rejected||$fixture->commands!==[]){throw new \RuntimeException('A failed crontab read reached replacement or was silently accepted.');}
    }
    $GLOBALS['cron_fixture']=['code'=>1,'lines'=>['no crontab for private-fixture']];
    $fixture=new \FreePBX\modules\CronFixture;$fixture->clean();
    if($fixture->commands!==[]){throw new \RuntimeException('A genuinely empty crontab was changed by legacy cleanup.');}
    $unrelated='7 * * * * /usr/local/bin/private-unrelated-job';
    $GLOBALS['cron_fixture']=['code'=>0,'lines'=>[$unrelated,'* * * * * /usr/local/bin/nws_weather_alert.sh']];
    $fixture=new \FreePBX\modules\CronFixture;$fixture->clean();
    if(count($fixture->commands)!==1||$fixture->saved!==$unrelated."\n"){throw new \RuntimeException('Verified legacy cleanup altered an unrelated cron entry.');}
    echo "Native legacy cron cleanup rejects partial/error/timeout reads and preserves an absent user crontab; no cron command executed.\n";
}
