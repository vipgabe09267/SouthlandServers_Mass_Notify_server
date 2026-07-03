<?php

namespace FreePBX\modules\Dashboard\Sections;

class SlsMassNotifyAnnouncement {
	public $rawname = 'SlsMassNotifyAnnouncement';

	public function getSections($order) {
		return [[
			'title' => _('SIP NOTIFY Announcements'),
			'group' => _('Announcements'),
			'width' => '550px',
			'order' => $order['sls_mass_notify_announcement'] ?? '50',
			'section' => 'sls_mass_notify_announcement',
		]];
	}

	public function getContent($section) {
		try {
			$module = \FreePBX::Slsmassnotifyserver();
			$setupComplete = $module->isSetupWizardComplete();
			return load_view(dirname(__DIR__) . '/views/sections/sls-mass-notify-announcement.php', [
				'setup_complete' => $setupComplete,
				'setup_required_message' => $module->getSetupRequiredMessage(),
				'setup_modal' => $setupComplete ? '' : $module->getSetupWizardModalHtml(true),
				'announcement_targets' => $module->getSipNotifyTargets(),
				'announcement_group_targets' => $module->getAllPjsipExtensions(),
				'announcement_groups' => $module->getAnnouncementGroups(),
				'announcement_cooldown_remaining' => $module->getCooldownState()['announcement']['remaining'] ?? 0,
				'announcement_state' => $module->getAnnouncementDashboardState(),
			]);
		} catch (\Throwable $e) {
			return '<div class="alert alert-warning">' . htmlspecialchars(_('Unable to load SIP NOTIFY announcement controls.')) . '</div>';
		}
	}
}
