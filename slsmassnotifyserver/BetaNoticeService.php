<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__ . '/BetaNotice.php';
use SLS\MassNotify\BetaNotice;

trait SlsBetaNotice
{
    private function betaNoticeRequired(): bool
    {
        try {
            if (!$this->isSetupWizardComplete()) { return false; }
            $principal = $this->assertEnterpriseAdministrator();
        }
        catch (\Throwable $error) { return false; }
        try {
            // Read the protected bytes without normalizing unrelated settings.
            // A damaged beta receipt must display the warning rather than make
            // the warning disappear with the normalization error.
            $settings = $this->loadSettingsFile(self::SETTINGS_JSON);
        } catch (\Throwable $error) {
            error_log('SLS Mass Notify beta acknowledgment could not be read; the mandatory warning remains active.');
            return true;
        }
        try {
            return !BetaNotice::acknowledged($settings, $principal['username']);
        } catch (\Throwable $error) {
            error_log('SLS Mass Notify beta acknowledgment is invalid; the mandatory warning remains active.');
            return true;
        }
    }
    public function renderBetaNotice(): string
    {
        if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !$this->betaNoticeRequired()) { return ''; }
        return load_view(__DIR__ . '/views/beta_notice.php', ['csrf_token' => $this->getCsrfToken()]);
    }
    public function betaNoticeAction(string $action, array $input): array
    {
        $principal = $this->assertEnterpriseAdministrator();
        if (!$this->isSetupWizardComplete()) { throw new \DomainException('Complete the setup wizard before acknowledging the beta warning.'); }
        if ($action === 'beta_notice_begin') {
            if (BetaNotice::acknowledged($this->getActiveSettings(), $principal['username'])) { return ['success' => true, 'acknowledged' => true]; }
            return ['success' => true, 'acknowledged' => false] + BetaNotice::begin($_SESSION, $principal['username'], session_id());
        }
        if ($action !== 'beta_notice_accept') { throw new \InvalidArgumentException('Unsupported beta warning action.'); }
        $notice = BetaNotice::accept($_SESSION, $input, $principal['username'], session_id());
        $this->saveEnterpriseNamespace('beta_notice', $notice);
        return ['success' => true, 'acknowledged' => true];
    }
}
