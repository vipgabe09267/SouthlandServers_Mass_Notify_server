<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group
$controlUrl = $control_api_url ?? '';
$modulePath = dirname(__DIR__);
$moduleRaw = basename($modulePath);
$settingsPath = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
$shellConfigPath = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.conf';
?>
<div class="container-fluid">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image ?? '']); ?>
	<h2><?php echo _('Help'); ?></h2>
	<p class="lead"><?php echo _('Southland Servers Mass Notifications Server by the Southland Servers Group is an AGPLv3 FreePBX module for SIP NOTIFY alerts, desktop notifications, NWS weather alerts, dashboard announcements, and Piper TTS audio delivery.'); ?></p>

	<h3><?php echo _('Project Status'); ?></h3>
	<ul>
		<li><?php echo _('This is beta software. Test on a non-critical PBX before relying on it for emergency workflows.'); ?></li>
		<li><?php echo _('The module is designed to keep deployment settings outside module code in a centralized .config file so updates do not overwrite local configuration.'); ?></li>
		<li><?php echo _('Custom/local FreePBX module signatures normally show as Unknown. Altered means the module should be signed again on that PBX.'); ?></li>
	</ul>

	<h3><?php echo _('Core Workflows'); ?></h3>
	<ul>
		<li><?php echo _('Live NWS polling runs from cron, reads the centralized config, polls the configured NWS API zone, deduplicates alert chains, applies quiet hours, generates Piper TTS, queues direct Asterisk audio calls, and sends SIP NOTIFY visuals.'); ?></li>
		<li><?php echo _('Dashboard announcements can send phone SIP NOTIFY text, publish to the SLS Mass Notify desktop API, and optionally queue Piper TTS audio first.'); ?></li>
		<li><?php echo _('Manual NWS tests use the same direct audio context and SIP NOTIFY sender as live alerts.'); ?></li>
		<li><?php echo _('Desktop clients poll the token-protected desktop endpoint and display the newest event when latest.id changes.'); ?></li>
	</ul>

	<h3><?php echo _('First-Run Setup'); ?></h3>
	<p><?php echo _('New installs show a mandatory setup modal the first time a Mass Notifications page is opened. Existing deployments that already have setup accepted in the central config are not forced through the wizard during normal updates.'); ?></p>
	<ol>
		<li><?php echo _('Accept the beta at-your-own-risk warning.'); ?></li>
		<li><?php echo _('Accept the AGPL-3.0 license notice.'); ?></li>
		<li><?php echo _('Read and accept the EULA.'); ?></li>
		<li><?php echo _('Choose whether to enable the NWS weather-alert system.'); ?></li>
		<li><?php echo _('If NWS is enabled, enter the zone/county code and choose recipient extensions.'); ?></li>
		<li><?php echo _('Choose Control API, SIP NOTIFY endpoints, TTS voices, TTS volume, and notification log retention.'); ?></li>
	</ol>
	<p><?php echo _('NWS zone/county codes can be found at:'); ?> <a href="https://www.weather.gov/gis/ZoneCounty" target="_blank" rel="noopener noreferrer">https://www.weather.gov/gis/ZoneCounty</a></p>

	<h3><?php echo _('Important Files'); ?></h3>
	<ul>
		<li><code><?php echo htmlspecialchars($modulePath); ?></code> <?php echo sprintf(_('FreePBX module UI and PHP class for module raw name %s.'), htmlspecialchars($moduleRaw)); ?></li>
		<li><code><?php echo htmlspecialchars($settingsPath); ?></code> <?php echo _('central applied configuration file. This JSON .config file is the source of truth for local settings.'); ?></li>
		<li><code>/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.pending.config</code> <?php echo _('staged settings waiting for Apply Config.'); ?></li>
		<li><code><?php echo htmlspecialchars($shellConfigPath); ?></code> <?php echo _('generated shell configuration consumed by scripts.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/config.ini</code> <?php echo _('generated Python SIP NOTIFY sender configuration.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh</code> <?php echo _('live NWS poller.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_test.sh</code> <?php echo _('manual test sender.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_notify.py</code> <?php echo _('SIP NOTIFY and desktop journal publisher.'); ?></li>
		<li><code>/var/www/html/api/sipnotify</code> <?php echo _('desktop and phone-brand SIP NOTIFY API endpoint.'); ?></li>
		<li><code>/var/www/html/api/sls-mass-notify</code> <?php echo _('optional Control API endpoint.'); ?></li>
		<li><code>/etc/asterisk/extensions_custom.conf</code> <?php echo _('managed direct audio context sls-alert-audio.'); ?></li>
	</ul>

	<h3><?php echo _('Central Config, Backup, and Restore'); ?></h3>
	<p><?php echo _('All user-facing Mass Notifications settings should be stored in the central .config file. Generated runtime files are rebuilt from it and should not be edited as the primary configuration method.'); ?></p>
	<ul>
		<li><?php echo _('Download the current .config from Other Settings before major updates.'); ?></li>
		<li><?php echo _('Upload a replacement .config only when intentionally restoring or transplanting a deployment. Replacing it overwrites tokens, endpoints, voices, announcement groups, NWS settings, quiet hours, and retention settings.'); ?></li>
		<li><?php echo _('FreePBX backup support exports the module settings payload where the module backup hook is available. Keep an external .config backup anyway.'); ?></li>
	</ul>

	<h3><?php echo _('NWS Weather Alerts'); ?></h3>
	<ul>
		<li><?php echo _('NWS polling is optional and can be disabled during setup or from NWS Settings.'); ?></li>
		<li><?php echo _('Supported event names are mapped internally to priorities, SIP NOTIFY colors, quiet-hour behavior, and TTS summaries.'); ?></li>
		<li><?php echo _('Quiet hours suppress non-critical configured alerts. Critical bypass events can still notify during quiet hours.'); ?></li>
		<li><?php echo _('TTS is limited to short important alert summaries rather than reading the full NWS alert text.'); ?></li>
	</ul>

	<h3><?php echo _('Dashboard Announcements'); ?></h3>
	<ul>
		<li><?php echo _('The dashboard widget can target online registered extensions, announcement groups, the desktop app, or a combination.'); ?></li>
		<li><?php echo _('Announcement groups can include online or offline extensions. Offline extensions are skipped when sending and the UI warns the sender.'); ?></li>
		<li><?php echo _('If TTS Audio is enabled, the module queues opening tone, Piper TTS, and closing tone first, then sends the text notification after the audio starts.'); ?></li>
		<li><?php echo _('A short cooldown prevents repeated accidental announcement sends.'); ?></li>
	</ul>

	<h3><?php echo _('SIP NOTIFY and Desktop API'); ?></h3>
	<p><?php echo _('The base endpoint is generated from the PBX hostname. The desktop endpoint uses a bearer token. Phone-brand endpoints use their configured username and password.'); ?></p>
	<ul>
		<li><code>/api/sipnotify</code> <?php echo _('defaults to the desktop JSON endpoint.'); ?></li>
		<li><code>/api/sipnotify/desktop</code> <?php echo _('returns JSON for the SLS Mass Notify desktop app.'); ?></li>
		<li><code>/api/sipnotify/yealink</code> <?php echo _('returns Yealink XML payloads.'); ?></li>
		<li><code>/api/sipnotify/cisco</code>, <code>/polycom</code>, <code>/grandstream</code>, <code>/snom</code>, <code>/fanvil</code>, <code>/mitel</code> <?php echo _('return brand-specific or compatible XML where supported.'); ?></li>
	</ul>
	<p><?php echo _('Vendor firmware and provisioning settings can affect XML push behavior. Test each phone brand and firmware before relying on it for emergency workflows.'); ?></p>

	<h3><?php echo _('Control API'); ?></h3>
	<p><?php echo _('Endpoint:'); ?> <code><?php echo htmlspecialchars($controlUrl); ?></code></p>
	<p><?php echo _('The Control API is disabled by default. Enable it only if remote administration is required. Authentication uses Authorization: Bearer <api-key> or X-API-Key.'); ?></p>
	<ul>
		<li><code>GET ?resource=status</code> <?php echo _('returns status JSON.'); ?></li>
		<li><code>GET ?resource=events&amp;limit=25</code> <?php echo _('returns recent event records.'); ?></li>
		<li><code>GET ?resource=config</code> <?php echo _('returns non-secret configuration metadata.'); ?></li>
		<li><code>POST {"action":"send_announcement","message":"...","targets":["1000"],"desktop":true}</code> <?php echo _('sends an announcement through the Mass Notify path.'); ?></li>
	</ul>

	<h3><?php echo _('Email and Discord'); ?></h3>
	<p><?php echo _('Email subjects and bodies support placeholders such as {{event}}, {{severity}}, {{message_type}}, {{audio}}, {{alert_id}}, {{zone}}, {{page_group}}, {{trigger_source}}, {{trigger_name}}, and {{time}}. Email is sent through the local sendmail path.'); ?></p>
	<p><?php echo _('Discord webhooks receive JSON content summarizing the event, severity, recipients, and alert/test metadata. Webhook URLs must match Discord webhook URL format and are stored only in the centralized config.'); ?></p>

	<h3><?php echo _('TTS and Audio'); ?></h3>
	<ul>
		<li><?php echo _('Piper voices are selected separately for announcements and NWS alerts.'); ?></li>
		<li><?php echo _('Volume controls are saved as percentages and applied to the final Asterisk WAV conversion.'); ?></li>
		<li><?php echo _('Opening and closing tones can be selected or uploaded from the SIP Notify/NWS settings pages.'); ?></li>
		<li><?php echo _('Audio delivery uses the private Asterisk context sls-alert-audio and does not require a public paging group such as *6767.'); ?></li>
	</ul>

	<h3><?php echo _('Logs and Health Checks'); ?></h3>
	<ul>
		<li><code>/var/log/sls_mass_notify.log</code> <?php echo _('live NWS poller and test log.'); ?></li>
		<li><code>/var/log/sls_mass_notify_events.jsonl</code> <?php echo _('notification log shown in Notification Logs.'); ?></li>
		<li><code>/var/log/sls_mass_notify_push.log</code> <?php echo _('SIP NOTIFY sender log.'); ?></li>
		<li><code>/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/sipnotify_events.jsonl</code> <?php echo _('desktop API event journal.'); ?></li>
		<li><code>/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json</code> <?php echo _('last poll, delivery, and fault status.'); ?></li>
	</ul>
	<pre>fwconsole ma list | egrep -i 'slsmassnotifyserver|dashboard|Module'
asterisk -rx "dialplan show 1000@sls-alert-audio"
asterisk -rx "manager show users" | grep slsmassnotify
bash -n /usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh
bash -n /usr/local/bin/sls_mass_notify/sls_mass_notify_test.sh
python3 -m py_compile /usr/local/bin/sls_mass_notify/sls_notify.py</pre>

	<h3><?php echo _('Troubleshooting'); ?></h3>
	<ul>
		<li><?php echo _('Desktop app unauthorized: confirm the desktop token under SIP Notify Settings matches the app token and test /api/sipnotify/desktop with Authorization: Bearer <token>.'); ?></li>
		<li><?php echo _('Phone SIP NOTIFY missing: confirm the target extension is registered, AMI user slsmassnotify exists, and /var/log/sls_mass_notify_push.log has no AMI errors.'); ?></li>
		<li><?php echo _('Audio missing: confirm dialplan show <extension>@sls-alert-audio works, Piper generated a WAV under the TTS folder, and Asterisk can read the generated sound path.'); ?></li>
		<li><?php echo _('Module says Altered: remove generated caches such as __pycache__ if present, run the local signing helper, then fwconsole reload.'); ?></li>
		<li><?php echo _('Setup wizard appears after update: verify setup.completed remains 1 in the central .config and no pending config reset it.'); ?></li>
	</ul>

	<h3><?php echo _('License and EULA'); ?></h3>
	<p><?php echo _('This software is licensed under the GNU Affero General Public License version 3 or later. You may use, study, modify, and share it under the AGPLv3 terms.'); ?></p>
	<p><strong><?php echo _('No warranty.'); ?></strong> <?php echo _('The software is provided as-is, without warranties or guarantees of merchantability, fitness for a particular purpose, uninterrupted operation, emergency suitability, or regulatory compliance. You use it at your own risk. The authors, contributors, and Southland Servers Group are not liable for damages, missed alerts, incorrect alerts, service interruption, data loss, device behavior, or any direct, indirect, incidental, special, consequential, or punitive damages.'); ?></p>
	<p><?php echo _('This system is an aid for notifications and should not be treated as the sole source for life-safety, legal, medical, weather, or emergency decisions. Maintain independent alerting paths.'); ?></p>

	<h3><?php echo _('Bugs, Support, and Credits'); ?></h3>
	<p><?php echo _('Report bugs at:'); ?> <a href="https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues" target="_blank" rel="noopener noreferrer">https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues</a></p>
	<p><?php echo _('Project information:'); ?> <a href="https://southlandservers.xyz/projects" target="_blank" rel="noopener noreferrer">https://southlandservers.xyz/projects</a></p>
	<p><?php echo _('Community/support Discord:'); ?> <a href="https://southlandservers.xyz/discord" target="_blank" rel="noopener noreferrer">https://southlandservers.xyz/discord</a></p>
	<p><?php echo _('Credits: Southland Servers Group, FreePBX/Asterisk, National Weather Service API, Piper TTS, and supported SIP phone vendors.'); ?></p>
</div>
