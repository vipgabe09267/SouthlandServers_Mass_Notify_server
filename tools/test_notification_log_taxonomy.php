<?php

declare(strict_types=1);

if (!interface_exists('BMO')) { interface BMO {} }
if (!function_exists('load_view')) { function load_view($path, array $variables = []): string { return ''; } }
if (!function_exists('_')) { function _($value) { return $value; } }

require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

function log_taxonomy_fail(string $message): void
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = $reflection->newInstanceWithoutConstructor();
$normalize = $reflection->getMethod('normalizeEvent');
$normalize->setAccessible(true);
$sanitize = $reflection->getMethod('sanitizeType');
$sanitize->setAccessible(true);

$cases = [
	[['type' => 'nws', 'trigger_source' => 'NWS API'], 'weather', 'Weather Alert'],
	[['type' => 'xweather', 'trigger_source' => 'Xweather API'], 'lightning', 'Lightning Alert'],
	[['type' => 'xweather', 'trigger_source' => 'Manual Lightning Test'], 'manual_test', 'Manual Test'],
	[['type' => 'test', 'trigger_source' => 'FreePBX Dashboard'], 'manual_test', 'Manual Test'],
	[['type' => 'announcement', 'trigger_source' => 'FreePBX Dashboard'], 'dashboard', 'Dashboard Announcement'],
	[['type' => 'announcement_audio', 'trigger_source' => 'Control API'], 'api', 'API Announcement'],
	[['type' => 'announcement', 'trigger_source' => 'Scheduled: Weekly drill'], 'scheduling', 'Scheduled Announcement'],
	[['type' => 'desktop', 'trigger_source' => 'Desktop App'], 'desktop', 'Desktop Event'],
	[['type' => 'error', 'message_type' => 'Delivery Fault'], 'system', 'System/Error'],
];
foreach ($cases as [$input, $expectedType, $expectedLabel]) {
	$event = $normalize->invoke($module, $input + ['logged_at' => '2026-09-02T12:00:00-05:00']);
	if (($event['notification_type'] ?? '') !== $expectedType || ($event['notification_type_label'] ?? '') !== $expectedLabel) {
		log_taxonomy_fail('Notification log taxonomy did not classify ' . json_encode($input));
	}
}

foreach ([
	'nws' => 'weather',
	'xweather' => 'lightning',
	'test' => 'manual_test',
	'announcement' => 'dashboard',
	'announcement_audio' => 'dashboard',
	'schedule' => 'scheduling',
] as $legacy => $expected) {
	if ($sanitize->invoke($module, $legacy) !== $expected) {
		log_taxonomy_fail("Legacy log filter {$legacy} is no longer compatible.");
	}
}

$view = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/views/main.php');
foreach (["<?php echo _('Type'); ?>", "<?php echo _('Notification Type'); ?>", 'notification_type_label'] as $marker) {
	if (strpos($view, $marker) === false) {
		log_taxonomy_fail('Notification Logs view is missing: ' . $marker);
	}
}
if (strpos($view, "<th><?php echo _('Severity'); ?></th>") !== false) {
	log_taxonomy_fail('Notification Logs still presents Severity as the table taxonomy.');
}

echo "Notification log taxonomy contract passed.\n";
