<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__ . '/IncidentService.php';
require_once __DIR__ . '/EnterpriseClusterIncidentStore.php';
require_once __DIR__ . '/api/sls-mass-notify/security.php';
use SLS\MassNotify\IncidentConfig;
use SLS\MassNotify\IncidentService;
use SLS\MassNotify\IncidentStore;

/** FreePBX facade. HTTP/API callers must apply their normal authentication/scopes. */
trait SlsIncidents
{
    private ?array $incidentSubmissionContext = null;
    private ?string $incidentSpeechSubmissionVoice = null;
    public static function validateIncidentContext($value): array { return IncidentConfig::context($value); }
    protected function currentIncidentAnnouncementContext(): ?array { return $this->incidentSubmissionContext; }
    protected function currentIncidentSpeechVoice(): ?string { return $this->incidentSpeechSubmissionVoice; }
    protected function incidentStore(): IncidentStore
    {
        $settings=$this->getActiveSettings();
        if (\SLS\MassNotify\EnterpriseClusterConfig::enabled($settings) && ($settings['enterprise_cluster']['mode']??'')==='notification_ha') {
            return new \SLS\MassNotify\EnterpriseClusterIncidentStore(self::PLUGIN_DATA_DIR.'/incidents',fn():array=>$this->getActiveSettings());
        }
        return new IncidentStore(self::PLUGIN_DATA_DIR . '/incidents');
    }
    private function incidentActor(array $actor = []): array
    {
        $principal = $GLOBALS['sls_control_principal'] ?? null;
        if (is_array($principal)) {
            $id = $principal['id'] ?? '';
            if (!is_string($id) || !preg_match('/^api_[a-f0-9]{24}$/D', $id)) {
                throw new \InvalidArgumentException('Incident workflows require a named, revocable Control API credential. Create one with the required scope before continuing.');
            }
            return ['identity' => isset($principal['operator_role']) ? $principal['username'] : 'Control API ' . $id,
                'source' => isset($principal['operator_role']) ? 'freepbx_operator' : 'control_api', 'credential_id' => $id];
        }
        if ($actor) { return $actor; }
        $user = $_SESSION['AMP_user'] ?? null;
        return ['identity' => is_object($user) && is_string($user->username ?? null) ? $user->username : (PHP_SAPI === 'cli' ? 'SLS incident worker' : 'FreePBX administrator'),
            'source' => PHP_SAPI === 'cli' ? 'incident_worker' : 'freepbx'];
    }
    private function incidentTemplates(bool $pending = false): array
    {
        $settings = $pending ? ($this->getPendingSettings() ?? $this->getActiveSettings()) : $this->getActiveSettings();
        return IncidentConfig::normalize($settings['incident_workflows'] ?? [])['templates'];
    }
    private function incidentService(): IncidentService
    {
        return new IncidentService($this->incidentStore(), function ($id): array {
            foreach ($this->incidentTemplates() as $template) { if ($template['id'] === $id) { return method_exists($this,'routeEnterpriseIncidentTemplate') ? $this->routeEnterpriseIncidentTemplate($template) : $template; } }
            throw new \InvalidArgumentException('Incident template is unavailable. Save and Apply Config before launching it.');
        }, function (array $delivery, string $locale = ''): array { return $this->freezeIncidentDelivery($delivery, $locale); },
        function (array $delivery, string $message, string $title, array $context, array $actor): array {
            return $this->submitIncidentAnnouncement($delivery, $message, $title, $context, $actor);
        }, function (string $job): array { return $this->getAnnouncementJob($job); });
    }
    /** Resolve saved groups once, without permitting subsequent group additions. */
    protected function freezeIncidentDelivery(array $delivery, string $locale = '', bool $freezeSpeech = true): array
    {
        $delivery = IncidentConfig::delivery($delivery); $settings = $this->getActiveSettings();
        $groups = array_column($this->getAnnouncementGroups(), null, 'id');
        foreach ($delivery['group_ids'] as $id) {
            if (!isset($groups[$id])) { throw new \InvalidArgumentException('A saved incident group no longer exists. Update its template.'); }
            if (!\SLS\MassNotify\ApiSecurity::groupDesktopBindingsValid($groups[$id], $settings)) {
                throw new \InvalidArgumentException('A desktop identity in the location audience changed. Create a new reviewed audience from Locations.');
            }
            foreach (['extensions', 'desktop_clients', 'voice_recipient_ids', 'email_recipient_ids', 'sms_recipient_ids', 'webhook_ids'] as $key) {
                $delivery[$key] = array_values(array_unique(array_merge($delivery[$key], array_map('strval', $groups[$id][$key] ?? []))));
            }
        }
        $delivery['group_ids'] = [];
        $extensions = array_map('strval', $this->getConfiguredPjsipExtensionNumbers());
        if (array_diff($delivery['extensions'], $extensions)) { throw new \InvalidArgumentException('An incident phone extension no longer exists. Update its template.'); }
        $identities = ['desktop_clients' => [], 'voice_recipient_ids' => [], 'webhook_ids' => [], 'email_recipient_ids' => [], 'sms_recipient_ids' => []];
        $desktops = [];
        foreach ($this->getDesktopClients($settings) as $client) {
            if (!empty($client['enabled'])) {
                $desktops[] = (string)$client['username'];
                if (in_array((string)$client['username'], $delivery['desktop_clients'], true)) {
                    $identities['desktop_clients'][(string)$client['username']] = hash('sha256', (string)($client['client_id'] ?? '') . "\0" . (string)$client['username']);
                }
            }
        }
        if (array_diff($delivery['desktop_clients'], $desktops)) { throw new \InvalidArgumentException('An incident desktop is disabled or no longer exists. Update its template.'); }
        $voiceTargets = $this->outboundVoiceTargets($settings);
        $voices = array_keys($voiceTargets);
        foreach ($delivery['voice_recipient_ids'] as $id) { if (isset($voiceTargets[$id])) { $identities['voice_recipient_ids'][$id] = $this->outboundVoiceFingerprint($voiceTargets[$id]); } }
        if (array_diff($delivery['voice_recipient_ids'], $voices)) { throw new \InvalidArgumentException('An incident external voice recipient is unavailable.'); }
        if ($delivery['email_recipient_ids']) {
            $emailTargets = $this->announcementEmailTargets($settings);
            if (array_diff($delivery['email_recipient_ids'], array_keys($emailTargets))) {
                throw new \InvalidArgumentException('An incident email recipient is disabled or unavailable.');
            }
            foreach ($delivery['email_recipient_ids'] as $id) {
                $identities['email_recipient_ids'][$id] = $this->announcementEmailFingerprint($emailTargets[$id]);
            }
        }
        if ($delivery['sms_recipient_ids']) {
            $smsTargets=\SLS\MassNotify\Sms\Service::targets($settings);
            if (array_diff($delivery['sms_recipient_ids'],array_keys($smsTargets))) { throw new \InvalidArgumentException('An incident SMS recipient is disabled or lacks recorded consent.'); }
            $smsConfig=\SLS\MassNotify\Sms\Config::normalize($settings['announcement_sms']??[]);
            $route=\SLS\MassNotify\Sms\Config::route($smsConfig,\SLS\MassNotify\Sms\Service::callbackUrl($settings));
            foreach ($delivery['sms_recipient_ids'] as $id) { $identities['sms_recipient_ids'][$id]=hash('sha256',\SLS\MassNotify\Sms\Service::targetFingerprint($smsTargets[$id]).$route); }
        }
        $webhooks = [];
        foreach ($this->normalizeWebhookDestinations($settings['announcement_webhooks'] ?? [], 'announcement') as $row) {
            if (!empty($row['enabled'])) {
                $webhooks[] = (string)$row['id'];
                if (in_array((string)$row['id'], $delivery['webhook_ids'], true)) { $identities['webhook_ids'][(string)$row['id']] = $this->webhookDestinationFingerprint($row); }
            }
        }
        if (array_diff($delivery['webhook_ids'], $webhooks)) { throw new \InvalidArgumentException('An incident webhook destination is disabled or unavailable.'); }
        foreach (['opening_tone', 'closing_tone'] as $field) {
            if ($delivery[$field] !== '' && !in_array($delivery[$field], $this->getAvailableTones(), true)) { throw new \InvalidArgumentException('An incident tone is unavailable.'); }
        }
        foreach ($identities as &$map) { ksort($map); } unset($map);
        $frozen = IncidentConfig::delivery($delivery) + ['_identities' => $identities];
        if ($freezeSpeech && in_array($delivery['audio_mode'], ['tts', 'tones_tts'], true)) {
            $frozen['_speech'] = $this->incidentSpeechSnapshot($locale, $settings);
        }
        return $frozen;
    }

    protected function incidentSpeechSnapshot(string $locale, array $settings): array
    {
        $models = ['en' => 'en_US-lessac-low.onnx', 'es' => 'es_ES-davefx-medium.onnx', 'fr' => 'fr_FR-siwis-medium.onnx',
            'de' => 'de_DE-thorsten-low.onnx', 'pt' => 'pt_BR-faber-medium.onnx'];
        $path = $settings['announcement_piper_voice'] ?? self::PIPER_VOICE;
        $language = $locale === '' ? '' : IncidentConfig::speechLanguage($locale);
        if ($language !== '' && !str_starts_with(basename($path), $language . '_')) { $path = self::PIPER_VOICE_DIR . '/' . $models[$language]; }
        $external = $settings['outbound_voice']['piper_voice'] ?? '';
        if ($external === '' || ($language !== '' && !str_starts_with(basename($external), $language . '_'))) { $external = $path; }
        $snapshot = ['locale' => strtolower($locale), 'path' => $path, 'external_path' => $external];
        $this->validateIncidentSpeechSnapshot($snapshot);
        return $snapshot;
    }

    protected function validateIncidentSpeechSnapshot($snapshot): void
    {
        if (!is_array($snapshot) || count($snapshot) !== 3 || !is_string($snapshot['locale'] ?? null)
            || !is_string($snapshot['path'] ?? null) || !is_string($snapshot['external_path'] ?? null)) {
            throw new \InvalidArgumentException('The incident speech snapshot is invalid. No channels were submitted.');
        }
        $locale = $snapshot['locale'];
        foreach ([$snapshot['path'], $snapshot['external_path']] as $path) {
            if ($locale !== '' && !str_starts_with(basename($path), IncidentConfig::speechLanguage($locale) . '_')) {
                throw new \InvalidArgumentException('The incident voice does not match its reviewed language. No channels were submitted.');
            }
            if (dirname($path) !== self::PIPER_VOICE_DIR || is_link($path) || is_link($path . '.json')
                || !$this->isValidPiperVoiceFile($path) || !$this->isValidPiperVoiceFile($path . '.json')) {
                throw new \InvalidArgumentException('The incident speech model or its configuration is missing or failed checksum verification. Run Repair Installation to restore the selected language. No channels were submitted.');
            }
        }
    }
    protected function submitIncidentAnnouncement(array $delivery, string $message, string $title, array $context, array $actor): array
    {
        // Keep the frozen identity check and the facade's new job snapshot in
        // one shared activity lease. Config replacement takes the exclusive
        // lease, so it cannot redirect this incident between those reads.
        try { $activity = $this->acquireAnnouncementActivityLock(false, 30); }
        catch (\Throwable $error) {
            return ['success' => false, 'delivery_started' => false, 'error_code' => 'announcement_activity_timeout',
                'message' => 'Protected configuration maintenance prevented incident submission. No channels were submitted.'];
        }
        try { return $this->submitIncidentAnnouncementWithActivity($delivery, $message, $title, $context, $actor); }
        finally { $this->releaseNativeBackupFileLock($activity); }
    }
    private function submitIncidentAnnouncementWithActivity(array $delivery, string $message, string $title, array $context, array $actor): array
    {
        if (!defined(static::class . '::INCIDENT_JOB_CONTRACT') || self::INCIDENT_JOB_CONTRACT !== 1) {
            return ['success' => false, 'delivery_started' => false, 'message' => 'Incident delivery integration is unavailable. Install the matching module and announcement worker together.'];
        }
        $frozenIdentities = $delivery['_identities'] ?? null;
        $speech = $delivery['_speech'] ?? null;
        unset($delivery['_identities'], $delivery['_speech']);
        if ($speech !== null) {
            try { $this->validateIncidentSpeechSnapshot($speech); }
            catch (\InvalidArgumentException $error) { return ['success' => false, 'delivery_started' => false, 'message' => $error->getMessage()]; }
        }
        try { $delivery = IncidentConfig::delivery($delivery); $current = $this->freezeIncidentDelivery($delivery, '', false); }
        catch (\Throwable $error) { return ['success' => false, 'delivery_started' => false, 'message' => 'An incident destination is no longer enabled or authorized. Review the frozen audience; no update was submitted.']; }
        // Older incidents had no email channel. Add only an empty comparison
        // map; never invent identities for a newly injected email audience.
        if (is_array($frozenIdentities) && !array_key_exists('email_recipient_ids', $frozenIdentities) && !$delivery['email_recipient_ids']) {
            $frozenIdentities['email_recipient_ids'] = [];
        }
        if (is_array($frozenIdentities) && !array_key_exists('sms_recipient_ids',$frozenIdentities) && !$delivery['sms_recipient_ids']) { $frozenIdentities['sms_recipient_ids']=[]; }
        // Additive empty channels must not invalidate an older snapshot solely
        // because PHP's strict array comparison also compares map key order.
        if (is_array($frozenIdentities)) { ksort($frozenIdentities); }
        ksort($current['_identities']);
        if (!is_array($frozenIdentities) || $frozenIdentities !== $current['_identities']) {
            return ['success' => false, 'delivery_started' => false, 'message' => 'An incident desktop, external number, email address/sender, SMS consent/number/provider or webhook identity changed. Begin a new incident with the intended audience; no update was submitted.'];
        }
        // Announcement validation rechecks current destination authorization;
        // explicit frozen selectors cannot expand if saved groups later change.
        $validatedContext = IncidentConfig::context($context);
        $triggerContext=$actor['automation_context'] ?? null;
        if ($triggerContext !== null && (!is_array($triggerContext) || !method_exists($this,'automationContextPermitted') || !$this->automationContextPermitted($triggerContext))) {
            return ['success'=>false,'delivery_started'=>false,'message'=>'Trigger source changed, expired or was superseded. This automatic incident delivery was not submitted.'];
        }

        $hadPrincipal = array_key_exists('sls_control_principal', $GLOBALS);
        $previousPrincipal = $GLOBALS['sls_control_principal'] ?? null;
        if (isset($actor['credential_id'])) {
            $settings = $this->getActiveSettings();
            $principal = \SLS\MassNotify\ApiSecurity::currentCredential($settings, $actor['credential_id']);
            $resolved = ['phones' => $delivery['extensions'], 'desktops' => $delivery['desktop_clients'],
                'webhooks' => $delivery['webhook_ids'], 'voice_recipient_ids' => $delivery['voice_recipient_ids'],
                'email_recipient_ids' => $delivery['email_recipient_ids'], 'sms_recipient_ids' => $delivery['sms_recipient_ids']];
            if (!is_array($principal) || !\SLS\MassNotify\ApiSecurity::permitsResolvedAnnouncement($principal, $resolved, $settings)) {
                return ['success' => false, 'delivery_started' => false, 'message' => 'The originating API credential was revoked, disabled or no longer permits this incident audience. No announcement was submitted.'];
            }
            // Carry the revalidated named credential into the existing durable
            // announcement job, where each channel checks revocation again.
            $GLOBALS['sls_control_principal'] = $principal;
        } elseif (($actor['source'] ?? '') === 'control_api') {
            return ['success' => false, 'delivery_started' => false, 'message' => 'This incident lacks a revocable API authorization identity. No announcement was submitted.'];
        }
        $previousAutomationContext=$this->automationSubmissionContext ?? null;
        if ($triggerContext !== null) { $this->automationSubmissionContext=$triggerContext; }
        $previousContext = $this->incidentSubmissionContext;
        $previousVoice = $this->incidentSpeechSubmissionVoice;
        $this->incidentSubmissionContext = $validatedContext;
        $this->incidentSpeechSubmissionVoice = $speech['external_path'] ?? null;
        try { return $this->sendSipNotifyAnnouncement($delivery['extensions'], $message, true,
            in_array($delivery['audio_mode'], ['tts', 'tones_tts'], true), [], [
                'desktop_clients' => $delivery['desktop_clients'], 'voice_recipient_ids' => $delivery['voice_recipient_ids'],
                'email_recipient_ids' => $delivery['email_recipient_ids'], 'sms_recipient_ids' => $delivery['sms_recipient_ids'], 'webhook_ids' => $delivery['webhook_ids'], 'audio_mode' => $delivery['audio_mode'],
                'opening_tone' => $delivery['opening_tone'], 'closing_tone' => $delivery['closing_tone'],
                'piper_voice' => $speech['path'] ?? '',
                'style' => $delivery['style'], 'background_color' => $delivery['background_color'], 'title' => $title,
                'priority' => $context['severity'] === 'critical' ? 'urgent' : 'normal',
                'trigger_source' => isset($actor['credential_id']) ? 'Control API' : ($context['is_test'] ? 'Incident Drill' : 'Incident Workflow'), 'sender' => $actor['identity'],
                '_is_test' => $context['is_test']]);
        } finally {
            $this->incidentSubmissionContext = $previousContext;
            $this->incidentSpeechSubmissionVoice = $previousVoice;
            if ($triggerContext !== null) { $this->automationSubmissionContext=$previousAutomationContext; }
            if ($hadPrincipal) { $GLOBALS['sls_control_principal'] = $previousPrincipal; }
            else { unset($GLOBALS['sls_control_principal']); }
        }
    }
    private function incidentResult(callable $work): array
    {
        try { return $work(); }
        catch (\InvalidArgumentException $error) { return ['success' => false, 'error_code' => 'invalid_incident_request', 'message' => $error->getMessage()]; }
        catch (\Throwable $error) {
            return ['success' => false, 'error_code' => 'incident_storage_unavailable', 'message' => 'Incident operation could not confirm durable completion. Refresh its record and retry only with the same request identifier. Check incident storage permissions, free space and worker health.'];
        }
    }
    public function saveIncidentTemplate(array $input): array
    {
        return $this->incidentResult(function () use ($input): array {
            if (!$this->isSetupComplete($this->getActiveSettings())) { throw new \InvalidArgumentException($this->getSetupRequiredMessage()); }
            if (($input['id'] ?? '') === '') { $input['id'] = 'tpl_' . bin2hex(random_bytes(12)); }
            $template = IncidentConfig::template($input);
            $settings = $this->getPendingSettings() ?? $this->getActiveSettings();
            $workflow = IncidentConfig::normalize($settings['incident_workflows'] ?? []);
            $rows = $workflow['templates']; $found = false;
            foreach ($rows as &$row) { if ($row['id'] === $template['id']) { $row = $template; $found = true; break; } }
            unset($row); if (!$found) { $rows[] = $template; }
            $workflow['templates'] = $rows; $settings['incident_workflows'] = IncidentConfig::normalize($workflow);
            $this->persistPendingSettings($this->normalizeSettings($settings));
            return ['success' => true, 'template_id' => $template['id'], 'message' => 'Incident template saved. Apply Config before launching the new version. Existing incidents retain their original snapshot.'];
        });
    }
    public function deleteIncidentTemplate(string $id): array
    {
        return $this->incidentResult(function () use ($id): array {
            IncidentConfig::identifier($id, '/^tpl_[a-f0-9]{24}$/D', 'Template identifier');
            $settings = $this->getPendingSettings() ?? $this->getActiveSettings();
            $workflow = IncidentConfig::normalize($settings['incident_workflows'] ?? []);
            $workflow['templates'] = array_values(array_filter($workflow['templates'], static fn(array $row): bool => $row['id'] !== $id));
            $settings['incident_workflows'] = $workflow;
            $this->persistPendingSettings($this->normalizeSettings($settings));
            return ['success' => true, 'message' => 'Template removed from pending configuration. Existing incident history is preserved.'];
        });
    }
    public function saveIncidentRetention(array $input): array
    {
        return $this->incidentResult(function () use ($input): array {
            $settings = $this->getPendingSettings() ?? $this->getActiveSettings();
            $workflow = IncidentConfig::normalize($settings['incident_workflows'] ?? []);
            $workflow['retention'] = IncidentConfig::retention($input); $settings['incident_workflows'] = $workflow;
            $this->persistPendingSettings($this->normalizeSettings($settings));
            return ['success' => true, 'message' => 'Archival settings saved. Apply Config to activate them. Reports and previous request identities are retained permanently.'];
        });
    }
    public function listIncidents(int $limit = 50, string $cursor = '', bool $archived = false): array { return $this->incidentResult(fn(): array => $this->incidentService()->listing($limit, $cursor, $archived)); }
    public function getIncident(string $id): array { return $this->incidentResult(fn(): array => $this->incidentService()->get($id)); }
    private function authorizeIncidentDesktopIdentity(string $id, string $username, ?array $authenticatedIdentity): void
    {
        // A reused username must not inherit a former participant's history or
        // human-response authority. Credential rotation within the same stable
        // client identity remains compatible.
        if (!is_array($authenticatedIdentity) || !is_string($authenticatedIdentity['client_id'] ?? null)
            || $authenticatedIdentity['client_id'] === '' || !is_string($authenticatedIdentity['credential_fingerprint'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $authenticatedIdentity['credential_fingerprint'])) {
            throw new \InvalidArgumentException('A verified desktop authentication identity is required.');
        }
        $record = $this->incidentStore()->read($id);
        $expected = $record['template']['delivery']['_identities']['desktop_clients'][$username] ?? null;
        $current = null;
        foreach ($this->getDesktopClients($this->getActiveSettings()) as $client) {
            if (!empty($client['enabled']) && ($client['username'] ?? '') === $username && is_string($client['client_id'] ?? null)) {
                // Bind this request to the row that actually passed Basic auth,
                // rather than adopting a same-name row fetched after bootstrap.
                $encrypted = $client['password_enc'] ?? '';
                if (!is_string($encrypted) || $encrypted === ''
                    || !hash_equals($authenticatedIdentity['client_id'], $client['client_id'])
                    || !hash_equals($authenticatedIdentity['credential_fingerprint'], hash('sha256', $encrypted))) {
                    throw new \InvalidArgumentException('The desktop identity or credential changed during this request. Authenticate again.');
                }
                $current = hash('sha256', $client['client_id'] . "\0" . $username);
                break;
            }
        }
        if (!is_string($expected) || !is_string($current) || !hash_equals($expected, $current)) {
            throw new \InvalidArgumentException('This desktop identity is not authorized for this incident.');
        }
    }
    public function getIncidentForDesktop(string $id, string $authenticatedDesktop, ?array $authenticatedIdentity = null): array
    {
        return $this->incidentResult(function () use ($id, $authenticatedDesktop, $authenticatedIdentity): array {
            $this->authorizeIncidentDesktopIdentity($id, $authenticatedDesktop, $authenticatedIdentity);
            return $this->incidentService()->forDesktop($id, $authenticatedDesktop);
        });
    }
    public function startIncident(array $input, array $actor = []): array { return $this->incidentResult(fn(): array => $this->incidentService()->start($input, $this->incidentActor($actor))); }
    public function sendIncidentUpdate(string $id, array $input, array $actor = []): array { return $this->incidentResult(fn(): array => $this->incidentService()->update($id, $input, $this->incidentActor($actor))); }
    public function respondToIncident(string $id, string $authenticatedDesktop, array $input, ?array $authenticatedIdentity = null): array
    {
        return $this->incidentResult(function () use ($id, $authenticatedDesktop, $input, $authenticatedIdentity): array {
            $this->authorizeIncidentDesktopIdentity($id, $authenticatedDesktop, $authenticatedIdentity);
            return $this->incidentService()->respond($id, $input,
                ['identity' => $authenticatedDesktop, 'source' => 'desktop'], $authenticatedDesktop);
        });
    }
    public function recordIncidentRollCall(string $id, array $input, array $actor = []): array { return $this->incidentResult(fn(): array => $this->incidentService()->respond($id, $input, $this->incidentActor($actor))); }
    public function recordIncidentChecklist(string $id, array $input, array $actor = []): array { return $this->incidentResult(fn(): array => $this->incidentService()->checklist($id, $input, $this->incidentActor($actor))); }
    public function processIncidentWorkflows(): array
    {
        if (PHP_SAPI !== 'cli') { return ['success' => false, 'message' => 'Incident policy processing is reserved for the local worker.']; }
        return $this->incidentResult(function (): array {
            $result = $this->incidentService()->process();
            $settings = $this->getActiveSettings();
            $policy = IncidentConfig::normalize($settings['incident_workflows'] ?? [])['retention'] ?? [];
            try {
                $result['archival'] = $this->incidentStore()->retire($policy, time());
                foreach ($result['archival']['errors'] as $error) { $result['errors'][] = $error; }
            } catch (\Throwable $error) { $result['errors'][] = ['message' => 'Incident archival could not complete: ' . $error->getMessage()]; }
            $result['success'] = !$result['errors']; return $result;
        });
    }
    public function exportIncidentReport(string $id, int $jobOffset = 0, string $revision = ''): array
    {
        return $this->incidentResult(function () use ($id, $jobOffset, $revision): array {
            $report = $this->incidentService()->report($id, $jobOffset, $revision);
            $report['exported_at'] = gmdate('c');
            $report['receipt_meaning'] = 'Received by desktop app confirms software receipt. Human responses and operator roll-call records are separate. Phone channel activity does not prove playback. Email acceptance confirms only the local PBX mail service accepted the message, not inbox delivery or a human response.';
            return $report;
        });
    }
    public function renderIncidentsPage(array $params = []): string
    {
        $settings = $this->getPendingSettings() ?? $this->getActiveSettings();
        $choices = ['extensions' => [], 'desktop_clients' => [], 'group_ids' => [], 'voice_recipient_ids' => [], 'webhook_ids' => [], 'email_recipient_ids' => [], 'sms_recipient_ids' => []];
        foreach ($this->getExtensionNameMap() as $id => $name) { $choices['extensions'][] = ['id' => (string)$id, 'name' => $id . ' · ' . $name]; }
        foreach ($this->getDesktopClients($settings) as $row) { if (!empty($row['enabled'])) { $choices['desktop_clients'][] = ['id' => $row['username'], 'name' => $row['name'] . ' · ' . $row['username']]; } }
        foreach ($this->getAnnouncementGroups() as $row) { $choices['group_ids'][] = ['id' => $row['id'], 'name' => $row['name']]; }
        foreach ($this->outboundVoiceTargets($settings) as $id => $row) { $choices['voice_recipient_ids'][] = ['id' => $id, 'name' => $row['name'] ?? $id]; }
        foreach ($this->normalizeWebhookDestinations($settings['announcement_webhooks'] ?? [], 'announcement') as $row) { if (!empty($row['enabled'])) { $choices['webhook_ids'][] = ['id' => $row['id'], 'name' => $row['name']]; } }
        $emailNames = array_column((array)($settings['announcement_email']['recipients'] ?? []), 'name', 'id');
        foreach ($this->announcementEmailTargets($settings) as $id => $row) { $choices['email_recipient_ids'][] = ['id' => $id, 'name' => $emailNames[$id] ?? $id]; }
        foreach (\SLS\MassNotify\Sms\Service::targets($settings) as $id=>$row) { $choices['sms_recipient_ids'][]=['id'=>$id,'name'=>$row['name']]; }
        return load_view(__DIR__ . '/views/incidents.php', ['templates' => $this->incidentTemplates(true), 'active_templates' => $this->incidentTemplates(),
            'choices' => $choices + ['locations'=>array_map(static fn(array $row): array => ['id'=>$row['id'], 'name'=>\SLS\MassNotify\LocationDirectory::path(\SLS\MassNotify\LocationDirectory::effective($settings['location_directory'] ?? []), $row['id'])], \SLS\MassNotify\LocationDirectory::effective($settings['location_directory'] ?? [])['nodes'])], 'tones' => $this->getAvailableTones(), 'listing' => $this->listIncidents(50, is_string($params['cursor'] ?? null) ? $params['cursor'] : '', ($params['archived'] ?? false) === true),
            'retention' => IncidentConfig::retention($settings['incident_workflows']['retention'] ?? []), 'show_archived' => ($params['archived'] ?? false) === true,
            'incident' => !empty($params['incident_id']) ? $this->getIncident((string)$params['incident_id']) : null,
            'save_result' => $params['save_result'] ?? null, 'csrf_token' => $this->getCsrfToken(), 'hero_image' => self::HERO_IMAGE]);
    }
}
