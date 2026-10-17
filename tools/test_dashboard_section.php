<?php
declare(strict_types=1);

if (!function_exists('_')) {
    function _(string $value): string
    {
        return $value;
    }
}

function load_view(string $path, array $data): string
{
    $GLOBALS['dashboard_fixture_view'] = [$path, $data];
    return 'fixture-dashboard-view';
}

final class DashboardModuleFixture
{
    public bool $allowed = true;

    public function operatorPageAllowed(string $page): bool
    {
        if ($page !== 'slsmassnotifyserver') {
            throw new RuntimeException('Dashboard checked an unexpected permission.');
        }
        return $this->allowed;
    }

    public function isSetupWizardComplete(): bool
    {
        return false;
    }

    public function getSetupRequiredMessage(): string
    {
        return 'Setup required';
    }

    public function getSetupWizardModalHtml(bool $dashboard): string
    {
        return $dashboard ? '<div>fixture modal</div>' : '';
    }

    public function getCsrfToken(): string
    {
        return str_repeat('a', 64);
    }

    public function __call(string $method, array $arguments)
    {
        throw new RuntimeException('Incomplete setup invoked expensive dashboard method: ' . $method);
    }
}

final class FreePBX
{
    public static DashboardModuleFixture $module;

    public static function Slsmassnotifyserver(): DashboardModuleFixture
    {
        return self::$module;
    }
}

require dirname(__DIR__) . '/slsmassnotifyserver/dashboard/sections/SlsMassNotifyAnnouncement.class.php';

use FreePBX\modules\Dashboard\Sections\SlsMassNotifyAnnouncement;

FreePBX::$module = new DashboardModuleFixture();
$section = new SlsMassNotifyAnnouncement();
$definitions = $section->getSections(null);
if (($definitions[0]['section'] ?? null) !== 'sls_mass_notify_announcement'
    || ($definitions[0]['order'] ?? null) !== '50') {
    throw new RuntimeException('Dashboard section metadata did not tolerate a missing order map.');
}
if ($section->getContent('unrelated') !== '' || isset($GLOBALS['dashboard_fixture_view'])) {
    throw new RuntimeException('Dashboard rendered content for an unrelated section.');
}
$rendered = $section->getContent('sls_mass_notify_announcement');
$view = $GLOBALS['dashboard_fixture_view'] ?? null;
if ($rendered !== 'fixture-dashboard-view' || !is_array($view)) {
    throw new RuntimeException('Dashboard setup-required view was not rendered.');
}
$data = $view[1];
foreach ([
    'announcement_targets',
    'announcement_group_targets',
    'announcement_desktop_clients',
    'outbound_voice_recipients',
    'announcement_email_recipients',
    'announcement_sms_recipients',
    'announcement_webhooks',
    'announcement_groups',
    'source_picker_choices',
    'announcement_tones',
    'available_system_sounds',
    'recording_catalogue',
] as $field) {
    if (($data[$field] ?? null) !== []) {
        throw new RuntimeException('Incomplete setup populated dashboard field: ' . $field);
    }
}
FreePBX::$module->allowed = false;
unset($GLOBALS['dashboard_fixture_view']);
$denied = $section->getContent('sls_mass_notify_announcement');
if (!str_contains($denied, '/mass-notify/') || isset($GLOBALS['dashboard_fixture_view'])) {
    throw new RuntimeException('Restricted dashboard access did not use the operator portal notice.');
}

echo "Dashboard section: unrelated, restricted and incomplete-setup paths remain lightweight.\n";
