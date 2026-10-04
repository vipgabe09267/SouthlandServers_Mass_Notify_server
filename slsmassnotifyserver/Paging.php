<?php
namespace FreePBX\modules;

require_once __DIR__ . '/bin/sls_mass_notify/sls_live_paging.php';

use SLS\MassNotify\LivePagingConfig;

/** Internal dial-in live paging; activation uses native FreePBX Apply Config. */
trait SlsLivePaging
{
    private function normalizeLivePagingSettings($value)
    {
        if (!is_array($value)) { throw new \InvalidArgumentException(_('Live paging settings must be an object.')); }
        return LivePagingConfig::normalize($value);
    }

    private function validateLivePagingFields($value)
    {
        try { $this->normalizeLivePagingSettings($value); return []; }
        catch (\Throwable $error) { return [$error->getMessage()]; }
    }

    public function generateLivePagingPin($length)
    {
        try {
            if (!is_int($length) && (!is_string($length) || !preg_match('/^[4-8]$/D', $length))) {
                throw new \InvalidArgumentException(_('Choose a PIN length between 4 and 8 digits.'));
            }
            return ['success' => true, 'pin' => LivePagingConfig::generatePin((int)$length)];
        } catch (\Throwable $error) { return ['success' => false, 'message' => $error->getMessage()]; }
    }

    private function livePagingInteger($value)
    {
        if (!is_int($value) && (!is_string($value) || !preg_match('/^[0-9]{1,4}$/D', $value))) {
            throw new \InvalidArgumentException(_('Paging numeric settings must contain whole numbers.'));
        }
        return (int)$value;
    }

    public function saveLivePagingSettings(array $input, ?string $revision = null)
    {
        if (!$this->isSetupComplete($this->getActiveSettings())) {
            return ['success' => false, 'message' => $this->getSetupRequiredMessage(), 'errors' => []];
        }
        try {
            $settings = $this->getPendingSettings() ?? $this->getActiveSettings();
            $previous = LivePagingConfig::normalize((array)($settings['live_paging'] ?? []));
            if ($revision !== null && !hash_equals(hash('sha256', json_encode([$previous, $settings['announcement_groups'] ?? []], JSON_THROW_ON_ERROR)), $revision)) {
                throw new \InvalidArgumentException(_('Paging settings or linked recipients changed while this page was open. Reload Paging and review your changes again.'));
            }
            $previousGroups = array_column($previous['groups'], null, 'group_id');
            if (array_diff(array_keys($input), array_merge(array_keys(LivePagingConfig::defaults()), ['external_access']))) {
                throw new \InvalidArgumentException(_('Unknown live paging form field.'));
            }
            $next = array_replace(LivePagingConfig::defaults(), $input);
            $next['enabled'] = LivePagingConfig::flag($next['enabled']);
            $next['max_duration_seconds'] = $this->livePagingInteger($next['max_duration_seconds']);
            // Large recipient selectors travel as bounded JSON fields so PHP's
            // max_input_vars cannot silently discard any configured recipient.
            if (is_string($next['allowed_callers'])) {
                if (strlen($next['allowed_callers']) > 25000) { throw new \InvalidArgumentException(_('The paging caller selection is too large.')); }
                try { $decoded = json_decode($next['allowed_callers'], false, 4, JSON_THROW_ON_ERROR); }
                catch (\JsonException $error) { throw new \InvalidArgumentException(_('The paging caller selection is invalid.')); }
                if (!is_array($decoded)) { throw new \InvalidArgumentException(_('The paging caller selection must be a list.')); }
                $next['allowed_callers'] = $decoded;
            }
            if (is_string($next['groups'])) {
                if (strlen($next['groups']) > 800000) { throw new \InvalidArgumentException(_('The paging group selection is too large.')); }
                try { $decoded = json_decode($next['groups'], false, 8, JSON_THROW_ON_ERROR); }
                catch (\JsonException $error) { throw new \InvalidArgumentException(_('The paging group selection is invalid.')); }
                if (!is_array($decoded)) { throw new \InvalidArgumentException(_('Paging groups must be a list.')); }
                $next['groups'] = [];
                foreach ($decoded as $row) {
                    if (!$row instanceof \stdClass) { throw new \InvalidArgumentException(_('Each paging group must be an object.')); }
                    $next['groups'][] = (array)$row;
                }
            }
            if (!is_array($next['groups']) || !array_is_list($next['groups']) || count($next['groups']) > 10) {
                throw new \InvalidArgumentException(_('Select no more than 10 live paging groups.'));
            }
            $rows = [];
            foreach ($next['groups'] as $row) {
                if (!is_array($row) || array_diff(array_keys($row), ['group_id', 'menu_number', 'require_pin', 'pin_length', 'pin',
                    'name', 'extensions', 'notify_extensions', 'allowed_callers', 'text_message', 'allow_external', 'external_callers'])) {
                    throw new \InvalidArgumentException(_('Invalid live paging group form field.'));
                }
                $groupId = $row['group_id'] ?? '';
                if (!is_string($groupId)) { throw new \InvalidArgumentException(_('Invalid paging group identifier.')); }
                if ($groupId === '' && LivePagingConfig::isStandalone($row)) { $groupId = 'paging_' . bin2hex(random_bytes(12)); }
                $saved = $previousGroups[$groupId] ?? [];
                // A standalone group cannot silently revert to an announcement
                // reference through an incomplete or truncated form submission.
                if (LivePagingConfig::isStandalone($saved) && !LivePagingConfig::isStandalone($row)) {
                    throw new \InvalidArgumentException(_('The saved paging group is missing its independent group fields.'));
                }
                $length = $this->livePagingInteger($row['pin_length'] ?? 4);
                $pin = $row['pin'] ?? '';
                if (!is_string($pin)) { throw new \InvalidArgumentException(_('Paging PINs must contain digits.')); }
                $hash = (string)($saved['pin_hash'] ?? '');
                if ($pin !== '') {
                    if (!preg_match('/^[0-9]{4,8}$/D', $pin) || strlen($pin) !== $length) {
                        throw new \InvalidArgumentException(_('A paging PIN must match the selected 4–8 digit length.'));
                    }
                    $hash = password_hash($pin, PASSWORD_DEFAULT);
                    if (function_exists('sodium_memzero')) { sodium_memzero($pin); } else { $pin = ''; }
                } elseif ($hash !== '' && $length !== (int)($saved['pin_length'] ?? 4)) {
                    throw new \InvalidArgumentException(_('Randomize or enter a new PIN when changing its length.'));
                }
                $normalizedRow = ['group_id' => $groupId, 'menu_number' => $this->livePagingInteger($row['menu_number'] ?? 0),
                    'require_pin' => LivePagingConfig::flag($row['require_pin'] ?? '1'), 'pin_length' => $length, 'pin_hash' => $hash];
                foreach (['name', 'extensions', 'notify_extensions', 'allowed_callers', 'text_message', 'allow_external', 'external_callers'] as $field) {
                    if (array_key_exists($field, $row)) { $normalizedRow[$field] = $row[$field]; }
                }
                $rows[] = $normalizedRow;
            }
            $next['groups'] = $rows;
            $next = LivePagingConfig::normalize($next);
            // Explicitly saving an independent-only menu retires its unused
            // legacy caller list. Loading installed settings never migrates it.
            if (!array_filter($next['groups'], static function (array $row): bool { return !LivePagingConfig::isStandalone($row); })) {
                $next['allowed_callers'] = [];
            }
            $this->validateLivePagingSelection($next, $settings);
            if ($next['enabled'] === '1') { $this->assertLivePagingExtensionAvailable($next['extension']); }
            $settings['live_paging'] = $next;
            $this->persistPendingSettings($this->normalizeSettings($settings));
            return ['success' => true, 'message' => $next['enabled'] === '1'
                ? _('Paging settings saved. Use Apply Config to prepare the spoken prompts and activate the menu.')
                : _('Paging settings saved, but dial-in paging is disabled. Enable it and use Apply Config before dialing.'), 'errors' => []];
        } catch (\InvalidArgumentException $error) {
            return ['success' => false, 'message' => _('Live paging settings were not saved.'), 'errors' => [$error->getMessage()]];
        } catch (\Throwable $error) {
            // Never log POST bodies, entered PINs, hashes, or the protected file.
            return ['success' => false, 'message' => _('Live paging settings were not saved.'),
                'errors' => [_('The protected configuration or extension availability check failed. Check PBX health and try again.')]];
        }
    }

    private function validateLivePagingSelection(array $paging, array $settings)
    {
        if ($paging['enabled'] === '1' && ($paging['external_access'] ?? '0') === '1') {
            $ivrs = array_column($this->getLivePagingIvrs(), null, 'id');
            if (!$paging['external_ivr_ids']) { throw new \InvalidArgumentException(_('Select an IVR before enabling external paging.')); }
            foreach ($paging['external_ivr_ids'] as $id) {
                if (!isset($ivrs[$id])) { throw new \InvalidArgumentException(_('A selected external paging IVR no longer exists. Review the IVR selection.')); }
                if (in_array($paging['extension'], $ivrs[$id]['entries'], true)) { throw new \InvalidArgumentException(_('The paging number is already assigned to a key in a selected IVR. Choose another paging number or remove that IVR key first.')); }
            }
        }
        $available = array_fill_keys(array_map('strval', $this->getConfiguredPjsipExtensionNumbers()), true);
        foreach ($paging['allowed_callers'] as $caller) {
            if (!isset($available[$caller])) { throw new \InvalidArgumentException(_('An authorized legacy paging extension no longer exists. Update the caller selection.')); }
        }
        foreach (LivePagingConfig::resolvedGroups($paging, (array)($settings['announcement_groups'] ?? [])) as $group) {
            foreach (['extensions' => _('audio recipient'), 'notify_extensions' => _('text recipient'), 'allowed_callers' => _('authorized caller')] as $field => $label) {
                foreach ($group[$field] as $target) {
                    if (!isset($available[$target])) {
                        throw new \InvalidArgumentException(sprintf(_('A paging %s extension no longer exists. Update the group selection.'), $label));
                    }
                }
            }
        }
    }

    protected function queryLivePagingDialplan($extension)
    {
        $output = []; $status = 1;
        exec('/usr/bin/timeout --signal=TERM --kill-after=1 3 /usr/sbin/asterisk -rx '
            . escapeshellarg('dialplan show ' . $extension . '@from-internal') . ' 2>&1', $output, $status);
        if ($status !== 0) { throw new \RuntimeException('Paging extension conflict check is unavailable.'); }
        $text = implode("\n", $output);
        if (!preg_match("/(?:[Cc]ontext '|There is no existence of)/", $text)) { throw new \RuntimeException('Paging extension conflict check returned an unexpected response.'); }
        return $text;
    }

    public static function livePagingDialplanHasConflict($output, string $ownedContext = 'sls-live-paging')
    {
        $context = ''; $generated = false; $pattern = ''; $priorities = [];
        $isConflict = static function () use (&$context, &$generated, &$pattern, &$priorities, $ownedContext): bool {
            if (!$priorities || $context === $ownedContext) { return false; }
            // FreePBX includes this rejection fallback for every unused number.
            // Exempt only its complete stock body, never an outbound wildcard,
            // a custom route, or an exact extension in the same context.
            $reject = ['ResetCDR()', 'Set(CDR_PROP(disable)=true)', 'Progress()', 'Wait(1)',
                'Playback(silence/1&cannot-complete-as-dialed&check-number-dial-again,noanswer)',
                'Wait(1)', 'Congestion(20)', 'Hangup()'];
            $legacyReject = $reject; $legacyReject[1] = 'NoCDR()';
            return !($context === 'bad-number' && $generated && $pattern === '_X.'
                && ($priorities === $reject || $priorities === $legacyReject));
        };
        foreach (preg_split('/\R/', (string)$output) ?: [] as $line) {
            if (preg_match("/\\[\\s*(?:Included )?[Cc]ontext '([^']+)'/", $line, $match)) {
                if ($isConflict()) { return true; }
                $context = $match[1]; $generated = strpos($line, "created by 'pbx_config'") !== false;
                $pattern = ''; $priorities = [];
            }
            if (preg_match("/^\\s*'([^']+)'\\s*=>\\s*([0-9]+)\\.\\s*(.*?)\\s*\\[[^\\]]+\\]\\s*$/", $line, $match)) {
                if ($isConflict()) { return true; }
                $pattern = $match[1]; $priorities = [];
                if ((int)$match[2] !== 1) { return true; }
                $priorities[] = $match[3];
            } elseif (preg_match('/^\s*([0-9]+)\.\s*(.*?)\s*\[[^\]]+\]\s*$/', $line, $match)) {
                if ((int)$match[1] !== count($priorities) + 1) { return true; }
                $priorities[] = $match[2];
            } elseif (preg_match('/=>\s*[0-9]+\./', $line)) {
                // An unexpected CLI format cannot establish an unused number.
                if ($context !== $ownedContext) { return true; }
            }
        }
        return $isConflict();
    }

    private function assertLivePagingExtensionAvailable($extension)
    {
        $usage = $this->FreePBX->Extensions->checkUsage([(string)$extension], false);
        unset($usage['slsmassnotifyserver']);
        if (!empty($usage) || self::livePagingDialplanHasConflict($this->queryLivePagingDialplan($extension))) {
            throw new \InvalidArgumentException(_('That paging extension is already used or matches another PBX dialplan route. Choose an unused internal extension.'));
        }
    }

    public function getLivePagingExtensionUsage($extensions = true)
    {
        $settings = $this->getPendingSettings() ?? $this->getActiveSettings();
        $paging = LivePagingConfig::normalize((array)($settings['live_paging'] ?? []));
        $panic=method_exists($this,'getPanicExtensionUsage') ? $this->getPanicExtensionUsage($extensions,$settings) : [];
        if ($paging['enabled'] !== '1' || ($extensions !== true && !in_array($paging['extension'], array_map('strval', (array)$extensions), true))) { return $panic; }
        return $panic + [$paging['extension'] => ['description' => _('SLS live paging menu'), 'status' => 'INUSE',
            'edit_url' => 'config.php?display=slsmassnotifyserver_paging']];
    }

    public function renderLivePagingPage(array $params = [])
    {
        $settings = $this->getPendingSettings() ?? $this->getActiveSettings();
        $paging = LivePagingConfig::normalize((array)($settings['live_paging'] ?? []));
        $revision = hash('sha256', json_encode([$paging, $settings['announcement_groups'] ?? []], JSON_THROW_ON_ERROR));
        foreach ($paging['groups'] as &$row) { $row['has_pin'] = $row['pin_hash'] !== ''; unset($row['pin_hash']); }
        unset($row);
        $extensions = [];
        foreach ($this->getExtensionNameMap() as $extension => $name) { $extensions[] = ['extension' => (string)$extension, 'name' => $name]; }
        $active = LivePagingConfig::normalize((array)($this->getActiveSettings()['live_paging'] ?? []));
        return load_view(__DIR__ . '/views/paging.php', ['paging' => $paging, 'paging_revision' => $revision, 'settings' => $settings,
            'active_paging_enabled' => $active['enabled'] === '1', 'active_paging_extension' => $active['extension'],
            'has_pending_changes' => $this->getPendingSettings() !== null,
            'announcement_groups' => $settings['announcement_groups'] ?? [], 'available_extensions' => $extensions,
            'available_ivrs' => $this->getLivePagingIvrs(),
            'save_result' => $params['save_result'] ?? null, 'csrf_token' => $this->getCsrfToken(), 'hero_image' => self::HERO_IMAGE]);
    }

    private function assertLivePagingPromptsReady(array $settings)
    {
        $paging = LivePagingConfig::normalize((array)($settings['live_paging'] ?? []));
        if ($paging['enabled'] !== '1' && !array_filter($settings['automations']['rules'] ?? [], static fn($r)=>($r['kind'] ?? '')==='panic' && !empty($r['enabled']) && ($r['dial_extension'] ?? '')!=='')) { return; }
        $message = _('Paging prompts changed or are unavailable. Stage these settings and use Apply Config before activating them.');
        $account = function_exists('posix_getpwnam') ? posix_getpwnam('asterisk') : false;
        if (!is_array($account)) { throw new \DomainException($message); }
        foreach (LivePagingConfig::promptFiles($settings) as $relative) {
            $path = self::SOUNDS_DIR . '/paging/' . basename($relative) . '.wav';
            clearstatcache(true, $path);
            $before = @lstat($path);
            if (!is_array($before) || realpath($path) !== $path || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
                || ($before['mode'] & 0777) !== 0640 || $before['uid'] !== $account['uid'] || $before['gid'] !== $account['gid'] || $before['size'] <= 44) {
                throw new \DomainException($message);
            }
            $handle = @fopen($path, 'rb');
            if ($handle === false) { throw new \DomainException($message); }
            try {
                $opened = fstat($handle);
                $header = fread($handle, 12);
                if (!is_array($opened) || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino'] || $opened['nlink'] !== 1
                    || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') { throw new \DomainException($message); }
            } finally { fclose($handle); }
        }
    }

    private function ensureLivePagingPrompts(array $settings)
    {
        $paging = LivePagingConfig::normalize((array)($settings['live_paging'] ?? []));
        if ($paging['enabled'] !== '1' && !array_filter($settings['automations']['rules'] ?? [], static fn($r)=>($r['kind'] ?? '')==='panic' && !empty($r['enabled']) && ($r['dial_extension'] ?? '')!=='')) { return; }
        $directory = self::SOUNDS_DIR . '/paging';
        if (is_link($directory)) {
            throw new \RuntimeException(_('The live paging prompt directory is unavailable.'));
        }
        $this->ensureOwnedDirectory($directory, 0750);
        $texts = LivePagingConfig::promptTexts($paging, (array)($settings['announcement_groups'] ?? []), (array)($settings['automations'] ?? []));
        $files = LivePagingConfig::promptFiles($settings);
        $voiceSettings = $settings;
        // Menu construction must not silently truncate a caller's choices.
        $voiceSettings['tts_max_seconds'] = 600;
        $voiceSettings['_tts_prompt_only'] = true;
        foreach ($files as $key => $relative) {
            $destination = $directory . '/' . basename($relative) . '.wav';
            if (is_link($destination)) { throw new \RuntimeException(_('A live paging prompt path is unsafe. Run protected repair.')); }
            if (is_file($destination) && is_readable($destination) && filesize($destination) > 44) {
                $metadata = lstat($destination);
                if (!is_array($metadata) || $metadata['nlink'] !== 1) { throw new \RuntimeException(_('A live paging prompt path is unsafe. Run protected repair.')); }
                $handle = @fopen($destination, 'rb');
                $header = ''; $opened = false;
                if ($handle !== false) {
                    try { $opened = fstat($handle); $header = fread($handle, 12); }
                    finally { fclose($handle); }
                }
                if (is_array($opened) && $opened['dev'] === $metadata['dev'] && $opened['ino'] === $metadata['ino']
                    && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WAVE') {
                    $this->setPrivateOwnership($destination);
                    continue;
                }
            }
            $generated = $this->generateAnnouncementTtsFile($texts[$key], $voiceSettings);
            if ($generated === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $generated)) {
                throw new \RuntimeException(sprintf(_('Unable to generate the live paging %s prompt. Check Piper voice readiness.'), $key));
            }
            $source = self::TTS_DIR . '/' . $generated . '.wav';
            $temporary = tempnam($directory, '.prompt-');
            if ($temporary === false) { throw new \RuntimeException(_('Unable to stage a live paging prompt.')); }
            try {
                if (is_link($source) || !is_file($source) || !copy($source, $temporary)) { throw new \RuntimeException(_('Unable to copy the generated paging prompt.')); }
                $handle = fopen($temporary, 'rb');
                if ($handle === false) { throw new \RuntimeException(_('Unable to verify the generated paging prompt.')); }
                $header = fread($handle, 12);
                $synced = !function_exists('fsync') || fsync($handle);
                fclose($handle);
                if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE' || !$synced) {
                    throw new \RuntimeException(_('The generated paging prompt is not a valid WAV file.'));
                }
                $this->setPrivateOwnership($temporary);
                if (!rename($temporary, $destination)) { throw new \RuntimeException(_('Unable to activate a live paging prompt.')); }
                $directoryHandle = fopen($directory, 'r');
                if ($directoryHandle === false) { throw new \RuntimeException(_('Unable to persist the live paging prompt directory.')); }
                try {
                    if (function_exists('fsync') && !fsync($directoryHandle)) { throw new \RuntimeException(_('Unable to persist the live paging prompt directory.')); }
                } finally { fclose($directoryHandle); }
                @unlink($source);
            } finally { if (is_file($temporary)) { @unlink($temporary); } }
        }
        $this->pruneLivePagingPrompts($settings);
    }

    private function pruneLivePagingPrompts(array $prospective)
    {
        // Cleanup is optional: uncertain config state must preserve every file.
        // Strict disk reads avoid normalization/default fallbacks hiding a
        // malformed or unreadable active/pending configuration.
        try {
            $states = [$prospective, \SLS\MassNotify\LivePagingSession::loadSettings(self::SETTINGS_JSON)];
            $paths = [self::PENDING_SETTINGS_JSON];
            foreach (['LEGACY_PENDING_SETTINGS_JSON', 'LEGACY_OLD_PENDING_SETTINGS_JSON'] as $constant) {
                $name = self::class . '::' . $constant;
                if (defined($name)) { $paths[] = constant($name); }
            }
            foreach (array_unique($paths) as $path) {
                if (!is_readable(dirname($path)) || !is_executable(dirname($path))) { return; }
                if (@lstat($path) !== false || is_link($path)) {
                    $states[] = \SLS\MassNotify\LivePagingSession::loadSettings($path);
                }
            }
            $keep = [];
            foreach ($states as $state) {
                foreach (LivePagingConfig::promptFiles($state) as $relative) { $keep[basename($relative) . '.wav'] = true; }
            }
            $refresh = [];
            foreach (LivePagingConfig::promptFiles($prospective) as $relative) { $refresh[] = basename($relative) . '.wav'; }
            $manifest = json_encode(['keep' => array_keys($keep), 'refresh' => $refresh], JSON_THROW_ON_ERROR);
        } catch (\Throwable $ignored) { return; }

        // Anchor each parent component without following links. Only our exact
        // generated names, single-link regular files, and old use times qualify.
        // Refreshing prospective prompts also protects a concurrent activation
        // and calls already in progress for much longer than the 30-minute cap.
        $program = <<<'PY'
import json, os, re, stat, sys, time
path = sys.argv[1]
manifest = json.loads(sys.argv[2])
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, 'O_NOFOLLOW', 0)
directory = os.open('/', flags)
try:
    for component in [part for part in path.split('/') if part]:
        next_directory = os.open(component, flags, dir_fd=directory)
        os.close(directory)
        directory = next_directory
    keep = set(manifest['keep'])
    for name in manifest['refresh']:
        metadata = os.stat(name, dir_fd=directory, follow_symlinks=False)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            raise SystemExit(2)
        os.utime(name, None, dir_fd=directory, follow_symlinks=False)
    cutoff = time.time() - 86400
    removed = False
    for name in os.listdir(directory):
        if name in keep or not re.fullmatch(r'[0-9a-f]{64}\.wav', name):
            continue
        try:
            metadata = os.stat(name, dir_fd=directory, follow_symlinks=False)
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_mtime >= cutoff:
                continue
            current = os.stat(name, dir_fd=directory, follow_symlinks=False)
            if (current.st_dev, current.st_ino, current.st_nlink, current.st_mtime) != (metadata.st_dev, metadata.st_ino, 1, metadata.st_mtime):
                continue
            os.unlink(name, dir_fd=directory)
            removed = True
        except OSError:
            continue
    if removed:
        os.fsync(directory)
finally:
    os.close(directory)
PY;
        $output = []; $status = 1;
        @exec('/usr/bin/python3 -I -c ' . escapeshellarg($program) . ' ' . escapeshellarg(self::SOUNDS_DIR . '/paging') . ' ' . escapeshellarg($manifest) . ' 2>/dev/null', $output, $status);
    }

    public function myDialplanHooks() { return 500; }

    public function getLivePagingIvrs(): array
    {
        try {
            $db = $this->FreePBX->Database();
            $rows = $db->query('SELECT id, name FROM ivr_details ORDER BY name LIMIT 101')->fetchAll(\PDO::FETCH_ASSOC);
            $statement = $db->prepare('SELECT selection FROM ivr_entries WHERE ivr_id = ?');
            $result = [];
            foreach ($rows as $row) {
                $id = (string)$row['id'];
                if (!preg_match('/^[1-9][0-9]{0,8}$/D', $id)) { continue; }
                $statement->execute([$id]);
                $result[] = ['id'=>$id, 'name'=>(string)$row['name'], 'entries'=>array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN))];
            }
            return $result;
        } catch (\Throwable $error) { return []; }
    }

    public function getLivePagingExternalDestinations(): array
    {
        $settings = $this->getPendingSettings() ?? $this->getActiveSettings();
        $paging = LivePagingConfig::normalize((array)($settings['live_paging'] ?? []));
        if ($paging['enabled'] !== '1' || ($paging['external_access'] ?? '0') !== '1'
            || !array_filter($paging['groups'], static fn($r) => ($r['allow_external'] ?? '0') === '1')) { return []; }
        return [['destination' => 'sls-live-paging-external,s,1',
            'description' => _('SLS external paging · group PIN required')]];
    }

    public function doDialplanHook(&$ext, $engine, $priority)
    {
        if ($engine !== 'asterisk') { return; }
        // Generate the same revision that postReload will activate after Apply Config.
        $settings = $this->getPendingSettings() ?? $this->getActiveSettings();
        if (method_exists($this,'addPanicDialplan')) { $this->addPanicDialplan($ext,$settings); }
        $paging = LivePagingConfig::normalize((array)($settings['live_paging'] ?? []));
        if ($paging['enabled'] !== '1') { return; }
        $this->validateLivePagingSelection($paging, $settings);
        $this->assertLivePagingExtensionAvailable($paging['extension']);
        $this->ensureLivePagingPrompts($settings);
        // A raw local AGI entry intentionally avoids FreePBX's optional FastAGI
        // rewriting: this protected executable reads its own standard AGI pipe.
        $ext->add('sls-live-paging', $paging['extension'], '', new \ext_setvar('AGISIGHUP', 'no'));
        $ext->add('sls-live-paging', $paging['extension'], '', new \extension('AGI(' . self::RUNTIME_DIR . '/sls_mass_notify_live_paging.php)'));
        $ext->add('sls-live-paging', $paging['extension'], '', new \ext_hangup());
        $ext->addInclude('from-internal-additional', 'sls-live-paging');
        if (($paging['external_access'] ?? '0') === '1') {
            // No public pattern or from-trunk include: an administrator must
            // explicitly choose this destination in an IVR or inbound route.
            $ext->add('sls-live-paging-external', 's', '', new \ext_setvar('AGISIGHUP', 'no'));
            $ext->add('sls-live-paging-external', 's', '', new \extension('AGI(' . self::RUNTIME_DIR . '/sls_mass_notify_live_paging.php)'));
            $ext->add('sls-live-paging-external', 's', '', new \ext_hangup());
            foreach ($paging['external_ivr_ids'] as $id) {
                // Add an exact number to the selected IVR. Never expose this
                // through from-trunk or the general external direct-dial route.
                $ext->add('ivr-'.$id, $paging['extension'], '', new \extension('Goto(sls-live-paging-external,s,1)'));
            }
        }
        $ext->add('sls-live-paging-autoanswer', 's', '', new \ext_setvar('SLS_PAGING_TARGET', '${CHANNEL(endpoint)}'));
        $ext->add('sls-live-paging-autoanswer', 's', '', new \ext_gosub(1, 's', 'sls-alert-autoanswer', '${SLS_PAGING_TARGET}'));
        $ext->add('sls-live-paging-autoanswer', 's', '', new \ext_return());
    }
}
