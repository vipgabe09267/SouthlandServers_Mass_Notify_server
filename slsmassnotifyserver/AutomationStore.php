<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/InitializationJournal.php';
// CLI workers load the installed runtime copy before FreePBX loads the module.
// Both paths declare the same shared job-store class.
if (!class_exists('SlsAnnouncementJobStore', false)) {
    require_once __DIR__ . '/bin/sls_mass_notify/sls_announcement_jobs.php';
}

/** Small bounded journal on the same durable, checked file primitives as delivery jobs. */
final class AutomationStore
{
    public const DIRECTORY='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/automations';
    private const MARKER="SLS_AUTOMATION_STATE_V1\n";
    private \SlsAnnouncementJobStore $files;
    private string $markerPath;
    public function __construct(string $directory=self::DIRECTORY)
    {
        $directory=rtrim($directory,'/');$this->markerPath=dirname($directory).'/.'.basename($directory).'-required';
        $required=$this->fence();
        if($required&&(!is_dir($directory)||is_link($directory))){throw new \RuntimeException('Established trigger directory is missing. Preserve replay history for reviewed recovery.');}
        $this->files=new \SlsAnnouncementJobStore($directory);
    }
    private static function initial(): array {return ['schema'=>1,'events'=>[],'challenges'=>[],'cooldowns'=>[],'invalidated'=>[],'replay_fence_required'=>true];}
    private static function text($value, int $limit, bool $empty=false): bool
    {
        return is_string($value) && strlen($value)<=$limit && preg_match('//u',$value)===1
            && !preg_match('/[\p{C}]/u',$value) && ($empty || trim($value)!=='');
    }
    private function fence(): bool
    {
        $marker=InitializationJournal::read($this->markerPath);
        if($marker!==null&&$marker!==self::MARKER){throw new \RuntimeException('Trigger replay marker is damaged. Preserve its history.');}
        return $marker!==null;
    }
    public function read(): array
    {
        $required=$this->fence();
        if(InitializationJournal::read($this->files->directory().'/initialization.pending')!==null){throw new \RuntimeException('Trigger initialization is unfinished. Retry its transaction before using the journal.');}
        $state=$this->files->state();
        if (!$state) {
            if($required){throw new \RuntimeException('Established trigger journal is missing. Restore its history; no event was admitted.');}
            return self::initial();
        }
        $keys=array_keys($state);sort($keys,SORT_STRING);
        $legacyKeys=['challenges','cooldowns','events','invalidated','schema'];
        $currentKeys=['challenges','cooldowns','events','invalidated','replay_fence_required','schema'];
        if($keys!==$legacyKeys&&$keys!==$currentKeys){
            throw new \RuntimeException('Trigger journal contains unsupported fields. Preserve it for reviewed recovery.');
        }
        if(array_key_exists('replay_fence_required',$state)&&($state['replay_fence_required']!==true||!$required)){
            throw new \RuntimeException('Established trigger replay marker is missing or invalid. Preserve its history.');
        }
        if (($state['schema'] ?? null) !== 1) { throw new \RuntimeException('Trigger journal schema is unsupported. Preserve the journal for recovery.'); }
        foreach (['events','challenges','cooldowns','invalidated'] as $key) {
            if (!is_array($state[$key] ?? null)) { throw new \RuntimeException('Trigger journal is damaged. Preserve it; do not delete it to retry an activation.'); }
        }
        if (count($state['events']) > 500 || count($state['challenges']) > 128 || count($state['cooldowns']) > 100 || count($state['invalidated']) > 2048) {
            throw new \RuntimeException('Trigger journal exceeds its bounded storage allocation.');
        }
        foreach($state['challenges'] as $id=>$challenge){
            $challengeKeys=is_array($challenge)?array_keys($challenge):[];sort($challengeKeys,SORT_STRING);
            if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)||!is_array($challenge)
                ||$challengeKeys!==['confirmation','expires_at','location','not_before','request_id','revision','rule_id','trigger']
                ||!is_string($challenge['rule_id'])||!preg_match('/^trg_[a-f0-9]{24}$/D',$challenge['rule_id'])
                ||!is_string($challenge['request_id'])||!preg_match('/^[a-f0-9]{32}$/D',$challenge['request_id'])
                ||!hash_equals($id,hash('sha256',$challenge['rule_id'].'|'.$challenge['request_id']))
                ||!is_string($challenge['confirmation'])||!preg_match('/^[a-f0-9]{32}$/D',$challenge['confirmation'])
                ||!is_string($challenge['revision'])||!preg_match('/^[a-f0-9]{64}$/D',$challenge['revision'])
                ||!is_int($challenge['expires_at'])||!is_int($challenge['not_before'])||$challenge['not_before']<1||$challenge['not_before']>=$challenge['expires_at']
                ||!self::text($challenge['location'],160)||!self::text($challenge['trigger'],80)){
                throw new \RuntimeException('Panic confirmation history is invalid. Preserve it; no activation was authorized.');
            }
        }
        foreach($state['cooldowns'] as $id=>$expires){
            if(!is_string($id)||!preg_match('/^trg_[a-f0-9]{24}$/D',$id)||!is_int($expires)||$expires<1){
                throw new \RuntimeException('Trigger cooldown history is invalid. Preserve it; no event was admitted.');
            }
        }
        foreach($state['invalidated'] as $id=>$expires){
            if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)||!is_int($expires)||$expires<1){
                throw new \RuntimeException('CAP invalidation history is invalid. Preserve it; no event was admitted.');
            }
        }
        foreach($state['events'] as $id=>$event){
            $context=$event['context']??null;
            $eventKeys=is_array($event)?array_keys($event):[];sort($eventKeys,SORT_STRING);
            $allowedEventKeys=['blocked','caller','claimed_at','context','created_at','error','event','fields','fingerprint','id','is_test','message','references','results','rule_id','rule_name','source','state'];
            if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)||!is_array($event)||array_diff($eventKeys,$allowedEventKeys)||($event['id']??null)!==$id
                ||!is_string($event['fingerprint']??null)||!preg_match('/^[a-f0-9]{64}$/D',$event['fingerprint'])
                ||!is_string($event['rule_id']??null)||!preg_match('/^trg_[a-f0-9]{24}$/D',$event['rule_id'])
                ||!is_int($event['created_at']??null)||$event['created_at']<1||!in_array($event['state']??null,['queued','running','complete','failed','uncertain','expired','cancelled'],true)
                ||!is_array($context)||count($context)!==4||array_diff(array_keys($context),['event_id','rule_id','revision','expires_at'])
                ||($context['event_id']??null)!==$id||($context['rule_id']??null)!==$event['rule_id']
                ||!is_string($context['revision']??null)||!preg_match('/^[a-f0-9]{64}$/D',$context['revision'])||!is_int($context['expires_at']??null)||$context['expires_at']<1
                ||!is_bool($event['blocked']??null)||!is_bool($event['is_test']??null)
                ||!in_array($event['source']??null,['panic','panic_phone','sensor','emergency_call','cap'],true)
                ||!self::text($event['rule_name']??null,80)||!self::text($event['event']??null,80)
                ||!self::text($event['message']??null,3000,true)||!self::text($event['caller']??null,20,true)
                ||!is_array($event['fields']??null)||count($event['fields'])>12||!is_array($event['results']??null)||count($event['results'])>12
                ||!is_array($event['references']??null)||!array_is_list($event['references'])||count($event['references'])>100
                ||count($event['references'])!==count(array_unique($event['references']))) {
                throw new \RuntimeException('Trigger event history is invalid. Preserve it; no replay was authorized.');
            }
            foreach($event['references'] as $reference){if(!is_string($reference)||!preg_match('/^[a-f0-9]{64}$/D',$reference)){throw new \RuntimeException('Trigger event history contains an invalid CAP lineage. Preserve it; no replay was authorized.');}}
            foreach($event['fields'] as $key=>$value){
                if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$key)||!self::text($value,3000,true)){
                    throw new \RuntimeException('Trigger event history contains invalid template fields. Preserve it; no replay was authorized.');
                }
            }
            foreach($event['results'] as $key=>$value){
                if(!is_string($key)||(!in_array($key,['announcement','guard','worker'],true)&&!preg_match('/^act_[a-f0-9]{24}$/D',$key))||!is_array($value)){
                    throw new \RuntimeException('Trigger event history contains invalid action results. Preserve it; no replay was authorized.');
                }
            }
            if((array_key_exists('claimed_at',$event)&&(!is_int($event['claimed_at'])||$event['claimed_at']<1))
                ||(array_key_exists('error',$event)&&!self::text($event['error'],500))){
                throw new \RuntimeException('Trigger event lifecycle history is invalid. Preserve it; no replay was authorized.');
            }
        }
        return $state;
    }
    private function initialize(): void
    {
        $directory=$this->files->directory();$intentPath=$directory.'/initialization.pending';
        $expected="SLS_AUTOMATION_INIT_V1\n".hash('sha256',json_encode(self::initial(),JSON_THROW_ON_ERROR))."\n";
        $intent=InitializationJournal::read($intentPath);$present=file_exists($directory.'/worker-state.json')||is_link($directory.'/worker-state.json');
        if($intent!==null){
            if($intent!==$expected||($present&&$this->files->state()!==self::initial())){throw new \RuntimeException('Trigger initialization conflicts with application history. Preserve both for review.');}
            if(!$present){$this->files->atomic('worker-state.json',self::initial());}
            InitializationJournal::write($this->markerPath,self::MARKER);InitializationJournal::finish($intentPath,$expected);return;
        }
        if($present){
            $state=$this->read();
            InitializationJournal::write($this->markerPath,self::MARKER);
            if(!isset($state['replay_fence_required'])){$state['replay_fence_required']=true;$this->files->atomic('worker-state.json',$state);}
            return;
        }
        if($this->fence()){throw new \RuntimeException('Established trigger journal is missing. Restore its history.');}
        throw new \RuntimeException('Trigger initialization intent is unavailable. Preserve unknown history for review.');
    }
    public function transaction(callable $work)
    {
        $directory=$this->files->directory();$firstLock=!file_exists($directory.'/announcement-send.lock')&&!is_link($directory.'/announcement-send.lock');
        $lock=$this->files->admissionLock();
        if (!$lock) { throw new \RuntimeException('Trigger journal is busy. Retry the same event identifier.'); }
        try {
            $present=file_exists($directory.'/worker-state.json')||is_link($directory.'/worker-state.json');
            if(!$present&&!$this->fence()&&InitializationJournal::read($directory.'/initialization.pending')===null){
                if(!$firstLock){throw new \RuntimeException('Trigger state is missing without an initialization intent. Preserve the existing mutex and review recovery; no history was reset.');}
                InitializationJournal::write($directory.'/initialization.pending',"SLS_AUTOMATION_INIT_V1\n".hash('sha256',json_encode(self::initial(),JSON_THROW_ON_ERROR))."\n");
            }
            $this->initialize();
            $state=$this->read(); $result=$work($state);
            if (strlen(json_encode($state,JSON_THROW_ON_ERROR)) > 1800000) { throw new \RuntimeException('Trigger journal is full. Export its history and review retention before admitting more events.'); }
            $this->files->atomic('worker-state.json',$state); return $result;
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }
    public static function prune(array &$state, int $now): void
    {
        $state['challenges']=array_filter($state['challenges'],static fn($v)=>$v['expires_at']>$now);
        $state['invalidated']=array_filter($state['invalidated'],static fn($v)=>$v>$now);
        $state['cooldowns']=array_filter($state['cooldowns'],static fn($v)=>$v>$now);
        // Keep at least a day of event deduplication. Admission timestamps and
        // signed-request freshness reject replay after that window.
        foreach ($state['events'] as $id=>$event) {
            if ($event['created_at'] < $now-86400 && in_array($event['state'],['complete','failed','uncertain','expired','cancelled'],true)) { unset($state['events'][$id]); }
        }
    }
    public function permits(array $context, int $now): bool
    {
        $eventId=$context['event_id']??null;
        if(!is_string($eventId)||!preg_match('/^[a-f0-9]{64}$/D',$eventId)){return false;}
        $state=$this->read(); $event=$state['events'][$eventId] ?? null;
        if (!$event || ($event['context'] ?? null) !== $context || !empty($event['blocked'])) { return false; }
        foreach ($event['references'] as $reference) { if (($state['invalidated'][$reference] ?? 0)>$now) { return false; } }
        return true;
    }
}
