<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/AutomationConfig.php';

final class AutomationInputs
{
    public static function authenticate(array $rule, string $raw, array $headers, int $now): bool
    {
        if (!in_array($rule['kind'],['panic','sensor'],true) || !$rule['enabled'] || strlen($raw)>16384) { return false; }
        $timestamp=$headers['timestamp'] ?? ''; $signature=$headers['signature'] ?? '';
        if (!is_string($timestamp) || !preg_match('/^[0-9]{10}$/D',$timestamp) || abs($now-(int)$timestamp)>60
            || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D',$signature)) { return false; }
        // Bind the endpoint identity as well as the exact raw JSON bytes.
        return hash_equals(hash_hmac('sha256',$rule['id'].'.'.$timestamp.'.'.$raw,$rule['secret']),$signature);
    }
    private static function timestamp(string $value): int
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',$value)) { throw new \InvalidArgumentException('CAP timestamps require explicit timezone offsets.'); }
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$value);
        $errors=\DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) { throw new \InvalidArgumentException('CAP contains an invalid timestamp.'); }
        return $date->getTimestamp();
    }
    private static function reference(array $rule, string $sender, string $identifier, int $sent): string
    {
        return hash('sha256',json_encode([$rule['id'],$sender,$identifier,$sent],JSON_THROW_ON_ERROR));
    }
    /** CAP 1.2 alert or Atom feed with inline CAP alerts. External XML is forbidden. */
    public static function cap(string $xml, array $rule): array
    {
        if (strlen($xml)>1048576 || preg_match('/<!DOCTYPE|<!ENTITY/i',$xml)) { throw new \InvalidArgumentException('CAP XML exceeds 1 MiB or contains prohibited document/entity declarations.'); }
        if (!class_exists('\DOMDocument')) { throw new \RuntimeException('CAP parsing requires the PHP XML extension. Install php-xml before enabling this feed.'); }
        $before=libxml_use_internal_errors(true); $doc=new \DOMDocument();
        try { $ok=$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($before); }
        if (!$ok || !$doc->documentElement || $doc->doctype) { throw new \InvalidArgumentException('CAP feed is not valid XML.'); }
        $xpath=new \DOMXPath($doc); $xpath->registerNamespace('c','urn:oasis:names:tc:emergency:cap:1.2'); $xpath->registerNamespace('a','http://www.w3.org/2005/Atom');
        $alerts=$xpath->query('/c:alert | /a:feed/a:entry/c:alert | /a:feed/a:entry/a:content/c:alert');
        if (!$alerts || $alerts->length>50 || ($alerts->length===0 && !($doc->documentElement->namespaceURI==='http://www.w3.org/2005/Atom' && $doc->documentElement->localName==='feed'))) { throw new \InvalidArgumentException('Expected CAP 1.2 XML or an Atom feed containing at most 50 inline CAP alerts. Linked CAP entries require a direct CAP URL.'); }
        $out=[];
        foreach ($alerts as $alert) {
            $text=static function(string $field, \DOMNode $parent) use($xpath): string {
                $nodes=$xpath->query('c:'.$field,$parent);
                if ($nodes->length>1) { throw new \InvalidArgumentException('CAP contains duplicate '.$field.' fields.'); }
                return $nodes->length ? trim($nodes->item(0)->textContent) : '';
            };
            $sender=$text('sender',$alert); $identifier=$text('identifier',$alert);
            if ($sender!==$rule['sender']) { continue; }
            $identifier=IncidentConfig::text($identifier,200,'CAP identifier'); $sent=self::timestamp($text('sent',$alert));
            $status=$text('status',$alert); $type=$text('msgType',$alert);
            if (!in_array($status,['Actual','Test','Exercise'],true) || $text('scope',$alert)!=='Public' || !in_array($type,['Alert','Update','Cancel'],true)) { continue; }
            $own=self::reference($rule,$sender,$identifier,$sent); $refs=[];
            $rawReferences=$text('references',$alert);
            if (strlen($rawReferences)>32768) { throw new \InvalidArgumentException('CAP reference list is too large.'); }
            if ($rawReferences!=='') {
                foreach (preg_split('/\s+/',$rawReferences) as $reference) {
                    $parts=explode(',',$reference);
                    if (count($parts)!==3 || $parts[0]!==$sender || count($refs)>=50) { throw new \InvalidArgumentException('CAP references must identify at most 50 messages from the configured sender.'); }
                    $refs[]=self::reference($rule,$parts[0],IncidentConfig::text($parts[1],200,'Referenced CAP identifier'),self::timestamp($parts[2]));
                }
            }
            if (in_array($type,['Update','Cancel'],true) && !$refs) { throw new \InvalidArgumentException('CAP Update/Cancel requires explicit source references.'); }
            $base=['request_id'=>$own,'sent_at'=>$sent,'is_test'=>$status!=='Actual','caller'=>'','references'=>[$own],
                'invalidate'=>$type==='Alert' ? [] : $refs,'operation'=>strtolower($type)];
            if ($type==='Cancel') { $out[]=$base+['expires_at'=>$sent+$rule['max_age_seconds'],'event'=>$rule['event'],'message'=>'']; continue; }
            $infos=$xpath->query('c:info',$alert);
            if ($infos->length>20) { throw new \InvalidArgumentException('CAP contains too many language/info blocks.'); }
            foreach ($infos as $info) {
                if ($text('event',$info)!==$rule['event']) { continue; }
                $language=$text('language',$info);
                if ($language!=='' && !preg_match('/^en(?:-|$)/i',$language)) { continue; }
                $expires=self::timestamp($text('expires',$info));
                $effective=$text('effective',$info);
                // No future activation or implicit severity/recipient escalation.
                if ($effective!=='' && self::timestamp($effective)>time()+15) { continue; }
                $headline=$text('headline',$info);
                if ($headline==='') { $headline=$rule['event']; }
                $out[]=$base+['expires_at'=>$expires,'event'=>$rule['event'],'message'=>IncidentConfig::text($headline,200,'CAP headline')];
                break;
            }
        }
        return $out;
    }
    /** Public HTTPS feed, DNS pinned per request, no redirect or ambient proxy credentials. */
    public static function publicAddress(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
        $number = ip2long($ip);
        // Explicitly exclude non-global ranges omitted by some PHP releases.
        foreach (['0.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','192.0.0.0/24',
            '192.0.2.0/24','192.88.99.0/24','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/3'] as $range) {
            [$network, $bits] = explode('/', $range); $mask = -1 << (32 - (int)$bits);
            if (($number & $mask) === (ip2long($network) & $mask)) { return false; }
        }
        return true;
    }

    public static function fetch(string $url): string
    {
        $parts=parse_url($url); $host=$parts['host'] ?? ''; $port=$parts['port'] ?? 443;
        if (($parts['scheme'] ?? '')!=='https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !preg_match('/^[A-Za-z0-9.-]{1,253}$/D',$host) || $port!==443) { throw new \DomainException('Trusted CAP feeds require a public HTTPS hostname on port 443.'); }
        $addresses=gethostbynamel($host);
        if (!$addresses || count($addresses)>32) { throw new \RuntimeException('CAP feed hostname could not be resolved.'); }
        foreach ($addresses as $ip) {
            if (!self::publicAddress($ip)) { throw new \DomainException('CAP feed DNS contains a private or reserved address.'); }
        }
        $handle=curl_init($url); $body='';
        curl_setopt_array($handle,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$addresses[0]],
            CURLOPT_HTTPHEADER=>['Accept: application/cap+xml, application/atom+xml, application/xml','Accept-Encoding: identity'],
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use(&$body): int { if (strlen($body)+strlen($chunk)>1048576) { return 0; } $body.=$chunk; return strlen($chunk); }]);
        try {
            $ok=curl_exec($handle); $status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if ($ok===false || $status!==200) { throw new \RuntimeException('CAP feed fetch failed, exceeded 8 seconds/1 MiB, or returned HTTP '.$status.'. Redirects are not followed.'); }
            return $body;
        } finally { curl_close($handle); }
    }
}
