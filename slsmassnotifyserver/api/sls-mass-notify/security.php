<?php
declare(strict_types=1);

namespace SLS\MassNotify;

if (!class_exists(ApiSecurity::class, false)) {
/** Shared by the Control and desktop APIs. No framework bootstrap or side effects. */
final class ApiSecurity
{
    public const SCOPES = ['read', 'send', 'test', 'config'];
    public const AUDIENCES = ['extensions', 'desktop_client_ids', 'announcement_group_ids', 'nws_zone_ids', 'voice_recipient_ids', 'webhook_ids', 'email_recipient_ids', 'sms_recipient_ids', 'browser_recipient_ids', 'connector_ids'];

    public static function jsonObject(string $raw, bool $allowLegacyEmptyList = false, int $maximumDepth = 64): array
    {
        if ($maximumDepth < 2 || $maximumDepth > 64) { throw new \InvalidArgumentException('JSON nesting limit is invalid.'); }
        self::assertUniqueJsonMembers($raw, $maximumDepth);
        $decoded = json_decode($raw, true, $maximumDepth, JSON_THROW_ON_ERROR);
        self::assertFiniteJsonNumbers($decoded);
        $trimmed = ltrim($raw);
        $object = str_starts_with($trimmed, '{');
        $legacyEmptyList = $allowLegacyEmptyList && $decoded === []
            && preg_match('/\A\[\s*\]\z/D', $trimmed) === 1;
        if (!is_array($decoded) || (!$object && !$legacyEmptyList)) {
            throw new \InvalidArgumentException('JSON object required.');
        }
        return $decoded;
    }

    private static function assertFiniteJsonNumbers($value): void
    {
        if (is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('JSON numbers must be finite.');
        }
        if (!is_array($value)) { return; }
        foreach ($value as $member) { self::assertFiniteJsonNumbers($member); }
    }

    /** Reject duplicate object members without retaining a second decoded tree. */
    private static function assertUniqueJsonMembers(string $raw, int $maximumDepth): void
    {
        $length = strlen($raw);
        $stack = [];
        for ($index = 0; $index < $length; $index++) {
            $byte = $raw[$index];
            if ($byte === '{' || $byte === '[') {
                if (count($stack) >= $maximumDepth) {
                    throw new \InvalidArgumentException('JSON nesting limit is invalid.');
                }
                $stack[] = ['object' => $byte === '{', 'keys' => []];
                continue;
            }
            if ($byte === '}' || $byte === ']') {
                $last = count($stack) - 1;
                if ($last < 0 || ($stack[$last]['object'] !== ($byte === '}'))) {
                    throw new \InvalidArgumentException('Invalid JSON structure.');
                }
                array_pop($stack);
                continue;
            }
            if ($byte !== '"') { continue; }

            $start = $index;
            $escaped = false;
            for ($index++; $index < $length; $index++) {
                if ($raw[$index] === '\\') {
                    $escaped = true;
                    if (++$index >= $length) { throw new \InvalidArgumentException('Invalid JSON string.'); }
                    continue;
                }
                if ($raw[$index] === '"') { break; }
            }
            if ($index >= $length) { throw new \InvalidArgumentException('Invalid JSON string.'); }
            $next = $index + 1;
            while ($next < $length && ($raw[$next] === ' ' || $raw[$next] === "\t"
                || $raw[$next] === "\r" || $raw[$next] === "\n")) { $next++; }
            if ($next >= $length || $raw[$next] !== ':') { continue; }
            $last = count($stack) - 1;
            if ($last < 0 || !$stack[$last]['object']) {
                throw new \InvalidArgumentException('Invalid JSON member.');
            }
            $literal = substr($raw, $start, $index - $start + 1);
            if ($escaped) {
                $key = json_decode($literal, true, 2, JSON_THROW_ON_ERROR);
                if (!is_string($key)) { throw new \InvalidArgumentException('Invalid JSON key.'); }
            } else {
                $key = substr($literal, 1, -1);
            }
            $identity = 'k:' . $key;
            if (isset($stack[$last]['keys'][$identity])) {
                throw new \InvalidArgumentException('Duplicate JSON members are not supported.');
            }
            $stack[$last]['keys'][$identity] = true;
        }
        if ($stack) { throw new \InvalidArgumentException('Invalid JSON structure.'); }
    }

    private static function values($value, string $pattern, int $maximum = 1000): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new \DomainException('Permissions must use bounded lists of identifiers.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || !preg_match($pattern, $item)) { throw new \DomainException('A permission identifier is invalid.'); }
            $result[$item] = $item;
        }
        return array_values($result);
    }

    public static function audience($value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), array_merge(['unrestricted'], self::AUDIENCES))
            || !is_bool($value['unrestricted'] ?? null)) {
            throw new \DomainException('Choose an unrestricted audience or explicit permitted recipients.');
        }
        $result = ['unrestricted' => $value['unrestricted']];
        foreach (self::AUDIENCES as $key) {
            $pattern = $key === 'extensions' ? '/^[0-9]{1,20}$/D'
                : ($key === 'email_recipient_ids' ? '/^email_[a-f0-9]{24}$/D' : ($key === 'sms_recipient_ids' ? '/^sms_[a-f0-9]{24}$/D' : '/^[A-Za-z0-9_-]{1,64}$/D'));
            if ($key==='browser_recipient_ids') { $pattern='/^sub_[a-f0-9]{24}$/D'; }
            if ($key==='connector_ids') { $pattern='/^conn_[a-f0-9]{24}$/D'; }
            $items = in_array($key, ['email_recipient_ids','sms_recipient_ids','browser_recipient_ids','connector_ids'], true) && array_key_exists($key, $value) ? $value[$key] : ($value[$key] ?? []);
            $result[$key] = self::values($items, $pattern);
        }
        return $result;
    }

    public static function credentials($value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) { throw new \DomainException('Control API supports up to 100 named credentials.'); }
        $result = []; $ids = []; $hashes = [];
        foreach ($value as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['id', 'name', 'secret_hash', 'scopes', 'audience', 'created_at', 'revoked_at'])
                || !is_string($row['id'] ?? null) || !preg_match('/^api_[a-f0-9]{24}$/D', $row['id']) || isset($ids[$row['id']])
                || !is_string($row['secret_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $row['secret_hash'])
                || isset($hashes[$row['secret_hash']])
                || !is_string($row['name'] ?? null) || trim($row['name']) === '' || strlen($row['name']) > 80 || preg_match('/[\x00-\x1f\x7f]/', $row['name'])) {
                throw new \DomainException('A named API credential has invalid metadata.');
            }
            $scopes = self::values($row['scopes'] ?? [], '/^(read|send|test|config)$/D', 4);
            if (!$scopes) { throw new \DomainException('Select at least one API permission.'); }
            $audience = self::audience($row['audience'] ?? []);
            if (!$audience['unrestricted'] && in_array('config', $scopes, true)) { throw new \DomainException('Configuration permission requires an unrestricted audience.'); }
            foreach (['created_at', 'revoked_at'] as $field) {
                if (!is_string($row[$field] ?? null) || ($row[$field] === '' && $field !== 'revoked_at')
                    || ($row[$field] !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $row[$field])
                        || !($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $row[$field]))
                        || $parsed->format('Y-m-d\TH:i:sP') !== $row[$field]))) {
                    throw new \DomainException('An API credential timestamp is invalid.');
                }
            }
            $ids[$row['id']] = true; $hashes[$row['secret_hash']] = true;
            $result[] = ['id' => $row['id'], 'name' => trim($row['name']), 'secret_hash' => $row['secret_hash'],
                'scopes' => $scopes, 'audience' => $audience, 'created_at' => $row['created_at'], 'revoked_at' => $row['revoked_at']];
        }
        return $result;
    }

    /** Return a new row plus its one-time secret. Only the row belongs in .config. */
    public static function issue(array $input): array
    {
        $secret = 'sls_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $row = ['id' => 'api_' . bin2hex(random_bytes(12)), 'name' => $input['name'] ?? '',
            'secret_hash' => hash('sha256', $secret), 'scopes' => $input['scopes'] ?? [],
            'audience' => $input['audience'] ?? [], 'created_at' => gmdate('c'), 'revoked_at' => ''];
        return ['credential' => self::credentials([$row])[0], 'secret' => $secret];
    }

    public static function revoke(array $credentials, string $id): array
    {
        $credentials = self::credentials($credentials); $found = false;
        foreach ($credentials as &$row) {
            if (hash_equals($row['id'], $id)) { $found = true; if ($row['revoked_at'] === '') { $row['revoked_at'] = gmdate('c'); } }
        }
        unset($row);
        if (!$found) { throw new \DomainException('That API credential no longer exists. Reload the page.'); }
        return $credentials;
    }

    public static function authenticate(array $control, string $secret): ?array
    {
        if ($secret === '' || strlen($secret) > 128) { return null; }
        $legacy = $control['api_key'] ?? '';
        if (is_string($legacy) && $legacy !== '' && hash_equals($legacy, $secret)) {
            return ['id' => 'legacy', 'name' => 'Legacy Control API', 'scopes' => self::SCOPES, 'audience' => self::audience(['unrestricted' => true])];
        }
        try { $rows = self::credentials($control['credentials'] ?? []); } catch (\DomainException $e) { return null; }
        $digest = hash('sha256', $secret); $match = null;
        foreach ($rows as $row) {
            if (hash_equals($row['secret_hash'], $digest) && $row['revoked_at'] === '') { unset($row['secret_hash']); $match = $row; }
        }
        return $match;
    }

    public static function currentCredential(array $settings, string $id): ?array
    {
        if ($id === 'legacy') { return null; } // Legacy jobs keep their existing behavior; never promote a missing named key.
        if (array_key_exists('operator_access', $settings)) {
            if (!is_array($settings['operator_access'])) { return null; }
            $local = dirname(__DIR__, 2) . '/OperatorAccess.php';
            require_once is_file($local) ? $local : '/var/www/html/admin/modules/slsmassnotifyserver/OperatorAccess.php';
            $operator = OperatorAccess::principal($settings, $id);
            // An operator ID can never fall through to a network API key, even
            // after revocation or if a malformed file contains an ID collision.
            foreach ($settings['operator_access']['accounts'] ?? [] as $row) {
                if (($row['id'] ?? '') === $id) {
                    return $operator !== null && OperatorAccess::localIdentityCurrent($operator) ? $operator : null;
                }
            }
        }
        if (empty($settings['control_api']['enabled'])) { return null; }
        try { $rows = self::credentials($settings['control_api']['credentials'] ?? []); } catch (\DomainException $e) { return null; }
        foreach ($rows as $row) {
            if (hash_equals($row['id'], $id) && $row['revoked_at'] === '') { unset($row['secret_hash']); return $row; }
        }
        return null;
    }

    public static function permits(array $principal, string $scope): bool
    {
        return in_array($scope, self::SCOPES, true) && in_array($scope, $principal['scopes'] ?? [], true);
    }

    /** IDs only: no addresses or mail credentials enter API permission projections. */
    public static function enabledEmailRecipientIds(array $settings): array
    {
        $ids = []; $seen = [];
        foreach ((array)($settings['announcement_email']['recipients'] ?? []) as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)
                || !preg_match('/^email_[a-f0-9]{24}$/D', $row['id'])) { continue; }
            $id = $row['id'];
            if (isset($seen[$id])) { unset($ids[$id]); continue; }
            $seen[$id] = true;
            if (!in_array($row['enabled'] ?? null, [true, 1, '1'], true)
                || !is_string($row['address'] ?? null) || strlen($row['address']) > 254
                || !filter_var($row['address'], FILTER_VALIDATE_EMAIL)
                || preg_match('/[^\x21-\x7e]/', $row['address'])) { continue; }
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    public static function enabledSmsRecipientIds(array $settings): array
    {
        $ids=[]; $seen=[];
        foreach ((array)($settings['announcement_sms']['recipients']??[]) as $row) {
            if (!is_array($row) || !is_string($row['id']??null) || !preg_match('/^sms_[a-f0-9]{24}$/D',$row['id'])) { continue; }
            $id=$row['id']; if (isset($seen[$id])) { unset($ids[$id]); continue; } $seen[$id]=true;
            if (($row['enabled']??false)!==true || ($row['consent']??false)!==true
                || !is_string($row['number']??null) || !preg_match('/^\+[1-9][0-9]{7,14}$/D',$row['number'])) { continue; }
            $ids[$id]=$id;
        }
        return array_values($ids);
    }

    /** A saved location snapshot must not authorize a reused desktop username. */
    public static function groupDesktopBindingsValid(array $group, array $settings): bool
    {
        if (!array_key_exists('location_snapshot', $group) && !array_key_exists('desktop_bindings', $group)) { return true; }
        $snapshot = $group['location_snapshot'] ?? ['schema' => 1, 'desktop_bindings' => $group['desktop_bindings']];
        if (!is_array($snapshot) || !in_array($snapshot['schema'] ?? null, [1, 2], true)
            || !is_array($snapshot['desktop_bindings'] ?? null) || !array_is_list($snapshot['desktop_bindings'])
            || count($snapshot['desktop_bindings']) > 1000 || !is_array($group['desktop_clients'] ?? null)
            || !array_is_list($group['desktop_clients']) || count($group['desktop_clients']) !== count($snapshot['desktop_bindings'])) { return false; }
        foreach ($group['desktop_clients'] as $username) {
            if (!is_string($username) || !preg_match('/^[a-z0-9_.-]{1,48}$/D', $username)) { return false; }
        }
        $current = []; $seen = []; $ids = [];
        foreach ($settings['desktop_clients'] ?? [] as $row) {
            if (is_array($row) && !empty($row['enabled']) && is_string($row['username'] ?? null) && is_string($row['client_id'] ?? null)) {
                if (isset($current[$row['username']])) { return false; }
                $current[$row['username']] = $row['client_id'];
            }
        }
        foreach ($snapshot['desktop_bindings'] as $binding) {
            if (!is_array($binding) || !is_string($binding['username'] ?? null) || !is_string($binding['client_id'] ?? null)
                || !preg_match('/^[a-z0-9_.-]{1,48}$/D', $binding['username']) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $binding['client_id'])
                || isset($seen[$binding['username']]) || isset($ids[$binding['client_id']])
                || !in_array($binding['username'], $group['desktop_clients'], true)
                || ($current[$binding['username']] ?? null) !== $binding['client_id']) { return false; }
            $seen[$binding['username']] = true; $ids[$binding['client_id']] = true;
        }
        return count($seen) === count(array_unique($group['desktop_clients'], SORT_REGULAR));
    }

    public static function allowedAudience(array $principal, array $settings): array
    {
        $audience = self::audience($principal['audience'] ?? []);
        $labs = self::labsDirectory($settings);
        $reviewedGroups = [];
        $allowed = ['phones' => $audience['extensions'], 'desktops' => [], 'webhooks' => $audience['webhook_ids'], 'voice_recipient_ids' => $audience['voice_recipient_ids'], 'email_recipient_ids' => $audience['email_recipient_ids'], 'sms_recipient_ids' => $audience['sms_recipient_ids']];
        foreach ($settings['desktop_clients'] ?? [] as $row) {
            if (is_array($row) && !empty($row['enabled']) && in_array($row['client_id'] ?? '', $audience['desktop_client_ids'], true)) {
                $allowed['desktops'][] = (string)($row['username'] ?? '');
            }
        }
        foreach ($settings['announcement_groups'] ?? [] as $group) {
            if (!is_array($group) || !in_array($group['id'] ?? '', $audience['announcement_group_ids'], true)) { continue; }
            if (!self::groupDesktopBindingsValid($group, $settings) || !self::groupLabsBindingsValid($group,$settings,$labs)) { continue; }
            $reviewedGroups[] = $group;
            foreach (['phones' => 'extensions', 'desktops' => 'desktop_clients', 'voice_recipient_ids' => 'voice_recipient_ids', 'email_recipient_ids' => 'email_recipient_ids', 'sms_recipient_ids' => 'sms_recipient_ids', 'webhooks' => 'webhook_ids'] as $key => $field) {
                foreach ($group[$field] ?? [] as $target) { if (is_string($target) || is_int($target)) { $allowed[$key][] = (string)$target; } }
            }
        }
        $allowed['email_recipient_ids'] = array_values(array_intersect($allowed['email_recipient_ids'], self::enabledEmailRecipientIds($settings)));
        $allowed['sms_recipient_ids'] = array_values(array_intersect($allowed['sms_recipient_ids'], self::enabledSmsRecipientIds($settings)));
        foreach (['browser_recipient_ids','connector_ids'] as $field) {
            $selected=$audience[$field];
            foreach ($reviewedGroups as $group) {
                $selected=array_merge($selected,$group[$field] ?? []);
            }
            $allowed[$field]=array_values(array_filter(array_unique($selected),static fn($id)=>!empty($labs[$field][$id]['available'])));
        }
        // Apply channel restrictions after expanding saved audiences. A group
        // may contain channels the operator has not been allowed to use.
        if (isset($principal['allowed_channels']) && empty($principal['audience']['unrestricted'])) {
            foreach (['phones'=>'extensions','desktops'=>'desktop_client_ids','webhooks'=>'webhook_ids','voice_recipient_ids'=>'voice_recipient_ids','email_recipient_ids'=>'email_recipient_ids','sms_recipient_ids'=>'sms_recipient_ids','browser_recipient_ids'=>'browser_recipient_ids','connector_ids'=>'connector_ids'] as $key=>$channel) {
                if (!in_array($channel,$principal['allowed_channels'],true)) { $allowed[$key]=[]; }
            }
        }
        foreach ($allowed as &$values) { $values = array_values(array_unique($values)); } unset($values);
        return $allowed;
    }

    public static function permitsResolvedAnnouncement(array $principal, array $request, array $settings): bool
    {
        if (!self::permits($principal, 'send')) { return false; }
        // Saved email IDs are the only supported email authority, even for legacy keys.
        foreach (['emails', 'email_recipients', 'sms', 'sms_recipients', 'sms_numbers'] as $key) { if (!empty($request[$key])) { return false; } }
        try { self::values(array_key_exists('email_recipient_ids', $request) ? $request['email_recipient_ids'] : [], '/^email_[a-f0-9]{24}$/D', 50); }
        catch (\DomainException $error) { return false; }
        try { self::values(array_key_exists('sms_recipient_ids', $request) ? $request['sms_recipient_ids'] : [], '/^sms_[a-f0-9]{24}$/D', 50); }
        catch (\DomainException $error) { return false; }
        if (($principal['audience']['unrestricted'] ?? false) === true) {
            if (!isset($principal['allowed_channels'])) { return true; }
            if (!is_array($principal['allowed_channels']) || !array_is_list($principal['allowed_channels'])) { return false; }
            $channelFields = ['phones'=>'extensions', 'desktops'=>'desktop_client_ids', 'webhooks'=>'webhook_ids',
                'voice_recipient_ids'=>'voice_recipient_ids', 'email_recipient_ids'=>'email_recipient_ids',
                'sms_recipient_ids'=>'sms_recipient_ids', 'browser_recipient_ids'=>'browser_recipient_ids',
                'connector_ids'=>'connector_ids'];
            foreach ($channelFields as $field => $channel) {
                if (!empty($request[$field]) && !in_array($channel, $principal['allowed_channels'], true)) { return false; }
            }
            return true;
        }
        $allowed = self::allowedAudience($principal, $settings);
        foreach ($allowed as $key => $targets) {
            if (!is_array($request[$key] ?? []) || array_diff(array_map('strval', $request[$key] ?? []), $targets)) { return false; }
        }
        // Future delivery channels require an explicit authorization contract.
        foreach (['emails', 'email_recipients', 'sms_recipients', 'mobile_recipients'] as $key) { if (!empty($request[$key])) { return false; } }
        return true;
    }

    public static function labsDirectory(array $settings): array
    {
        $result=['browser_recipient_ids'=>[],'connector_ids'=>[]];
        $subscribers=$settings['subscriber_browser'] ?? [];
        foreach ($subscribers['people'] ?? [] as $row) {
            if (!is_array($row) || !preg_match('/^sub_[a-f0-9]{24}$/D',$row['id'] ?? '')) { continue; }
            $identity=hash('sha256',json_encode([$row['id'],$row['person_id'] ?? '',$row['name'] ?? '',
                strtolower($row['email'] ?? ''),$row['version'] ?? '',$row['registered_at'] ?? 0],JSON_THROW_ON_ERROR));
            $result['browser_recipient_ids'][$row['id']]=['id'=>$row['id'],'label'=>($row['name'] ?? $row['id']).' · Browser (Labs)',
                'identity'=>$identity,'available'=>($subscribers['enabled'] ?? false)===true && ($subscribers['push']['enabled'] ?? false)===true && ($row['enabled'] ?? false)===true];
        }
        $integrations=$settings['enterprise_integrations'] ?? [];
        foreach ($integrations['connectors']['destinations'] ?? [] as $row) {
            if (!is_array($row) || !preg_match('/^conn_[a-f0-9]{24}$/D',$row['id'] ?? '')) { continue; }
            $fields=[];
            foreach (['id','name','provider','origin','pinned_ipv4','token','topic'] as $key) { $fields[$key]=$row[$key] ?? ''; }
            $result['connector_ids'][$row['id']]=['id'=>$row['id'],'label'=>($row['name'] ?? $row['id']).' · Connector (Labs)',
                'identity'=>hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR)),
                'available'=>($integrations['enabled'] ?? '0')==='1' && ($integrations['connectors']['enabled'] ?? '0')==='1' && ($row['enabled'] ?? '0')==='1'];
        }
        return $result;
    }

    public static function labsBindings(array $members,array $settings,?array $catalog=null): array
    {
        $catalog=$catalog??self::labsDirectory($settings);$bindings=[];
        foreach ($catalog as $field=>$rows) {
            $pattern=$field==='connector_ids' ? '/^conn_[a-f0-9]{24}$/D' : '/^sub_[a-f0-9]{24}$/D';
            foreach (self::values($members[$field] ?? [],$pattern,50) as $id) {
                if (empty($rows[$id]['available'])) { throw new \DomainException('A Labs audience recipient is disabled or missing. Review the saved audience.'); }
                $bindings[$field][$id]=$rows[$id]['identity'];
            }
        }
        return self::normalizeLabsBindings($bindings);
    }

    public static function groupLabsBindingsValid(array $group,array $settings,?array $catalog=null): bool
    {
        try {
            $bindings=self::normalizeLabsBindings(self::labsBindings($group,$settings,$catalog));
            return $bindings===self::normalizeLabsBindings($group['labs_bindings'] ?? []);
        } catch (\Throwable $error) { return false; }
    }

    public static function normalizeLabsBindings($value): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value),['browser_recipient_ids','connector_ids'])) {
            throw new \DomainException('Labs audience enrollment bindings are invalid.');
        }
        foreach ($value as $field=>&$rows) {
            if (!is_array($rows) || ($rows && array_is_list($rows)) || count($rows)>50) { throw new \DomainException('Labs audience enrollment bindings exceed their bound.'); }
            $pattern=$field==='connector_ids' ? '/^conn_[a-f0-9]{24}$/D' : '/^sub_[a-f0-9]{24}$/D';
            foreach ($rows as $id=>$identity) {
                if (!is_string($id) || !preg_match($pattern,$id) || !is_string($identity) || !preg_match('/^[a-f0-9]{64}$/D',$identity)) {
                    throw new \DomainException('A Labs audience has an invalid enrollment fingerprint.');
                }
            }
            ksort($rows,SORT_STRING);
        }
        unset($rows);ksort($value,SORT_STRING);return $value;
    }

    public static function permitsTest(array $principal, array $body, array $settings): bool
    {
        if (!self::permits($principal, 'test')) { return false; }
        if (($principal['audience']['unrestricted'] ?? false) === true) { return true; }
        $ids = $body['zone_ids'] ?? $body['zones'] ?? [];
        if (($body['zone_scope'] ?? 'all') !== 'selected' || !is_array($ids) || !$ids || !array_is_list($ids)) { return false; }
        $known = array_column((array)($settings['nws_zones'] ?? []), 'id');
        foreach ($ids as $id) {
            if (!is_string($id) || !in_array($id, $principal['audience']['nws_zone_ids'] ?? [], true) || !in_array($id, $known, true)) { return false; }
        }
        return true;
    }

    public static function network($value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['trusted_proxy_cidrs'])) { throw new \DomainException('Invalid API network settings.'); }
        $cidrs = self::values($value['trusted_proxy_cidrs'] ?? [], '/^[a-fA-F0-9:.]+(?:\/[0-9]{1,3})?$/D', 32);
        foreach ($cidrs as $cidr) {
            $parts = explode('/', $cidr); $packed = @inet_pton($parts[0]);
            if ($packed === false || (isset($parts[1]) && ((int)$parts[1] < 1 || (int)$parts[1] > strlen($packed) * 8))) {
                throw new \DomainException('Trusted proxies must be explicit IPv4/IPv6 addresses or CIDRs; a /0 network is not allowed.');
            }
        }
        return ['trusted_proxy_cidrs' => $cidrs];
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr); $address = @inet_pton($ip); $network = @inet_pton($parts[0]);
        if ($address === false || $network === false || strlen($address) !== strlen($network)) { return false; }
        $bits = isset($parts[1]) ? (int)$parts[1] : strlen($network) * 8;
        if ($bits < 1 || $bits > strlen($network) * 8) { return false; }
        $bytes = intdiv($bits, 8); $remaining = $bits % 8;
        return substr($address, 0, $bytes) === substr($network, 0, $bytes)
            && (!$remaining || ((ord($address[$bytes]) ^ ord($network[$bytes])) & (255 << (8 - $remaining))) === 0);
    }

    /** Trust only the immediate configured proxy and walk its address chain right-to-left. */
    public static function requestNetwork(array $server, array $settings): array
    {
        $peerValue = $server['REMOTE_ADDR'] ?? '';
        $httpsValue = $server['HTTPS'] ?? '';
        $peer = is_string($peerValue) ? trim($peerValue) : '';
        $directHttps = is_string($httpsValue) && $httpsValue !== '' && strtolower($httpsValue) !== 'off';
        $result = ['ip' => $peer, 'peer' => $peer, 'https' => $directHttps, 'loopback' => false, 'proxied' => false, 'error' => ''];
        if (!filter_var($peer, FILTER_VALIDATE_IP)) { $result['error'] = 'invalid_remote_address'; return $result; }
        try { $cidrs = self::network($settings['api_network'] ?? [])['trusted_proxy_cidrs']; }
        catch (\DomainException $e) { $result['error'] = 'invalid_proxy_configuration'; return $result; }
        $trusted = static function (string $ip) use ($cidrs): bool {
            foreach ($cidrs as $cidr) { if (self::inCidr($ip, $cidr)) { return true; } }
            return false;
        };
        $hasForwarded = false;
        foreach ($server as $key => $unused) {
            if (is_string($key) && ($key === 'HTTP_FORWARDED' || strpos($key, 'HTTP_X_FORWARDED_') === 0)) { $hasForwarded = true; break; }
        }
        $peerTrusted = $trusted($peer);
        $result['loopback'] = in_array($peer, ['127.0.0.1', '::1'], true) && !$hasForwarded && !$peerTrusted;
        if (!$peerTrusted) { return $result; }
        $rawValue = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        $protoValue = $server['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (!is_string($rawValue) || !is_string($protoValue)) { $result['error'] = 'invalid_forwarded_headers'; return $result; }
        $raw = $rawValue; $proto = strtolower(trim($protoValue));
        $chain = array_map('trim', explode(',', $raw));
        if (strlen($raw) > 1024 || count($chain) > 16 || !in_array($proto, ['https', 'http'], true)) { $result['error'] = 'invalid_forwarded_headers'; return $result; }
        foreach ($chain as $ip) { if (!filter_var($ip, FILTER_VALIDATE_IP)) { $result['error'] = 'invalid_forwarded_headers'; return $result; } }
        $ip = $peer;
        for ($index = count($chain) - 1; $index >= 0 && $trusted($ip); --$index) { $ip = $chain[$index]; }
        $result['ip'] = $ip; $result['https'] = $proto === 'https'; $result['proxied'] = true;
        // Proxied addresses never qualify for the local HTTP maintenance exemption.
        return $result;
    }
}
}
