<?php
namespace SLS\MassNotify;

/** Reported client compatibility is advisory, never an authentication decision. */
final class DesktopFleet
{
    public const DEFAULT_MINIMUM = '1.0.10-beta';
    public static function validMinimum($value): bool
    {
        return is_string($value) && ($value === '' || (strlen($value) <= 40
            && preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:alpha|beta|rc)(?:\.[0-9]+)?)?$/D', $value)));
    }

    public static function readiness(array $client, array $seen, string $minimum, ?int $now = null): array
    {
        $now = $now ?? time();
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
            elseif ($version !== '' && self::validMinimum($version) && self::validMinimum($minimum)) {
                $compatibility = $minimum === '' ? 'no_minimum' : (version_compare($version, $minimum, '<') ? 'upgrade_required' : 'meets_minimum');
            }
        } elseif ($report) { $compatibility = 'stale_report'; }
        return ['connection'=>$connection, 'client_version'=>$version, 'compatibility'=>$compatibility,
            'client_reported_at'=>$reportAt !== false ? gmdate('c', $reportAt) : '',
            'payload_schema'=>is_int($report['payload_schema'] ?? null) ? $report['payload_schema'] : null,
            'sse_protocol'=>is_int($report['sse_protocol'] ?? null) ? $report['sse_protocol'] : null];
    }
}
