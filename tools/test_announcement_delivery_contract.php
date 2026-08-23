<?php

declare(strict_types=1);

if (!interface_exists('BMO')) { interface BMO {} }
if (!function_exists('load_view')) { function load_view($path, array $variables = []): string { return ''; } }
if (!function_exists('_')) { function _($value) { return $value; } }

require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

function announcement_contract_fail(string $message): void
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = $reflection->newInstanceWithoutConstructor();
$buildCommand = $reflection->getMethod('buildAnnouncementVisualPushCommand');
$buildCommand->setAccessible(true);

$apiOnly = (string)$buildCommand->invoke($module, 'Contract announcement', [], 300, [
	'mode' => 'api_only',
	'image' => true,
	'title' => 'Contract title',
	'background_color' => '#112233',
	'desktop_clients' => ['desk-one', 'desk-two'],
]);
if (substr_count($apiOnly, ' --api-only') !== 1
	|| strpos($apiOnly, ' --no-api') !== false
	|| strpos($apiOnly, ' --targets ') !== false
	|| strpos($apiOnly, ' --desktop-targets ') === false
	|| strpos($apiOnly, 'desk-one,desk-two') === false
	|| strpos($apiOnly, ' --announcement-timeout-seconds 300') === false) {
	announcement_contract_fail('Desktop publication command is not an isolated, targeted API-only submission.');
}

$phoneOnly = (string)$buildCommand->invoke($module, 'Contract announcement', ['1000', '1001'], 45, [
	'mode' => 'phone_only',
	'image' => false,
]);
if (substr_count($phoneOnly, ' --no-api') !== 1
	|| strpos($phoneOnly, ' --api-only') !== false
	|| strpos($phoneOnly, ' --targets ') === false
	|| strpos($phoneOnly, '1000,1001') === false
	|| strpos($phoneOnly, ' --desktop-targets ') !== false
	|| strpos($phoneOnly, ' --desktop-all') !== false) {
	announcement_contract_fail('Phone SIP NOTIFY command can still republish a desktop event.');
}

$classSource = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php');
$sendStart = strpos($classSource, "\tpublic function sendSipNotifyAnnouncement");
$sendEnd = strpos($classSource, "\n\tprivate function buildAnnouncementVisualPushCommand", $sendStart ?: 0);
if ($sendStart === false || $sendEnd === false) {
	announcement_contract_fail('Unable to isolate the announcement delivery orchestration source.');
}
$sendSource = substr($classSource, $sendStart, $sendEnd - $sendStart);
$earlyDesktop = strpos($sendSource, 'if ($desktopRequested && !$desktopNeedsAudioDuration)');
$audioPreparation = strpos($sendSource, '$audioResult = $this->sendAnnouncementTtsAudio');
$durationDesktop = strpos($sendSource, 'if ($desktopNeedsAudioDuration)', ($audioPreparation === false ? 0 : $audioPreparation));
$phoneDelay = strpos($sendSource, 'sleep($notifyDelay)');
if ($earlyDesktop === false || $audioPreparation === false || $durationDesktop === false || $phoneDelay === false
	|| !($earlyDesktop < $audioPreparation && $audioPreparation < $durationDesktop && $durationDesktop < $phoneDelay)) {
	announcement_contract_fail('Desktop publication is not ordered before TTS, or immediately after duration discovery and before the phone delay.');
}
foreach (['desktop_publish_failed_after_audio', 'audio_failed_after_desktop', 'notify_failed_after_desktop', "'partial_delivery' => true"] as $marker) {
	if (strpos($sendSource, $marker) === false) {
		announcement_contract_fail('Mixed-channel partial-delivery reporting is missing: ' . $marker);
	}
}

$dashboard = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/dashboard/views/sections/sls-mass-notify-announcement.php');
foreach ([
	'dashboard-sls-mass-notify-group-result',
	'dashboard-sls-mass-notify-announcement-result',
	'role="status"',
	'aria-live="polite"',
	'aria-atomic="true"',
	'function setAnnouncementStatus',
	'Preparing announcement and starting the selected delivery channels',
	'Announcement response could not be confirmed. Wait for cooldown status before retrying.',
	'deliveryOutcomeUnknown = true',
	"cooldown.textContent = 'Checking delivery status…'",
] as $marker) {
	if (strpos($dashboard, $marker) === false) {
		announcement_contract_fail('Dashboard delivery feedback contract is missing: ' . $marker);
	}
}
$buttonPosition = strpos($dashboard, 'dashboard-sls-mass-notify-announcement-submit');
$inlineStatusPosition = strpos($dashboard, 'dashboard-sls-mass-notify-announcement-result', $buttonPosition === false ? 0 : $buttonPosition);
$formSubmitPosition = strpos($dashboard, "form.addEventListener('submit'");
$progressPosition = strpos($dashboard, "setAnnouncementStatus('info'", $formSubmitPosition === false ? 0 : $formSubmitPosition);
$fetchPosition = strpos($dashboard, 'fetch(form.action', $progressPosition === false ? 0 : $progressPosition);
if ($buttonPosition === false || $inlineStatusPosition === false || $formSubmitPosition === false
	|| $progressPosition === false || $fetchPosition === false
	|| $inlineStatusPosition < $buttonPosition || $progressPosition > $fetchPosition) {
	announcement_contract_fail('Dashboard progress is not visible beside Send before the request begins.');
}

echo "Announcement delivery contract tests passed.\n";
