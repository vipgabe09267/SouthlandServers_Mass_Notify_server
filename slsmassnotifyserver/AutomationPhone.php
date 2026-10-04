<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/AutomationService.php';
require_once __DIR__.'/bin/sls_mass_notify/sls_live_paging.php';

/** An internal SIP softkey dials the saved extension, then the caller confirms. */
final class AutomationPhone
{
    private LivePagingAgi $agi; private LivePagingState $slots;
    private $settings; private $store; private $worker; private $sound;
    public function __construct(LivePagingAgi $agi,LivePagingState $slots,callable $settings,callable $store,callable $worker,callable $sound)
    { $this->agi=$agi; $this->slots=$slots; $this->settings=$settings; $this->store=$store; $this->worker=$worker; $this->sound=$sound; }
    public function run(array $environment): array
    {
        $settings=($this->settings)(); $config=AutomationConfig::normalize($settings['automations'] ?? []); $rule=null;
        foreach ($config['rules'] as $candidate) {
            if ($candidate['kind']==='panic' && $candidate['enabled'] && $candidate['dial_extension']!=='' && $candidate['dial_extension']===($environment['agi_extension'] ?? '')) { $rule=$candidate; break; }
        }
        if (!$rule || ($environment['agi_context'] ?? '')!=='sls-trigger-panic' || empty($settings['enabled'])) { return ['state'=>'disabled']; }
        $caller=$this->agi->variable('${CHANNEL(endpoint)}');
        if (!preg_match('/^[0-9]{1,20}$/D',$caller) || !in_array($caller,$rule['callers'],true)
            || !preg_match('/^PJSIP\/'.preg_quote($caller,'/').'-[a-f0-9]{8,16}$/Di',$environment['agi_channel'] ?? '')
            || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$environment['agi_uniqueid'] ?? '')) { return ['state'=>'unauthorized_caller']; }
        if (!$this->slots->acquireCallSlot($caller)) { return ['state'=>'call_capacity_reached']; }
        try {
            $prompts=LivePagingConfig::promptFiles($settings);
            foreach (['panic_'.$rule['id'],'panic_queued','panic_failed'] as $key) {
                if (!isset($prompts[$key]) || !($this->sound)($prompts[$key])) { throw new \RuntimeException('Panic prompts are unavailable. Apply Config before activating this shortcut.'); }
            }
            $service=new AutomationService(($this->store)(),$this->settings,static function(){ throw new \LogicException('Phone admission cannot deliver an announcement.'); },static function(){ throw new \LogicException('Phone admission cannot execute an action.'); });
            $request=substr(hash('sha256',$rule['id'].'|'.$environment['agi_uniqueid']),0,32);
            $challenge=$service->challenge($rule['id'],$request);
            $this->agi->answer(); $this->agi->autoHangup(45);
            // Stream the whole purpose/confirmation prompt before accepting DTMF.
            $this->agi->play($prompts['panic_'.$rule['id']]);
            if ($this->agi->digits('beep',1)!=='1') { return ['state'=>'not_confirmed']; }
            $remaining=$challenge['not_before']-time(); if ($remaining>0) { usleep(min(2,$remaining)*1000000); }
            $now=time();
            try {
                $event=$service->activate($rule['id'],['request_id'=>$request,'sent_at'=>$now,'expires_at'=>$now+$rule['max_age_seconds'],
                    'event'=>'panic','message'=>'Phone panic activation requested.','caller'=>$caller,'is_test'=>false,'confirmation'=>$challenge['confirmation']], 'panic_phone');
                ($this->worker)($event['id']);
                $this->agi->play($prompts['panic_queued']); return ['state'=>'queued','event_id'=>$event['id']];
            } catch (\Throwable $error) { $this->agi->play($prompts['panic_failed']); return ['state'=>'unconfirmed']; }
        } finally { $this->slots->releaseCallSlot(); }
    }
}
