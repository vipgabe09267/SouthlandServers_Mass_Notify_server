<?php
declare(strict_types=1);

namespace FreePBX\modules\Dashboard\Sections;

class SlsMassNotifyAnnouncement
{
	public $rawname = 'SlsMassNotifyAnnouncement';

	public function getSections($order)
	{
		$order = is_array($order) ? $order : [];
		return [[
			'title' => _('SLS Mass Notify Announcements'),
			'group' => _('Announcements'),
			'width' => '680px',
			'order' => $order['sls_mass_notify_announcement'] ?? '50',
			'section' => 'sls_mass_notify_announcement',
		]];
	}

	public function getContent($section)
	{
		if ($section !== 'sls_mass_notify_announcement') {
			return '';
		}
		try {
			$module = \FreePBX::Slsmassnotifyserver();
			if (!$module->operatorPageAllowed('slsmassnotifyserver')) {
				return '<div class="alert alert-info">Use <a href="/mass-notify/" target="_blank" rel="noopener noreferrer">SLS Operations</a> for your assigned sites and audiences.</div>';
			}
			$setupComplete = $module->isSetupWizardComplete();
			$moduleDirectory = dirname((new \ReflectionClass($module))->getFileName());
			$data = [
				'recipient_selection_view' => $moduleDirectory . '/views/recipient_selection.php',
				'setup_complete' => $setupComplete,
				'setup_required_message' => $module->getSetupRequiredMessage(),
				'setup_modal' => $setupComplete ? '' : $module->getSetupWizardModalHtml(true),
				'announcement_targets' => [],
				'announcement_group_targets' => [],
				'announcement_desktop_clients' => [],
				'outbound_voice_recipients' => [],
				'announcement_email_recipients' => [],
				'announcement_sms_recipients' => [],
				'announcement_webhooks' => [],
				'announcement_groups' => [],
				'source_picker_choices' => [],
				'announcement_cooldown_remaining' => 0,
				'announcement_state' => [],
				'announcement_tones' => [],
				'available_system_sounds' => [],
				'recording_catalogue' => [],
				'system_recording_options_view' => $moduleDirectory . '/views/system_recording_options.php',
				'csrf_token' => $module->getCsrfToken(),
			];
			if ($setupComplete) {
				$data = array_replace($data, [
					'announcement_targets' => $module->getSipNotifyTargets(),
					'announcement_group_targets' => $module->getAllPjsipExtensions(),
					'announcement_desktop_clients' => $module->getDesktopClients(),
					'outbound_voice_recipients' => $module->getOutboundVoiceRecipients(),
					'announcement_email_recipients' => $module->getAnnouncementEmailRecipients(),
					'announcement_sms_recipients' => $module->getAnnouncementSmsRecipients(),
					'announcement_webhooks' => $module->getAnnouncementWebhookDestinations(),
					'announcement_groups' => $module->getAnnouncementGroups(),
					'source_picker_choices' => $module->getAudiencePickerChoices(),
					'announcement_cooldown_remaining' => $module->getCooldownState()['announcement']['remaining'] ?? 0,
					'announcement_state' => $module->getAnnouncementDashboardState(),
					'announcement_tones' => $module->getAvailableTones(),
					'available_system_sounds' => $module->getAvailableSystemSounds(),
					'recording_catalogue' => method_exists($module, 'getSystemRecordingCatalogue')
						? $module->getSystemRecordingCatalogue('', 0, 500)
						: [],
				]);
			}
			return load_view(
				dirname(__DIR__) . '/views/sections/sls-mass-notify-announcement.php',
				$data
			);
		} catch (\Throwable $e) {
			return '<div class="alert alert-warning">'
				. htmlspecialchars(
					_('Unable to load SLS Mass Notify announcement controls.'),
					ENT_QUOTES | ENT_SUBSTITUTE,
					'UTF-8'
				)
				. '</div>';
		}
	}
}
