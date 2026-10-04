<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/LocationDirectory.php';
require_once __DIR__ . '/OperatorAuth.php';
require_once __DIR__ . '/api/sls-mass-notify/security.php';

/** Local roles use authenticated FreePBX identities and the existing SLS directory. */
final class OperatorAccess
{
    public const ROLES = ['viewer', 'sender', 'scheduler', 'warden', 'administrator'];

    private static function ids($value, string $pattern, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) { throw new \DomainException('Operator assignments exceed their supported list size.'); }
        $seen = [];
        foreach ($value as $id) {
            if (!is_string($id) || !preg_match($pattern, $id) || isset($seen[$id])) { throw new \DomainException('Operator assignments contain an invalid or repeated identifier.'); }
            $seen[$id] = true;
        }
        return array_values($value);
    }

    private static function personal($value): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value),array_keys(LocationDirectory::MEMBERS))) { throw new \DomainException('Personal devices must use saved device identifiers.'); }
        $out=[];
        foreach (LocationDirectory::MEMBERS as $key=>$pattern) {
            $limit=$key==='webhook_ids'?10:(in_array($key,['email_recipient_ids','sms_recipient_ids'],true)?50:1000);
            $out[$key]=self::ids($value[$key]??[],$pattern,$limit);
        }
        return $out;
    }

    public static function normalize($value): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value), ['schema', 'enabled', 'portal_enabled', 'accounts'])
            || ($value['schema'] ?? 1) !== 1 || !is_bool($value['enabled'] ?? false)
            || !is_bool($value['portal_enabled'] ?? false)) { throw new \DomainException('Operator access must use schema 1 and boolean enable settings.'); }
        $rows = $value['accounts'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) { throw new \DomainException('Operator access supports up to 100 local accounts.'); }
        $accounts = []; $ids = []; $users = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['id', 'username', 'name', 'email', 'role', 'enabled', 'site_ids', 'location_ids', 'group_ids', 'personal_members', 'actions', 'channels', 'source', 'identity', 'auth'])
                || !is_string($row['id'] ?? null) || !preg_match('/^api_[a-f0-9]{24}$/D', $row['id']) || isset($ids[$row['id']])
                || !is_string($row['username'] ?? null) || !preg_match('/^[A-Za-z0-9_.@-]{1,80}$/D', $row['username']) || isset($users[strtolower($row['username'])])
                || !in_array($row['role'] ?? null, self::ROLES, true) || !is_bool($row['enabled'] ?? null)
                || !in_array($row['source'] ?? null, ['portal', 'database', 'usermanager'], true)
                || !is_string($row['identity'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $row['identity'])) { throw new \DomainException('An operator account has invalid or duplicate identity or role fields.'); }
            $sites = self::ids($row['site_ids'] ?? [], '/^loc_[a-f0-9]{24}$/D', 500);
            $groups = self::ids($row['group_ids'] ?? [], '/^grp_[a-f0-9]{12,32}$/D', 20);
            $locations=self::ids($row['location_ids']??[], '/^loc_[a-f0-9]{24}$/D',500);
            $members=self::personal($row['personal_members']??[]);
            $defaults=match($row['role']) {'sender'=>['view','send'],'scheduler'=>['view','schedule'],'warden'=>['view','roll_call'],'administrator'=>OperatorAuth::ACTIONS,default=>['view']};
            $actions=self::ids($row['actions']??$defaults,'/^(?:view|send|schedule|roll_call)$/D',4);
            if (!in_array('view',$actions,true)) { throw new \DomainException('An operator must be allowed to view their assigned work.'); }
            $channels=self::ids($row['channels']??array_keys(LocationDirectory::MEMBERS),'/^(?:extensions|desktop_client_ids|voice_recipient_ids|email_recipient_ids|sms_recipient_ids|webhook_ids)$/D',6);
            if (!$channels) { throw new \DomainException('Select at least one permitted delivery channel.'); }
            if ($row['role'] === 'administrator' && ($sites || $groups || $locations || array_filter($members))) { throw new \DomainException('SLS administrators have module-wide access. Use another role to restrict recipients.'); }
            if ($row['enabled'] && $row['role'] !== 'administrator' && !$sites && !$groups && !$locations && !array_filter($members)) { throw new \DomainException('An enabled operator needs a site, location, saved audience or personal device assignment.'); }
            $auth=isset($row['auth'])?OperatorAuth::normalize($row['auth']):null;
            if ($row['source']==='portal' && $auth===null) { throw new \DomainException('A portal login needs a protected password record.'); }
            $accounts[] = ['id'=>$row['id'], 'username'=>$row['username'], 'name'=>LocationDirectory::text(($row['name'] ?? '') ?: $row['username'], 100, 'Operator name'),
                'email'=>OperatorRecovery::email($row['email']??''),
                'role'=>$row['role'], 'enabled'=>$row['enabled'], 'site_ids'=>$sites, 'group_ids'=>$groups,
                'location_ids'=>$locations,'personal_members'=>$members,'actions'=>$actions,'channels'=>$channels,
                'source'=>$row['source'], 'identity'=>$row['identity']] + ($auth===null?[]:['auth'=>$auth]);
            $ids[$row['id']] = true; $users[strtolower($row['username'])] = true;
        }
        return ['schema'=>1, 'enabled'=>$value['enabled'] ?? false, 'portal_enabled'=>$value['portal_enabled'] ?? false, 'accounts'=>$accounts];
    }

    /** A reused username or changed login credential needs administrator review. */
    public static function identity(array $row): string
    {
        if (($row['mode']??$row['source']??'')==='portal') { return OperatorAuth::identity($row); }
        if (!is_string($row['username'] ?? null) || !is_string($row['password_sha1'] ?? null)
            || $row['password_sha1'] === '' || !in_array($row['mode'] ?? null, ['database', 'usermanager'], true)) {
            throw new \DomainException('The PBX login identity is unavailable.');
        }
        return hash('sha256', json_encode([$row['mode'], (string)($row['id'] ?? ''), $row['username'], $row['password_sha1']], JSON_THROW_ON_ERROR));
    }

    public static function localIdentityCurrent(array $principal, ?callable $lookup = null): bool
    {
        if (($principal['source']??'')==='portal') { return !empty($principal['portal_auth_ready']); }
        try {
            if ($lookup === null) {
                if (class_exists('FreePBX', false) && !class_exists('ampuser', false)) { require_once '/var/www/html/admin/libraries/ampuser.class.php'; }
                if (!class_exists('ampuser', false) || !class_exists('FreePBX', false)) { return false; }
                $lookup = static fn(string $username, string $source) => (new \ampuser($username, $source))->getAmpUser($username);
            }
            $row = $lookup($principal['username'], $principal['source']);
            if (is_array($row) && ($row['mode'] ?? '') === 'usermanager'
                && !\FreePBX::Userman()->getCombinedGlobalSettingByID($row['id'], 'pbx_login')) { return false; }
            return is_array($row) && ($row['mode'] ?? '') === $principal['source']
                && hash_equals($principal['identity'], self::identity($row))
                && (in_array('*', $row['sections'] ?? [], true) || in_array('slsmassnotifyserver_operations', $row['sections'] ?? [], true));
        } catch (\Throwable $error) { return false; }
    }

    public static function principal(array $settings, string $id): ?array
    {
        try {
            $config = self::normalize($settings['operator_access'] ?? []);
            if (array_intersect(array_column($config['accounts'], 'id'), array_column($settings['control_api']['credentials'] ?? [], 'id'))) { return null; }
            foreach ($config['accounts'] as $account) {
                if ($account['id'] !== $id || !$account['enabled']) { continue; }
                if ($account['source']==='portal' ? !$config['portal_enabled'] : !$config['enabled']) { return null; }
                $members = $account['personal_members'];
                $directory = LocationDirectory::effective($settings['location_directory'] ?? []);
                $nodes = array_column($directory['nodes'], null, 'id');
                foreach (array_unique(array_merge($account['site_ids'],$account['location_ids'])) as $site) {
                    // Removed sites grant no recipients. A saved group remains a
                    // separate explicit authority rather than an inferred site.
                    if (!isset($nodes[$site]) || (in_array($site,$account['site_ids'],true) && $nodes[$site]['type'] !== 'site')) { continue; }
                    foreach (LocationDirectory::selected($directory, $site, true) as $node) {
                        foreach ($node['members'] as $key=>$values) { $members[$key] = array_merge($members[$key], $values); }
                    }
                }
                $scopes = array_intersect($account['actions'],['send','schedule']) ? ['read', 'send'] : ['read'];
                if ($account['role'] === 'administrator') { $scopes = ['read', 'send', 'test', 'config']; }
                $audience = ['unrestricted'=>$account['role'] === 'administrator', 'announcement_group_ids'=>$account['group_ids'], 'nws_zone_ids'=>[]];
                foreach ($members as $key=>$values) { $audience[$key] = array_values(array_unique($values)); }
                return ['id'=>$account['id'], 'name'=>$account['name'], 'username'=>$account['username'], 'operator_role'=>$account['role'],
                    'site_ids'=>$account['site_ids'], 'location_ids'=>$account['location_ids'],'group_ids'=>$account['group_ids'], 'scopes'=>$scopes, 'audience'=>$audience,
                    'actions'=>$account['actions'],'allowed_channels'=>$account['channels'],
                    'source'=>$account['source'], 'identity'=>$account['identity'],'portal_auth_ready'=>$account['source']==='portal' && OperatorAuth::ready($account)];
            }
        } catch (\Throwable $error) { return null; }
        return null;
    }

    public static function byUsername(array $settings, string $username): ?array
    {
        foreach (self::normalize($settings['operator_access'] ?? [])['accounts'] as $row) {
            if ($row['username'] === $username) { return self::principal($settings, $row['id']); }
        }
        return null;
    }

    public static function may(array $principal, string $operation): bool
    {
        $role = $principal['operator_role'] ?? '';
        if ($role === 'administrator') { return true; }
        if (isset($principal['actions'])) { return in_array($operation,$principal['actions'],true); }
        return in_array($role, self::ROLES, true) && ($operation === 'view'
            || ($operation === 'send' && $role === 'sender') || ($operation === 'schedule' && $role === 'scheduler')
            || ($operation === 'roll_call' && $role === 'warden'));
    }

    public static function delivery(array $principal, array $delivery, array $settings): bool
    {
        if (($principal['audience']['unrestricted'] ?? false) === true) { return true; }
        $allowed = ApiSecurity::allowedAudience($principal, $settings);
        $mapping = ['extensions'=>'phones', 'desktop_clients'=>'desktops', 'voice_recipient_ids'=>'voice_recipient_ids',
            'email_recipient_ids'=>'email_recipient_ids', 'sms_recipient_ids'=>'sms_recipient_ids', 'webhook_ids'=>'webhooks'];
        foreach ($mapping as $key=>$target) {
            if (!is_array($delivery[$key] ?? []) || array_diff($delivery[$key] ?? [], $allowed[$target])) { return false; }
        }
        return empty($delivery['group_ids']); // The caller must resolve groups first.
    }

    public static function person(array $principal, array $person, array $settings, array $identities): bool
    {
        if (($principal['audience']['unrestricted'] ?? false) === true) { return true; }
        $username = $person['desktop_username'] ?? '';
        if ($username !== '') {
            $allowed = ApiSecurity::allowedAudience($principal, $settings)['desktops'];
            $client = array_column($settings['desktop_clients'] ?? [], null, 'username')[$username] ?? null;
            $expected = $identities['desktop_clients'][$username] ?? null;
            if ($client && in_array($username, $allowed, true) && is_string($expected)
                && hash_equals($expected, hash('sha256', $client['client_id']."\0".$username))) { return true; }
        }
        $location = $person['location_id'] ?? '';
        if ($location === '') { return false; }
        $directory = LocationDirectory::effective($settings['location_directory'] ?? []);
        $nodes = array_column($directory['nodes'], null, 'id');
        foreach (array_unique(array_merge($principal['site_ids'] ?? [],$principal['location_ids']??[])) as $site) {
            if (!isset($nodes[$site]) || (in_array($site,$principal['site_ids']??[],true) && $nodes[$site]['type'] !== 'site')) { continue; }
            if (in_array($location, array_column(LocationDirectory::selected($directory, $site, true), 'id'), true)) { return true; }
        }
        return false;
    }
}
