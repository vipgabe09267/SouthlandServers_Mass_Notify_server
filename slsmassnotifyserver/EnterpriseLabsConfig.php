<?php
declare(strict_types=1);
namespace SLS\MassNotify;

require_once __DIR__.'/EnterpriseClusterConfig.php';
require_once __DIR__.'/DirectoryConfig.php';
require_once __DIR__.'/SubscriberConfig.php';
require_once __DIR__.'/EnterpriseIntegrationsConfig.php';
require_once __DIR__.'/EnterpriseOperationsConfig.php';

/** Master activation and the fixed, non-secret feature-manager contract. */
final class EnterpriseLabsConfig
{
    public const SECTIONS = ['enterprise_labs','enterprise_cluster','directory_sync','subscriber_browser',
        'enterprise_integrations','enterprise_operations'];

    public static function defaults(): array { return ['enabled'=>false]; }

    public static function normalize($value): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value), ['enabled'])
            || !is_bool($value['enabled'] ?? false)) {
            throw new \InvalidArgumentException('Enterprise Labs activation must contain an enabled boolean.');
        }
        return ['enabled'=>$value['enabled'] ?? false];
    }

    public static function enabled(array $settings): bool
    {
        return self::normalize($settings['enterprise_labs'] ?? [])['enabled'];
    }

    public static function requireEnabled(array $settings): void
    {
        if (!self::enabled($settings)) {
            throw new \DomainException('Enterprise Labs is disabled. Enable it in Feature manager before running this operation.');
        }
    }

    private static function on($value): bool { return $value === true || $value === '1' || $value === 1; }

    public static function catalog(): array
    {
        $rows = [
            ['cluster','Notification cluster','Connect prepared nodes using configured peer trust.','Continuity and remote sites',['enterprise_cluster','enabled']],
            ['mirroring','Shared-policy mirroring','Mirror only the approved settings between configured peers.','Continuity and remote sites',['enterprise_cluster','mirroring_enabled']],
            ['remote_sites','Remote-site delivery','Queue explicitly addressed jobs at configured remote sites.','Continuity and remote sites',['enterprise_cluster','remote_enabled']],
            ['offline_cache','Approved offline cache','Permit only previously approved cached content at an edge.','Continuity and remote sites',['enterprise_cluster','offline_cache_enabled']],
            ['directory','Directory imports','Preview and apply records from saved directory sources.','Directory and subscribers',['directory_sync','enabled']],
            ['subscribers','Verified browser subscribers','Invite saved people to verify and use their browser enrollment.','Directory and subscribers',['subscriber_browser','enabled']],
            ['browser_push','Subscriber browser push','Use the configured VAPID key and verified browser subscriptions.','Directory and subscribers',['subscriber_browser','push','enabled']],
            ['speakers','SIP speakers','Deliver audio to saved speaker profiles through their PBX extensions.','Devices and providers',['enterprise_integrations','speakers','enabled']],
            ['radios','SIP radio gateways','Deliver duration-limited audio to saved radio gateway profiles.','Devices and providers',['enterprise_integrations','radios','enabled']],
            ['access_control','Access control','Use saved controller doors and their explicitly permitted operations.','Devices and providers',['enterprise_integrations','access_control','enabled']],
            ['meetings','Incident meetings','Create meetings using configured Zoom, Google Meet or Webex accounts.','Devices and providers',['enterprise_integrations','meetings','enabled']],
            ['mail_evidence','Postfix delivery evidence','Read local mail queue and relay evidence without resending mail.','Devices and providers',['enterprise_integrations','mail_evidence','enabled']],
            ['browser_display','Browser displays','Allow authenticated displays using saved desktop credentials.','Devices and providers',['enterprise_integrations','browser_display','enabled']],
            ['connectors','ntfy and Gotify','Use saved HTTPS notification destinations and scoped tokens.','Devices and providers',['enterprise_integrations','connectors','enabled']],
            ['responses','Participant replies','Record supported SMS and voice responses for saved participant bindings.','Devices and providers',['enterprise_integrations','responses','enabled']],
            ['sensors','Physical sensor health','Monitor heartbeats from enrolled sensor triggers.','Devices and providers',['enterprise_integrations','sensors','enabled']],
            ['public_warning','CAP authoring','Prepare and export CAP warnings without transmitting them.','Devices and providers',['enterprise_integrations','public_warning','enabled']],
            ['ipaws','Authorized IPAWS submission','Use a separately qualified authority account and signed envelope.','Devices and providers',['enterprise_integrations','public_warning','ipaws_enabled']],
            ['floorplans','Private floor plans','Use saved private images and device or location overlays.','Incident coordination',['enterprise_operations','floorplans','enabled']],
            ['drills','Central drills','Launch and review saved drill assignments.','Incident coordination',['enterprise_operations','drills','enabled']],
            ['shift_routing','Shift routing','Resolve incident recipients through saved on-duty schedules.','Incident coordination',['enterprise_operations','shift_routing','enabled']],
            ['dual_approval','Two-person approval','Require the saved approval policies for their selected channels.','Incident coordination',['enterprise_operations','dual_approval','enabled']],
        ];
        return array_map(static function (array $row): array {
            [$id,$label,$description,$group,$path] = $row;
            return compact('id','label','description','group','path');
        }, $rows);
    }

    private static function value(array $settings, array $path)
    {
        $value = $settings;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) { return false; }
            $value = $value[$key];
        }
        return $value;
    }

    private static function set(array &$settings, array $path, bool $enabled): void
    {
        $value = &$settings;
        foreach ($path as $key) {
            if (!isset($value[$key])) { $value[$key] = []; }
            $value = &$value[$key];
        }
        $value = $path[0] === 'enterprise_integrations' ? ($enabled ? '1' : '0') : $enabled;
    }

    /** Mask legacy flags until the master is explicitly enabled; never discard data. */
    public static function disableWhenOff(array $settings): array
    {
        if (self::enabled($settings)) { return $settings; }
        foreach (self::catalog() as $feature) {
            if (array_key_exists($feature['path'][0], $settings)) { self::set($settings, $feature['path'], false); }
        }
        foreach (['enterprise_integrations','enterprise_operations'] as $key) {
            if (array_key_exists($key, $settings)) { $settings[$key]['enabled'] = $key === 'enterprise_integrations' ? '0' : false; }
        }
        return $settings;
    }

    /** Reject explicit enable requests before a normalizer can mask them. */
    public static function assertActivation(array $settings): void
    {
        if (self::enabled($settings)) { return; }
        foreach (self::catalog() as $feature) {
            if (self::on(self::value($settings, $feature['path']))) {
                throw new \DomainException('Enable Enterprise Labs in Feature manager before enabling '.$feature['label'].'.');
            }
        }
        foreach (['enterprise_integrations','enterprise_operations'] as $key) {
            if (self::on($settings[$key]['enabled'] ?? false)) {
                throw new \DomainException('Enable Enterprise Labs in Feature manager before enabling this section.');
            }
        }
    }

    private static function sections(array $settings): array
    {
        return [
            'enterprise_labs'=>self::normalize($settings['enterprise_labs'] ?? []),
            'enterprise_cluster'=>EnterpriseClusterConfig::normalize($settings['enterprise_cluster'] ?? []),
            'directory_sync'=>DirectoryConfig::normalize($settings['directory_sync'] ?? []),
            'subscriber_browser'=>SubscriberConfig::normalize($settings['subscriber_browser'] ?? []),
            'enterprise_integrations'=>EnterpriseIntegrationsConfig::normalize($settings['enterprise_integrations'] ?? []),
            'enterprise_operations'=>EnterpriseOperationsConfig::normalize($settings['enterprise_operations'] ?? []),
        ];
    }

    public static function state(array $settings): array
    {
        $sections = self::sections($settings);
        $enabled = $sections['enterprise_labs']['enabled'];
        $features = [];
        foreach (self::catalog() as $feature) {
            $parent = $feature['path'][0];
            $features[$feature['id']] = $enabled && self::on($sections[$parent]['enabled'])
                && self::on(self::value($sections, $feature['path']));
        }
        return ['config'=>['enabled'=>$enabled,'features'=>$features],
            'revision'=>EnterpriseIntegrationsConfig::fingerprint($sections),'catalog'=>self::catalog()];
    }

    /** Produce only the six immediate namespace patches; preserve every other value. */
    public static function changes(array $settings, bool $enabled, array $features): array
    {
        $catalog = self::catalog(); $ids = array_column($catalog, 'id');
        if (array_diff(array_keys($features), $ids) || array_diff($ids, array_keys($features))) {
            throw new \InvalidArgumentException('Feature manager requires one selection for each supported feature. Reload the manager.');
        }
        foreach ($features as $value) { if (!is_bool($value)) { throw new \InvalidArgumentException('Feature selections must be explicit booleans.'); } }
        if (!$enabled && in_array(true, $features, true)) {
            throw new \DomainException('Enable Enterprise Labs before selecting a feature, or clear the selections to disable it.');
        }
        $out = self::sections(self::disableWhenOff($settings));
        $out['enterprise_labs'] = ['enabled'=>$enabled];
        foreach (['mirroring','remote_sites','offline_cache'] as $id) { if ($features[$id]) { $features['cluster'] = true; } }
        if ($features['browser_push']) { $features['subscribers'] = true; }
        if ($features['ipaws']) { $features['public_warning'] = true; }
        foreach ($catalog as $feature) { self::set($out, $feature['path'], $enabled && $features[$feature['id']]); }
        $out['enterprise_integrations']['enabled'] = count(array_filter($catalog, static fn($f) => $f['path'][0] === 'enterprise_integrations' && $features[$f['id']])) > 0 ? '1' : '0';
        $out['enterprise_operations']['enabled'] = count(array_filter($catalog, static fn($f) => $f['path'][0] === 'enterprise_operations' && $features[$f['id']])) > 0;
        $out = self::sections($out);
        self::prerequisites($out, $settings);
        return $out;
    }

    private static function prerequisites(array $sections, array $settings): void
    {
        $integrations = $sections['enterprise_integrations'];
        if ($integrations['enabled'] === '1') {
            foreach (['meetings','access_control','connectors'] as $key) {
                if ($integrations[$key]['enabled'] === '1' && (!extension_loaded('curl') || !extension_loaded('openssl'))) {
                    throw new \DomainException('Enable PHP cURL and OpenSSL in the runtime serving FreePBX before enabling '.$key.'.');
                }
            }
            if ($integrations['public_warning']['enabled'] === '1' && (!extension_loaded('dom') || !extension_loaded('openssl')
                || ($integrations['public_warning']['ipaws_enabled'] === '1' && !extension_loaded('curl')))) {
                throw new \DomainException('CAP authoring requires PHP DOM and OpenSSL; IPAWS transmission also requires cURL in the runtime serving FreePBX.');
            }
        }
        $requirements = [
            'speakers'=>[$integrations['speakers']['devices'] !== [], 'Add a saved SIP speaker profile under Devices and providers.'],
            'radios'=>[$integrations['radios']['devices'] !== [], 'Add a saved SIP radio gateway under Devices and providers.'],
            'access_control'=>[$integrations['access_control']['origin'] !== '' && $integrations['access_control']['username'] !== ''
                && $integrations['access_control']['password'] !== '' && $integrations['access_control']['doors'] !== [], 'Save the controller address, credentials and at least one permitted door under Devices and providers.'],
            'meetings'=>array_filter(array_intersect_key($integrations['meetings'], array_flip(['zoom_access_token','google_access_token','webex_access_token']))) !== []
                ? [true,''] : [false,'Save at least one Zoom, Google Meet or Webex access token under Devices and providers.'],
            'connectors'=>[$integrations['connectors']['destinations'] !== [], 'Add a saved ntfy or Gotify destination under Devices and providers.'],
            'responses'=>[$integrations['responses']['bindings'] !== [], 'Save at least one participant response binding under Devices and providers.'],
            'browser_display'=>[count(array_filter($settings['desktop_clients'] ?? [], static fn($c) => is_array($c) && self::on($c['enabled'] ?? false))) > 0, 'Save an enabled desktop credential before enabling browser displays.'],
            'sensors'=>[count(array_filter($settings['automations']['rules'] ?? [], static fn($r) => is_array($r) && ($r['kind'] ?? '') === 'sensor' && self::on($r['enabled'] ?? false))) > 0, 'Add an enabled sensor rule under Triggers and actions before enabling sensor health.'],
        ];
        foreach ($requirements as $id=>[$ready,$message]) {
            if ($integrations['enabled'] === '1' && $integrations[$id]['enabled'] === '1' && !$ready) { throw new \DomainException($message); }
        }
        $directory = $sections['directory_sync'];
        if ($directory['enabled'] && !array_filter($directory['sources'], static fn($s) => $s['enabled'])) { throw new \DomainException('Add an enabled directory source under Directory and subscribers before enabling directory imports.'); }
        if ($directory['enabled'] && !extension_loaded('ldap')
            && array_filter($directory['sources'], static fn($s) => $s['enabled'] && $s['type'] === 'ldap')) {
            throw new \DomainException('Install and enable the matching PHP LDAP extension in the runtime serving FreePBX before enabling an LDAP directory source.');
        }
        $subscribers = $sections['subscriber_browser'];
        if ($subscribers['enabled'] && !array_filter($subscribers['people'], static fn($p) => $p['enabled'])) { throw new \DomainException('Add an enabled person under Directory and subscribers before enabling browser subscribers.'); }
        foreach (['floorplans'=>'plans','drills'=>'assignments','shift_routing'=>'routes','dual_approval'=>'policies'] as $key=>$list) {
            if ($sections['enterprise_operations'][$key]['enabled'] && !$sections['enterprise_operations'][$key][$list]) {
                throw new \DomainException('Add a saved '.str_replace('_', ' ', $key).' entry under Incident coordination before enabling it.');
            }
        }
        $warning = $integrations['public_warning'];
        if ($warning['ipaws_enabled'] === '1' && (!$warning['authority_confirmed'] || in_array('', array_intersect_key($warning,
            array_flip(['endpoint','soap_action','logon_user','cog_id','certificate_pem','private_key_pem'])), true))) {
            throw new \DomainException('Save and qualify the issued IPAWS account, endpoint and signing credentials before enabling submission.');
        }
    }
}
