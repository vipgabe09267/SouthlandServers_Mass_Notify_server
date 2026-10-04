<?php
declare(strict_types=1);
namespace SLS\MassNotify;
// CLI workers load the installed runtime copy before FreePBX loads the module.
// Both paths declare the same shared job-store class.
if (!class_exists('SlsAnnouncementJobStore', false)) {
    require_once __DIR__ . '/bin/sls_mass_notify/sls_announcement_jobs.php';
}

/** Small bounded journal on the same durable, checked file primitives as delivery jobs. */
final class AutomationStore
{
    public const DIRECTORY='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/automations';
    private \SlsAnnouncementJobStore $files;
    public function __construct(string $directory=self::DIRECTORY) { $this->files=new \SlsAnnouncementJobStore($directory); }
    public function read(): array
    {
        $state=$this->files->state();
        if (!$state) { return ['schema'=>1,'events'=>[],'challenges'=>[],'cooldowns'=>[],'invalidated'=>[]]; }
        if (($state['schema'] ?? null) !== 1) { throw new \RuntimeException('Trigger journal schema is unsupported. Preserve the journal for recovery.'); }
        foreach (['events','challenges','cooldowns','invalidated'] as $key) {
            if (!is_array($state[$key] ?? null)) { throw new \RuntimeException('Trigger journal is damaged. Preserve it; do not delete it to retry an activation.'); }
        }
        if (count($state['events']) > 500 || count($state['challenges']) > 128 || count($state['cooldowns']) > 100 || count($state['invalidated']) > 2048) {
            throw new \RuntimeException('Trigger journal exceeds its bounded storage allocation.');
        }
        return $state;
    }
    public function transaction(callable $work)
    {
        $lock=$this->files->admissionLock();
        if (!$lock) { throw new \RuntimeException('Trigger journal is busy. Retry the same event identifier.'); }
        try {
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
        $state=$this->read(); $event=$state['events'][$context['event_id'] ?? ''] ?? null;
        if (!$event || ($event['context'] ?? null) !== $context || !empty($event['blocked'])) { return false; }
        foreach ($event['references'] ?? [] as $reference) { if (($state['invalidated'][$reference] ?? 0)>$now) { return false; } }
        return true;
    }
}
