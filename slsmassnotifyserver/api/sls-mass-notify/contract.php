<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/security.php';

if (!class_exists(ControlContract::class, false)) {
/** Read-only API discovery. Recipient projections never contain delivery addresses or secrets. */
final class ControlContract
{
    public static function labsEnabled(array $settings): bool
    {
        $value = $settings['enterprise_labs'] ?? [];
        return is_array($value) && !array_diff(array_keys($value), ['enabled']) && ($value['enabled'] ?? false) === true;
    }

    /** API readers see effective flags; settings storage and SSO stay independent. */
    public static function effectiveLabs(array $settings): array
    {
        if (!self::labsEnabled($settings)) {
            foreach (['enterprise_cluster','directory_sync','subscriber_browser','enterprise_integrations','enterprise_operations'] as $key) {
                if (is_array($settings[$key] ?? null)) { $settings[$key]['enabled'] = $key === 'enterprise_integrations' ? '0' : false; }
            }
        }
        return $settings;
    }

    public static function capabilities(array $principal): array
    {
        $resources = ['status', 'capabilities', 'audiences', 'delivery', 'events', 'readiness', 'metrics', 'desktop_fleet', 'config', 'incidents', 'incident', 'incident_report', 'enterprise_capabilities', 'incident_templates', 'recordings'];
        $actions = ['send_announcement' => 'send', 'preview_announcement' => 'send', 'rehearse_announcement'=>'send', 'retry_announcement' => 'send', 'test_nws' => 'test',
            'trigger_nws_test' => 'test', 'get_config' => 'config', 'update_config' => 'config',
            'start_incident' => 'send', 'update_incident' => 'send', 'incident_roll_call' => 'config', 'incident_checklist' => 'config', 'enterprise_template_start' => 'send'];
        $unrestricted = ($principal['audience']['unrestricted'] ?? false) === true;
        $incidentActions = ['start_incident', 'update_incident', 'incident_roll_call', 'incident_checklist'];
        $available = [];
        foreach ($actions as $action => $scope) {
            $incident = in_array($action, $incidentActions, true);
            if ($action === 'enterprise_template_start' && ($principal['id'] ?? '') === 'legacy') { continue; }
            if (ApiSecurity::permits($principal, $scope) && ($scope !== 'config' || $unrestricted)
                && (!$incident || ($unrestricted && ($principal['id'] ?? '') !== 'legacy'))) {
                $available[] = ['action' => $action, 'scope' => $scope,
                    'idempotency' => $incident || $action === 'enterprise_template_start' ? 'request_id' : ($action==='send_announcement'?'Idempotency-Key':'none'), 'maximum_body_bytes' => $action === 'update_config' ? 2097152 : 65536];
            }
        }
        $availableResources = [];
        foreach ($resources as $resource) {
            if (!ApiSecurity::permitsReadResource($principal, $resource)) { continue; }
            if (in_array($resource, ['config', 'recordings'], true) && !ApiSecurity::permits($principal, 'config')) { continue; }
            if (in_array($resource, ['enterprise_capabilities','incident_templates'], true) && ($principal['id'] ?? '') === 'legacy') { continue; }
            $narrowRead = ($resource === 'readiness' && ApiSecurity::permits($principal, 'monitor'))
                || ($resource === 'desktop_fleet' && ApiSecurity::permits($principal, 'fleet_read'));
            if (!$unrestricted && !$narrowRead && !in_array($resource, ['status', 'capabilities', 'metrics', 'audiences', 'delivery','enterprise_capabilities','incident_templates'], true)) { continue; }
            $availableResources[] = $resource;
        }
        return ['api_version' => 1, 'notification_payload_schema' => 1, 'desktop_sse_protocol' => 2,
            'resources' => $availableResources, 'actions' => $available,
            'credential' => ['id' => (string)($principal['id'] ?? ''), 'scopes' => array_values($principal['scopes'] ?? []), 'unrestricted_audience' => $unrestricted],
            'limits' => ['announcement_idempotency_retention_seconds'=>2592000,'event_page_records' => 100, 'event_page_scan_bytes' => 524288, 'incident_page_records' => 200,
                'saved_audiences'=>ApiSecurity::MAX_SAVED_AUDIENCES,
                'sms_recipients' => 50, 'email_recipients' => 50],
            'paths' => ['control' => '/api/sls-mass-notify/', 'desktop' => '/api/sipnotify/desktop',
                'desktop_acknowledgement' => '/api/sipnotify/desktop/ack', 'desktop_stream' => '/api/sipnotify/desktop/stream',
                'desktop_media'=>'/api/sipnotify/desktop/media', 'desktop_incident'=>'/api/sipnotify/desktop/incident', 'desktop_human_response'=>'/api/sipnotify/desktop/incident/respond',
                'signed_trigger' => '/api/sls-mass-notify/trigger.php', 'sms_callback' => '/api/sls-mass-notify/sms-callback.php']];
    }

    public static function fleet(array $principal,array $settings,array $report): array
    {
        if(!ApiSecurity::permitsReadResource($principal,'desktop_fleet')){throw new \DomainException('Fleet read permission is required.');}
        $unrestricted=($principal['audience']['unrestricted']??false)===true;
        $permitted=[];
        if(!$unrestricted){
            $users=ApiSecurity::allowedAudience($principal,$settings)['desktops'];
            foreach($settings['desktop_clients']??[] as $row){
                if(is_array($row)&&!empty($row['enabled'])&&in_array($row['username']??null,$users,true)){$permitted[$row['client_id']]=true;}
            }
        }
        $result=array_intersect_key($report,array_flip(['schema','generated_at','minimum_version']));$result['clients']=[];
        $fields=array_flip(['name','client_id','username','enabled','last_seen_at','last_seen_ip','connected','last_acknowledged_event',
            'last_acknowledged_at','state','connection','client_version','compatibility','client_reported_at','payload_schema','sse_protocol']);
        foreach(array_slice($report['clients']??[],0,1000) as $row){
            if(is_array($row)&&($unrestricted||isset($permitted[$row['client_id']??'']))){$result['clients'][]=array_intersect_key($row,$fields);}
        }
        return $result;
    }

    /** Feature switches only. Configuration, identity grants and secrets are never discovery data. */
    public static function enterpriseCapabilities(array $principal, array $settings): array
    {
        if (($principal['id'] ?? '') === 'legacy' || !ApiSecurity::permits($principal, 'read')) { throw new \DomainException('A named read credential is required.'); }
        $settings = self::effectiveLabs($settings);
        $enabled = static fn(string $key): bool => self::enabled((array)($settings[$key] ?? []));
        $operations = $enabled('enterprise_operations');
        return ['success'=>true,'schema'=>1,'labs_warning'=>'DO NOT USE ON PRODUCTION SERVERS',
            'features'=>['enterprise_labs'=>self::labsEnabled($settings),'identity'=>$enabled('enterprise_identity'),'directory'=>$enabled('directory_sync'),
                'subscriber_browser'=>$enabled('subscriber_browser'),'integrations'=>$enabled('enterprise_integrations'),
                'cluster'=>$enabled('enterprise_cluster'),'operations'=>$operations,
                'dual_approval'=>$operations && ($settings['enterprise_operations']['dual_approval']['enabled'] ?? false) === true,
                'drills'=>$operations && ($settings['enterprise_operations']['drills']['enabled'] ?? false) === true,
                'floorplans'=>$operations && ($settings['enterprise_operations']['floorplans']['enabled'] ?? false) === true,
                'shift_routing'=>$operations && ($settings['enterprise_operations']['shift_routing']['enabled'] ?? false) === true],
            'resources'=>['incident_templates'=>['scope'=>'read','audience'=>'complete effective delivery and every escalation']],
            'actions'=>['enterprise_template_start'=>['scope'=>'send','idempotency'=>'request_id','requires_enabled'=>'enterprise_operations']],
            'human_actions'=>['approvals'=>'authenticated operator only','drill_reviews'=>'assigned authenticated operator only'],
            'configuration'=>'FreePBX administrator with CSRF and central Labs controls'];
    }

    private static function enabled(array $row): bool
    {
        return in_array($row['enabled'] ?? null, [true, 1, '1'], true);
    }

    public static function announcementInput(array $body): array
    {
        $options = $body['options'] ?? [];
        $allowedOptions = ['style', 'image', 'title', 'background_color', 'audio_mode', 'opening_tone', 'closing_tone',
            'priority', 'desktop_all', 'phones_all', 'desktop_clients', 'voice_recipient_ids', 'email_recipient_ids',
            'sms_recipient_ids', 'webhook_ids', 'browser_recipient_ids','connector_ids','piper_voice', 'tts_volume', 'preview', 'is_test'];
        $allowedBody = array_merge(['action', 'options', 'message', 'body', 'text', 'targets', 'extensions', 'groups',
            'announcement_groups', 'desktop_targets', 'all_desktops', 'all_phones', 'desktop', 'tts'], $allowedOptions);
        if (array_diff(array_keys($body), $allowedBody) || !is_array($options) || ($options && array_is_list($options))
            || array_diff(array_keys($options), $allowedOptions)) {
            throw new \InvalidArgumentException('Announcements accept only documented fields and options.');
        }
        foreach ([$body, $options] as $fields) {
            foreach (['style' => ['standard', 'colored', 'image', 'nws'], 'audio_mode' => ['none', 'tones', 'tts', 'tones_tts']] as $field => $allowed) {
                if (array_key_exists($field, $fields)
                    && (!is_string($fields[$field]) || !in_array(strtolower(trim($fields[$field])), $allowed, true))) {
                    throw new \InvalidArgumentException($field . ' contains an unsupported value.');
                }
            }
            if (array_key_exists('background_color', $fields)
                && (!is_string($fields['background_color']) || !preg_match('/^#[0-9A-Fa-f]{6}$/D', $fields['background_color']))) {
                throw new \InvalidArgumentException('background_color must be a six-digit CSS hex color.');
            }
            foreach (['preview', 'is_test'] as $field) {
                if (array_key_exists($field, $fields) && !is_bool($fields[$field])) {
                    throw new \InvalidArgumentException($field . ' must be a JSON boolean.');
                }
            }
            if (array_key_exists('piper_voice', $fields) && !is_string($fields['piper_voice'])) {
                throw new \InvalidArgumentException('piper_voice must be a saved voice path string.');
            }
            if (array_key_exists('tts_volume', $fields) && (!is_int($fields['tts_volume']) || $fields['tts_volume'] < 1 || $fields['tts_volume'] > 200)) {
                throw new \InvalidArgumentException('tts_volume must be an integer from 1 through 200.');
            }
            foreach (['browser_recipient_ids'=>['/^sub_[a-f0-9]{24}$/D',50],'connector_ids'=>['/^conn_[a-f0-9]{24}$/D',20]] as $field=>[$pattern,$maximum]) {
                if (!array_key_exists($field,$fields)) { continue; }
                $ids=$fields[$field];
                if (!is_array($ids)||!array_is_list($ids)||count($ids)>$maximum) { throw new \InvalidArgumentException($field.' must contain a bounded list of saved IDs.'); }
                foreach ($ids as $id) { if (!is_string($id)||!preg_match($pattern,$id)) { throw new \InvalidArgumentException($field.' contains an invalid saved ID.'); } }
                if (count(array_unique($ids))!==count($ids)) { throw new \InvalidArgumentException($field.' contains duplicate IDs.'); }
            }
            if (array_key_exists('webhook_ids', $fields) && (!is_array($fields['webhook_ids']) || !array_is_list($fields['webhook_ids'])
                || count($fields['webhook_ids']) > 10)) {
                throw new \InvalidArgumentException('webhook_ids must contain at most ten saved identifiers.');
            }
            foreach ($fields['webhook_ids'] ?? [] as $id) {
                if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
                    throw new \InvalidArgumentException('A webhook identifier is invalid.');
                }
            }
        }
        foreach (['webhook_ids', 'piper_voice', 'tts_volume', 'preview'] as $field) {
            if (array_key_exists($field, $body)) { $options[$field] = $body[$field]; unset($body[$field]); }
        }
        $options['_is_test'] = ($body['is_test'] ?? $options['is_test'] ?? false) === true;
        unset($body['is_test'], $options['is_test']);
        if (in_array($body['action'] ?? '', ['preview_announcement','rehearse_announcement'],true)) { $options['preview'] = true; }
        $body['options'] = $options;
        return $body;
    }

    private static function metadata(array $row, string $id): array
    {
        $name = $row['name'] ?? '';
        return ['id' => $id, 'name' => is_string($name) && preg_match('//u', $name) ? mb_strcut($name, 0, 200, 'UTF-8') : ''];
    }

    public static function audiences(array $principal, array $settings): array
    {
        $unrestricted = ($principal['audience']['unrestricted'] ?? false) === true;
        $allowed = ApiSecurity::allowedAudience($principal, $settings);
        $result = ['phone_targets' => [], 'desktop_clients' => [], 'announcement_groups' => [], 'nws_zones' => [],
            'voice_recipients' => [], 'email_recipients' => [], 'sms_recipients' => [], 'webhooks' => [], 'browser_recipients' => [], 'connectors' => []];
        $phones = $unrestricted ? (array)($settings['alert_recipients'] ?? []) : $allowed['phones'];
        foreach (['announcement_groups', 'nws_zones'] as $collection) {
            foreach ((array)($settings[$collection] ?? []) as $row) {
                if (!is_array($row) || !is_string($row['id'] ?? null)) { continue; }
                $permission = $collection === 'announcement_groups' ? 'announcement_group_ids' : 'nws_zone_ids';
                if (!$unrestricted && !in_array($row['id'], $principal['audience'][$permission] ?? [], true)) { continue; }
                if ($collection === 'announcement_groups' && (!ApiSecurity::groupDesktopBindingsValid($row, $settings) || !ApiSecurity::groupLabsBindingsValid($row, $settings))) { continue; }
                $result[$collection][] = self::metadata($row, $row['id']);
                if ($unrestricted) { $phones = array_merge($phones, (array)($row['extensions'] ?? [])); }
            }
        }
        if ($unrestricted) {
            foreach ((array)($settings['xweather']['groups'] ?? []) as $row) {
                if (is_array($row)) { $phones = array_merge($phones, (array)($row['extensions'] ?? [])); }
            }
        }
        foreach ($phones as $phone) {
            if ((is_string($phone) || is_int($phone)) && preg_match('/^[0-9]{1,20}$/D', (string)$phone)) { $result['phone_targets'][(string)$phone] = (string)$phone; }
        }
        $result['phone_targets'] = array_values($result['phone_targets']);
        sort($result['phone_targets'], SORT_STRING);
        foreach ((array)($settings['desktop_clients'] ?? []) as $row) {
            if (!is_array($row) || !self::enabled($row) || !is_string($row['client_id'] ?? null) || !is_string($row['username'] ?? null)
                || (!$unrestricted && !in_array($row['username'], $allowed['desktops'], true))) { continue; }
            $result['desktop_clients'][] = self::metadata($row, $row['client_id']) + ['username' => $row['username']];
        }
        foreach (['voice_recipients' => ['outbound_voice', 'voice_recipient_ids'], 'email_recipients' => ['announcement_email', 'email_recipient_ids'],
            'sms_recipients' => ['announcement_sms', 'sms_recipient_ids']] as $output => [$section, $permission]) {
            if (!in_array($settings[$section]['enabled'] ?? null, [true, 1, '1'], true)) { continue; }
            foreach ((array)($settings[$section]['recipients'] ?? []) as $row) {
                if (!is_array($row) || !self::enabled($row) || !is_string($row['id'] ?? null)
                    || (!$unrestricted && !in_array($row['id'], $allowed[$permission], true))) { continue; }
                if ($output === 'sms_recipients' && ($row['consent'] ?? null) !== true) { continue; }
                $result[$output][] = self::metadata($row, $row['id']);
            }
        }
        foreach ((array)($settings['announcement_webhooks'] ?? []) as $row) {
            if (!is_array($row) || !self::enabled($row) || !is_string($row['id'] ?? null)
                || (!$unrestricted && !in_array($row['id'], $allowed['webhooks'], true))) { continue; }
            $result['webhooks'][] = self::metadata($row, $row['id']);
        }
        if (($principal['id']??'')!=='legacy') {
            foreach (ApiSecurity::labsDirectory($settings) as $field=>$rows) {
                $output=$field==='browser_recipient_ids' ? 'browser_recipients' : 'connectors';
                foreach ($rows as $id=>$row) {
                    if ($row['available'] && ($unrestricted || in_array($id,$allowed[$field],true))) {
                        $result[$output][]=['id'=>$id,'name'=>$row['label']];
                    }
                }
            }
        }
        return $result;
    }
}
}
