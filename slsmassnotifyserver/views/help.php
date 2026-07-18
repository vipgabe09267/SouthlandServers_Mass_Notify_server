<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group
$controlUrl = $control_api_url ?? '';
$modulePath = dirname(__DIR__);
$moduleRaw = basename($modulePath);
$settingsPath = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
$diagnostics = is_array($diagnostics ?? null) ? $diagnostics : [];
$endpointDiagnostics = array_values((array)($diagnostics['endpoints'] ?? []));
$desktopDiagnostics = array_values((array)($diagnostics['desktop_clients'] ?? []));
$controlApiAudit = array_values((array)($diagnostics['control_api_audit'] ?? []));
?>
<style>
.sls-help-scroll-table {
	max-height: 300px;
	overflow-y: auto;
	overflow-x: auto;
	margin-bottom: 12px;
}
.sls-help-scroll-table table {
	margin-bottom: 0;
}
.sls-help-scroll-table td,
.sls-help-scroll-table th {
	vertical-align: middle !important;
	overflow-wrap: anywhere;
}
.sls-help-diagnostics .panel {
	margin-bottom: 16px;
}
.sls-help-diagnostics .panel-heading h4 {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}
.sls-help-diagnostics code {
	white-space: normal;
	word-break: break-word;
}
</style>
<div class="container-fluid">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image ?? '']); ?>
	<h2><?php echo _('Help'); ?></h2>
	<p class="lead"><?php echo _('Southland Servers Mass Notifications Server by the Southland Servers Group is an AGPLv3 FreePBX module for SIP NOTIFY alerts, desktop notifications, NWS weather alerts, dashboard announcements, and Piper TTS audio delivery.'); ?></p>

	<h3><?php echo _('Project Status'); ?></h3>
	<ul>
		<li><?php echo _('This is beta software. Test on a non-critical PBX before relying on it for emergency workflows.'); ?></li>
		<li><?php echo _('The module is designed to keep deployment settings outside module code in a centralized .config file so updates do not overwrite local configuration.'); ?></li>
		<li><?php echo _('Custom/local FreePBX module signatures normally show as Unknown. Altered means the module should be signed again on that PBX.'); ?></li>
		<li><?php echo _('General Settings shows the installed package version and whether the known release status is LATEST or an update is available.'); ?></li>
		<li><?php echo _('After a Dashboard or Framework upgrade, Repair Installation restores the managed announcement widget and menu placement, rebuilds the stored Dashboard hook index, and verifies that the announcement controls render. Framework 17.0.30 and earlier Framework 17 menu comparator forms are supported.'); ?></li>
	</ul>
	<p><?php echo _('Generated phone images use the automatically detected, read-only Public PBX Hostname shown in General Settings. Phone Image Transport remains configurable: HTTP is the compatibility default for legacy Yealink models such as the T48G, while HTTPS should be selected only when target phones trust the PBX certificate and support its TLS configuration. Authenticated APIs remain HTTPS.'); ?></p>
	<p><?php echo _('Use the Phone Format Overrides manager in General Settings only when automatic endpoint detection is wrong. Enter the extension and select a supported phone family from the list; the saved value is written to the protected central config.'); ?></p>
	<p><?php echo _('Yealink overrides are labeled “Yealink - Color” and “Yealink - Text Only.” Panasonic KX phones are detected from registered User-Agent data and can also be selected manually. Unknown endpoints remain visible in diagnostics but are not offered as a manual format.'); ?></p>

	<h3><?php echo _('Diagnostics'); ?></h3>
	<div class="sls-help-diagnostics">
		<div class="panel panel-default">
			<div class="panel-heading"><h4><?php echo _('System Checks'); ?></h4></div>
			<div class="panel-body">
				<div class="sls-help-scroll-table">
					<table class="table table-condensed table-striped">
						<thead><tr><th><?php echo _('Check'); ?></th><th><?php echo _('State'); ?></th><th><?php echo _('Detail'); ?></th></tr></thead>
						<tbody>
							<?php foreach ((array)($diagnostics['checks'] ?? []) as $check) { ?>
								<tr>
									<td><?php echo htmlspecialchars($check['label'] ?? ''); ?></td>
									<td><?php echo !empty($check['ok']) ? '<span class="label label-success">OK</span>' : '<span class="label label-warning">Check</span>'; ?></td>
									<td><code><?php echo htmlspecialchars((string)($check['detail'] ?? '')); ?></code></td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<div class="panel panel-default">
			<div class="panel-heading"><h4><?php echo _('Detected Phone Endpoints'); ?></h4></div>
			<div class="panel-body">
			<?php if (empty($endpointDiagnostics)) { ?>
				<p class="text-muted"><?php echo _('No registered phone endpoints were detected or AMI endpoint detection is unavailable.'); ?></p>
			<?php } else { ?>
				<div class="sls-help-scroll-table">
					<table class="table table-condensed table-striped">
						<thead><tr><th><?php echo _('Ext'); ?></th><th><?php echo _('Format'); ?></th><th><?php echo _('Contacts'); ?></th><th><?php echo _('User Agent'); ?></th></tr></thead>
						<tbody>
							<?php foreach ($endpointDiagnostics as $endpoint) { ?>
								<tr>
									<td><?php echo htmlspecialchars($endpoint['extension'] ?? ''); ?></td>
									<td>
										<?php $formats = array_values((array)($endpoint['formats'] ?? [$endpoint['format'] ?? 'unknown'])); ?>
										<?php if (!empty($endpoint['unknown'])) { ?>
											<span class="label label-warning">&#9733; <?php echo _('Unknown'); ?></span>
										<?php } else { ?>
											<span class="label label-info"><?php echo htmlspecialchars(implode(', ', $formats)); ?></span>
										<?php } ?>
										<?php if (!empty($endpoint['override'])) { ?><span class="label label-default"><?php echo _('override'); ?></span><?php } ?>
									</td>
									<td><?php echo (int)($endpoint['contacts'] ?? 1); ?></td>
									<td><?php echo htmlspecialchars($endpoint['user_agent'] ?? ''); ?></td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			<?php } ?>
			</div>
		</div>
		<div class="panel panel-default">
			<div class="panel-heading"><h4><?php echo _('Desktop Clients'); ?></h4></div>
			<div class="panel-body">
				<?php if (empty($desktopDiagnostics)) { ?>
					<p class="text-muted"><?php echo _('No desktop clients are configured.'); ?></p>
				<?php } else { ?>
					<div class="sls-help-scroll-table">
						<table class="table table-condensed table-striped">
							<thead><tr><th><?php echo _('Client'); ?></th><th><?php echo _('Last Seen'); ?></th><th><?php echo _('State'); ?></th></tr></thead>
							<tbody>
								<?php foreach ($desktopDiagnostics as $client) { ?>
									<tr>
										<td><?php echo htmlspecialchars(($client['name'] ?? '') . ' (' . ($client['client_id'] ?? '') . ')'); ?></td>
										<td><?php echo htmlspecialchars(($client['last_seen_at'] ?? '') ?: _('Never')); ?> <?php echo !empty($client['last_seen_ip']) ? htmlspecialchars(' from ' . $client['last_seen_ip']) : ''; ?></td>
										<td>
											<?php $state = (string)($client['state'] ?? 'never'); ?>
											<span class="label <?php echo $state === 'recent' ? 'label-success' : ($state === 'stale' ? 'label-warning' : 'label-default'); ?>"><?php echo htmlspecialchars($state); ?></span>
										</td>
									</tr>
								<?php } ?>
							</tbody>
						</table>
					</div>
				<?php } ?>
			</div>
		</div>
		<div class="panel panel-default">
			<div class="panel-heading"><h4><?php echo _('Recent Control API Use'); ?></h4></div>
			<div class="panel-body">
				<?php if (empty($controlApiAudit)) { ?>
					<p class="text-muted"><?php echo _('No Control API audit entries are available.'); ?></p>
				<?php } else { ?>
					<div class="sls-help-scroll-table">
						<table class="table table-condensed table-striped">
							<thead><tr><th><?php echo _('Time'); ?></th><th><?php echo _('IP'); ?></th><th><?php echo _('Action'); ?></th><th><?php echo _('Status'); ?></th></tr></thead>
							<tbody>
								<?php foreach ($controlApiAudit as $event) { ?>
									<tr>
										<td><?php echo htmlspecialchars($event['created_at'] ?? ''); ?></td>
										<td><?php echo htmlspecialchars($event['ip'] ?? ''); ?></td>
										<td><?php echo htmlspecialchars($event['action'] ?? ''); ?></td>
										<td><?php echo htmlspecialchars((string)($event['status'] ?? '')); ?></td>
									</tr>
								<?php } ?>
							</tbody>
						</table>
					</div>
				<?php } ?>
			</div>
		</div>
	</div>

	<h3><?php echo _('Core Workflows'); ?></h3>
	<ul>
		<li><?php echo _('The one-minute weather scheduler reads the centralized config, polls up to five independent NWS zone groups, deduplicates alert chains, applies quiet hours, and can also check the optional Xweather lightning API.'); ?></li>
		<li><?php echo _('Dashboard announcements can send phone SIP NOTIFY text, publish to the SLS Mass Notify desktop API, and independently use opening/closing tones, Piper TTS, both, or neither.'); ?></li>
		<li><?php echo _('Manual NWS tests use the same direct audio context and SIP NOTIFY sender as live alerts and can target all or selected weather-zone groups.'); ?></li>
		<li><?php echo _('Desktop clients can receive authenticated live server-sent events or use the backward-compatible JSON endpoint with their assigned username and password.'); ?></li>
	</ul>

	<h3><?php echo _('First-Run Setup'); ?></h3>
	<p><?php echo _('New installs show a mandatory setup modal the first time a Mass Notifications page is opened. Existing deployments that already have setup accepted in the central config are not forced through the wizard during normal updates.'); ?></p>
	<ol>
		<li><?php echo _('Accept the beta at-your-own-risk warning.'); ?></li>
		<li><?php echo _('Accept the AGPL-3.0 license notice.'); ?></li>
		<li><?php echo _('Read and accept the EULA.'); ?></li>
		<li><?php echo _('Choose whether to enable the NWS weather-alert system.'); ?></li>
			<li><?php echo _('If NWS is enabled, configure up to five named zone/county groups and choose recipient extensions for each group.'); ?></li>
			<li><?php echo _('Choose Control API, desktop clients, TTS voices, TTS volume, and notification log retention. The General Settings desktop-client table keeps approximately five rows visible, then scrolls with a sticky header.'); ?></li>
	</ol>
	<p><?php echo _('NWS zone/county codes can be found at:'); ?> <a href="https://www.weather.gov/gis/ZoneCounty" target="_blank" rel="noopener noreferrer">https://www.weather.gov/gis/ZoneCounty</a></p>

	<h3><?php echo _('Notification Logs'); ?></h3>
	<ul>
		<li><?php echo _('Use the Event Type selector and calendar field together to filter notification history by category and PBX-local date. Clear Filters returns to the complete recent view.'); ?></li>
		<li><?php echo _('The row limit is applied after the selected type and date filters, so the requested number of matching events is retained.'); ?></li>
	</ul>

	<h3><?php echo _('Important Files'); ?></h3>
	<ul>
		<li><code><?php echo htmlspecialchars($modulePath); ?></code> <?php echo sprintf(_('FreePBX module UI and PHP class for module raw name %s.'), htmlspecialchars($moduleRaw)); ?></li>
		<li><code><?php echo htmlspecialchars($settingsPath); ?></code> <?php echo _('central applied configuration file. This JSON .config file is the source of truth for local settings.'); ?></li>
		<li><code>/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.pending.config</code> <?php echo _('staged settings waiting for Apply Config.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh</code> <?php echo _('one-minute multi-zone NWS and Xweather scheduler.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh</code> <?php echo _('single-zone NWS worker launched by the scheduler.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py</code> <?php echo _('optional Xweather lightning worker.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_test.sh</code> <?php echo _('manual test sender.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh</code> <?php echo _('root-owned manual and automatic beta updater.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh</code> <?php echo _('root-owned worker for queued repairs, manual updates, and complete uninstall requests.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_uninstall.sh</code> <?php echo _('standalone cleanup path used by the confirmed Danger Zone uninstall action; if the FreePBX repository is unavailable, cleaned Dashboard and Framework copies receive a locally verified fallback signature and the private key is removed.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/piper/venv</code> <?php echo _('root-owned Piper executable environment, also exposed through the compatibility path /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv.'); ?></li>
		<li><code>/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices</code> <?php echo _('checksum-verified Piper voice models.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_notify.py</code> <?php echo _('SIP NOTIFY and desktop journal publisher.'); ?></li>
		<li><code>/var/www/html/api/sipnotify</code> <?php echo _('authenticated desktop notification API endpoint.'); ?></li>
		<li><code>/var/www/html/api/sls-mass-notify</code> <?php echo _('optional Control API endpoint.'); ?></li>
		<li><code>/etc/asterisk/extensions_custom.conf</code> <?php echo _('managed direct audio context sls-alert-audio and per-contact PJSIP header context sls-alert-autoanswer.'); ?></li>
	</ul>

	<h3><?php echo _('Central Config, Backup, and Restore'); ?></h3>
	<p><?php echo _('All user-facing Mass Notifications settings are stored in the central .config file. Runtime programs read that JSON source directly; there is no generated shell or Python settings copy to drift out of sync.'); ?></p>
	<ul>
		<li><?php echo _('Download the current .config from General Settings before major updates.'); ?></li>
		<li><?php echo _('Upload a replacement .config only when intentionally restoring or transplanting a deployment. Replacing it overwrites credentials, desktop clients, phone overrides, voices, announcement groups, NWS settings, quiet hours, and retention settings.'); ?></li>
		<li><?php echo _('FreePBX backup support exports the module settings payload where the module backup hook is available. Keep an external .config backup anyway.'); ?></li>
		<li><?php echo _('Danger Zone separates repair, complete uninstall, and configuration replacement into distinct confirmed actions. Repair preserves the central .config; complete uninstall and configuration replacement are destructive.'); ?></li>
	</ul>

	<h3><?php echo _('Weather Alerts'); ?></h3>
	<ul>
		<li><?php echo _('The setup wizard defaults Weather Alerts to No and keeps weather-specific fields hidden and excluded from validation until Yes is selected.'); ?></li>
		<li><?php echo _('Weather polling is optional and can be disabled during setup or from Weather Alerts. It supports U.S. weather.gov zones only. Up to five named weather-zone groups can each have their own phone recipients.'); ?></li>
		<li><?php echo _('Supported event names are mapped internally to priorities, SIP NOTIFY colors, quiet-hour behavior, and TTS summaries.'); ?></li>
		<li><?php echo _('Quiet hours suppress non-critical configured alerts. Critical bypass events can still notify during quiet hours.'); ?></li>
		<li><?php echo _('TTS is limited to short important alert summaries rather than reading the full NWS alert text.'); ?></li>
	</ul>

	<h3><?php echo _('Lightning Alerts'); ?></h3>
	<ul>
		<li><?php echo _('Lightning Alerts is labeled Labs while the Xweather integration continues beta testing. The setup wizard defaults it to No and does not require credentials, recipients, or a Weather trigger zone unless the administrator opts in.'); ?></li>
		<li><?php echo _('Lightning Alerts uses protected Xweather credentials, cloud-to-ground strikes, a location, radius, recipients, and a configurable 1–10 minute API query period.'); ?></li>
		<li><?php echo _('Adaptive protection is enabled by default and requires one selected Weather Alert zone. Its green shield means Xweather remains idle until that zone has an active Weather.gov thunderstorm event, then polls every five minutes through the grace period, which defaults to 60 minutes. Turning the toggle off changes the card to a red shield and polls continuously at the configured period regardless of NWS conditions; Lightning outside an NWS event can be missed while protection is enabled.'); ?></li>
		<li><?php echo _('Xweather usage is measured in cost tokens. The optimized nearest-strike query currently costs 10 tokens; continuous five-minute polling would exceed a 15,000-token monthly allowance. The adaptive quota governor limits scheduled use to no more than the allowance and reports standby or quota-guard state on the Dashboard.'); ?></li>
		<li><?php echo _('The 5-minute default is the longest gap-free period for standard Xweather access. Periods from 6–10 minutes can miss strikes unless the subscription includes extended lightning history.'); ?></li>
		<li><?php echo _('A storm creates one entry alert using the nearest strike distance reported by Xweather, rounded to one decimal mile. Repeated strikes do not alert again until two clear queries reset the state; an optional all-clear can be sent. Lightning uses its own quiet-hours toggle and opening/closing tones.'); ?></li>
		<li><?php echo _('Regular announcements, Weather Alerts, and Lightning Alerts default to 25% audio volume and retain independent 1–200% controls. Coordinate locations are announced as “this area,” while named locations use the configured city. Every Lightning audio sequence retains one second of leading silence before the pre-tone and speech.'); ?></li>
		<li><?php echo _('The Lightning system test has a dedicated 60-second anti-spam cooldown. Its phone message, speech, email subject, and branded email card are explicitly marked TEST ONLY and do not represent an actual strike.'); ?></li>
		<li><?php echo _('The saved Xweather Client Secret is masked on the page and can be revealed with the eye button by an authenticated FreePBX administrator. Diagnostics and API responses continue to redact it.'); ?></li>
	</ul>

	<h3><?php echo _('Dashboard Announcements'); ?></h3>
	<ul>
		<li><?php echo _('The dashboard widget can target online registered extensions, all phones, selected desktop clients, all desktops, announcement groups, or a combination.'); ?></li>
		<li><?php echo _('Announcement groups can include online or offline extensions plus desktop app clients. Offline extensions are skipped when sending and the UI warns the sender.'); ?></li>
		<li><?php echo _('Announcement audio can be disabled, tones only, TTS only, or tones plus TTS. Opening and closing recordings can be selected per announcement, and either may be None without changing the dialplan.'); ?></li>
		<li><?php echo _('The Labs colored-announcement designer provides a title, background color, and preview. Colored image announcements are currently limited to compatible Yealink phones; other vendors receive their text format.'); ?></li>
		<li><?php echo _('A short cooldown prevents repeated accidental announcement sends.'); ?></li>
	</ul>

	<h3><?php echo _('SIP NOTIFY and Desktop API'); ?></h3>
	<p><?php echo _('Phones receive SIP NOTIFY pushes directly from Asterisk/PJSIP using their registered endpoints. Desktop clients authenticate with their assigned username and password and can use either the live event stream or the JSON endpoint.'); ?></p>
	<ul>
		<li><code>/api/sipnotify/desktop</code> <?php echo _('returns JSON for the SLS Mass Notify desktop app. Use HTTP Basic authentication with the desktop client username and password configured in General Settings.'); ?></li>
		<li><code>/api/sipnotify/desktop/stream</code> <?php echo _('returns a live server-sent-event stream using the same Basic authentication and per-client target filtering. The authenticated handshake is flushed through Apache immediately; clients should reconnect after the server reconnect event and may send Last-Event-ID when resuming.'); ?></li>
		<li><?php echo _('A desktop application must connect to the /stream endpoint to receive live pushes. Applications that continue requesting the /desktop JSON fallback remain polling clients by design.'); ?></li>
		<li><?php echo _('Live and JSON notification records contain a presentation object plus flat compatibility fields. Weather supplies priority-derived background, header, accent, and text colors; colored announcements preserve the selected title/background; Lightning supplies its branded warning color.'); ?></li>
		<li><?php echo _('Each desktop only receives events sent to all desktops or events explicitly targeted to its username. Legacy records without routing fields are denied.'); ?></li>
	</ul>
	<p><?php echo _('Vendor firmware and provisioning settings can affect XML push behavior. Test each detected endpoint format and firmware before relying on it for emergency workflows.'); ?></p>

	<h3><?php echo _('Control API'); ?></h3>
	<p><?php echo _('Endpoint:'); ?> <code><?php echo htmlspecialchars($controlUrl); ?></code></p>
	<p><?php echo _('The Control API is disabled by default. Enable it only if remote administration is required. Authentication uses Authorization: Bearer <api-key> or X-API-Key. Successful loopback get_config/get_status health probes are excluded from Recent Control API Use, while failures and meaningful API actions remain auditable.'); ?></p>
	<ul>
		<li><code>GET ?resource=status</code> <?php echo _('returns status JSON.'); ?></li>
		<li><code>GET ?resource=events&amp;limit=25</code> <?php echo _('returns recent event records.'); ?></li>
		<li><code>GET ?resource=config</code> <?php echo _('returns configuration with API keys, AMI credentials, desktop encryption material, desktop passwords, and webhooks redacted. Secrets are never returned by this API.'); ?></li>
		<li><code>POST {"action":"send_announcement","message":"...","targets":["1000"],"groups":["Operations"],"desktop_clients":["cli_a1b2c3"],"tts":true}</code> <?php echo _('sends an announcement, optionally with TTS audio, phone targets, desktop client IDs, and announcement groups.'); ?></li>
		<li><code>POST {"action":"send_announcement","message":"...","all_phones":true,"all_desktops":true}</code> <?php echo _('targets every currently available phone and every configured desktop client.'); ?></li>
		<li><code>POST {"action":"send_announcement","message":"...","style":"colored","title":"Announcement","background_color":"#991b1b"}</code> <?php echo _('renders a colored announcement image where supported by the endpoint format.'); ?></li>
		<li><code>POST {"action":"trigger_nws_test","zone_scope":"selected","zone_ids":["zone_id"]}</code> <?php echo _('starts the NWS test workflow for all zones or the selected configured zone IDs using normal cooldown and recipient rules.'); ?></li>
		<li><code>POST {"action":"update_config","settings":{...},"apply":false}</code> <?php echo _('updates allowlisted centralized config fields. Set apply to true only when the remote client should immediately write live config.'); ?></li>
	</ul>

	<h3><?php echo _('Email and Discord'); ?></h3>
	<p><?php echo _('Shared Weather and Lightning destinations are managed from the Notification Destinations popup in General Settings. Add or remove validated recipient email addresses there and optionally store one Discord webhook.'); ?></p>
	<p><?php echo _('Alert email is sent through the local sendmail path as an always-branded Southland Servers HTML card with an embedded logo and a plain-text alternative. Test alerts are visibly identified as tests. Discord uses a compact branded embed with the SLS identity, public logo image/avatar, event-aware emoji/color, concise delivery fields, timestamp, and urgency/test footer. The public logo URL is built from the automatically detected PBX hostname. Destination values and the webhook are stored only in the protected central config.'); ?></p>

	<h3><?php echo _('TTS and Audio'); ?></h3>
	<ul>
		<li><?php echo _('Piper voices are selected separately: fresh regular announcements default to Lessac, while Weather and Lightning speech default to Amy.'); ?></li>
		<li><?php echo _('Volume controls are saved as percentages and applied to the final Asterisk WAV conversion.'); ?></li>
		<li><?php echo _('Generated Piper speech defaults to 30 seconds and can be capped anywhere from 1 to 600 seconds.'); ?></li>
		<li><?php echo _('Upload custom audio through FreePBX Admin > System Recordings, then select it globally or per announcement as the opening or closing tone. Either selection may be None. Selected recordings are validated and converted into managed Asterisk audio.'); ?></li>
		<li><?php echo _('Generated TTS and combined announcement audio files are automatically removed after 15 minutes.'); ?></li>
		<li><?php echo _('Audio delivery uses the private Asterisk context sls-alert-audio and its module-owned, vendor-aware sls-alert-autoanswer handler. It does not require a public paging group such as *6767 or FreePBX-generated paging macros.'); ?></li>
	</ul>

	<h3><?php echo _('Updates and Removal'); ?></h3>
	<ul>
		<li><?php echo _('Update checks run through the root-owned updater and record their result for General Settings and Dashboard health. A newer release produces a yellow warning even when automatic installation is disabled.'); ?></li>
		<li><?php echo _('Update to Latest Release is shown only when a newer beta is available. It queues an immediate verified update and shows queued, installing, success, or failure status before refreshing the page.'); ?></li>
		<li><?php echo _('Completely Uninstall in Danger Zone requires confirmation and permanently removes module code, runtime services, APIs, logs, credentials, backups, tones, and the central configuration. Download a config backup first if the deployment may be restored later.'); ?></li>
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
asterisk -rx "dialplan show s@sls-alert-autoanswer"
asterisk -rx "manager show users" | grep slsmassnotify
timeout 15 python3 /usr/local/bin/sls_mass_notify/sls_notify.py --ami-health-json
bash -n /usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh
bash -n /usr/local/bin/sls_mass_notify/sls_mass_notify_test.sh
python3 -m py_compile /usr/local/bin/sls_mass_notify/sls_notify.py</pre>

	<h3><?php echo _('Troubleshooting'); ?></h3>
	<ul>
		<li><?php echo _('Desktop app unauthorized: confirm the desktop client is enabled in General Settings and test /api/sipnotify/desktop with that client username and password.'); ?></li>
		<li><?php echo _('Phone SIP NOTIFY missing: confirm the target extension is registered, AMI user slsmassnotify exists, and /var/log/sls_mass_notify_push.log has no AMI errors.'); ?></li>
		<li><?php echo _('Audio missing or the phone rings normally: confirm both SLS dialplan contexts exist, the asterisk account can write to /var/spool/asterisk/tmp and outgoing, the two SLS sound links resolve to the protected sounds folder, Piper generated a WAV under the TTS folder, and Asterisk can read that sound path.'); ?></li>
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
