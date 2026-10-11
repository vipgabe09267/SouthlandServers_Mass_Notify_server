<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__ . '/BetaNotice.php';
use SLS\MassNotify\BetaNotice;

trait SlsBetaNotice
{
    public function renderBetaNotice(): string
    {
        if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return ''; }
        try {
            if (!$this->isSetupWizardComplete()) { return ''; }
            $principal = $this->assertEnterpriseAdministrator();
            if (BetaNotice::acknowledged($this->getActiveSettings(), $principal['username'])) { return ''; }
        }
        catch (\Throwable $error) { return ''; }
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
