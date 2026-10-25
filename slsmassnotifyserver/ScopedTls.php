<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Trust applies to one saved integration, without changing the PBX trust store. */
final class ScopedTls
{
    public static function normalize($value, ?int $now = null): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value), ['ca_pem','public_key_pin'])) {
            throw new \DomainException('Integration TLS policy contains unsupported fields.');
        }
        $ca = $value['ca_pem'] ?? ''; $pin = $value['public_key_pin'] ?? '';
        if (!is_string($ca) || strlen($ca) > 16384 || !is_string($pin)) { throw new \DomainException('Integration CA certificates must fit within 16 KiB.'); }
        $ca = trim(str_replace("\r\n", "\n", $ca));
        if ($ca !== '') {
            preg_match_all('/-----BEGIN CERTIFICATE-----\n[A-Za-z0-9+\/=\n]+\n-----END CERTIFICATE-----/', $ca, $matches);
            if (!$matches[0] || count($matches[0]) > 4 || preg_replace('/\s+/', '', implode('', $matches[0])) !== preg_replace('/\s+/', '', $ca)) {
                throw new \DomainException('Use one through four PEM CA certificates without private keys or other content.');
            }
            foreach ($matches[0] as $pem) {
                $cert = @openssl_x509_read($pem); $details = $cert ? openssl_x509_parse($cert) : false;
                $public = $cert ? openssl_pkey_get_public($cert) : false; $key = $public ? openssl_pkey_get_details($public) : false;
                if (!$details || !$key || ($now !== null && (($details['validFrom_time_t'] ?? PHP_INT_MAX) > $now || ($details['validTo_time_t'] ?? 0) <= $now))
                    || !str_contains($details['extensions']['basicConstraints'] ?? '', 'CA:TRUE')
                    || (isset($details['extensions']['keyUsage']) && !str_contains($details['extensions']['keyUsage'], 'Certificate Sign'))
                    || preg_match('/(?:md5|sha1)/i', $details['signatureTypeSN'] ?? '')
                    || ($key['type'] === OPENSSL_KEYTYPE_RSA && $key['bits'] < 2048)
                    || ($key['type'] === OPENSSL_KEYTYPE_EC && $key['bits'] < 256)
                    || !in_array($key['type'], [OPENSSL_KEYTYPE_RSA, OPENSSL_KEYTYPE_EC], true)) {
                    throw new \DomainException('Each integration CA must be currently valid, use a supported strong key and permit certificate signing.');
                }
            }
            $ca = implode("\n", $matches[0])."\n";
        }
        if ($pin !== '') {
            if (!preg_match('#^sha256//([A-Za-z0-9+/]{43}=)$#D', $pin, $m)
                || !is_string($decoded = base64_decode($m[1], true)) || strlen($decoded) !== 32 || base64_encode($decoded) !== $m[1]) {
                throw new \DomainException('Use a canonical sha256// base64 SHA-256 public-key pin.');
            }
        }
        return array_filter(['ca_pem' => $ca, 'public_key_pin' => $pin], static fn($v) => $v !== '');
    }

    public static function curlOptions(array $value): array
    {
        $value = self::normalize($value,time()); $out = [];
        if (isset($value['ca_pem'])) {
            if (!defined('CURLOPT_CAINFO_BLOB')) { throw new \DomainException('This PHP/libcurl runtime cannot use an integration-specific CA certificate. Update PHP/libcurl; TLS verification remains required.'); }
            $out[CURLOPT_CAINFO_BLOB] = $value['ca_pem'];
        }
        if (isset($value['public_key_pin'])) {
            if (!defined('CURLOPT_PINNEDPUBLICKEY')) { throw new \DomainException('This PHP/libcurl runtime does not support public-key pinning.'); }
            $out[CURLOPT_PINNEDPUBLICKEY] = $value['public_key_pin'];
        }
        return $out;
    }
}
