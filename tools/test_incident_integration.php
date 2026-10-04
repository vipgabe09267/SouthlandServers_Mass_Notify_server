<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
function incident_integration_check($value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
$directory = sys_get_temp_dir() . '/sls-incident-integration-' . bin2hex(random_bytes(8)); mkdir($directory, 0700);
register_shutdown_function(static function () use ($directory): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($directory);
});
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$methods = '';
foreach (['sendSipNotifyAnnouncement', 'resolveAnnouncementRequest', 'buildAnnouncementVisualPushCommand', 'normalizeAnnouncementAudioMode', 'webhookPayloadFormat', 'webhookDestinationFingerprint', 'announcementTone'] as $name) {
    $method = $reflection->getMethod($name); $source = file($method->getFileName());
    $methods .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
// Execute the actual source orchestration + durable job worker. Only host
// inventory, worker launch and final phone/provider adapters are inert fixtures.
$fixture = <<<'PHP'
class IncidentIntegratedFixture
{
    use \FreePBX\modules\SlsAnnouncementDelivery;
    use \FreePBX\modules\SlsEnterpriseOperations;
    use \FreePBX\modules\SlsIncidents;
    use \FreePBX\modules\SlsOutboundVoice;
    use \FreePBX\modules\SlsAnnouncementEmail; use \FreePBX\modules\SlsAnnouncementSms;
    const INCIDENT_JOB_CONTRACT = 1;
    const MAX_WEBHOOK_DESTINATIONS = 10;
    public array $validModels = [];
    public function getAvailablePiperVoices() { return array_map(fn($path)=>['path'=>$path], array_keys($this->validModels)); }
    private function isValidPiperVoiceFile($path) { return isset($this->validModels[$path]) && is_file($path) && hash_file('sha256', $path) === $this->validModels[$path]; }
    const VISUAL_PUSH_SCRIPT = '/usr/bin/true';
    public array $settings = [];
    public array $commands = [];
    public array $workers = [];
    public array $webhookContexts = [];
    private $announcementAdmissionLock;
    private function recordInteractiveAnnouncementAdmission(array $request): void {}
    private function getActiveSettings() { return $this->settings; }
    private function getPendingSettings() { return null; }
    private function isSetupComplete($settings) { return true; }
    private function ensurePluginDataDir() {}
    private function setOwnership($path) {}
    private function getAnnouncementCooldownState() { return ['remaining' => 0]; }
    private function setAnnouncementCooldown() {}
    private function getSipNotifyTargets() { return [['extension' => '1000']]; }
    public function getConfiguredPjsipExtensionNumbers() { return ['1000']; }
    private function getDesktopClients($settings) { return $settings['desktop_clients'] ?? []; }
    private function normalizeDesktopUsername($value) { return strtolower($value); }
    private function normalizeDesktopClientId($value) { return strtolower($value); }
    private function normalizeWebhookDestinations($rows, $kind) { return $rows; }
    public function getAnnouncementGroups() { return $this->settings['announcement_groups'] ?? []; }
    private function getAvailableTones() { return []; }
    private function normalizeToneName($value) { return $value; }
    private function normalizeHexColor($value, $fallback) { return $value; }
    private function normalizeAnnouncementTimeoutMode($value) { return $value; }
    private function normalizeAnnouncementTimeoutSeconds($value) { return $value; }
    private function sanitizeScheduleText($value, $limit, $single) { return substr($value, 0, $limit); }
    private function startAnnouncementWorker($id) { $this->workers[] = $id; return true; }
    private function acquireAnnouncementActivityLock($exclusive = false, $timeoutSeconds = 30) { return null; }
    private function releaseNativeBackupFileLock($lock) {}
    private function appendAnnouncementNotifyLog(...$args) {}
    private function dispatchAnnouncementWebhooks(...$args) {
        $this->webhookContexts[] = $args[9] ?? null;
        return ['accepted'=>[], 'failed'=>[]];
    }
    private function executeAnnouncementVisualPushCommand($command) {
        $this->commands[] = $command;
        return ['success' => true, 'output' => ['SLS_DESKTOP_PUBLICATION ' . json_encode(['event_id' => 'announcement-20260922123456000000-' . str_repeat('a', 32)])]];
    }
    protected function readAnnouncementPhoneOutcomes($correlation) { return ['available' => false, 'targets' => []]; }
    private function getDeviceOverrideInventory() { return ['devices' => []]; }
}
PHP;
$fixture = substr($fixture, 0, strrpos($fixture, '}'));
eval($fixture . ' const PIPER_VOICE_DIR = ' . var_export($directory.'/voices', true)
    . '; const PIPER_VOICE = ' . var_export($directory.'/voices/en_US-lessac-low.onnx', true)
    . '; const PLUGIN_DATA_DIR = ' . var_export($directory, true)
    . '; const ANNOUNCEMENT_LOCK_FILE = ' . var_export($directory . '/announcement.lock', true)
    . '; const RUNTIME_DIR = ' . var_export($directory, true) . ';' . $methods . '}');
$template = \SLS\MassNotify\IncidentConfig::template(['id' => 'tpl_' . str_repeat('b', 24), 'name' => 'Fixture', 'title' => 'Fixture incident', 'message' => 'Fixture message',
    'delivery' => ['desktop_clients' => ['alice']], 'roster' => [['id' => 'alice_person', 'name' => 'Alice', 'location' => 'Office', 'desktop_username' => 'alice']]]);
$module = new IncidentIntegratedFixture(); $module->settings = ['desktop_clients' => [['enabled' => '1', 'username' => 'alice', 'client_id' => 'cli_alice']],
    'announcement_webhooks' => [], 'announcement_groups' => [], 'incident_workflows' => ['schema' => 1, 'templates' => [$template]], 'announcement_timeout_mode' => 'custom', 'announcement_timeout_seconds' => 45];
$context = ['schema' => 1, 'incident_id' => 'inc_' . str_repeat('c', 32), 'sequence' => 1, 'kind' => 'all_clear', 'severity' => 'critical', 'is_test' => false];
$ordinary = $module->sendSipNotifyAnnouncement([], 'Ordinary all-clear wording is not incident metadata', true, false, [], ['desktop_clients' => ['alice'], '_force_queue' => true, '_incident_context' => $context]);
incident_integration_check(!empty($ordinary['success']) && count($module->workers) === 0 && count($module->commands) === 1, 'Generic caller forged forced queue authority.');
incident_integration_check(strpos($module->commands[0], '--incident-json') === false, 'Generic caller forged incident publication context.');
incident_integration_check($module->webhookContexts === [null], 'Ordinary announcement forged webhook incident context.');
$input = ['template_id' => $template['id'], 'request_id' => str_repeat('1', 32), 'fields' => [], 'is_test' => true];
$incident = $module->startIncident($input);
incident_integration_check(!empty($incident['success']), 'Real incident facade rejected its integrated source path: ' . ($incident['message'] ?? 'unknown'));
$operation = $incident['operations'][$input['request_id']]; $jobId = $operation['job_id'];
incident_integration_check(preg_match('/^job_[a-f0-9]{32}$/D', $jobId) && count($module->workers) === 1 && count($module->commands) === 1, 'CLI incident did not use a durable async job.');
$store = new SlsAnnouncementJobStore($directory . '/announcement-jobs'); $job = $store->read($jobId);
incident_integration_check($job['request']['force_queue'] === true && $job['request']['incident_context']['incident_id'] === $incident['id']
    && $job['request']['incident_context']['is_test'] === true && $job['request']['is_test'] === true, 'Queued request lost trusted typed context.');
incident_integration_check($job['request']['message'] === 'DRILL / TEST. Fixture message' && $job['request']['title'] === 'DRILL: Fixture incident'
    && $job['request']['display_timeout'] === 45 && $job['request']['desktops'] === ['alice'], 'Workflow altered expiry/recipients or lost drill labels.');
$module->processAnnouncementJobs($jobId); $job = $store->read($jobId);
incident_integration_check($job['state'] === 'complete' && count($module->commands) === 2, 'Real job worker did not complete isolated desktop publication.');
incident_integration_check($module->webhookContexts[1] === $job['request']['incident_context'], 'Durable worker lost exact webhook incident context.');
$command = $module->commands[1];
incident_integration_check(strpos($command, ' --is-test') !== false && strpos($command, ' --api-only') !== false && strpos($command, ' --desktop-all') === false, 'Incident worker changed test or recipient scope.');
preg_match("/--incident-json='([^']+)'/", $command, $matches);
incident_integration_check(isset($matches[1]) && json_decode($matches[1], true) === $job['request']['incident_context'], 'Worker/publisher command lost exact incident context.');
$module->processAnnouncementJobs($jobId); $module->startIncident($input);
incident_integration_check(count($module->commands) === 2 && count($module->workers) === 1, 'Completed job or duplicate incident was replayed.');
$module->settings['desktop_clients'][0]['password_enc'] = 'fixture-encrypted-credential';
$authenticatedIdentity = ['client_id' => $module->settings['desktop_clients'][0]['client_id'],
    'credential_fingerprint' => hash('sha256', $module->settings['desktop_clients'][0]['password_enc'])];
$view = $module->getIncidentForDesktop($incident['id'], 'alice', $authenticatedIdentity);
incident_integration_check($view['success'] && !isset($view['template'], $view['timeline']) && $view['person']['id'] === 'alice_person', 'Integrated desktop projection exposes full administrative history.');
incident_integration_check(!$module->getIncidentForDesktop($incident['id'], 'mallory', $authenticatedIdentity)['success'], 'Unrelated desktop can read integrated incident.');
$human = $module->respondToIncident($incident['id'], 'alice', ['request_id' => str_repeat('2', 32), 'response' => 'safe'], $authenticatedIdentity);
incident_integration_check($human['success'] && $human['response']['source'] === 'human_desktop_response', 'Integrated self-response failed.');
$originalClientId = $module->settings['desktop_clients'][0]['client_id'];
$module->settings['desktop_clients'][0]['client_id'] = 'cli_reassigned_fixture';
incident_integration_check(!$module->getIncidentForDesktop($incident['id'], 'alice', $authenticatedIdentity)['success'], 'Reassigned desktop username inherited prior incident history.');
incident_integration_check(!$module->respondToIncident($incident['id'], 'alice', ['request_id' => str_repeat('9', 32), 'response' => 'needs_assistance'], $authenticatedIdentity)['success'], 'Reassigned desktop username changed a prior person response.');
$module->settings['desktop_clients'][0]['client_id'] = $originalClientId;
incident_integration_check($module->getIncidentForDesktop($incident['id'], 'alice', $authenticatedIdentity)['response']['response'] === 'safe', 'Rejected identity mutation changed the stored human response.');
// Interleave authentication of a formerly assigned row with a current row that
// matches the incident: old Basic credentials must not inherit the new identity.
$formerIdentity = $authenticatedIdentity; $formerIdentity['client_id'] = 'cli_former_authenticated';
incident_integration_check(!$module->getIncidentForDesktop($incident['id'], 'alice', $formerIdentity)['success'], 'In-flight former identity adopted reassigned current row.');
incident_integration_check(!$module->respondToIncident($incident['id'], 'alice', ['request_id' => str_repeat('8', 32), 'response' => 'needs_assistance'], $formerIdentity)['success'], 'In-flight former identity changed reassigned person response.');
$module->settings['desktop_clients'][0]['password_enc'] = 'fixture-rotated-credential';
incident_integration_check(!$module->getIncidentForDesktop($incident['id'], 'alice', $authenticatedIdentity)['success'], 'Rotated Basic credential retained in-flight incident access.');
incident_integration_check(!$module->respondToIncident($incident['id'], 'alice', ['request_id' => str_repeat('7', 32), 'response' => 'needs_assistance'], $authenticatedIdentity)['success'], 'Rotated credential submitted an in-flight response.');
$authenticatedIdentity['credential_fingerprint'] = hash('sha256', $module->settings['desktop_clients'][0]['password_enc']);
incident_integration_check($module->getIncidentForDesktop($incident['id'], 'alice', $authenticatedIdentity)['response']['response'] === 'safe', 'Fresh authentication after password rotation lost same-identity history.');
incident_integration_check(!$module->getIncidentForDesktop($incident['id'], 'alice')['success'], 'Unauthenticated username-only facade access remained allowed.');

// Named API-origin jobs and future policy launches retain revocable authorization.
$issued = \SLS\MassNotify\ApiSecurity::issue(['name' => 'Fixture sender', 'scopes' => ['read', 'send'], 'audience' => ['unrestricted' => true]]);
$credential = $issued['credential']; $credentialId = $credential['id'];
$module->settings['control_api'] = ['enabled' => '1', 'credentials' => [$credential]];
$GLOBALS['sls_control_principal'] = \SLS\MassNotify\ApiSecurity::currentCredential($module->settings, $credentialId);
$apiInput = array_replace($input, ['request_id' => str_repeat('3', 32), 'is_test' => false]);
$apiIncident = $module->startIncident($apiInput, ['identity' => 'untrusted display override', 'source' => 'other']);
incident_integration_check($apiIncident['created_by']['credential_id'] === $credentialId && $apiIncident['created_by']['source'] === 'control_api', 'API origin was not derived from authenticated principal.');
$apiJobId = $apiIncident['operations'][$apiInput['request_id']]['job_id']; $apiJob = $store->read($apiJobId);
incident_integration_check(($apiJob['request']['api_credential_id'] ?? '') === $credentialId && strpos($apiJob['request']['sender'], $credentialId) !== false, 'API incident job lost durable credential attribution.');
unset($GLOBALS['sls_control_principal']);
$module->settings['control_api']['credentials'][0]['revoked_at'] = gmdate('c'); $before = count($module->commands);
$module->processAnnouncementJobs($apiJobId);
incident_integration_check(count($module->commands) === $before && $store->read($apiJobId)['state'] === 'failed', 'Revoked API credential still published queued incident.');
// Schedule with named key, then revoke it before the isolated minute callback.
$module->settings['control_api']['credentials'][0]['revoked_at'] = '';
$GLOBALS['sls_control_principal'] = \SLS\MassNotify\ApiSecurity::currentCredential($module->settings, $credentialId);
$plannedInput = array_replace($input, ['request_id' => str_repeat('4', 32), 'planned_at' => gmdate('c', time() + 60)]);
$planned = $module->startIncident($plannedInput); unset($GLOBALS['sls_control_principal']);
$incidentStore = new \SLS\MassNotify\IncidentStore($directory . '/incidents');
$incidentStore->transaction($planned['id'], static function (array $r): array { $r['planned_at'] = gmdate('c', time() - 1); return $r; });
$module->settings['control_api']['enabled'] = '0'; $before = count($module->workers); $module->processIncidentWorkflows();
$plannedRecord = $incidentStore->read($planned['id']); $attempt = array_values($plannedRecord['operations'])[0];
incident_integration_check($attempt['actor']['credential_id'] === $credentialId && $attempt['state'] === 'not_submitted' && count($module->workers) === $before,
    'Disabling Control API did not prevent a previously planned incident launch.');
incident_integration_check(!isset($GLOBALS['sls_control_principal']), 'Cron policy leaked API principal globally.');
// Run native-language selection through the real incident facade and job
// snapshot. Only installed-model bytes are disposable checksum fixtures.
mkdir($directory.'/voices', 0700);
$native = new IncidentIntegratedFixture(); $native->settings = $module->settings;
$native->settings['announcement_piper_voice'] = IncidentIntegratedFixture::PIPER_VOICE;
$native->settings['outbound_voice']['piper_voice'] = $directory.'/voices/en_US-lessac-medium.onnx';
$models = ['en'=>'en_US-lessac-low.onnx', 'es'=>'es_ES-davefx-medium.onnx', 'fr'=>'fr_FR-siwis-medium.onnx',
    'de'=>'de_DE-thorsten-low.onnx', 'pt'=>'pt_BR-faber-medium.onnx'];
foreach (array_merge(array_values($models), ['en_US-lessac-medium.onnx', 'en_US-ryan-low.onnx']) as $model) {
    foreach ([$model, $model.'.json'] as $file) {
        $path=$directory.'/voices/'.$file; file_put_contents($path, 'Immutable fixture '.$file);
        $native->validModels[$path]=hash_file('sha256', $path);
    }
}
$spoken = $template; $spoken['delivery']['extensions']=['1000']; $spoken['delivery']['audio_mode']='tts';
$spoken['language_variants']=[['locale'=>'es-mx', 'label'=>'Reviewed Spanish', 'title'=>'Aviso de prueba',
    'message'=>'Salga por la puerta indicada.', 'review_note'=>'Fixture reviewer revision 1', 'reviewed'=>true]];
$spoken['escalation']=['enabled'=>true,'steps'=>[['name'=>'Supervisor', 'after_seconds'=>60, 'delivery'=>$spoken['delivery']]]];
$native->settings['incident_workflows']['templates']=[$spoken];
$voiceInput=array_replace($input,['request_id'=>str_repeat('5',32),'language_variant'=>'es-mx']);
$spanish=$native->startIncident($voiceInput);
incident_integration_check(!empty($spanish['success']), 'Reviewed Spanish speech failed before the isolated job: '.($spanish['message']??''));
$spanishPath=$directory.'/voices/'.$models['es'];
$initial=$store->read($spanish['operations'][$voiceInput['request_id']]['job_id']);
incident_integration_check($initial['request']['voice']===$spanishPath && $initial['request']['external_voice']===$spanishPath
    && $initial['request']['message']==='DRILL / TEST. Salga por la puerta indicada.', 'Initial incident used another language, external English override or altered reviewed words.');
incident_integration_check($spanish['template']['escalation']['steps'][0]['delivery']['_speech']['path']===$spanishPath,
    'Later speech step did not freeze its selected native model.');
$native->settings['announcement_piper_voice']=$directory.'/voices/en_US-ryan-low.onnx';
$native->settings['outbound_voice']['piper_voice']=$directory.'/voices/en_US-lessac-medium.onnx';
$updated=$native->sendIncidentUpdate($spanish['id'],['request_id'=>str_repeat('6',32),'kind'=>'update','message'=>'Use la salida este.']);
$updateJob=$store->read($updated['operations'][str_repeat('6',32)]['job_id']);
incident_integration_check($updateJob['request']['voice']===$spanishPath && $updateJob['request']['external_voice']===$spanishPath,
    'Changing ordinary settings changed the frozen incident language or external model.');
$incidentStore->transaction($spanish['id'],static function(array $r):array{$r['opened_at']=gmdate('c',time()-61);return $r;});
$native->processIncidentWorkflows(); $after=$incidentStore->read($spanish['id']);
$follow=array_values(array_filter($after['operations'],static fn($o)=>$o['kind']==='escalation'));
incident_integration_check(count($follow)===1 && str_contains($follow[0]['message'],'sin respuesta'), 'Supervisor follow-up ignored its frozen language.');
$followJob=$store->read($follow[0]['job_id']);
incident_integration_check($followJob['request']['voice']===$spanishPath && $followJob['request']['external_voice']===$spanishPath,
    'Delayed escalation reverted to current English voice settings.');
$snapshot=new ReflectionMethod($native,'incidentSpeechSnapshot');
$validate=new ReflectionMethod($native,'validateIncidentSpeechSnapshot');
foreach ($models as $language=>$model) {
    $chosen=$snapshot->invoke($native,$language.'-xx',$native->settings);
    incident_integration_check(str_starts_with(basename($chosen['path']),$language.'_') && str_starts_with(basename($chosen['external_path']),$language.'_'),
        'A supported regional variant selected another primary language.');
}
$default=$snapshot->invoke($native,'',$native->settings);
incident_integration_check($default['path']===$native->settings['announcement_piper_voice']
    && $default['external_path']===$native->settings['outbound_voice']['piper_voice'], 'Default incident lost the configured external-quality voice.');
foreach ([$default+['extra'=>true],array_replace($default,['path'=>'/tmp/en_US-ryan-low.onnx']),array_replace($default,['locale'=>'es'])] as $bad) {
    try {$validate->invoke($native,$bad);throw new RuntimeException('Malformed, untrusted or mismatched incident model accepted.');}
    catch (InvalidArgumentException $error) {incident_integration_check(str_contains($error->getMessage(),'No channels'), 'Voice refusal concealed pre-send status.');}
}
$plannedNative=$native->startIncident(array_replace($voiceInput,['request_id'=>str_repeat('7',32),'planned_at'=>gmdate('c',time()+60)]));
$native->settings['announcement_piper_voice']=IncidentIntegratedFixture::PIPER_VOICE;
$incidentStore->transaction($plannedNative['id'],static function(array $r):array{$r['planned_at']=gmdate('c',time()-1);return $r;});
$native->processIncidentWorkflows(); $plannedRecord=$incidentStore->read($plannedNative['id']);
$scheduledJob=$store->read(array_values($plannedRecord['operations'])[0]['job_id']);
incident_integration_check($scheduledJob['request']['voice']===$spanishPath && $scheduledJob['request']['external_voice']===$spanishPath,
    'Planned drill did not retain the voice chosen with its reviewed text.');
file_put_contents($spanishPath.'.json','Corrupt model configuration'); $before=count($native->workers);
$blocked=$native->startIncident(array_replace($voiceInput,['request_id'=>str_repeat('8',32)]));
incident_integration_check(empty($blocked['success']) && count($native->workers)===$before && str_contains($blocked['message'],'checksum'),
    'An invalid native model configuration sent initial channels.');
$revoked=$native->sendIncidentUpdate($spanish['id'],['request_id'=>str_repeat('9',32),'kind'=>'update','message'=>'Mensaje de prueba.']);
incident_integration_check($revoked['operations'][str_repeat('9',32)]['state']==='not_submitted' && count($native->workers)===$before,
    'An existing incident delivered after its frozen model failed verification.');
echo "Integrated incidents: immutable jobs, native language/internal-external voice isolation, planned drills, escalation, model revocation, receipts and credential revocation passed.\n";
