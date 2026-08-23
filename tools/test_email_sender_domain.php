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

function sender_domain_fail(string $message): void
{
	fwrite(STDERR, $message . "\n");
	exit(1);
}

require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = $reflection->newInstanceWithoutConstructor();
$normalize = $reflection->getMethod('normalizeEmailSenderDomain');
$normalize->setAccessible(true);
$normalizeLocalPart = $reflection->getMethod('normalizeEmailSenderLocalPart');
$normalizeLocalPart->setAccessible(true);

foreach ([
	'PBX.Example.com' => 'pbx.example.com',
	'@example.com' => 'example.com',
	'alerts.example.com.' => 'alerts.example.com',
	'a-b.example.com' => 'a-b.example.com',
] as $input => $expected) {
	$actual = $normalize->invoke($module, $input);
	if ($actual !== $expected) {
		sender_domain_fail("Valid sender domain was not normalized: {$input}");
	}
}

foreach ([
	'no-reply' => 'no-reply',
	'Alerts.Team+PBX' => 'alerts.team+pbx',
] as $input => $expected) {
	if ($normalizeLocalPart->invoke($module, $input) !== $expected) {
		sender_domain_fail("Valid sender local part was not normalized: {$input}");
	}
}
foreach (['', '.alerts', 'alerts.', 'alerts..pbx', 'bad space', str_repeat('a', 65)] as $input) {
	if ($normalizeLocalPart->invoke($module, $input) !== '') {
		sender_domain_fail("Invalid sender local part was accepted: {$input}");
	}
}

$invalid = [
	'', 'localhost', 'https://example.com', 'no-reply@example.com', '192.0.2.10',
	'2001:db8::1', 'bad..example.com', '_mail.example.com', '-bad.example.com',
	'bad-.example.com', str_repeat('a', 64) . '.example.com',
	str_repeat('a.', 126) . 'example.com', "example.com\r\nBcc: attacker@example.com",
];
foreach ($invalid as $input) {
	if ($normalize->invoke($module, $input) !== '') {
		sender_domain_fail("Invalid sender domain was accepted: {$input}");
	}
}

$classSource = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php');
$viewSource = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/views/other_settings.php');
foreach ([
	"'mail_from_domain' => \$defaultMailFromDomain",
	"'mail_from_local_part' => \$defaultMailFromLocalPart",
	"\$settings['mail_from_addr'] = \$settings['mail_from_local_part'] . '@' . \$mailFromDomain",
	"'mail_to', 'mail_from_local_part', 'mail_from_domain'",
] as $marker) {
	if (strpos($classSource, $marker) === false) {
		sender_domain_fail("Missing sender-domain class contract: {$marker}");
	}
}
foreach (['name="mail_from_local_part"', 'name="mail_from_domain"', 'id="sls-mail-from-preview"', 'does not configure a relay'] as $marker) {
	if (strpos($viewSource, $marker) === false) {
		sender_domain_fail("Missing sender-domain UI contract: {$marker}");
	}
}

echo "Email sender-domain PHP contract tests passed.\n";
