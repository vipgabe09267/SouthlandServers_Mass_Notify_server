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
$priorityValidator = $reflection->getMethod('validateControlApiAnnouncementPayload');
foreach (['normal', 'urgent', [], true, 'urgent;id'] as $priority) {
    foreach ([['priority' => $priority], ['options' => ['priority' => $priority]]] as $payload) {
        $errors = $priorityValidator->invoke($module, $payload);
        if (in_array($priority, ['normal', 'urgent'], true) !== empty($errors)) {
            announcement_contract_fail('Announcement priority schema accepted invalid input or rejected an allowed value.');
        }
    }
}
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
$deliverySource = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/AnnouncementDelivery.php');
$earlyDesktop = strpos($deliverySource, 'if (!$needsDuration) { $publishDesktop(); }');
$audioPreparation = strpos($deliverySource, '$audio = $this->sendAnnouncementTtsAudio');
$durationDesktop = strpos($deliverySource, 'if ($needsDuration) { $publishDesktop(); }');
$phoneDelay = strpos($deliverySource, 'if ($audioQueued) { sleep(1); }');
$webhookDispatch = strpos($deliverySource, '$this->dispatchAnnouncementWebhooks');
if ($earlyDesktop === false || $audioPreparation === false || $durationDesktop === false || $phoneDelay === false
    || $webhookDispatch === false || !($earlyDesktop < $audioPreparation && $audioPreparation < $durationDesktop
    && $durationDesktop < $phoneDelay && $phoneDelay < $webhookDispatch)) {
    announcement_contract_fail('Independent delivery ordering regressed.');
}
require __DIR__ . '/test_independent_channels.php';
foreach ([
    "(!empty(\$selected) || \$desktopRequested) && !is_executable(self::VISUAL_PUSH_SCRIPT)",
    "empty(\$selected) && !\$desktopRequested && !\$webhookRequested",
    "'webhooks' => array_keys(\$selectedAnnouncementWebhooks)",
] as $marker) {
    if (strpos($sendSource, $marker) === false) {
        announcement_contract_fail('Resolved destination validation is missing: ' . $marker);
    }
}

$webhookMethodStart = strpos($classSource, "\n\tprivate function dispatchAnnouncementWebhooks");
$webhookMethodEnd = strpos($classSource, "\n\tprivate function buildAnnouncementVisualPushCommand", $webhookMethodStart ?: 0);
$webhookMethod = ($webhookMethodStart !== false && $webhookMethodEnd !== false)
	? substr($classSource, $webhookMethodStart, $webhookMethodEnd - $webhookMethodStart)
	: '';
foreach (['proc_open($command', "['bypass_shell' => true]", "'--announcement'", "'SLS_NOTIFICATION_LIVE'"] as $marker) {
	if (strpos($webhookMethod, $marker) === false) {
		announcement_contract_fail('Dashboard webhook dispatcher is missing its shell-free bounded handoff: ' . $marker);
	}
}
if (strpos($webhookMethod, 'exec(') !== false || strpos($webhookMethod, 'shell_exec(') !== false) {
	announcement_contract_fail('Dashboard webhook request fields can reach a shell command.');
}

$audioStart = strpos($classSource, "\tprivate function sendAnnouncementTtsAudio");
$audioEnd = strpos($classSource, "\n\tprivate function generateAnnouncementTtsFile", $audioStart === false ? 0 : $audioStart);
if ($audioStart === false || $audioEnd === false) {
	announcement_contract_fail('Unable to isolate announcement audio preparation source.');
}
$audioSource = substr($classSource, $audioStart, $audioEnd - $audioStart);
foreach (['ensureRuntimePermissions(', 'ensurePiperRuntime(', 'ensurePluginDataDir('] as $forbidden) {
	if (strpos($audioSource, $forbidden) !== false) {
		announcement_contract_fail('A dashboard announcement request still invokes privileged runtime mutation: ' . $forbidden);
	}
}
if (strpos($audioSource, 'announcement audio workspace is unavailable') === false) {
	announcement_contract_fail('Announcement runtime validation does not direct the operator to protected repair.');
}

$installStart = strpos($classSource, "\tpublic function install()");
$installEnd = strpos($classSource, "\n\tpublic function uninstall()", $installStart === false ? 0 : $installStart);
$installSource = ($installStart !== false && $installEnd !== false) ? substr($classSource, $installStart, $installEnd - $installStart) : '';
$piperPosition = strpos($installSource, '$this->ensurePiperRuntime();');
$permissionsPosition = strpos($installSource, '$this->ensureRuntimePermissions();');
if ($piperPosition === false || $permissionsPosition === false || $permissionsPosition < $piperPosition) {
	announcement_contract_fail('Install scans the managed data tree before repairing the Piper compatibility boundary.');
}

$dashboard = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/dashboard/views/sections/sls-mass-notify-announcement.php');
foreach ([
	'dashboard-sls-mass-notify-group-result',
	'dashboard-sls-mass-notify-announcement-result',
	'role="status"',
	'aria-live="polite"',
	'aria-atomic="true"',
	'function setAnnouncementStatus',
	'Queueing announcement…',
	'function renderDeliveryStatus',
	'name="announcement_priority"',
	'name="announcement_webhooks[]"',
	"checkbox.name = 'announcement_groups[]'",
	'name="announcement_extensions[]"',
	'name="announcement_desktop_clients[]"',
	'name="announcement_all_phones"',
	'name="announcement_all_desktops"',
	'name="announcement_audio_mode"',
	'name="announcement_opening_tone"',
	'name="announcement_closing_tone"',
	'name="announcement_colored"',
	'Each Discord or compatible HTTPS selection receives a branded Discord embed JSON payload.',
	'Announcement response could not be confirmed. Wait for cooldown status before retrying.',
	'deliveryOutcomeUnknown = true',
	"setCooldownText('Checking delivery status…', 'refresh fa-spin')",
	'class="sls-destination-grid"',
	'<details class="sls-destination-panel">',
	'class="fa fa-circle-o-notch fa-spin sls-submit-spinner"',
	'function setSubmitBusy',
	'requestInFlight',
	"panel.addEventListener('toggle', scheduleDashboardLayout)",
	'lifecycle.timeouts = [35, 180].map',
	'id="dashboard-announcement-character-count"',
	"var lifecycleKey = '__slsMassNotifyAnnouncementWidget'",
	"previousLifecycle.dispose()",
	'this.intervals.forEach(function(timer) { window.clearInterval(timer); })',
	'this.timeouts.forEach(function(timer) { window.clearTimeout(timer); })',
	'this.resizeObserver.disconnect()',
	"window.removeEventListener('resize', this.resizeHandler)",
	'document.documentElement.contains(root)',
	'lifecycle.resizeObserver = new ResizeObserver(scheduleDashboardLayout)',
	"window.addEventListener('resize', lifecycle.resizeHandler)",
] as $marker) {
	if (strpos($dashboard, $marker) === false) {
		announcement_contract_fail('Dashboard delivery feedback contract is missing: ' . $marker);
	}
}
if (substr_count($dashboard, '<details class="sls-destination-panel">') !== 3) {
	announcement_contract_fail('Dashboard destinations are not consolidated into exactly three disclosure panels.');
}
if (substr_count($dashboard, '<section class="sls-step-card"') !== 2) {
	announcement_contract_fail('Dashboard composer is no longer consolidated into two primary sections.');
}
if (substr_count($dashboard, "var lifecycleKey = '__slsMassNotifyAnnouncementWidget'") < 2
	|| substr_count($dashboard, 'lifecycle.intervals.push(window.setInterval(') !== 3
	|| substr_count($dashboard, 'if (!instanceActive())') < 8
	|| strpos($dashboard, 'new ResizeObserver(scheduleDashboardLayout).observe(root)') !== false
	|| strpos($dashboard, "window.addEventListener('resize', scheduleDashboardLayout)") !== false) {
	announcement_contract_fail('Dashboard AJAX-refresh lifecycle cleanup or stale-callback guards regressed.');
}
if (strpos($dashboard, '#dashboard-sls-mass-notify-announcement {') === false
	|| strpos($dashboard, 'grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));') === false
	|| strpos($dashboard, "dashboardItem.style.height = 'auto'") === false
	|| strpos($dashboard, "dashboardContent.style.height = 'auto'") === false
	|| strpos($dashboard, '@media (max-width: 767px)') === false) {
	announcement_contract_fail('Dashboard overflow containment or responsive destination layout is missing.');
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
