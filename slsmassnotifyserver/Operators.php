<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__ . '/OperatorAccess.php';
require_once __DIR__ . '/OperatorRecoveryService.php';
use SLS\MassNotify\OperatorAccess;
use SLS\MassNotify\ApiSecurity;
use SLS\MassNotify\IncidentConfig;
use SLS\MassNotify\LocationDirectory;

/** FreePBX sessions authenticate people; .config grants their SLS authority. */
trait SlsOperators
{
    use SlsOperatorRecovery;
    private ?string $operatorScheduleOrigin = null;

    /** User Management grants full PBX access separately from its module list. */
    protected function isCurrentPbxAdministrator(array $identity): bool
    {
        if (($identity['mode'] ?? '') !== 'usermanager') { return in_array('*', $identity['sections'] ?? [], true); }
        if (empty($identity['id'])) { return false; }
        try {
            $directory = $this->FreePBX->Userman;
            return (bool)$directory->getCombinedGlobalSettingByID($identity['id'], 'pbx_login')
                && (in_array('*', $identity['sections'] ?? [], true)
                    || (bool)$directory->getCombinedGlobalSettingByID($identity['id'], 'pbx_admin'));
        } catch (\Throwable $error) { return false; }
    }

    public function currentOperator(): array
    {
        $settings = $this->getActiveSettings(); $user = $_SESSION['AMP_user'] ?? null;
        if (isset($GLOBALS['sls_operator_portal_principal'])) {
            $verified=$GLOBALS['sls_operator_portal_principal'];
            $principal=OperatorAccess::principal($settings,$verified['id']??'');
            if (!$principal || !OperatorAccess::localIdentityCurrent($principal) || !hash_equals($principal['identity'],$verified['identity']??'')) { throw new \DomainException('Your operator login or permissions changed. Sign in again.'); }
            return $principal;
        }
        if (!is_object($user) || !is_string($user->username ?? null) || !method_exists($user, 'getAmpUser')) {
            throw new \DomainException('Sign in to FreePBX with an authorized operator account.');
        }
        $identity = $user->getAmpUser($user->username);
        if (!is_array($identity)) { throw new \DomainException('The PBX login no longer exists. Sign in again.'); }
        if ($this->isCurrentPbxAdministrator($identity)) {
            return ['id'=>'recovery_admin', 'username'=>$user->username, 'name'=>$user->username,
                'operator_role'=>'administrator', 'audience'=>['unrestricted'=>true], 'site_ids'=>[], 'group_ids'=>[]];
        }
        $principal = OperatorAccess::byUsername($settings, $user->username);
        if (!$principal || !OperatorAccess::localIdentityCurrent($principal)
            || ($identity['mode'] ?? '') !== $principal['source']
            || !hash_equals($principal['identity'], OperatorAccess::identity($identity))) {
            throw new \DomainException('This PBX account is not currently authorized for SLS Operations. Ask an SLS administrator to review its role and login identity.');
        }
        return $principal;
    }

    public function operatorPageAllowed(string $page): bool
    {
        if (!in_array($page, ['slsmassnotifyserver_operations', 'slsmassnotifyserver_operators'], true)
            && empty($this->getActiveSettings()['operator_access']['enabled'])) { return true; }
        try {
            $principal = $this->currentOperator();
            return $principal['operator_role'] === 'administrator'
                || in_array($page, ['slsmassnotifyserver_operations', 'slsmassnotifyserver_help'], true);
        } catch (\Throwable $error) { return false; }
    }

    public function enforceOperatorPageAccess(string $page): void
    {
        if ($this->operatorPageAllowed($page)) { return; }
        while (ob_get_level() > 0) { @ob_end_clean(); }
        http_response_code(403); header('Cache-Control: private, no-store');
        $message = 'Your current SLS role does not allow this page. Use Operations for your assigned sites and audiences.';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false, 'message'=>$message]);
        } else {
            echo '<div class="alert alert-warning">'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
                .' <a href="config.php?display=slsmassnotifyserver_operations">Open Operations</a></div>';
        }
        exit;
    }

    /** Refreshes existing PBX directories; never creates accounts or grants roles. */
    public function operatorDirectory(): array
    {
        $principal = $this->currentOperator();
        if ($principal['operator_role'] !== 'administrator') { throw new \DomainException('Only an SLS administrator can review the PBX account directory.'); }
        $candidates = []; $errors = [];
        try {
            $statement = $this->FreePBX->Database()->prepare('SELECT username FROM ampusers ORDER BY username LIMIT 501');
            $statement->execute();
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) { $candidates[] = ['username'=>$row['username'], 'source'=>'database']; }
        } catch (\Throwable $error) { $errors[] = 'Local PBX administrator accounts could not be read. Existing assignments were preserved.'; }
        try {
            foreach ($this->FreePBX->Userman->getAllUsers() as $row) {
                if (count($candidates) >= 500) { $errors[] = 'The directory preview is limited to 500 accounts.'; break; }
                if (!$this->FreePBX->Userman->getCombinedGlobalSettingByID($row['id'], 'pbx_login')) {
                    continue;
                }
                $candidates[] = ['username'=>$row['username'], 'source'=>'usermanager'];
            }
        } catch (\Throwable $error) { $errors[] = 'User Management is unavailable. Local PBX accounts remain usable.'; }
        $rows = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate['username']) || !preg_match('/^[A-Za-z0-9_.@-]{1,80}$/D', $candidate['username'])) { continue; }
            try {
                $account = (new \ampuser($candidate['username'], $candidate['source']))->getAmpUser($candidate['username']);
                if (!is_array($account) || ($account['mode'] ?? '') !== $candidate['source']) { continue; }
                if (count($rows) >= 500) { $errors[] = 'The directory preview is limited to 500 accounts.'; break; }
                if ($candidate['source'] === 'usermanager' && !$this->FreePBX->Userman->getCombinedGlobalSettingByID($account['id'], 'pbx_login')) { continue; }
                $administrator = $this->isCurrentPbxAdministrator($account);
                $rows[] = $candidate + ['identity'=>OperatorAccess::identity($account), 'administrator'=>$administrator, 'eligible'=>$administrator
                    || in_array('slsmassnotifyserver_operations', $account['sections'] ?? [], true)];
            } catch (\Throwable $error) { continue; }
        }
        return ['accounts'=>$rows, 'warnings'=>$errors];
    }

    private function operatorRevision(array $settings): string
    {
        $access=OperatorAccess::normalize($settings['operator_access']??[]);
        // Routine sign-ins must not invalidate an administrator's open editor.
        // Save merges the latest counters/codes under the configuration lock.
        foreach ($access['accounts']??[] as $index=>$account) {
            unset($access['accounts'][$index]['auth']['totp_last_counter'],$access['accounts'][$index]['auth']['recovery_hashes'],
                $access['accounts'][$index]['auth']['password_recovery']);
        }
        return hash('sha256', json_encode([$access, $settings['location_directory'] ?? [],
            $settings['announcement_groups'] ?? [], $settings['desktop_clients']??[], $settings['outbound_voice']['recipients']??[],
            $settings['announcement_email']['recipients']??[], $settings['announcement_sms']['recipients']??[], $settings['announcement_webhooks']??[],
            array_column($settings['control_api']['credentials'] ?? [], 'id')], JSON_THROW_ON_ERROR));
    }

    public function operatorAccessState(): array
    {
        $directory = $this->operatorDirectory(); $settings = $this->getActiveSettings();
        $config = OperatorAccess::normalize($settings['operator_access'] ?? []);
        foreach ($config['accounts'] as &$row) {
            $row['identity_current'] = $row['source']==='portal' || OperatorAccess::localIdentityCurrent($row);
            $row=\SLS\MassNotify\OperatorAuth::publicAccount($row);
        } unset($row);
        foreach ($directory['accounts'] as &$row) { unset($row['identity']); } unset($row);
        return ['access'=>$config, 'directory'=>$directory, 'revision'=>$this->operatorRevision($settings),
            'security_activity'=>\SLS\MassNotify\SecurityAudit::recent(dirname(self::SETTINGS_JSON).'/security-audit.jsonl',($settings['control_api']['audit_syslog']??'0')==='1'),
            'sites'=>array_values(array_filter(LocationDirectory::effective($settings['location_directory'] ?? [])['nodes'], static fn(array $row): bool => $row['type'] === 'site')),
            'locations'=>array_values(array_filter(LocationDirectory::effective($settings['location_directory'] ?? [])['nodes'], static fn(array $row): bool => $row['type'] !== 'site')),
            'devices'=>array_map(static fn(array $devices): array => array_values($devices), $this->locationCatalog($settings)),
            'audiences'=>array_map(static fn(array $row): array => ['id'=>$row['id'], 'name'=>$row['name']], $settings['announcement_groups'] ?? [])];
    }

    /** Never exposes hashes or authenticator secrets to the account editor. */
    public function portalAccount(string $username, string $source = 'portal'): ?array
    {
        $config=OperatorAccess::normalize($this->getActiveSettings()['operator_access']??[]);
        if ($source==='portal' && !$config['portal_enabled']) { return null; }
        foreach ($config['accounts'] as $account) {
            if (strcasecmp($account['username'],$username)===0 && $account['source']===$source && $account['enabled']) { return $account; }
        }
        return null;
    }

    private function writeOperatorConfig(array $active, array $config): void
    {
        if (is_file(self::PENDING_SETTINGS_JSON) || is_link(self::PENDING_SETTINGS_JSON)) {
            $pending=$this->loadSettingsFile(self::PENDING_SETTINGS_JSON); $pending['operator_access']=$config;
            $this->writeSettingsFileUnlocked(self::PENDING_SETTINGS_JSON,$pending,false);
        }
        $active['operator_access']=$config; $this->writeSettingsFileUnlocked(self::SETTINGS_JSON,$active,true);
    }

    public function saveOperatorAccess(array $input): array
    {
        $lock=null;
        try {
            if (array_diff(array_keys($input),['revision','enabled','portal_enabled','accounts']) || !is_string($input['revision']??null)
                || !is_bool($input['enabled']??null) || !is_bool($input['portal_enabled']??false) || !is_array($input['accounts']??null) || !array_is_list($input['accounts'])
                || count($input['accounts'])>100) { throw new \DomainException('The operator form is invalid or exceeds 100 accounts. Reload Operator Access.'); }
            // Verify administrator authority before doing expensive password work.
            $directory=$this->operatorDirectory(); $available=[];
            foreach ($directory['accounts'] as $row) { $available[$row['source'].':'.$row['username']]=$row; }
            $hashes=[];
            foreach ($input['accounts'] as $index=>$row) {
                if (!is_array($row) || array_diff(array_keys($row),['id','username','source','name','email','role','enabled','site_ids','location_ids','group_ids','personal_members','actions','channels','rebind','password','reset_totp'])) { throw new \DomainException('An operator row contains unsupported fields.'); }
                if (!is_string($row['password']??'') || !is_bool($row['reset_totp']??false)) { throw new \DomainException('An operator password or authenticator reset has an invalid type.'); }
                if (($row['password']??'')!=='') { $hashes[$index]=\SLS\MassNotify\OperatorAuth::password($row['password']); }
            }
            $lock=$this->acquireSettingsLock(true); $active=$this->loadSettingsFile(self::SETTINGS_JSON);
            if (!hash_equals($this->operatorRevision($active),$input['revision'])) { throw new \DomainException('Operator permissions or their directory changed. Reload before saving.'); }
            $previous=array_column(OperatorAccess::normalize($active['operator_access']??[])['accounts'],null,'id'); $rows=[];
            foreach ($input['accounts'] as $index=>$row) {
                $old=$previous[$row['id']??'']??null;
                if (($row['id']??'')!=='' && !$old) { throw new \DomainException('An operator was removed. Reload before saving.'); }
                if ($old && ($old['username']!==($row['username']??'') || $old['source']!==($row['source']??''))) { throw new \DomainException('An existing login cannot be moved to another person. Remove it and create a new login.'); }
                $row['id']=$old['id']??'api_'.bin2hex(random_bytes(12));
                if (($row['source']??'')==='portal') {
                    if (!$old && !isset($hashes[$index])) { throw new \DomainException('Enter an initial password for the new operator login.'); }
                    $auth=$old['auth']??null;
                    if (isset($hashes[$index])) {
                        $auth=$auth??['totp_secret_enc'=>'','totp_last_counter'=>-1,'recovery_hashes'=>[]];
                        $auth['password_hash']=$hashes[$index]; $auth['version']=bin2hex(random_bytes(32)); $auth['force_password_change']=true;
                        if (isset($auth['password_recovery'])) { $auth['password_recovery']['token']=null; }
                    }
                    if (!empty($row['reset_totp'])) {
                        if (!isset($hashes[$index])) { throw new \DomainException('Set a new initial password when resetting an authenticator. Existing sessions will be revoked.'); }
                        $auth['totp_secret_enc']=''; $auth['totp_last_counter']=-1; $auth['recovery_hashes']=[];
                    }
                    // A disable/re-enable cycle must never revive an old session.
                    if (($input['portal_enabled']??false)!==($active['operator_access']['portal_enabled']??false)) {
                        $auth['version']=bin2hex(random_bytes(32));
                    }
                    if ($old && (($row['enabled']??false)!==$old['enabled'] || ($row['email']??'')!==($old['email']??''))) {
                        $auth['version']=bin2hex(random_bytes(32));
                    }
                    if (isset($auth['password_recovery']) && $old && (($row['email']??'')!==($old['email']??'') || ($row['enabled']??false)!==$old['enabled'])) {
                        $auth['password_recovery']['token']=null;
                    }
                    $row['auth']=\SLS\MassNotify\OperatorAuth::normalize($auth);
                    $row['identity']=\SLS\MassNotify\OperatorAuth::identity($row);
                } else {
                    if (isset($hashes[$index]) || !empty($row['reset_totp'])) { throw new \DomainException('Manage legacy PBX passwords in FreePBX, or replace this assignment with a dedicated operator login.'); }
                    $account=$available[($row['source']??'').':'.($row['username']??'')]??null;
                    if (!is_bool($row['rebind']??false)) { throw new \DomainException('Identity review must be an explicit checkbox.'); }
                    if (!empty($row['enabled']) && (!$account || !$account['eligible'])) { throw new \DomainException('Grant this PBX account the Operator Portal page permission before enabling its assignment.'); }
                    $row['identity']=!empty($row['rebind'])?($account['identity']??''):($old['identity']??$account['identity']??'');
                    if (!empty($row['enabled']) && !hash_equals($row['identity'],$account['identity'])) { throw new \DomainException('This PBX login changed. Review and approve its current identity.'); }
                    if (isset($old['auth'])) { $row['auth']=$old['auth']; }
                }
                unset($row['password'],$row['reset_totp'],$row['rebind']); $rows[]=$row;
            }
            $config=OperatorAccess::normalize(['schema'=>1,'enabled'=>$input['enabled'],'portal_enabled'=>$input['portal_enabled']??false,'accounts'=>$rows]);
            if (array_intersect(array_column($rows,'id'),array_column($active['control_api']['credentials']??[],'id'))) { throw new \DomainException('An operator ID conflicts with a Control API key. Recreate that login.'); }
            $nodes=array_column(LocationDirectory::effective($active['location_directory']??[])['nodes'],null,'id'); $catalog=$this->locationCatalog($active);
            foreach ($config['accounts'] as $row) {
                foreach ($row['site_ids'] as $id) { if (($nodes[$id]['type']??'')!=='site') { throw new \DomainException('An assigned site no longer exists. Reload before saving.'); } }
                foreach ($row['location_ids'] as $id) { if (!isset($nodes[$id]) || $nodes[$id]['type']==='site') { throw new \DomainException('An assigned location no longer exists. Reload before saving.'); } }
                if (array_diff($row['group_ids'],array_column($active['announcement_groups']??[],'id'))) { throw new \DomainException('An assigned audience no longer exists. Reload before saving.'); }
                foreach ($row['personal_members'] as $key=>$ids) {
                    if (array_diff($ids,array_column($catalog[$key],'id'))) { throw new \DomainException('A personal device no longer exists. Reload its assignments.'); }
                }
            }
            $this->writeOperatorConfig($active,$config);
            return ['success'=>true,'message'=>'Operator logins and permissions saved. New logins require a personal password and authenticator enrollment.'];
        } catch (\Throwable $error) {
            return ['success'=>false,'message'=>$error instanceof \DomainException || $error instanceof SlsConfigurationWriteException?$error->getMessage():
                'Operator changes could not be confirmed. Reload the accounts and check protected configuration storage.'];
        } finally { if ($lock!==null) { $this->releaseSettingsLock($lock); } }
    }

    /** Atomic OTP/recovery consumption prevents concurrent reuse across PHP workers. */
    public function operatorAuthUpdate(string $id, string $identity, string $action, string $token, string $secret='', string $password=''): array
    {
        $hash=$action==='password'?\SLS\MassNotify\OperatorAuth::password($password):null;
        $lock=$this->acquireSettingsLock(true);
        try {
            $active=$this->loadSettingsFile(self::SETTINGS_JSON); $config=OperatorAccess::normalize($active['operator_access']??[]);
            if (!$config['portal_enabled']) { throw new \DomainException('The operator portal has not been enabled.'); }
            foreach ($config['accounts'] as &$account) {
                if ($account['id']!==$id || !$account['enabled'] || $account['source']!=='portal') { continue; }
                if (!hash_equals(\SLS\MassNotify\OperatorAuth::identity($account),$identity)) { throw new \DomainException('This login changed. Sign in again.'); }
                $auth=$account['auth']; $codes=[];
                if ($action==='password') {
                    if (!$auth['force_password_change']) { throw new \DomainException('This login does not require a password change.'); }
                    if (\SLS\MassNotify\OperatorAuth::verify($password,$auth['password_hash'])) { throw new \DomainException('Choose a personal password different from the initial password.'); }
                    $auth['password_hash']=$hash; $auth['version']=bin2hex(random_bytes(32)); $auth['force_password_change']=false;
                } elseif ($action==='enroll') {
                    if ($auth['totp_secret_enc']!=='' || $auth['force_password_change']) { throw new \DomainException('Restart authenticator enrollment after signing in.'); }
                    $counter=\SLS\MassNotify\OperatorAuth::counter($secret,$token,time());
                    if ($counter===null) { throw new \DomainException('The authenticator code is invalid. Check the phone time and enter its current six-digit code.'); }
                    $recovery=\SLS\MassNotify\OperatorAuth::recovery(); $codes=$recovery['codes'];
                    $auth['totp_secret_enc']=\SLS\MassNotify\OperatorAuth::seal($secret,$id,$active);
                    $auth['totp_last_counter']=$counter; $auth['recovery_hashes']=$recovery['hashes']; $auth['version']=bin2hex(random_bytes(32));
                } elseif ($action==='totp') {
                    if ($auth['totp_secret_enc']==='') { throw new \DomainException('This login requires authenticator enrollment.'); }
                    if (preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{8}){3}$/D',$token)) {
                        $at=array_search(hash('sha256',$token),$auth['recovery_hashes'],true);
                        if ($at===false) { throw new \DomainException('That recovery code is invalid or has already been used.'); }
                        array_splice($auth['recovery_hashes'],$at,1);
                    } else {
                        $counter=\SLS\MassNotify\OperatorAuth::counter(\SLS\MassNotify\OperatorAuth::open($auth['totp_secret_enc'],$id,$active),$token,time(),$auth['totp_last_counter']);
                        if ($counter===null) { throw new \DomainException('The code is invalid or already used. Wait for the next code and check the phone time.'); }
                        $auth['totp_last_counter']=$counter;
                    }
                } else { throw new \DomainException('Unsupported operator authentication action.'); }
                $account['auth']=\SLS\MassNotify\OperatorAuth::normalize($auth); $account['identity']=\SLS\MassNotify\OperatorAuth::identity($account);
                $result=$account; unset($account); $this->writeOperatorConfig($active,$config);
                return ['account'=>$result,'recovery_codes'=>$codes];
            } unset($account);
            throw new \DomainException('This operator login is disabled or no longer exists.');
        } finally { $this->releaseSettingsLock($lock); }
    }

    private function withOperator(array $principal, callable $work): array
    {
        $existed = array_key_exists('sls_control_principal', $GLOBALS); $old = $GLOBALS['sls_control_principal'] ?? null;
        if ($principal['id'] === 'recovery_admin') { unset($GLOBALS['sls_control_principal']); } else { $GLOBALS['sls_control_principal'] = $principal; }
        try { return $work(); }
        finally { if ($existed) { $GLOBALS['sls_control_principal'] = $old; } else { unset($GLOBALS['sls_control_principal']); } }
    }

    private function operatorDelivery(array $principal, $input): array
    {
        $recordings = [];
        if (is_array($input)) {
            foreach (['opening_tone', 'closing_tone'] as $key) {
                if (is_string($input[$key] ?? null) && str_starts_with($input[$key], 'system:')) {
                    $recordings[$key] = $input[$key];
                    $input[$key] = '';
                }
            }
        }
        $delivery = IncidentConfig::delivery($input);
        if (!OperatorAccess::delivery($principal, $delivery, $this->getActiveSettings()) || !IncidentConfig::hasAudience($delivery)) {
            throw new \DomainException('Select at least one currently authorized recipient. Saved groups must be reviewed as explicit recipients.');
        }
        // Import only after audience authorization; queued work keeps immutable
        // local tone names, never a mutable recording path supplied by a browser.
        $errors = [];
        foreach ($recordings as $key => $selection) {
            $delivery[$key] = $this->announcementTone($selection, str_replace('_', ' ', $key), $errors);
        }
        if ($errors) { throw new \DomainException(implode(' ', $errors)); }
        return $delivery;
    }

    private function operatorIncidentProjection(array $record, array $principal): ?array
    {
        $settings = $this->getActiveSettings(); $roster = []; $responses = []; $counts = ['expected'=>0, 'safe'=>0, 'received'=>0, 'needs_assistance'=>0, 'missing'=>0, 'no_response'=>0];
        foreach ($record['template']['roster'] as $person) {
            if (!OperatorAccess::person($principal, $person, $settings, $record['template']['delivery']['_identities'] ?? [])) { continue; }
            $roster[] = $person; $response = $record['responses'][$person['id']] ?? null;
            if ($response) { $responses[$person['id']] = $response; }
            $counts['expected']++; $counts[$response['response'] ?? 'no_response']++;
        }
        $delivery = $record['template']['delivery'];
        $whole = OperatorAccess::delivery($principal, $delivery, $settings);
        if (!empty($record['template']['escalation']['enabled'])) {
            foreach (IncidentConfig::escalationSteps($record['template']['escalation']) as $step) { $whole = $whole && OperatorAccess::delivery($principal, $step['delivery'], $settings); }
        }
        if (!$whole && !$roster) { return null; }
        return array_intersect_key($record, array_flip(['id', 'title', 'severity', 'state', 'is_test', 'created_at']))
            + ['roster'=>$roster, 'responses'=>$responses, 'response_counts'=>$counts, 'can_update'=>$whole && OperatorAccess::may($principal, 'send'),
                'submission_states'=>array_values(array_unique(array_column($record['operations'] ?? [], 'state')))];
    }

    public function operatorOperationsState(string $cursor = ''): array
    {
        $principal = $this->currentOperator(); $settings = $this->getActiveSettings(); $catalog = $this->locationCatalog($settings);
        $allowed = ApiSecurity::allowedAudience($principal, $settings);
        $mapping = ['extensions'=>'phones', 'desktop_client_ids'=>'desktops', 'voice_recipient_ids'=>'voice_recipient_ids', 'email_recipient_ids'=>'email_recipient_ids', 'sms_recipient_ids'=>'sms_recipient_ids', 'webhook_ids'=>'webhooks'];
        $choices = [];
        foreach ($mapping as $key=>$target) {
            $field = $key === 'desktop_client_ids' ? 'desktop_clients' : $key; $choices[$field] = [];
            foreach ($catalog[$key] as $row) {
                $id = $key === 'desktop_client_ids' ? ($row['username'] ?? '') : $row['id'];
                if ($row['available'] && (!empty($principal['audience']['unrestricted']) || in_array($id, $allowed[$target], true))) {
                    $choices[$field][] = ['id'=>$id, 'name'=>$row['label']];
                }
            }
        }
        $incidents = []; $listing = $this->incidentService()->listing(10, $cursor);
        foreach ($listing['incidents'] as $summary) {
            // The history index deliberately contains no roster or delivery
            // snapshot. Load only this bounded page's records before applying
            // the operator's current site and participant permissions.
            $record = $this->incidentStore()->read($summary['id']);
            if (!$record) { continue; }
            $projected = $this->operatorIncidentProjection($record, $principal);
            if ($projected) { $incidents[] = $projected; }
        }
        $templates = $this->operatorPermittedTemplates($principal, $settings);
        $schedules = [];
        foreach ($settings['scheduled_announcements'] ?? [] as $schedule) {
            if (($schedule['operator_credential_id'] ?? '') === $principal['id']) { $schedules[] = array_intersect_key($schedule, array_flip(['id', 'name', 'message', 'enabled', 'occurrences', 'timezone'])); }
        }
        return ['principal'=>array_intersect_key($principal, array_flip(['name', 'username', 'operator_role'])), 'choices'=>$choices, 'tones'=>$this->getAvailableTones(), 'recordings'=>$this->getAvailableSystemSounds(),
            'tone_defaults'=>['opening_tone'=>$settings['opening_tone']??'', 'closing_tone'=>$settings['closing_tone']??''],
            'can_send'=>OperatorAccess::may($principal, 'send'), 'can_schedule'=>OperatorAccess::may($principal, 'schedule'), 'can_roll_call'=>OperatorAccess::may($principal, 'roll_call'),
            'templates'=>$templates, 'incidents'=>$incidents, 'next_cursor'=>$listing['next_cursor'], 'schedules'=>$schedules, 'timezone'=>$this->getPbxDateTimeZone()->getName(),
            'enterprise'=>$this->operatorEnterpriseProjection($principal, $settings, $templates)];
    }

    /** Checks the effective on-duty route and every escalation before exposing wording. */
    private function operatorPermittedTemplates(array $principal, array $settings): array
    {
        $templates = [];
        foreach ($this->incidentTemplates() as $template) {
            try {
                if (method_exists($this, 'routeEnterpriseIncidentTemplate')) { $template = $this->routeEnterpriseIncidentTemplate($template); }
                $delivery = $this->freezeIncidentDelivery($template['delivery'], '', false);
                if (!OperatorAccess::delivery($principal, $delivery, $settings)) { continue; }
                $permitted = true;
                if (!empty($template['escalation']['enabled'])) {
                    foreach (IncidentConfig::escalationSteps($template['escalation']) as $step) {
                        if (!OperatorAccess::delivery($principal, $this->freezeIncidentDelivery($step['delivery'], '', false), $settings)) { $permitted = false; break; }
                    }
                }
                if (!$permitted) { continue; }
                $templates[] = array_intersect_key($template, array_flip(['id', 'name', 'title', 'message', 'severity', 'fields', 'language_variants']));
            } catch (\Throwable $error) { continue; }
        }
        return $templates;
    }

    private function operatorDrillProjection(array $run): array
    {
        $result = array_intersect_key($run, array_flip(['id','state','created_at','incident_id','owner','reviews','result']));
        $result['assignment'] = array_intersect_key($run['assignment'] ?? [], array_flip(['id','name','template_id','objectives']));
        return $result;
    }

    private function operatorEnterpriseProjection(array $principal, array $settings, array $templates): array
    {
        $empty = ['enabled'=>false,'approvals'=>[],'drills'=>[],'floorplans'=>[],'warnings'=>[]];
        if (empty($settings['enterprise_operations']['enabled']) || !method_exists($this, 'enterpriseOperationsAction')) { return $empty; }
        $config = \SLS\MassNotify\EnterpriseOperationsConfig::normalize($settings['enterprise_operations']);
        $result = $empty; $result['enabled'] = true;
        if ($config['dual_approval']['enabled'] && OperatorAccess::may($principal, 'send')) {
            try { $result['approvals'] = $this->enterpriseOperationsAction('approval_list', [])['reviews']; }
            catch (\Throwable $error) { $result['warnings'][] = 'Approval history is unavailable. No delivery has been submitted by this page.'; }
        }
        if ($config['drills']['enabled']) {
            try {
                $person = $principal['id'] === 'recovery_admin' ? 'pbx:'.$principal['username'] : $principal['id'];
                foreach ($this->enterpriseOperationsAction('drill_report', [])['assignments'] as $row) {
                    $entry = array_intersect_key($row, array_flip(['id','name','enabled','template_id','due_at','objectives','status']));
                    $entry['can_launch'] = $row['enabled'] && OperatorAccess::may($principal, 'send')
                        && ($principal['operator_role'] === 'administrator' || $row['owner_id'] === $person)
                        && in_array($row['template_id'], array_column($templates, 'id'), true);
                    $entry['runs'] = array_map(fn(array $run): array => $this->operatorDrillProjection($run), $row['runs']);
                    $result['drills'][] = $entry;
                }
            } catch (\Throwable $error) { $result['warnings'][] = 'Assigned drill history is unavailable. Review storage diagnostics before launching or recording a review.'; }
        }
        if ($config['floorplans']['enabled']) {
            foreach ($config['floorplans']['plans'] as $plan) {
                if ($plan['enabled'] && ($principal['operator_role'] === 'administrator' || in_array($plan['site_id'], $principal['site_ids'] ?? [], true))) {
                    $result['floorplans'][] = array_intersect_key($plan, array_flip(['id','name']));
                }
            }
        }
        return $result;
    }

    /** Network credentials cannot be used as human approvers or drill reviewers. */
    private function enterpriseControlPrincipal(array $principal, string $scope): array
    {
        $authenticated = $GLOBALS['sls_control_principal'] ?? [];
        $id = $principal['id'] ?? '';
        $current = is_string($id) ? ApiSecurity::currentCredential($this->getActiveSettings(), $id) : null;
        if (!is_string($id) || !preg_match('/^api_[a-f0-9]{24}$/D', $id) || ($authenticated['id'] ?? '') !== $id
            || isset($authenticated['operator_role']) || !$current || isset($current['operator_role']) || !ApiSecurity::permits($current, $scope)) {
            throw new \DomainException('A current named Control API credential with the required scope is required.');
        }
        return $current;
    }

    public function enterpriseControlTemplates(array $principal): array
    {
        $principal = $this->enterpriseControlPrincipal($principal, 'read'); $settings = $this->getActiveSettings();
        return ['success'=>true,'enabled'=>($settings['enterprise_operations']['enabled'] ?? false) === true,
            'templates'=>($settings['enterprise_operations']['enabled'] ?? false) === true ? $this->operatorPermittedTemplates($principal, $settings) : []];
    }

    public function startEnterpriseControlTemplate(array $input, array $principal): array
    {
        $principal = $this->enterpriseControlPrincipal($principal, 'send'); $settings = $this->getActiveSettings();
        if (($settings['enterprise_operations']['enabled'] ?? false) !== true) { throw new \DomainException('Enterprise operations have not been enabled.'); }
        $input = IncidentConfig::object($input, ['template_id','request_id','fields','is_test','language_variant','planned_at'], 'Reviewed enterprise template launch');
        if (!in_array($input['template_id'] ?? '', array_column($this->operatorPermittedTemplates($principal, $settings), 'id'), true)) {
            throw new \DomainException('This template or a follow-up exceeds the current credential audience.');
        }
        $result = $this->withOperator($principal, fn(): array => $this->startIncident($input,
            ['identity'=>'Control API '.$principal['id'],'source'=>'control_api','credential_id'=>$principal['id']]));
        // A scoped sender may launch a roster-backed template without reading
        // other people's roster, observations, frozen addresses or identities.
        $public = array_intersect_key($result, array_flip(['success','message','errors','error_code','id','title','state','is_test','created_at']));
        if (isset($result['operations'])) {
            $public['operations'] = array_map(static fn(array $row): array => array_intersect_key($row,
                array_flip(['id','kind','sequence','state','job_id','review_id'])), $result['operations']);
        }
        return $public;
    }

    public function operatorAction(string $action, array $input): array
    {
        try {
            $principal = $this->currentOperator();
            if (in_array($action, ['approval_list','approval_approve','approval_submit','approval_reject','drill_start','drill_review','drill_report','floorplan_overlay','floorplan_image'], true)) {
                if (!method_exists($this, 'enterpriseOperationsAction') || ($this->getActiveSettings()['enterprise_operations']['enabled'] ?? false) !== true) {
                    throw new \DomainException('Enterprise operations have not been enabled.');
                }
                $fields = ['approval_list'=>[], 'approval_approve'=>['review_id'], 'approval_submit'=>['review_id'], 'approval_reject'=>['review_id'],
                    'drill_start'=>['assignment_id','request_id','fields','planned_at'], 'drill_review'=>['run_id','completed','note'], 'drill_report'=>[],
                    'floorplan_overlay'=>['plan_id','incident_id'], 'floorplan_image'=>['image_id']];
                $input = IncidentConfig::object($input, $fields[$action], 'Enterprise operator action');
                if ($action === 'floorplan_image') {
                    if (($this->getActiveSettings()['enterprise_operations']['floorplans']['enabled'] ?? false) !== true) { throw new \DomainException('Private floor plans have not been enabled.'); }
                    $id = IncidentConfig::identifier($input['image_id'] ?? '', '/^img_[a-f0-9]{64}$/D', 'Private image');
                    $image = $this->getEnterpriseFloorplanImage($id);
                    if (!in_array($image['type'] ?? '', ['image/png','image/jpeg'], true) || !is_string($image['bytes'] ?? null) || strlen($image['bytes']) > 5242880) { throw new \RuntimeException('Invalid private image.'); }
                    return ['success'=>true,'image'=>'data:'.$image['type'].';base64,'.base64_encode($image['bytes'])];
                }
                $result = $this->enterpriseOperationsAction($action, $input);
                if ($action === 'drill_start' || $action === 'drill_review') {
                    if (isset($result['run'])) { $result['run'] = $this->operatorDrillProjection($result['run']); }
                    if (isset($result['incident']['template'])) { $result['incident'] = $this->operatorIncidentProjection($result['incident'], $this->currentOperator()); }
                }
                if ($action === 'drill_report') {
                    $settings = $this->getActiveSettings(); $result['assignments'] = $this->operatorEnterpriseProjection($principal, $settings,
                        $this->operatorPermittedTemplates($principal, $settings))['drills'];
                }
                return $result;
            }
            if ($action === 'job') {
                if (array_diff(array_keys($input), ['job_id']) || !is_string($input['job_id'] ?? null) || !preg_match('/^job_[a-f0-9]{32}$/D', $input['job_id'])) { throw new \DomainException('Select an announcement job.'); }
                $record = (new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR.'/announcement-jobs'))->read($input['job_id']);
                $request = $record['request'] ?? []; $delivery = [];
                foreach (['extensions'=>'phones','desktop_clients'=>'desktops','voice_recipient_ids'=>'voice_recipient_ids','email_recipient_ids'=>'email_recipient_ids','sms_recipient_ids'=>'sms_recipient_ids','webhook_ids'=>'webhooks'] as $key=>$field) { $delivery[$key] = $request[$field] ?? []; }
                if (!$record || (empty($principal['audience']['unrestricted']) && (($request['api_credential_id'] ?? '') !== $principal['id'] || !OperatorAccess::delivery($principal,$delivery,$this->getActiveSettings())))) { throw new \DomainException('This announcement is outside your current assignments.'); }
                return $this->withOperator($principal, fn(): array => $this->getAnnouncementJob($input['job_id']));
            }
            if (in_array($action, ['preview', 'send', 'save_schedule'], true)) {
                $required = $action === 'save_schedule' ? 'schedule' : 'send';
                if (!OperatorAccess::may($principal, $required)) { throw new \DomainException('Your current role does not allow this operation.'); }
                if (array_diff(array_keys($input), ['delivery', 'message', 'title', 'name', 'occurrences', 'recurrence_mode', 'lateness_minutes'])) { throw new \DomainException('The announcement form contains unsupported fields.'); }
                $delivery = $this->operatorDelivery($principal, $input['delivery'] ?? []);
                $message = IncidentConfig::text($input['message'] ?? '', 500, 'Announcement message'); $title = IncidentConfig::text($input['title'] ?? '', 80, 'Announcement title');
                if ($action === 'save_schedule') {
                    $this->operatorScheduleOrigin = $principal['id'] === 'recovery_admin' ? null : $principal['id'];
                    try { return $this->withOperator($principal, fn(): array => $this->saveScheduledAnnouncement([
                        'schedule_name'=>$input['name'] ?? '', 'schedule_message'=>$message, 'schedule_title'=>$title, 'schedule_enabled'=>'1',
                        'schedule_occurrences'=>$input['occurrences'] ?? [], 'schedule_recurrence_mode'=>$input['recurrence_mode'] ?? 'none',
                        'schedule_max_lateness_minutes'=>$input['lateness_minutes'] ?? 15, 'schedule_audio_mode'=>$delivery['audio_mode'],
                        'schedule_opening_tone'=>$delivery['opening_tone'], 'schedule_closing_tone'=>$delivery['closing_tone'],
                        'schedule_extensions'=>$delivery['extensions'], 'schedule_desktop_clients'=>$delivery['desktop_clients'],
                        'schedule_voice_recipient_ids'=>$delivery['voice_recipient_ids'], 'schedule_email_recipient_ids'=>$delivery['email_recipient_ids'],
                        'schedule_sms_recipient_ids'=>$delivery['sms_recipient_ids'], 'schedule_webhook_ids'=>$delivery['webhook_ids'],
                        'schedule_colored'=>$delivery['style']==='colored'?'1':'0', 'schedule_background_color'=>$delivery['background_color'],
                    ])); } finally { $this->operatorScheduleOrigin = null; }
                }
                return $this->withOperator($principal, fn(): array => $this->sendSipNotifyAnnouncement($delivery['extensions'], $message, true,
                    in_array($delivery['audio_mode'], ['tts', 'tones_tts'], true), [], $delivery + ['title'=>$title, 'preview'=>$action === 'preview', 'trigger_source'=>'SLS Operator Portal']));
            }
            if ($action === 'remove_schedule') {
                if (!OperatorAccess::may($principal, 'schedule') || array_diff(array_keys($input), ['schedule_id']) || !is_string($input['schedule_id'] ?? null)) { throw new \DomainException('Select one of your saved schedules.'); }
                $schedule = array_column($this->getActiveSettings()['scheduled_announcements'] ?? [], null, 'id')[$input['schedule_id']] ?? null;
                if (!$schedule || ($schedule['operator_credential_id'] ?? '') !== $principal['id']) { throw new \DomainException('That schedule does not belong to this operator.'); }
                return $this->deleteScheduledAnnouncement($input['schedule_id']);
            }
            if ($action === 'roll_call') {
                if (!OperatorAccess::may($principal, 'roll_call')) { throw new \DomainException('Only an assigned warden or SLS administrator can record roll call.'); }
                $id = $input['incident_id'] ?? ''; unset($input['incident_id']);
                if (!is_string($id)) { throw new \DomainException('Select an incident.'); }
                $activity = $this->acquireAnnouncementActivityLock(false, 30);
                try { $result = $this->incidentService()->respond($id, $input, ['identity'=>$principal['username'], 'source'=>'freepbx'], null,
                    function (array $record, array $person) use ($principal): bool {
                        $current = $this->currentOperator();
                        return $current['id'] === $principal['id'] && OperatorAccess::may($current, 'roll_call')
                            && OperatorAccess::person($current, $person, $this->getActiveSettings(), $record['template']['delivery']['_identities'] ?? []);
                    }); } finally { $this->releaseNativeBackupFileLock($activity); }
                return ['success'=>true, 'incident'=>$this->operatorIncidentProjection($result, $this->currentOperator()), 'message'=>'Human response recorded for your assigned roster.'];
            }
            if (in_array($action, ['start_incident', 'update_incident'], true)) {
                if (!OperatorAccess::may($principal, 'send')) { throw new \DomainException('Your current role cannot send incident announcements.'); }
                $state = $this->operatorOperationsState();
                if ($action === 'start_incident') {
                    if (!in_array($input['template_id'] ?? '', array_column($state['templates'], 'id'), true)) { throw new \DomainException('The incident template exceeds your current audience permissions.'); }
                    return $this->withOperator($principal, function () use ($input, $principal): array {
                        $result = $this->startIncident($input);
                        return empty($result['success']) ? $result : ['success'=>true, 'incident'=>$this->operatorIncidentProjection($result, $principal)];
                    });
                }
                $id = $input['incident_id'] ?? ''; unset($input['incident_id']);
                if (!is_string($id)) { throw new \DomainException('Select an incident.'); }
                $record = $this->incidentStore()->read($id); $projected = $record ? $this->operatorIncidentProjection($record, $principal) : null;
                if (!$projected || !$projected['can_update']) { throw new \DomainException('This incident or its supervisor audience exceeds your current permissions.'); }
                return $this->withOperator($principal, function () use ($id, $input, $principal): array {
                    $result = $this->sendIncidentUpdate($id, $input);
                    return empty($result['success']) ? $result : ['success'=>true, 'incident'=>$this->operatorIncidentProjection($result, $principal)];
                });
            }
            throw new \DomainException('Unsupported Operations action.');
        } catch (\DomainException | \InvalidArgumentException $error) { return ['success'=>false, 'message'=>$error->getMessage()]; }
        catch (\Throwable $error) { return ['success'=>false, 'message'=>'The operation could not confirm completion. Refresh the record before retrying; check configuration and delivery storage health.']; }
    }

    public function renderOperatorsPage(string $endpoint = 'config.php?display=slsmassnotifyserver_operators', ?string $csrf = null): string
    { return load_view(__DIR__.'/views/operators.php', ['state'=>$this->operatorAccessState(), 'csrf_token'=>$csrf ?? $this->getCsrfToken(), 'endpoint'=>$endpoint]); }
    public function renderOperationsPage(string $cursor = '', string $endpoint = 'config.php?display=slsmassnotifyserver_operations', ?string $csrf = null): string
    { return load_view(__DIR__.'/views/operations.php', ['state'=>$this->operatorOperationsState($cursor), 'csrf_token'=>$csrf ?? $this->getCsrfToken(), 'endpoint'=>$endpoint]); }
}
