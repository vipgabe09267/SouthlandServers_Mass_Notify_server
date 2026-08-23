<?php

declare(strict_types=1);

if (!interface_exists('BMO')) {
	interface BMO {}
}
if (!function_exists('load_view')) {
	function load_view($path, array $variables = []): string
	{
		return '';
	}
}
if (!function_exists('_')) {
	function _($value)
	{
		return $value;
	}
}

date_default_timezone_set('UTC');
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

function xweather_usage_fail(string $message): void
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$fixture = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('buildXweatherApiUsageSummary');
$method->setAccessible(true);
$now = strtotime('Sat, 22 Aug 2026 00:00:00 GMT');
$settings = ['xweather' => ['query_interval_minutes' => 5]];

$expired = $method->invoke($fixture, [
	'xweather_rate_limit_period' => 15000,
	'xweather_rate_remaining_period' => 14084,
	'xweather_rate_reset_period' => 'Mon, 17 Aug 2026 00:00:00 GMT',
	'xweather_rate_observed_at' => '2026-08-12T15:30:00+00:00',
	'xweather_last_query_cost_tokens' => 10,
], $settings, $now);
if (($expired['period_state'] ?? '') !== 'expired'
	|| ($expired['snapshot_current'] ?? true) !== false
	|| ($expired['used'] ?? 0) !== 916
	|| !array_key_exists('estimated_days_remaining', $expired)
	|| $expired['estimated_days_remaining'] !== null
	|| ($expired['observed_at_formatted'] ?? '') === ''
	|| ($expired['reset_at_formatted'] ?? '') === '') {
	xweather_usage_fail('An expired Xweather snapshot was still presented as current.');
}

$current = $method->invoke($fixture, [
	'xweather_rate_limit_period' => 15000,
	'xweather_rate_remaining_period' => 14990,
	'xweather_rate_reset_period' => 'Thu, 17 Sep 2026 00:00:00 GMT',
	'xweather_rate_reset_epoch' => strtotime('Thu, 17 Sep 2026 00:00:00 GMT'),
	'xweather_rate_observed_at' => '2026-08-21T23:00:00+00:00',
	'xweather_last_query_cost_tokens' => 10,
], $settings, $now);
if (($current['period_state'] ?? '') !== 'current'
	|| ($current['snapshot_current'] ?? false) !== true
	|| ($current['snapshot_age_seconds'] ?? 0) !== 3600
	|| ($current['estimated_days_remaining'] ?? null) !== 5.2) {
	xweather_usage_fail('A current Xweather snapshot did not retain its deterministic usage metadata.');
}

$unknown = $method->invoke($fixture, [
	'xweather_rate_limit_period' => 15000,
	'xweather_rate_remaining_period' => 14000,
	'xweather_rate_reset_period' => 'invalid reset value',
], $settings, $now);
if (($unknown['period_state'] ?? '') !== 'unknown' || ($unknown['snapshot_current'] ?? true) !== false) {
	xweather_usage_fail('An unparseable Xweather period was labeled current.');
}

$unavailable = $method->invoke($fixture, [], $settings, $now);
if (($unavailable['period_state'] ?? '') !== 'unavailable' || ($unavailable['snapshot_current'] ?? true) !== false) {
	xweather_usage_fail('Missing Xweather counters were not labeled unavailable.');
}

echo "Xweather account-period usage regressions passed.\n";
