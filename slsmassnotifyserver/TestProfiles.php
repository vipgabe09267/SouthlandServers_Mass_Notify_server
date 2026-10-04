<?php
namespace FreePBX\modules;
require_once __DIR__.'/TestDeliveryReport.php';

/** Portable, explicitly scoped channel checks. Never sends email or webhooks. */
trait SlsTestProfiles
{
    /** Capture only publication markers from this worker, never its latest event. */
    private function testDesktopPublications(array $lines, array $allowed, array $settings): array
    {
        $clients=[]; $rows=[];
        foreach ($this->getDesktopClients($settings) as $client) { $clients[(string)$client['username']]=(string)($client['client_id']??''); }
        foreach ($lines as $line) {
            if (!str_starts_with($line,'SLS_TEST_DESKTOP_PUBLICATION ')) { continue; }
            $marker=json_decode(substr($line,29),true);
            if (!is_array($marker) || !is_string($marker['event_id']??null) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$marker['event_id']) || !is_array($marker['targets']??null)) { continue; }
            foreach (array_intersect($allowed,$marker['targets']) as $username) {
                if (!array_key_exists($username,$clients)) { continue; }
                $rows[$marker['event_id'].':'.$username]=['event_id'=>$marker['event_id'],'target'=>$username,'client_id'=>$clients[$username]];
            }
        }
        return array_values($rows);
    }

    private function withTestDeliveryReport(array $result, string $correlation, array $phones, array $desktops, array $settings): array
    {
        try {
            $ticket=\SLS\MassNotify\TestDeliveryReport::ticket(['correlation'=>$correlation,'created_at'=>time(),
                'phones'=>array_values(array_unique(array_map('strval',$phones))),'desktops'=>$desktops],$settings);
            $result['delivery_ticket']=$ticket;
            $result['delivery']=$this->getTestDeliveryStatus($ticket)['delivery'];
        } catch (\Throwable $error) {
            $result['errors'][]=_('Test receipt reporting is unavailable. Check protected storage and module readiness; do not resend solely to refresh the report.');
        }
        return $result;
    }

    public function getTestDeliveryStatus($ticket): array
    {
        $context=\SLS\MassNotify\TestDeliveryReport::context($ticket,$this->getActiveSettings(),time());
        $result=['receipts'=>array_map(static fn($row)=>$row+['channel'=>'desktop','state'=>'published','detail'=>'Awaiting desktop application receipt.'],$context['desktops'])];
        $result=$this->refreshAnnouncementDesktopReceipts($result,gmdate('c',$context['created_at']));
        $phone=$context['phones']?$this->readAnnouncementPhoneOutcomes($context['correlation']):['targets'=>[]];
        $result['receipts']=array_merge($result['receipts'],\SLS\MassNotify\TestDeliveryReport::phoneRows($context['phones'],$phone));
        $result['pending']=(bool)array_filter($result['receipts'],static fn($row)=>in_array($row['state'],['published','pending'],true));
        $result['phone_outcomes']=$phone;
        return ['success'=>true,'delivery'=>$result];
    }

    private function normalizeTestProfiles($rows)
    {
        $profiles = [];
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 10) as $row) {
            if (!is_array($row)) { continue; }
            $name = $this->sanitizeScheduleText(is_scalar($row['name'] ?? null) ? $row['name'] : '', 64, true);
            if ($name === '') { continue; }
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', is_scalar($row['id'] ?? null) ? (string)$row['id'] : '');
            $id = $id !== '' ? substr($id, 0, 64) : 'test_' . substr(hash('sha256', $name), 0, 16);
            if (isset($profiles[$id])) { continue; }
            $phones = array_values(array_unique(array_filter(array_map('strval', array_filter((array)($row['extensions'] ?? []), 'is_scalar')),
                static function ($value) { return preg_match('/^[0-9]{1,20}$/', $value); })));
            $desktops = array_values(array_unique(array_filter(array_map('strval', array_filter((array)($row['desktop_clients'] ?? []), 'is_scalar')),
                static function ($value) { return preg_match('/^[A-Za-z0-9_.@-]{1,80}$/', $value); })));
            $profiles[$id] = ['id' => $id, 'name' => $name, 'extensions' => array_slice($phones, 0, 100),
                'desktop_clients' => array_slice($desktops, 0, 100),
                'channels' => in_array($row['channels'] ?? '', ['all', 'audio', 'visual'], true) ? $row['channels'] : 'all'];
        }
        return array_values($profiles);
    }

    public function runTestProfile($profileId)
    {
        if (!is_string($profileId) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $profileId)) {
            return ['success' => false, 'message' => _('Invalid test profile identifier.')];
        }
        foreach ($this->getActiveSettings()['test_profiles'] ?? [] as $profile) {
            if ($profile['id'] !== (string)$profileId) { continue; }
            $audio = $profile['channels'] !== 'visual';
            $visual = $profile['channels'] !== 'audio';
            if (empty($profile['extensions']) && (!$visual || empty($profile['desktop_clients']))) {
                return ['success' => false, 'message' => _('This test profile has no destination for the selected channels.')];
            }
            return $this->sendSipNotifyAnnouncement($profile['extensions'],
                'SYSTEM TEST — NOT AN ACTUAL ALERT. This is a notification channel check: ' . $profile['name'] . '.',
                true, $audio, [], ['desktop_clients' => $visual ? $profile['desktop_clients'] : [],
                    'audio_mode' => $audio ? 'tones_tts' : 'none', 'title' => 'System channel test',
                    'trigger_source' => 'Saved Channel Test', '_is_test' => true, '_test_channels' => [
                        'audio' => $audio ? $profile['extensions'] : [],
                        'sip_notify' => $visual ? $profile['extensions'] : [],
                        'desktop' => $visual ? $profile['desktop_clients'] : [], 'webhook' => []]]);
        }
        return ['success' => false, 'message' => _('Saved test profile not found. Save and apply it before running.')];
    }

    private function normalizePagingAnswerTimeout($value)
    {
        // Page() is an auto-answer path. Keep the established five-second
        // ceiling; a longer ringing window needs a different playback lifecycle.
        return max(1, min(5, is_numeric($value) ? (int)$value : 5));
    }
}
