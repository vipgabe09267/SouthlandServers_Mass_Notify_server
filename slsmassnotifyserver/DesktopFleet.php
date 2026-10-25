<?php
namespace SLS\MassNotify;

/** Reported client compatibility is advisory, never an authentication decision. */
final class DesktopFleet
{
    public const DEFAULT_MINIMUM = '1.0.13-beta';
    public static function validMinimum($value): bool
    {
        return is_string($value) && ($value === '' || (strlen($value) <= 40
            && preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:alpha|beta|rc)(?:\.[0-9]+)?)?$/D', $value)));
    }

    private static function versionParts(string $value): ?array
    {
        if (strlen($value) > 64 || !preg_match(
            '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D',
            $value, $parts
        )) { return null; }
        $prerelease = isset($parts[4]) && $parts[4] !== '' ? explode('.', $parts[4]) : [];
        foreach ($prerelease as $identifier) {
            if (ctype_digit($identifier) && strlen($identifier) > 1 && $identifier[0] === '0') { return null; }
        }
        return ['core'=>[$parts[1], $parts[2], $parts[3]], 'prerelease'=>$prerelease];
    }

    public static function validReportedVersion(string $value): bool
    {
        return self::versionParts($value) !== null;
    }

    /** SemVer precedence, without converting untrusted numeric identifiers to integers. */
    public static function compareVersions(string $left, string $right): int
    {
        $a = self::versionParts($left); $b = self::versionParts($right);
        if ($a === null || $b === null) { throw new \InvalidArgumentException('A reported version is not valid SemVer.'); }
        $number = static function(string $x,string $y): int {
            $length=strlen($x)<=>strlen($y);
            return $length!==0?$length:(strcmp($x,$y)<=>0);
        };
        foreach ($a['core'] as $index=>$part) {
            $result = $number($part, $b['core'][$index]);
            if ($result !== 0) { return $result; }
        }
        if (!$a['prerelease'] || !$b['prerelease']) { return (int)!$a['prerelease'] <=> (int)!$b['prerelease']; }
        foreach ($a['prerelease'] as $index=>$part) {
            if (!isset($b['prerelease'][$index])) { return 1; }
            $other = $b['prerelease'][$index];
            $numeric = ctype_digit($part); $otherNumeric = ctype_digit($other);
            $result = $numeric && $otherNumeric ? $number($part, $other)
                : ($numeric !== $otherNumeric ? ($numeric ? -1 : 1) : strcmp($part, $other) <=> 0);
            if ($result !== 0) { return $result; }
        }
        return count($a['prerelease']) <=> count($b['prerelease']);
    }

    public static function readiness(array $client, array $seen, string $minimum, ?int $now = null): array
    {
        $now = $now ?? time();
        if ($minimum === '') $minimum = self::DEFAULT_MINIMUM;
        $enabled = !empty($client['enabled']);
        if (($seen['client_id'] ?? '') !== ($client['client_id'] ?? '') || ($client['client_id'] ?? '') === '') { $seen = []; }
        $report = is_array($seen['client_report'] ?? null) ? $seen['client_report'] : [];
        $version = is_string($report['version'] ?? null) && strlen($report['version']) <= 64 ? $report['version'] : '';
        $at = is_string($seen['seen_at'] ?? null) ? strtotime($seen['seen_at']) : false;
        $reportAt = is_string($report['reported_at'] ?? null) ? strtotime($report['reported_at']) : false;
        $reportFresh = $reportAt !== false && $reportAt <= $now + 60 && $reportAt >= $now - 86400;
        $connectedUntil = is_int($seen['connected_until'] ?? null) ? $seen['connected_until'] : 0;
        $connection = !$enabled ? 'disabled' : ($at === false ? 'never' : ($at > $now + 60 ? 'clock_error'
            : ($connectedUntil >= $now && $connectedUntil <= $now + 120 ? 'streaming'
            : ($at >= $now - 90 ? 'recent' : 'inactive'))));
        $compatibility = 'not_reported';
        if ($reportFresh) {
            $payloadReported = is_int($report['payload_schema'] ?? null);
            $streamReported = is_int($report['sse_protocol'] ?? null);
            if (!$payloadReported || !$streamReported) { $compatibility = 'protocol_not_reported'; }
            elseif ($report['payload_schema'] !== 1 || $report['sse_protocol'] !== 2) { $compatibility = 'unsupported_protocol'; }
            elseif ($version !== '' && !self::validReportedVersion($version)) { $compatibility = 'invalid_version'; }
            elseif ($version !== '' && self::validMinimum($minimum)) {
                $compatibility = $minimum === '' ? 'no_minimum' : (self::compareVersions($version, $minimum) < 0 ? 'upgrade_required' : 'meets_minimum');
            }
        } elseif ($report) { $compatibility = 'stale_report'; }
        $versionRequired = !self::validMinimum($minimum) || $minimum === '' ? self::DEFAULT_MINIMUM : $minimum;
        $upgradeRequired = !$reportFresh || !self::validReportedVersion($version)
            || self::compareVersions($version, $versionRequired) < 0;
        return ['connection'=>$connection, 'client_version'=>$version, 'compatibility'=>$compatibility,
            'upgrade_required'=>$upgradeRequired,
            'client_reported_at'=>$reportAt !== false ? gmdate('c', $reportAt) : '',
            'payload_schema'=>is_int($report['payload_schema'] ?? null) ? $report['payload_schema'] : null,
            'sse_protocol'=>is_int($report['sse_protocol'] ?? null) ? $report['sse_protocol'] : null];
    }
}
