<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__ . '/LocationDirectory.php';
require_once __DIR__ . '/IncidentConfig.php';
require_once __DIR__ . '/api/sls-mass-notify/security.php';
require_once __DIR__ . '/bin/sls_mass_notify/sls_live_paging.php';
use SLS\MassNotify\LocationDirectory;
use SLS\MassNotify\GeographicTargeting;

trait SlsLocations
{
    /** Deliberately project public recipient fields, never desktop/provider secrets. */
    protected function locationCatalog(array $settings): array
    {
        $catalog = array_fill_keys(array_keys(LocationDirectory::MEMBERS), []);
        foreach ($this->getAllPjsipExtensions() as $row) {
            $id = (string)$row['extension'];
            $catalog['extensions'][$id] = ['id' => $id, 'label' => $id . ' · ' . ($row['name'] ?? $id), 'available' => true];
        }
        foreach ($settings['desktop_clients'] ?? [] as $row) {
            $id = (string)$row['client_id'];
            $catalog['desktop_client_ids'][$id] = ['id' => $id, 'label' => ($row['name'] ?? $row['username']) . ' · ' . $row['username'],
                'username' => $row['username'], 'available' => !empty($row['enabled']), 'reason' => 'Desktop is disabled in General Settings.'];
        }
        $enabled = [
            'voice_recipient_ids' => array_column($this->getOutboundVoiceRecipients($settings), null, 'id'),
            'email_recipient_ids' => array_column($this->getAnnouncementEmailRecipients($settings), null, 'id'),
            'sms_recipient_ids' => array_column($this->getAnnouncementSmsRecipients($settings), null, 'id'),
        ];
        foreach (['voice_recipient_ids' => 'outbound_voice', 'email_recipient_ids' => 'announcement_email', 'sms_recipient_ids' => 'announcement_sms'] as $key => $source) {
            foreach ($settings[$source]['recipients'] ?? [] as $row) {
                $id = (string)$row['id'];
                $catalog[$key][$id] = ['id' => $id, 'label' => ($row['name'] ?? $id) . ' · ' . ($row['number'] ?? $row['address'] ?? ''),
                    'available' => isset($enabled[$key][$id]), 'reason' => 'Recipient or channel is disabled, or SMS consent is missing. Review General Settings.'];
            }
        }
        foreach ($settings['announcement_webhooks'] ?? [] as $row) {
            $id = (string)$row['id'];
            $catalog['webhook_ids'][$id] = ['id' => $id, 'label' => (string)$row['name'], 'available' => !empty($row['enabled']),
                'reason' => 'This webhook is disabled. Review its delivery provider settings.'];
        }
        foreach ($catalog as &$choices) { ksort($choices, SORT_STRING); } unset($choices);
        return $catalog;
    }

    private function locationSettings(): array { return $this->getPendingSettings() ?? $this->getActiveSettings(); }

    private function locationState(array $settings): array
    {
        $directory = LocationDirectory::effective(array_key_exists('location_directory', $settings) ? $settings['location_directory'] : []);
        $catalog = $this->locationCatalog($settings);
        // A stale browser must not overwrite a directory edit or copy an audience
        // after identities, channel availability, or existing groups changed.
        $revision = hash('sha256', json_encode([$directory, $catalog, $settings['announcement_groups'] ?? [],
            $settings['outbound_voice']['recipients'] ?? [], $settings['announcement_email']['recipients'] ?? [], $settings['announcement_sms']['recipients'] ?? []], JSON_THROW_ON_ERROR));
        return ['directory' => $directory, 'catalog' => $catalog, 'revision' => $revision,
            'audiences' => $this->locationAudienceCards($settings),
            'positions' => GeographicTargeting::positions($directory), 'observed_at' => time(),
            'weather_routes' => array_merge(
                array_map(static function($g){return ['site_id'=>$g['site_id']??'', 'name'=>$g['name']??'Weather', 'coverage'=>$g['zone']??''];}, $settings['nws_zones']??[]),
                array_map(static function($g){return ['site_id'=>$g['site_id']??'', 'name'=>$g['name']??'Lightning', 'coverage'=>$g['location']??''];}, $settings['xweather']['groups']??[])),
            'group_count' => count($settings['announcement_groups'] ?? []), 'max_locations' => LocationDirectory::MAX_NODES];
    }

    public function getAudiencePickerChoices(): array
    {
        require_once __DIR__ . '/AudiencePicker.php';
        return \SLS\MassNotify\AudiencePicker::choices($this->getActiveSettings());
    }

    public function getLocationDirectoryState(): array { return $this->locationState($this->locationSettings()); }

    private function locationAudienceCards(array $settings): array
    {
        $desktops = array_column($settings['desktop_clients'] ?? [], 'client_id', 'username');
        $result = [];
        foreach ($settings['announcement_groups'] ?? [] as $group) {
            $members = array_fill_keys(array_keys(LocationDirectory::MEMBERS), []);
            foreach ($members as $key => $_) { if ($key !== 'desktop_client_ids') { $members[$key] = $group[$key] ?? []; } }
            $bindings = $group['location_snapshot']['desktop_bindings'] ?? $group['desktop_bindings'] ?? null;
            foreach ($group['desktop_clients'] ?? [] as $username) {
                $id = $bindings === null ? ($desktops[$username] ?? null) : (array_column($bindings, 'client_id', 'username')[$username] ?? null);
                // Preserve missing identities for explicit correction in the editor.
                $members['desktop_client_ids'][] = $id ?? ('missing_' . substr(hash('sha256', $username), 0, 24));
            }
            $result[] = ['id' => $group['id'], 'name' => $group['name'], 'members' => $members,
                'reviewed_location' => $group['location_snapshot']['path'] ?? '',
                'identities_current' => \SLS\MassNotify\ApiSecurity::groupDesktopBindingsValid($group, $settings)];
        }
        return $result;
    }

    /** A person or team is a reusable set of saved device identities. */
    public function saveLocationAudience(array $input): array
    {
        return $this->locationResult(function () use ($input): array {
            [$settings, $state] = $this->locationRequest($input, ['id', 'name', 'members']);
            $id = $input['id'] ?? null;
            if (!is_string($id) || ($id !== '' && !preg_match('/^grp_[a-f0-9]{12,32}$/D', $id))) { throw new \InvalidArgumentException('The saved audience identifier is invalid. Reload Locations.'); }
            $groups = $settings['announcement_groups'] ?? []; $existing = null;
            foreach ($groups as $index => $group) { if ($group['id'] === $id) { $existing = $index; break; } }
            if ($id !== '' && $existing === null) { throw new \InvalidArgumentException('This audience was removed. Reload Locations.'); }
            if ($existing !== null && isset($groups[$existing]['location_snapshot'])) { throw new \InvalidArgumentException('This audience records a reviewed location selection. Create a new snapshot from the Directory tab to change its members.'); }
            if ($existing === null && count($groups) >= 20) { throw new \InvalidArgumentException('All 20 saved audiences are in use. Remove an unused audience first.'); }
            $name = LocationDirectory::text($input['name'] ?? null, 64, 'Audience name');
            foreach ($groups as $group) { if ($group['id'] !== $id && strcasecmp($group['name'], $name) === 0) { throw new \InvalidArgumentException('Another audience uses this name. Choose a distinct name.'); } }
            $members = LocationDirectory::node(['id' => LocationDirectory::DEFAULT_SITE_ID, 'type' => 'site', 'name' => $name, 'members' => $input['members'] ?? null])['members'];
            if (!array_sum(array_map('count', $members))) { throw new \InvalidArgumentException('Select at least one saved recipient or webhook for this audience.'); }
            foreach ($members as $channel => $ids) {
                foreach ($ids as $target) {
                    if (empty($state['catalog'][$channel][$target]['available'])) { throw new \InvalidArgumentException('A selected ' . $channel . ' recipient is missing, disabled, or not ready. Review the selection and recipient settings before saving.'); }
                }
            }
            $desktops = []; $bindings = [];
            foreach ($members['desktop_client_ids'] as $clientId) {
                $username = $state['catalog']['desktop_client_ids'][$clientId]['username'];
                $desktops[] = $username; $bindings[] = ['username' => $username, 'client_id' => $clientId];
            }
            $id = $id ?: 'grp_' . bin2hex(random_bytes(12));
            $candidate = ['id' => $id, 'name' => $name, 'extensions' => $members['extensions'], 'desktop_clients' => $desktops,
                'voice_recipient_ids' => $members['voice_recipient_ids'], 'email_recipient_ids' => $members['email_recipient_ids'],
                'sms_recipient_ids' => $members['sms_recipient_ids'], 'webhook_ids' => $members['webhook_ids'],
                'desktop_bindings' => LocationDirectory::bindings($bindings, $desktops)];
            if ($existing === null) { $groups[] = $candidate; } else { $groups[$existing] = $candidate; }
            \SLS\MassNotify\LivePagingConfig::resolvedGroups(\SLS\MassNotify\LivePagingConfig::normalize($settings['live_paging'] ?? []), $groups);
            $settings['announcement_groups'] = $groups;
            $this->persistPendingSettings($settings);
            return ['success' => true, 'group_id' => $id, 'state' => $this->locationState($settings),
                'message' => 'Audience saved. Apply Config to activate its devices. Alerts already queued keep their original recipients.'];
        });
    }

    public function deleteLocationAudience(array $input): array
    {
        return $this->locationResult(function () use ($input): array {
            [$settings, $state] = $this->locationRequest($input, ['id']);
            $groups = $settings['announcement_groups'] ?? [];
            if (!is_string($input['id'] ?? null) || !in_array($input['id'], array_column($groups, 'id'), true)) { throw new \InvalidArgumentException('This audience was removed. Reload Locations.'); }
            foreach ($settings['live_paging']['groups'] ?? [] as $group) {
                if (($group['group_id'] ?? '') === $input['id']) { throw new \InvalidArgumentException('A dial-in paging group still uses this audience. Change that paging group before removing the audience.'); }
            }
            foreach ($settings['scheduled_announcements'] ?? [] as $schedule) {
                if (in_array($input['id'], $schedule['targets']['groups'] ?? [], true)) { throw new \InvalidArgumentException('A saved schedule still uses this audience. Update that schedule before removing the audience.'); }
            }
            foreach ($settings['incident_workflows']['templates'] ?? [] as $template) {
                $references = $template['delivery']['group_ids'] ?? [];
                foreach (\SLS\MassNotify\IncidentConfig::escalationSteps($template['escalation'] ?? []) as $step) { $references = array_merge($references, $step['delivery']['group_ids'] ?? []); }
                if (in_array($input['id'], $references, true)) { throw new \InvalidArgumentException('An incident template or escalation step still uses this audience. Update that template before removing the audience.'); }
            }
            $settings['announcement_groups'] = array_values(array_filter($groups, static fn(array $group): bool => $group['id'] !== $input['id']));
            $this->persistPendingSettings($settings);
            return ['success' => true, 'state' => $this->locationState($settings), 'message' => 'Audience removal saved. Apply Config to activate it. Queued alerts and delivery history were preserved.'];
        });
    }

    public function renderLocationsPage(): string
    {
        return load_view(__DIR__ . '/views/locations.php', ['state' => $this->getLocationDirectoryState(),
            'settings' => $this->locationSettings(),
            'announcement_sms_usage' => $this->getAnnouncementSmsUsage(),
            'has_pending_changes' => $this->getPendingSettings() !== null, 'hero_image' => self::HERO_IMAGE,
            'csrf_token' => $this->getCsrfToken()]);
    }

    public function saveLocationRecipients(array $input): array
    {
        return $this->locationResult(function () use ($input): array {
            [$settings, $state] = $this->locationRequest($input, ['voice_json', 'email_json', 'sms_json']);
            foreach (['voice_json', 'email_json', 'sms_json'] as $field) {
                if (!is_string($input[$field] ?? null) || strlen($input[$field]) > ($field === 'voice_json' ? 524288 : 65536)) { throw new \InvalidArgumentException('A recipient editor is incomplete or too large. Reload Locations before saving.'); }
                if (!str_starts_with(ltrim($input[$field]), '[')) { throw new \InvalidArgumentException('Each recipient editor must submit a complete JSON list. Reload Locations before saving.'); }
            }
            $voice = $settings['outbound_voice'] ?? $this->defaultOutboundVoice();
            try { $voice['recipients'] = json_decode($input['voice_json'], true, 8, JSON_THROW_ON_ERROR); }
            catch (\JsonException $error) { throw new \InvalidArgumentException('The external voice recipient list contains invalid JSON.'); }
            $settings['outbound_voice'] = $this->normalizeOutboundVoice($voice, true);
            $email = $settings['announcement_email'] ?? $this->defaultAnnouncementEmail();
            $settings['announcement_email'] = $this->readAnnouncementEmailForm(['announcement_email_present' => '1',
                'announcement_email_complete' => '1', 'announcement_email_enabled' => $email['enabled'],
                'announcement_email_recipients_json' => $input['email_json']], $email);
            $sms = $settings['announcement_sms'] ?? $this->defaultAnnouncementSms();
            $settings['announcement_sms'] = $this->readAnnouncementSmsForm(['announcement_sms_present' => '1',
                'announcement_sms_complete' => '1', 'announcement_sms_enabled' => !empty($sms['enabled']) ? '1' : '0',
                'announcement_sms_recipients_json' => $input['sms_json']], $sms);
            $this->persistPendingSettings($settings);
            return ['success' => true, 'state' => $this->locationState($settings),
                'message' => 'Recipients saved. Apply Config to activate them. Provider credentials, routing and delivery limits were preserved.'];
        });
    }

    private function locationRequest(array $input, array $allowed): array
    {
        if (array_diff(array_keys($input), array_merge(['revision'], $allowed))) { throw new \InvalidArgumentException('The location request contains unsupported fields. Reload Locations.'); }
        $settings = $this->locationSettings();
        if (!$this->isSetupComplete($settings)) { throw new \InvalidArgumentException($this->getSetupRequiredMessage()); }
        $state = $this->locationState($settings);
        if (!is_string($input['revision'] ?? null) || !hash_equals($state['revision'], $input['revision'])) {
            throw new \InvalidArgumentException('Locations, recipients, or audience groups changed while this page was open. Reload Locations and review your selection again.');
        }
        return [$settings, $state];
    }

    private function locationResult(callable $work): array
    {
        try { return $work(); }
        catch (\InvalidArgumentException | \DomainException $error) { return ['success' => false, 'message' => $error->getMessage()]; }
        catch (\Throwable $error) {
            return ['success' => false, 'reload_required' => true,
                'message' => 'Location settings could not confirm a durable save. Reload Locations before retrying; check concurrent edits, protected configuration permissions, and free storage.'];
        }
    }

    public function saveLocation(array $input): array
    {
        return $this->locationResult(function () use ($input): array {
            [$settings, $state] = $this->locationRequest($input, ['node', 'position_reviewed']);
            $node = $input['node'] ?? null;
            if (!is_array($node)) { throw new \InvalidArgumentException('Location details are missing.'); }
            $reviewed = array_key_exists('position_reviewed', $input) ? $input['position_reviewed'] : false;
            if (!is_bool($reviewed)) { throw new \InvalidArgumentException('Coordinate review confirmation must be true or false.'); }
            if ($reviewed) {
                if (!isset($node['position']) || !is_array($node['position'])) { throw new \InvalidArgumentException('Enter latitude and longitude before confirming their review.'); }
                $node['position']['reviewed_at'] = time();
            }
            $isNew = ($node['id'] ?? '') === '';
            if ($isNew) { $node['id'] = 'loc_' . bin2hex(random_bytes(12)); }
            $node = LocationDirectory::node($node); $old = null; $rows = $state['directory']['nodes'];
            foreach ($rows as $index => $row) { if ($row['id'] === $node['id']) { $old = $row; $rows[$index] = $node; break; } }
            if (!$isNew && $old === null) { throw new \InvalidArgumentException('This location was removed. Reload Locations.'); }
            if (!$reviewed && isset($node['position']) && $node['position'] !== ($old['position'] ?? null)) {
                throw new \InvalidArgumentException('Confirm that you have reviewed these coordinates before saving a new or changed position.');
            }
            foreach ($node['members'] as $channel => $members) {
                foreach ($members as $id) {
                    if (!isset($state['catalog'][$channel][$id]) && !in_array($id, $old['members'][$channel] ?? [], true)) {
                        throw new \InvalidArgumentException('Recipient ' . $id . ' does not exist in General Settings. Select a saved recipient.');
                    }
                }
            }
            if ($isNew) { $rows[] = $node; }
            $settings['location_directory'] = LocationDirectory::normalize(array_replace($state['directory'], ['nodes' => $rows]));
            $this->persistPendingSettings($settings);
            return ['success' => true, 'location_id' => $node['id'], 'state' => $this->locationState($settings),
                'message' => 'Location saved to pending configuration. Apply Config to activate it. Existing audience groups and queued alerts retain their saved recipients.'];
        });
    }

    public function deleteLocation(array $input): array
    {
        return $this->locationResult(function () use ($input): array {
            [$settings, $state] = $this->locationRequest($input, ['id']); $id = LocationDirectory::id($input['id'] ?? null);
            $rows = array_column($state['directory']['nodes'], null, 'id');
            if (!isset($rows[$id])) { throw new \InvalidArgumentException('This location was removed. Reload Locations.'); }
            if ($state['directory']['default_site_id'] === $id) { throw new \InvalidArgumentException('Choose another default site before removing this one. Rename the default site if you only need to change its name.'); }
            foreach ($rows as $row) { if ($row['parent_id'] === $id) { throw new \InvalidArgumentException('Move or remove the child locations first. A parent cannot be removed with children still assigned.'); } }
            unset($rows[$id]); $settings['location_directory'] = LocationDirectory::normalize(array_replace($state['directory'], ['nodes' => array_values($rows)]));
            $this->persistPendingSettings($settings);
            return ['success' => true, 'state' => $this->locationState($settings),
                'message' => 'Location removal saved. Apply Config to activate it. Saved audience groups and delivery records were preserved.'];
        });
    }

    public function setDefaultSite(array $input): array
    {
        return $this->locationResult(function () use ($input): array {
            [$settings, $state] = $this->locationRequest($input, ['id']);
            $settings['location_directory'] = LocationDirectory::normalize(array_replace($state['directory'], ['default_site_id' => $input['id']]));
            $this->persistPendingSettings($settings);
            return ['success' => true, 'state' => $this->locationState($settings),
                'message' => 'Default site saved. Apply Config to activate it. Existing alert routes are unchanged.'];
        });
    }

    private function locationAudiencePreview(array $input, array $state, bool $geographic = false): array
    {
        $descendants = $input['include_descendants'] ?? null; $channels = $input['channels'] ?? null;
        if ((!$geographic && !is_bool($descendants)) || !is_array($channels) || !array_is_list($channels) || !$channels
            || count($channels) > count(LocationDirectory::MEMBERS) || count(array_unique($channels, SORT_REGULAR)) !== count($channels)) {
            throw new \InvalidArgumentException('Select recipient types and whether child locations are included.');
        }
        foreach ($channels as $channel) {
            if (!is_string($channel) || !array_key_exists($channel, LocationDirectory::MEMBERS)) { throw new \InvalidArgumentException('Select supported location recipient types.'); }
        }
        $id = $geographic ? '' : LocationDirectory::id($input['id'] ?? null);
        $preview = $geographic ? GeographicTargeting::audience($state['directory'], $input['selection'] ?? null, $state['catalog'], time())
            : LocationDirectory::audience($state['directory'], $id, $descendants, $state['catalog']);
        foreach ($preview['members'] as $channel => &$members) { if (!in_array($channel, $channels, true)) { $members = []; } } unset($members);
        $preview['unavailable'] = array_values(array_filter($preview['unavailable'], static fn(array $row): bool => in_array($row['channel'], $channels, true)));
        $preview['token'] = hash('sha256', json_encode([$state['revision'], $id, $descendants, $channels, $preview], JSON_THROW_ON_ERROR));
        return $preview;
    }

    public function previewLocationAudience(array $input): array
    {
        return $this->locationResult(function () use ($input): array {
            [, $state] = $this->locationRequest($input, ['id', 'include_descendants', 'channels']);
            return ['success' => true, 'preview' => $this->locationAudiencePreview($input, $state)];
        });
    }

    public function createLocationAudienceGroup(array $input): array
    {
        return $this->createReviewedLocationGroup($input, false);
    }

    public function previewGeographicAudience(array $input): array
    {
        return $this->locationResult(function () use ($input): array {
            [, $state] = $this->locationRequest($input, ['selection', 'channels']);
            return ['success' => true, 'preview' => $this->locationAudiencePreview($input, $state, true)];
        });
    }

    public function createGeographicAudienceGroup(array $input): array
    {
        return $this->createReviewedLocationGroup($input, true);
    }

    private function createReviewedLocationGroup(array $input, bool $geographic): array
    {
        return $this->locationResult(function () use ($input, $geographic): array {
            $allowed = array_merge($geographic ? ['selection', 'exclusions_reviewed'] : ['id', 'include_descendants'], ['channels', 'preview_token', 'name']);
            [$settings, $state] = $this->locationRequest($input, $allowed);
            $preview = $this->locationAudiencePreview($input, $state, $geographic);
            if (!is_string($input['preview_token'] ?? null) || !hash_equals($preview['token'], $input['preview_token'])) {
                throw new \InvalidArgumentException('Preview the current audience before creating its group.');
            }
            if ($geographic && ($input['exclusions_reviewed'] ?? null) !== true) {
                throw new \InvalidArgumentException('Review the included and excluded locations, then confirm that review before creating the geographic audience.');
            }
            if ($preview['unavailable']) { throw new \InvalidArgumentException('The reviewed audience contains unavailable recipients. Correct their assignments or choose different recipient types before creating a group.'); }
            if (array_sum(array_map('count', $preview['members'])) === 0) { throw new \InvalidArgumentException('The selected locations have no recipients of the selected types. Assign recipients first.'); }
            if ($state['group_count'] >= 20) { throw new \InvalidArgumentException('Announcement groups are limited to 20. Remove an unused group from the Dashboard before creating another.'); }
            $name = LocationDirectory::text($input['name'] ?? null, 64, 'Audience group name');
            foreach ($settings['announcement_groups'] ?? [] as $group) { if (strcasecmp($group['name'], $name) === 0) { throw new \InvalidArgumentException('An audience group already uses that name. Choose a distinct name.'); } }
            $members = $preview['members']; $bindings = []; $desktops = [];
            foreach ($members['desktop_client_ids'] as $clientId) {
                $username = $state['catalog']['desktop_client_ids'][$clientId]['username'];
                $desktops[] = $username; $bindings[] = ['username' => $username, 'client_id' => $clientId];
            }
            $id = 'grp_' . bin2hex(random_bytes(12));
            $snapshot = ['schema' => $geographic ? 2 : 1, 'path' => $preview['path'], 'created_at' => time(),
                'directory_revision' => $state['revision'], 'desktop_bindings' => $bindings];
            if ($geographic) {
                $snapshot['geographic'] = ['selection' => $preview['selection'], 'locations' => array_map(
                    static fn(array $row): array => ['id' => $row['id'], 'position' => $row['position']], $preview['included_locations'])];
            } else { $snapshot += ['location_id' => $input['id'], 'include_descendants' => $input['include_descendants']]; }
            $group = ['id' => $id, 'name' => $name, 'extensions' => $members['extensions'], 'desktop_clients' => $desktops,
                'voice_recipient_ids' => $members['voice_recipient_ids'], 'email_recipient_ids' => $members['email_recipient_ids'], 'sms_recipient_ids' => $members['sms_recipient_ids'],
                'webhook_ids' => $members['webhook_ids'],
                'location_snapshot' => LocationDirectory::snapshot($snapshot, $desktops)];
            $settings['announcement_groups'][] = $group;
            $this->persistPendingSettings($settings);
            return ['success' => true, 'group_id' => $id, 'state' => $this->locationState($settings),
                'message' => 'Audience group created in pending configuration. Apply Config, then select it on the Dashboard, in a schedule, or in an incident template. Location edits will not change this group. No announcement was sent.'];
        });
    }
}
