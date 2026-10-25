<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/DirectoryConfig.php';
require_once __DIR__.'/EnterpriseIdentityHttp.php';

/** Read-only cloud snapshots. No account, group or consent provisioning. */
final class DirectoryCloud
{
    public const GRAPH_FIELDS = 'id,displayName,mail,userPrincipalName,accountEnabled,officeLocation,businessPhones,mobilePhone';
    public const GOOGLE_FIELDS = 'users(id,name/fullName,primaryEmail,suspended,phones/value),nextPageToken';
    public const GOOGLE_SCOPE = 'https://www.googleapis.com/auth/admin.directory.user.readonly';

    public static function normalize(array $source): array
    {
        if ($source['type'] === 'entra') {
            $out = [];
            foreach (['tenant_id', 'client_id'] as $key) {
                $value = $source[$key] ?? '';
                if (!is_string($value) || !preg_match('/^[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}$/D', $value)) {
                    throw new \DomainException('Entra directory imports require the tenant and application UUIDs.');
                }
                $out[$key] = strtolower($value);
            }
            $secret = $source['cloud_client_secret'] ?? '';
            if (!is_string($secret) || $secret === '' || strlen($secret) > 4096 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
                throw new \DomainException('Enter the Entra application secret value. Only grant read-only User.Read.All permission.');
            }
            return $out + ['cloud_client_secret' => $secret];
        }
        $out = [];
        foreach (['google_client_email', 'google_admin_subject'] as $key) {
            $value = $source[$key] ?? '';
            if (!is_string($value) || strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)
                || preg_match('/[\x00-\x20\x7f]/', $value)) {
                throw new \DomainException('Google directory imports require the service-account email and delegated administrator email.');
            }
            $out[$key] = $value;
        }
        $customer = $source['google_customer'] ?? 'my_customer';
        if (!is_string($customer) || !preg_match('/^(?:my_customer|C[A-Za-z0-9]{1,30})$/D', $customer)) {
            throw new \DomainException('Use my_customer or the Google Workspace customer ID.');
        }
        $pem = $source['google_private_key'] ?? '';
        if (!is_string($pem) || strlen($pem) > 16384 || !str_starts_with($pem, '-----BEGIN PRIVATE KEY-----')) {
            throw new \DomainException('Enter the unencrypted RSA private key from the Google service-account credentials.');
        }
        $key = @openssl_pkey_get_private($pem); $details = $key ? openssl_pkey_get_details($key) : false;
        if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048 || $details['bits'] > 8192) {
            throw new \DomainException('The Google service-account key must be RSA with 2048–8192 bits.');
        }
        return $out + ['google_customer' => $customer, 'google_private_key' => $pem];
    }

    /** Also validates opaque continuation URLs before a credential is attached. */
    public static function requestUrl(string $url): string
    {
        if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)) { throw new \DomainException('Cloud directory URL is invalid.'); }
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
            || ($p['port'] ?? 443) !== 443) { throw new \DomainException('Cloud directory continuation left its approved HTTPS origin.'); }
        $host = $p['host'] ?? ''; $path = $p['path'] ?? '';
        if ($host === 'login.microsoftonline.com' && preg_match('#^/[a-f0-9-]{36}/oauth2/v2.0/token$#D', $path) && !isset($p['query'])) { return $host; }
        if ($host === 'oauth2.googleapis.com' && $path === '/token' && !isset($p['query'])) { return $host; }
        $graph = $host === 'graph.microsoft.com' && $path === '/v1.0/users';
        $google = $host === 'admin.googleapis.com' && $path === '/admin/directory/v1/users';
        if (!$graph && !$google) { throw new \DomainException('Cloud directory continuation left its approved collection.'); }
        $keys = []; $query = [];
        foreach (explode('&', $p['query'] ?? '') as $pair) {
            $parts = explode('=', $pair, 2); $key = rawurldecode($parts[0]); $value = rawurldecode($parts[1] ?? '');
            if (isset($keys[$key]) || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new \DomainException('Cloud directory query is ambiguous.'); }
            $keys[$key] = true; $query[$key] = $value;
        }
        if ($graph) {
            if (array_diff(array_keys($query), ['$top', '$select', '$skiptoken'])
                || (isset($query['$top']) && $query['$top'] !== '200')
                || (isset($query['$select']) && $query['$select'] !== self::GRAPH_FIELDS)
                || (!isset($query['$skiptoken']) && !isset($query['$select']))) {
                throw new \DomainException('Unsupported Graph directory continuation query.');
            }
        } elseif (array_diff(array_keys($query), ['customer', 'maxResults', 'fields', 'pageToken'])
            || !preg_match('/^(?:my_customer|C[A-Za-z0-9]{1,30})$/D', $query['customer'] ?? '')
            || ($query['maxResults'] ?? '') !== '200' || ($query['fields'] ?? '') !== self::GOOGLE_FIELDS) {
            throw new \DomainException('Unsupported Google directory query.');
        }
        return $host;
    }

    private static function phones(array $values): array
    {
        if (count($values) > 20) { throw new \DomainException('A cloud directory record has too many phone values. No import was made.'); }
        $out = [];
        foreach ($values as $value) {
            if ($value === null || $value === '') { continue; }
            $out[] = EnterpriseIdentityConfig::text($value, 80, 'Imported phone candidate');
        }
        return array_slice(array_values(array_unique($out)), 0, 5);
    }

    public static function fetch(array $source, ?callable $http = null, ?callable $clock = null): array
    {
        if (!in_array($source['type'] ?? '', ['entra', 'google'], true) || empty($source['enabled'])) {
            throw new \DomainException('Enable a saved cloud directory source before requesting its dry run.');
        }
        $clock ??= static fn(): float => microtime(true); $deadline = $clock() + 45;
        $http ??= [EnterpriseIdentityHttp::class, 'directoryRequest'];
        $request = static function(string $url, string $method = 'GET', array $form = [], array $headers = []) use ($http, $clock, $deadline): array {
            $host = self::requestUrl($url); $remaining = (int)floor(($deadline - $clock()) * 1000);
            if ($remaining <= 0) { throw new \DomainException('Cloud directory exceeded its 45-second snapshot deadline. No partial import was made.'); }
            $result = $http($url, [$host], $method, $form, $headers, min(12000, $remaining));
            if ($clock() >= $deadline) { throw new \DomainException('Cloud directory snapshot timed out. No partial import was made.'); }
            return $result;
        };
        $source = array_replace($source, self::normalize($source));
        if ($source['type'] === 'entra') {
            $token = $request('https://login.microsoftonline.com/'.$source['tenant_id'].'/oauth2/v2.0/token', 'POST', [
                'grant_type' => 'client_credentials', 'client_id' => $source['client_id'], 'client_secret' => $source['cloud_client_secret'],
                'scope' => 'https://graph.microsoft.com/.default']);
            $url = 'https://graph.microsoft.com/v1.0/users?'.http_build_query(['$top' => 200, '$select' => self::GRAPH_FIELDS], '', '&', PHP_QUERY_RFC3986);
        } else {
            require_once __DIR__.'/identity-libs/vendor/autoload.php';
            $assertion = \Firebase\JWT\JWT::encode(['iss' => $source['google_client_email'], 'sub' => $source['google_admin_subject'],
                'scope' => self::GOOGLE_SCOPE, 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time() - 30, 'exp' => time() + 300], $source['google_private_key'], 'RS256');
            $token = $request('https://oauth2.googleapis.com/token', 'POST', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]);
            $url = 'https://admin.googleapis.com/admin/directory/v1/users?'.http_build_query([
                'customer' => $source['google_customer'], 'maxResults' => 200, 'fields' => self::GOOGLE_FIELDS], '', '&', PHP_QUERY_RFC3986);
        }
        if (!is_string($token['access_token'] ?? null) || strlen($token['access_token']) > 8192 || $token['access_token'] === ''
            || preg_match('/[\x00-\x20\x7f]/', $token['access_token']) || strtolower($token['token_type'] ?? '') !== 'bearer') {
            throw new \DomainException('Cloud directory did not return a valid bearer token.');
        }
        $rows = []; $visited = [];
        for ($page = 0; $page < 10; $page++) {
            if (isset($visited[$url])) { throw new \DomainException('Cloud directory repeated a continuation. No import was made.'); }
            $visited[$url] = true;
            $data = $request($url, 'GET', [], ['Accept: application/json', 'Authorization: Bearer '.$token['access_token']]);
            if (isset($data['error'])) { throw new \DomainException('Cloud directory returned an error. No import was made.'); }
            $users = $data[$source['type'] === 'entra' ? 'value' : 'users'] ?? null;
            // Google omits users for an empty complete result.
            if ($users === null && $source['type'] === 'google' && !isset($data['nextPageToken'])) { $users = []; }
            if (!is_array($users) || !array_is_list($users) || count($users) > 200) { throw new \DomainException('Cloud directory returned an incomplete or oversized page.'); }
            foreach ($users as $u) {
                if (!is_array($u)) { throw new \DomainException('Cloud directory returned an invalid person.'); }
                if ($source['type'] === 'entra') {
                    if (!is_bool($u['accountEnabled'] ?? null) || !is_array($u['businessPhones'] ?? []) || !array_is_list($u['businessPhones'] ?? [])) {
                        throw new \DomainException('Graph did not supply readable account status or phone values. Check read-only User.Read.All consent.');
                    }
                    $phones = self::phones(array_merge($u['businessPhones'] ?? [], [$u['mobilePhone'] ?? '']));
                    $row = ['external_id' => $u['id'] ?? '', 'name' => $u['displayName'] ?? '', 'email' => $u['mail'] ?? $u['userPrincipalName'] ?? '',
                        'active' => $u['accountEnabled'], 'location' => $u['officeLocation'] ?? '', 'phone_candidates' => $phones];
                } else {
                    if (!is_bool($u['suspended'] ?? null) || !is_array($u['phones'] ?? []) || !array_is_list($u['phones'] ?? [])) { throw new \DomainException('Google directory person status or phones are invalid.'); }
                    $values = [];
                    foreach ($u['phones'] ?? [] as $phone) { if (!is_array($phone) || !is_string($phone['value'] ?? null)) { throw new \DomainException('Google directory phone is invalid.'); } $values[] = $phone['value']; }
                    $row = ['external_id' => $u['id'] ?? '', 'name' => $u['name']['fullName'] ?? '', 'email' => $u['primaryEmail'] ?? '',
                        'active' => !$u['suspended'], 'phone_candidates' => self::phones($values)];
                }
                $rows[] = $row;
                if (count($rows) > 1000) { throw new \DomainException('Cloud directory exceeds 1000 people. No partial import was made.'); }
            }
            $next = $data[$source['type'] === 'entra' ? '@odata.nextLink' : 'nextPageToken'] ?? '';
            if (!is_string($next) || strlen($next) > 8192) { throw new \DomainException('Cloud directory continuation is invalid.'); }
            if ($next === '') { return DirectoryConfig::records($rows); }
            if (!$users) { throw new \DomainException('Cloud directory returned an empty partial page. No import was made.'); }
            if ($source['type'] === 'entra') { $url = $next; }
            else { $url = preg_replace('/&pageToken=[^&]*$/D', '', $url).'&pageToken='.rawurlencode($next); }
            if (self::requestUrl($url) !== ($source['type'] === 'entra' ? 'graph.microsoft.com' : 'admin.googleapis.com')) {
                throw new \DomainException('Cloud continuation must remain on the directory collection.');
            }
        }
        throw new \DomainException('Cloud directory did not complete within ten pages. No partial import was made.');
    }
}
