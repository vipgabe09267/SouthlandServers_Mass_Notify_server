<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Optional notification coordination. No PBX, registrar or trunk replication. */
final class EnterpriseClusterConfig
{
    public const MAX_WIRE = 1048576;
    public const MIRROR_FIELDS = ['announcement_groups', 'scheduled_announcements', 'announcement_pronunciation', 'announcement_cooldown_seconds',
        'announcement_timeout_mode', 'announcement_timeout_seconds', 'quiet_hours_enabled', 'quiet_hours_start',
        'quiet_hours_end', 'quiet_critical_events', 'incident_workflows'];

    public static function defaults(): array
    {
        return ['enabled'=>false, 'mirroring_enabled'=>false, 'remote_enabled'=>false, 'mode'=>'standalone',
            'cluster_id'=>'', 'node_id'=>'', 'site_id'=>'', 'role'=>'node', 'witness_id'=>'', 'witness_epoch'=>'',
            'tls_cert'=>'', 'tls_key'=>'', 'tls_ca'=>'', 'peers'=>[], 'lease_seconds'=>15, 'max_queue'=>100,
            'auto_failover'=>false, 'initial_owner'=>'', 'pbx_contract'=>'', 'spend_limit_cents'=>0,
            'spend_currency'=>'USD', 'voice_reservation_cents'=>0,
            'site_recipients'=>[], 'approved_cache'=>[], 'offline_cache_enabled'=>false, 'local_device_channels'=>['desktop'],
            'edge_devices'=>[], 'edge_assets'=>[],
            'mirror_fields'=>self::MIRROR_FIELDS];
    }

    public static function enabled(array $settings): bool
    {
        $value = $settings['enterprise_cluster'] ?? [];
        return is_array($value) && (($value['enabled'] ?? false) === true || ($value['enabled'] ?? false) === '1');
    }

    public static function id($value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$/D', $value)) {
            throw new \DomainException('Invalid cluster identity.');
        }
        return $value;
    }

    public static function normalize(array $input): array
    {
        if (array_diff(array_keys($input), array_keys(self::defaults()))) { throw new \DomainException('Unknown enterprise cluster field.'); }
        $c = array_replace(self::defaults(), $input);
        foreach (['enabled','mirroring_enabled','remote_enabled','auto_failover','offline_cache_enabled'] as $key) {
            if (!in_array($c[$key], [true,false,'1','0'], true)) { throw new \DomainException('Cluster switches must be booleans.'); }
            $c[$key] = $c[$key] === true || $c[$key] === '1';
        }
        if (!in_array($c['mode'], ['standalone','notification_ha','remote_site','edge'], true)
            || !in_array($c['role'], ['node','witness','coordinator'], true)) { throw new \DomainException('Invalid cluster role or deployment mode.'); }
        foreach (['lease_seconds'=>[5,60], 'max_queue'=>[1,500], 'spend_limit_cents'=>[0,100000000], 'voice_reservation_cents'=>[0,10000000]] as $key=>$range) {
            if (!is_int($c[$key]) || $c[$key] < $range[0] || $c[$key] > $range[1]) { throw new \DomainException('Invalid cluster limit: '.$key); }
        }
        if (!is_string($c['spend_currency']) || !preg_match('/^[A-Z]{3}$/D',$c['spend_currency'])) { throw new \DomainException('Cluster spending currency must be a three-letter code.'); }
        foreach (['cluster_id','node_id','site_id','witness_id','initial_owner'] as $key) { if ($c[$key] !== '') { self::id($c[$key]); } }
        foreach (['witness_epoch','pbx_contract'] as $key) {
            if ($c[$key] !== '' && (!is_string($c[$key]) || !preg_match('/^[a-f0-9]{64}$/D',$c[$key]))) { throw new \DomainException('Invalid cluster contract or epoch.'); }
        }
        foreach (['tls_cert','tls_key','tls_ca'] as $key) {
            if (!is_string($c[$key]) || strlen($c[$key]) > 512 || ($c[$key] !== '' && (!str_starts_with($c[$key],'/') || preg_match('/[\x00-\x1f]/',$c[$key])))) { throw new \DomainException('TLS files must be absolute local paths.'); }
        }
        if (!is_array($c['peers']) || !array_is_list($c['peers']) || count($c['peers']) > 32) { throw new \DomainException('At most 32 explicit cluster peers are supported.'); }
        $seen=[];
        foreach ($c['peers'] as $peer) {
            if (!is_array($peer) || array_diff(array_keys($peer),['node_id','site_id','role','https_url','cert_sha256','hmac_secret'])
                || count($peer) !== 6) { throw new \DomainException('Invalid peer schema.'); }
            self::id($peer['node_id']); self::id($peer['site_id']);
            $url=is_string($peer['https_url']) ? parse_url($peer['https_url']) : false;
            if (!$url || preg_match('/[\x00-\x20\x7f]/',$peer['https_url']) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment']) || strlen($peer['https_url']) > 512
                || !in_array($peer['role'], ['node','witness','coordinator'], true)
                || !is_string($peer['cert_sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$peer['cert_sha256'])
                || !is_string($peer['hmac_secret']) || !preg_match('/^[a-f0-9]{64}$/D',$peer['hmac_secret'])
                || $peer['node_id'] === $c['node_id'] || isset($seen[$peer['node_id']])) { throw new \DomainException('Peer URL, certificate, secret, or identity is invalid.'); }
            $seen[$peer['node_id']]=true;
        }
        foreach (['site_recipients','approved_cache','local_device_channels','mirror_fields'] as $key) {
            if (!is_array($c[$key]) || !array_is_list($c[$key]) || count($c[$key]) > 1000 || count(array_unique($c[$key],SORT_REGULAR)) !== count($c[$key])) { throw new \DomainException('Invalid cluster list: '.$key); }
        }
        foreach ($c['site_recipients'] as $id) { self::id($id); }
        foreach ($c['approved_cache'] as $hash) { if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) { throw new \DomainException('Approved cache entries must be SHA256 hashes.'); } }
        if (array_diff($c['mirror_fields'],self::MIRROR_FIELDS) || array_diff($c['local_device_channels'],['desktop','local_audio','local_display'])) { throw new \DomainException('Unsafe cluster mirror or edge channel.'); }
        if (!is_array($c['edge_devices']) || !array_is_list($c['edge_devices']) || count($c['edge_devices'])>1000
            || !is_array($c['edge_assets']) || !array_is_list($c['edge_assets']) || count($c['edge_assets'])>100) { throw new \DomainException('Edge inventory exceeds its bounds.'); }
        $deviceIds=[]; $assetIds=[];
        foreach ($c['edge_devices'] as $d) {
            if (!is_array($d) || count($d)!==3 || array_diff(array_keys($d),['id','token_sha256','channels'])) { throw new \DomainException('Invalid edge device schema.'); }
            self::id($d['id']);
            if (!is_string($d['token_sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$d['token_sha256']) || isset($deviceIds[$d['id']])
                || !in_array($d['id'],$c['site_recipients'],true) || !is_array($d['channels']) || !array_is_list($d['channels'])
                || !$d['channels'] || array_diff($d['channels'],$c['local_device_channels'])) { throw new \DomainException('Edge device identity, credential or channels are invalid.'); }
            $deviceIds[$d['id']]=true;
        }
        foreach ($c['edge_assets'] as $a) {
            if (!is_array($a) || count($a)!==3 || array_diff(array_keys($a),['id','sha256','path'])) { throw new \DomainException('Invalid edge asset schema.'); }
            self::id($a['id']);
            if (isset($assetIds[$a['id']]) || !is_string($a['sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$a['sha256'])
                || !is_string($a['path']) || !str_starts_with($a['path'],'/') || strlen($a['path'])>512 || preg_match('/[\x00-\x1f]/',$a['path'])) { throw new \DomainException('Edge asset is invalid.'); }
            $assetIds[$a['id']]=true;
        }
        if ($c['enabled']) {
            foreach (['cluster_id','node_id','site_id','tls_cert','tls_key','tls_ca'] as $key) { if ($c[$key] === '') { throw new \DomainException('Enabled cluster requires '.$key.'.'); } }
            if ($c['mode'] === 'notification_ha') {
                $w=self::peer($c,$c['witness_id']);
                if (!$w || $w['role'] !== 'witness' || $c['witness_epoch'] === '' || $c['pbx_contract'] === '' || $c['initial_owner'] === '') { throw new \DomainException('Notification HA requires an external witness, its fixed epoch, an initial owner, and matching PBX evidence.'); }
                if (!array_filter($c['peers'],static fn($p)=>in_array($p['role'],['node','coordinator'],true) && $p['site_id']===$c['site_id'])) { throw new \DomainException('Notification HA requires a second configured PBX peer at this site.'); }
            }
            if ($c['role']==='witness' && $c['witness_epoch']==='') { throw new \DomainException('Witness requires a persistent explicitly initialized epoch.'); }
            if ($c['mode']==='edge' && !$c['remote_enabled']) { throw new \DomainException('An edge requires remote-site delivery enabled.'); }
        }
        return $c;
    }

    public static function peer(array $c, string $id): ?array
    {
        foreach ($c['peers'] as $peer) { if ($peer['node_id'] === $id) { return $peer; } }
        return null;
    }

    public static function mirror(array $settings, array $c): array
    {
        return array_intersect_key($settings,array_flip($c['mirror_fields']));
    }

    public static function applyMirror(array $local, array $shared, array $c): array
    {
        if (array_diff(array_keys($shared),$c['mirror_fields'])) { throw new \DomainException('Mirror contains a prohibited or unapproved field.'); }
        // Default deny excludes credentials, AMI, trunks, origins, capacity,
        // device registration, cluster membership and all encryption keyrings.
        return array_replace($local,$shared);
    }
}
