<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/IncidentConfig.php';
require_once __DIR__ . '/LocationDirectory.php';

/** Configuration only. Remote requests cannot choose recipients or executable paths. */
final class AutomationConfig
{
    public static function defaults(): array { return ['schema'=>1, 'rules'=>[], 'actions'=>[]]; }
    public static function id($value, string $prefix): string
    {
        return IncidentConfig::identifier($value, '/^' . $prefix . '_[a-f0-9]{24}$/D', 'Trigger/action identifier');
    }
    private static function integer($value, int $min, int $max, string $label): int
    {
        if (!is_int($value) || $value < $min || $value > $max) { throw new \InvalidArgumentException("$label must be $min–$max whole numbers."); }
        return $value;
    }
    public static function action($value): array
    {
        $row = IncidentConfig::object($value, ['id','name','enabled','kind','host','port','message','scheme','led','clear','path','sha256'], 'Trigger action');
        $out = ['id'=>self::id($row['id'] ?? '', 'act'), 'name'=>IncidentConfig::text($row['name'] ?? '', 80, 'Action name'),
            'enabled'=>IncidentConfig::flag($row['enabled'] ?? false, 'Action enabled'), 'kind'=>$row['kind'] ?? ''];
        if ($out['kind'] === 'script') {
            $out['path'] = IncidentConfig::identifier($row['path'] ?? '', '/^\/(?:[A-Za-z0-9_.-]+\/)*[A-Za-z0-9_.-]+\.(?:sh|js)$/D', 'Absolute script path');
            if (str_contains($out['path'], '/./') || str_contains($out['path'], '/../') || strlen($out['path']) > 512) { throw new \InvalidArgumentException('Use a canonical absolute script path without relative components.'); }
            $out['sha256'] = IncidentConfig::identifier($row['sha256'] ?? '', '/^[a-f0-9]{64}$/D', 'Approved script SHA-256');
        } elseif (in_array($out['kind'], ['brightsign_udp', 'patlite_nhv'], true)) {
            $host = $row['host'] ?? '';
            if (!is_string($host) || !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || !preg_match('/^(?:10\.|192\.168\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/', $host)
                || in_array((int)explode('.', $host)[3], [0,255], true)) {
                throw new \InvalidArgumentException('Enter the device’s private unicast IPv4 address. Public, loopback, broadcast and multicast targets are not supported.');
            }
            $out['host'] = $host;
            $out['port'] = self::integer($row['port'] ?? ($out['kind'] === 'brightsign_udp' ? 5000 : 443), 1, 65535, 'Device port');
            if ($out['kind'] === 'brightsign_udp') {
                $out['message'] = IncidentConfig::identifier($row['message'] ?? '', '/^[A-Za-z0-9_.: -]{1,128}$/D', 'BrightSign presentation event');
            } else {
                $out['scheme'] = $row['scheme'] ?? 'https';
                if (!in_array($out['scheme'], ['https','http'], true)) { throw new \InvalidArgumentException('Choose HTTPS or HTTP for the local PATLITE device.'); }
                $out['led'] = IncidentConfig::identifier($row['led'] ?? '10000', '/^[012]{5}$/D', 'PATLITE LED pattern (five digits: 0 off, 1 steady, 2 flashing)');
                $out['clear'] = IncidentConfig::flag($row['clear'] ?? false, 'PATLITE clear command');
            }
        } else { throw new \InvalidArgumentException('Choose BrightSign UDP, PATLITE NHV/NHB, or a local script.'); }
        if (array_diff(array_keys($row), array_keys($out))) { throw new \InvalidArgumentException('This action contains fields for a different action type.'); }
        return $out;
    }
    public static function rule($value): array
    {
        $row = IncidentConfig::object($value, ['id','name','enabled','kind','location_id','template_id','fields','action_ids','secret','event','callers','numbers','feed_url','sender','allow_tests','max_age_seconds','cooldown_seconds','dial_extension','confirmation_prompt'], 'Trigger rule');
        $out = ['id'=>self::id($row['id'] ?? '', 'trg'), 'name'=>IncidentConfig::text($row['name'] ?? '', 80, 'Trigger name'),
            'enabled'=>IncidentConfig::flag($row['enabled'] ?? false, 'Trigger enabled'), 'kind'=>$row['kind'] ?? '',
            'location_id'=>IncidentConfig::identifier($row['location_id'] ?? '', '/^(?:loc_[a-f0-9]{24})?$/D', 'Assigned location'),
            'template_id'=>IncidentConfig::identifier($row['template_id'] ?? '', '/^(?:tpl_[a-f0-9]{24})?$/D', 'Incident template'),
            'fields'=>IncidentConfig::object($row['fields'] ?? [], is_array($row['fields'] ?? []) ? array_keys($row['fields'] ?? []) : [], 'Template field bindings'),
            'action_ids'=>[], 'allow_tests'=>IncidentConfig::flag($row['allow_tests'] ?? false, 'Allow test events'),
            'max_age_seconds'=>self::integer($row['max_age_seconds'] ?? 300, 30, 600, 'Maximum event age in seconds'),
            'cooldown_seconds'=>self::integer($row['cooldown_seconds'] ?? 60, 10, 3600, 'Trigger cooldown in seconds')];
        if (count($out['fields']) > 12) { throw new \InvalidArgumentException('A trigger supports up to 12 template field bindings.'); }
        foreach ($out['fields'] as $key=>&$binding) {
            IncidentConfig::identifier($key, '/^[a-z][a-z0-9_]{0,31}$/D', 'Template field');
            $binding = IncidentConfig::text($binding, 200, 'Field binding');
            if (str_starts_with($binding, '@') && !in_array($binding, ['@location','@event','@message','@caller'], true)) { throw new \InvalidArgumentException('Field bindings support @location, @event, @message, @caller or fixed text.'); }
        } unset($binding);
        foreach (IncidentConfig::listOf($row['action_ids'] ?? [], 10, 'Trigger actions') as $id) { $out['action_ids'][]=self::id($id,'act'); }
        if (count($out['action_ids']) !== count(array_unique($out['action_ids']))) { throw new \InvalidArgumentException('Select each action only once.'); }
        if ($out['template_id'] === '' && !$out['action_ids']) { throw new \InvalidArgumentException('Choose an incident template or at least one device/script action.'); }
        if (in_array($out['kind'], ['panic','sensor'], true)) {
            // Random per-device enrollment secret; sensor requests use it for HMAC.
            $out['secret']=IncidentConfig::identifier($row['secret'] ?? '', '/^[a-f0-9]{64}$/D', 'Enrollment secret');
            if ($out['kind'] === 'sensor') { $out['event']=IncidentConfig::text($row['event'] ?? '', 80, 'Exact sensor event name'); }
            if ($out['kind'] === 'panic' && $out['location_id'] === '') { throw new \InvalidArgumentException('Assign and review a location for each panic identity.'); }
            if ($out['kind']==='panic') {
                $out['dial_extension']=IncidentConfig::identifier($row['dial_extension'] ?? '', '/^[0-9]{0,20}$/D','Panic activation extension');
                $out['confirmation_prompt']=IncidentConfig::text($row['confirmation_prompt'] ?? 'Emergency alert activation. Press one to send the configured alert. Hang up to cancel.',300,'Panic confirmation prompt');
                $out['callers']=[];
                foreach (IncidentConfig::listOf($row['callers'] ?? [],100,'Panic caller extensions') as $caller) { $out['callers'][]=IncidentConfig::identifier($caller,'/^[0-9]{1,20}$/D','Panic caller extension'); }
                $out['callers']=array_values(array_unique($out['callers']));
                if ($out['dial_extension']!=='' && !$out['callers']) { throw new \InvalidArgumentException('A panic dial-in extension requires explicit authorized PJSIP callers.'); }
            }

        } elseif ($out['kind'] === 'emergency_call') {
            foreach (['callers','numbers'] as $key) {
                $out[$key]=[];
                foreach (IncidentConfig::listOf($row[$key] ?? [], 100, $key) as $item) { $out[$key][]=IncidentConfig::identifier($item,'/^[0-9]{1,20}$/D', $key); }
                $out[$key]=array_values(array_unique($out[$key]));
                if (!$out[$key]) { throw new \InvalidArgumentException('Emergency-call observation requires explicit caller extensions and exact dialled numbers. No emergency number is assumed.'); }
            }
            if ($out['location_id'] === '') { throw new \InvalidArgumentException('Assign a location to the configured emergency-call extensions.'); }
        } elseif ($out['kind'] === 'cap') {
            if ($out['max_age_seconds']<300) { throw new \InvalidArgumentException('CAP feeds require a 300–600 second freshness window for bounded minute polling.'); }
            $out['feed_url']=IncidentConfig::text($row['feed_url'] ?? '',2048,'Trusted CAP feed URL');
            $parts=parse_url($out['feed_url']);
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !filter_var($out['feed_url'], FILTER_VALIDATE_URL)
                || ($parts['port'] ?? 443) !== 443 || !preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/D', $parts['host'] ?? '')
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\\\\\x00-\x20\x7f]/', $out['feed_url'])) {
                throw new \InvalidArgumentException('CAP feeds require an explicit HTTPS URL without credentials or redirects.');
            }
            $out['sender']=IncidentConfig::text($row['sender'] ?? '', 200, 'Exact trusted CAP sender');
            $out['event']=IncidentConfig::text($row['event'] ?? '', 80, 'Exact CAP event name');
        } else { throw new \InvalidArgumentException('Choose panic, signed sensor, CAP feed or emergency-call observation.'); }
        if (array_diff(array_keys($row), array_keys($out))) { throw new \InvalidArgumentException('This trigger contains fields for a different source type.'); }
        return $out;
    }
    public static function normalize($value): array
    {
        $value=IncidentConfig::object($value,['schema','rules','actions'],'Trigger settings');
        if (($value['schema'] ?? 1) !== 1) { throw new \InvalidArgumentException('Unsupported trigger settings schema.'); }
        $out=self::defaults(); $seen=[];
        foreach (['actions'=>50,'rules'=>100] as $key=>$limit) {
            foreach (IncidentConfig::listOf($value[$key] ?? [],$limit,$key) as $row) {
                $row=$key === 'actions' ? self::action($row) : self::rule($row);
                if (isset($seen[$row['id']])) { throw new \InvalidArgumentException('Trigger/action identifiers must be unique.'); }
                $seen[$row['id']]=true; $out[$key][]=$row;
            }
        }
        foreach ($out['rules'] as $rule) {
            if (array_diff($rule['action_ids'],array_column($out['actions'],'id'))) { throw new \InvalidArgumentException('A trigger references a removed action. Remove that assignment first.'); }
        }
        $panicNumbers=array_column(array_filter($out['rules'],static fn($row)=>$row['kind']==='panic' && ($row['dial_extension'] ?? '')!==''),'dial_extension');
        if (count($panicNumbers)>10 || count($panicNumbers)!==count(array_unique($panicNumbers))) { throw new \InvalidArgumentException('Up to ten unique panic activation extensions are supported.'); }
        if (count(array_filter($out['rules'],static fn($row)=>$row['kind']==='cap'))>10) { throw new \InvalidArgumentException('Up to ten trusted CAP feeds are supported.'); }
        if (strlen(json_encode($out,JSON_THROW_ON_ERROR)) > 262144) { throw new \InvalidArgumentException('Trigger settings exceed 256 KiB.'); }
        return $out;
    }
    public static function resolve(array $settings, string $id): array
    {
        $config=self::normalize($settings['automations'] ?? []);
        $rule=array_column($config['rules'],null,'id')[$id] ?? null;
        if (!$rule || !$rule['enabled'] || empty($settings['enabled'])) { throw new \DomainException('Trigger is disabled, removed, or Mass Notify is disabled.'); }
        $template=$rule['template_id'] !== '' ? (array_column($settings['incident_workflows']['templates'] ?? [],null,'id')[$rule['template_id']] ?? null) : null;
        if ($rule['template_id'] !== '' && !$template) { throw new \DomainException('The trigger’s incident template is unavailable.'); }
        $location=array_column(LocationDirectory::effective($settings['location_directory'] ?? [])['nodes'],null,'id')[$rule['location_id']] ?? null;
        if ($rule['location_id'] !== '' && !$location) { throw new \DomainException('The trigger’s assigned location was removed. Re-enroll or reassign it before activation.'); }
        $actions=[];
        foreach ($config['actions'] as $action) { if (in_array($action['id'],$rule['action_ids'],true)) {
            if (!$action['enabled']) { throw new \DomainException('A trigger action is disabled. Review the trigger before enabling it.'); }
            $actions[]=$action;
        } }
        // Bind group membership and recipient identities as well as the selected template.
        $routing=array_intersect_key($settings,array_flip(['announcement_groups','desktop_clients','outbound_voice','announcement_email','announcement_sms','announcement_webhooks']));
        $revision=hash('sha256',json_encode([$rule,$template,$location,$actions,$routing],JSON_THROW_ON_ERROR));
        return compact('rule','template','location','actions','revision');
    }
    public static function permits(array $settings, array $context, ?int $now=null): bool
    {
        if (count($context) !== 4 || !is_string($context['rule_id'] ?? null) || !is_string($context['revision'] ?? null)
            || !is_string($context['event_id'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$context['event_id'])
            || !is_int($context['expires_at'] ?? null) || ($now ?? time()) >= $context['expires_at']) { return false; }
        try { $current=self::resolve($settings,$context['rule_id']); return hash_equals($current['revision'],$context['revision']); }
        catch (\Throwable $error) { return false; }
    }
}
