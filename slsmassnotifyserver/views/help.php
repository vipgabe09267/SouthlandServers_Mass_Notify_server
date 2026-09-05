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
.sls-help-endpoint-table {
	min-width: 660px;
}
.sls-help-endpoint-table th:nth-child(1),
.sls-help-endpoint-table td:nth-child(1),
.sls-help-endpoint-table th:nth-child(3),
.sls-help-endpoint-table td:nth-child(3) {
	width: 92px;
	min-width: 92px;
	white-space: nowrap;
	overflow-wrap: normal;
}
.sls-help-endpoint-table th:nth-child(1),
.sls-help-endpoint-table td:nth-child(1) {
	padding-left: 14px;
	padding-right: 20px;
}
.sls-help-endpoint-table th:nth-child(2),
.sls-help-endpoint-table td:nth-child(2) {
	min-width: 150px;
	overflow-wrap: normal;
}
.sls-help-endpoint-table th:nth-child(4),
.sls-help-endpoint-table td:nth-child(4) {
	min-width: 260px;
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
	<p class="lead"><?php echo _('Southland Servers Mass Notifications Server by the Southland Servers Group is an AGPL version 3-or-later FreePBX module for SIP NOTIFY alerts, desktop notifications, NWS weather alerts, dashboard announcements, and Piper TTS audio delivery.'); ?></p>

	<h3><?php echo _('Project Status'); ?></h3>
	<ul>
		<li><?php echo _('This is beta software. Test on a non-critical PBX before relying on it for emergency workflows.'); ?></li>
		<li><?php echo _('The module is designed to keep deployment settings outside module code in a centralized .config file so updates do not overwrite local configuration.'); ?></li>
		<li><?php echo _('Custom/local FreePBX module signatures normally show as Unknown. Altered means the module should be signed again on that PBX.'); ?></li>
		<li><?php echo _('General Settings shows the installed package version and whether the known release status is LATEST or an update is available.'); ?></li>
		<li><?php echo _('Version 0.1.2-beta adds background announcement jobs with sender attribution and per-channel results, saved local channel checks, per-device format overrides, reliable desktop reconnects with optional app acknowledgments, independent Weather observation and delivery, fresher Lightning state, and signed release verification.'); ?></li>
		<li><?php echo _('Local signing now uses the web account, module root, and GPG home reported by FreePBX. Install, update, repair, and uninstall share a maintenance lock; each candidate signature must return trusted status 129 before it replaces the previous module.sig.'); ?></li>
		<li><?php echo _('After a Dashboard or Framework upgrade, Repair Installation restores the managed announcement widget and menu placement, rebuilds the stored Dashboard hook index, and verifies that the announcement controls render. Framework 17.0.30 and earlier Framework 17 menu comparator forms are supported.'); ?></li>
	</ul>
	<p><?php echo _('Generated phone images use the automatically detected, read-only Public PBX Hostname shown in General Settings. Phone Image Transport remains configurable: HTTP is the compatibility default for legacy Yealink models such as the T48G, while HTTPS should be selected only when target phones trust the PBX certificate and support its TLS configuration. Authenticated APIs remain HTTPS.'); ?></p>
	<p><?php echo _('Use the Phone Format Overrides manager in General Settings only when automatic endpoint detection is wrong. Enter the extension and select a supported phone family from the list; the saved value is written to the protected central config.'); ?></p>
	<p><?php echo _('Yealink overrides are labeled “Yealink - Color” and “Yealink - Text Only.” Panasonic KX phones are detected from registered User-Agent data and can also be selected manually. Unknown endpoints remain visible in diagnostics but are not offered as a manual format.'); ?></p>

	<h3><?php echo _('Diagnostics'); ?></h3>
	<p><?php echo _('General Settings can save up to ten local channel-check profiles. Choose explicit phones and desktops, then audio-only, visual-only, or both. Save and apply the profile before running it. These checks use announcement settings and cooldown; they do not send email/webhooks or query Xweather.'); ?></p>
	<p><?php echo _('Paging answer timeout is one through five seconds, default five. It limits unanswered invitations, not visual-message expiry or audio length. Phones still need auto-answer enabled.'); ?></p>
	<p><?php echo _('Weather observations run separately from delivery. Queued alerts are checked for current routing, expiry, cancellation, and fresh observations before submission. Interrupted or uncertain deliveries are not automatically repeated. For general announcements, retry is available only for confirmed failed destinations; inspect the channel results first.'); ?></p>
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
					<table class="table table-condensed table-striped sls-help-endpoint-table">
						<thead><tr><th><?php echo _('Extension'); ?></th><th><?php echo _('Format'); ?></th><th><?php echo _('Contacts'); ?></th><th><?php echo _('User Agent'); ?></th></tr></thead>
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
							<thead><tr><th><?php echo _('Client'); ?></th><th><?php echo _('Last Seen'); ?></th><th><?php echo _('Connection'); ?></th><th><?php echo _('Last Acknowledgment'); ?></th></tr></thead>
							<tbody>
								<?php foreach ($desktopDiagnostics as $client) { ?>
									<tr>
										<td><?php echo htmlspecialchars(($client['name'] ?? '') . ' (' . ($client['client_id'] ?? '') . ')'); ?></td>
										<td><?php echo htmlspecialchars(($client['last_seen_at'] ?? '') ?: _('Never')); ?> <?php echo !empty($client['last_seen_ip']) ? htmlspecialchars(' from ' . $client['last_seen_ip']) : ''; ?></td>
										<td>
											<?php $connected = !empty($client['connected']); ?>
											<span class="label <?php echo $connected ? 'label-success' : 'label-default'; ?>"><?php echo $connected ? _('Live stream active') : _('Not connected'); ?></span>
										</td>
										<td><?php echo htmlspecialchars(($client['last_acknowledged_at'] ?? '') ?: _('Not reported')); ?></td>
									</tr>
								<?php } ?>
							</tbody>
						</table>
					</div>
					<p class="text-muted"><?php echo _('A live connection does not confirm display. Acknowledgments require a compatible desktop app and do not prove the user read the message.'); ?></p>
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
		<li><?php echo _('The one-minute weather scheduler reads the centralized config, polls up to five independent NWS zone groups, routes each group to its selected phone extensions and desktop clients, deduplicates alert chains, applies quiet hours, and can also check the optional Xweather lightning API.'); ?></li>
			<li><?php echo _('Dashboard announcements can submit phone SIP NOTIFY text, publish to the SLS Mass Notify desktop API, and send branded Discord embed JSON to individually selected Discord or Discord-compatible HTTPS webhook destinations. A webhook can be the only target. Announcements independently use opening/closing tones, Piper TTS, both, or neither. Audio uses Page/ConfBridge and includes every resolved PJSIP contact, so a softphone registration does not displace a desk phone registration.'); ?></li>
		<li><?php echo _('Scheduling creates one-time announcements for one or more PBX-local calendar dates, or repeats from one selected start time every 7 or 14 days. Recurring dates are generated as a protected, DST-validated series for up to five years. Each schedule can select phones, groups, desktops, audio mode, Piper voice, volume, tones, and the Labs colored Yealink presentation. The worker checks once per minute and retries pre-delivery cooldown, busy, temporarily offline, or no-audio-target conditions inside a 15-minute grace window. It revalidates the live schedule before claiming it; a claimed delivery is never replayed after an interrupted worker. Failed or missed items must be re-armed with a new future date, while uncertain items require review.'); ?></li>
			<li><?php echo _('Scheduling uses the PBX operating-system timezone and rejects missing or ambiguous daylight-saving times. Dashboard health reports a mismatch with the FreePBX PHP timezone. Worker, cron, and journal faults are enforced only while at least one schedule is enabled, so viewing an unused Scheduling page cannot create a false fault.'); ?></li>
		<li><?php echo _('General Settings can leave regular announcement screens without expiry, expire them with the generated page audio, or use a fixed 1–86,400 second timeout. The timeout is carried by regular Yealink XML and live desktop metadata; an expired desktop record advances the live cursor without being displayed, and weather alert validity is unchanged.'); ?></li>
			<li><?php echo _('Email and Webhook Delivery manages the Postfix sender identity, optional system/error email recipients, multiple Discord webhooks, and multiple generic HTTPS webhooks. Weather and Lightning email recipients are selected on the matching zone or trigger area. Fresh installs begin with no-reply at the local Postfix/PBX domain. Values and destination secrets remain in protected central config.'); ?></li>
			<li><?php echo _('Manual NWS and Lightning tests use the normal phone, audio, desktop, status, and popup paths. They remain local and do not send email, Discord, or generic webhook traffic. A queued or submitted result means Asterisk accepted the request; it is not proof that every handset answered or displayed the payload.'); ?></li>
		<li><?php echo _('Desktop clients can receive authenticated live server-sent events or use the backward-compatible JSON endpoint with their assigned username and password.'); ?></li>
	</ul>

	<h3><?php echo _('First-Run Setup'); ?></h3>
	<p><?php echo _('New installs show a mandatory setup modal the first time a Mass Notifications page is opened. Existing deployments that already have setup accepted in the central config are not forced through the wizard during normal updates.'); ?></p>
	<ol>
		<li><?php echo _('Accept the beta at-your-own-risk warning.'); ?></li>
		<li><?php echo _('Accept the AGPL-3.0 license notice.'); ?></li>
		<li><?php echo _('Read and accept the EULA.'); ?></li>
		<li><?php echo _('Choose whether to enable Weather Alerts. If enabled, configure the primary U.S. weather.gov zone/county group and select its phone and/or enabled desktop recipients. Add up to four more independently routed zones later from Weather Alerts.'); ?></li>
		<li><?php echo _('Choose whether to enable Lightning Alerts. If enabled, configure Xweather credentials and the first Lightning trigger area. Up to five areas can later select independent Weather Alert triggers, locations, radii, phones, desktops, email recipients, and all-clear behavior.'); ?></li>
		<li><?php echo _('Review Control API access, paging audio, TTS voices and volume, and log retention in setup. After setup, manage desktop clients, the Postfix sender identity, optional system/error recipients, and webhooks under General Settings. The desktop-client table keeps approximately five rows visible, then scrolls with a sticky header.'); ?></li>
	</ol>

	<h3><?php echo _('Notification Logs'); ?></h3>
	<ul>
		<li><?php echo _('Use the Notification Type selector and calendar field together to filter history by origin and PBX-local date. Types distinguish Dashboard, Control API, Scheduling, Weather, Lightning, manual test, desktop, and system/error activity. Clear Filters returns to the complete recent view.'); ?></li>
		<li><?php echo _('The row limit is applied after the selected type and date filters, so the requested number of matching events is retained.'); ?></li>
	</ul>

	<h3><?php echo _('Important Files'); ?></h3>
	<ul>
		<li><code><?php echo htmlspecialchars($modulePath); ?></code> <?php echo sprintf(_('FreePBX module UI and PHP class for module raw name %s.'), htmlspecialchars($moduleRaw)); ?></li>
		<li><code><?php echo htmlspecialchars($settingsPath); ?></code> <?php echo _('central applied configuration file. This JSON .config file is the source of truth for local settings.'); ?></li>
		<li><code>/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.pending.config</code> <?php echo _('staged settings waiting for Apply Config.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh</code> <?php echo _('one-minute multi-zone NWS and Xweather scheduler.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php</code> <?php echo _('one-minute scheduled-announcement worker.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh</code> <?php echo _('single-zone NWS worker launched by the scheduler.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py</code> <?php echo _('optional Xweather lightning worker.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_test.sh</code> <?php echo _('manual test sender.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh</code> <?php echo _('root-owned manual and automatic beta updater.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh</code> <?php echo _('root-owned worker for queued repairs, manual updates, and complete uninstall requests.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_uninstall.sh</code> <?php echo _('standalone cleanup path used by the confirmed Danger Zone uninstall action; it snapshots the current transactional signer before the module hook removes the installed copies, then deletes that protected snapshot before exit. If the FreePBX repository is unavailable, cleaned Dashboard and Framework copies receive a locally verified fallback signature. Normal uninstall preserves the schedule execution ledger; a complete purge removes it.'); ?></li>
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
			<li><?php echo _('The canonical mail sender local part and domain are included in protected config. Fresh installs default to no-reply at the local Postfix/PBX domain, and older valid sender values migrate forward.'); ?></li>
		<li><?php echo _('Upload a replacement .config only when intentionally restoring or transplanting a deployment. Replacing it overwrites credentials, desktop clients, phone overrides, voices, announcement groups, schedules, NWS settings, quiet hours, and retention settings. Imported schedules remain present but are disabled until reviewed and re-enabled.'); ?></li>
			<li><?php echo _('Native FreePBX 17 module backup includes protected config, the scheduling execution journal, and custom module tones. Restore verifies its manifest, sizes, hashes, config structure, credentials, and WAV data before atomic activation, prevents schedule replay, and verifies post-restore integration.'); ?></li>
			<li><?php echo _('The installer verifies the FreePBX Backup module and native adapter. A healthy zero-job state means no backup policy has been created yet; choose the schedule, storage, and retention in Backup & Restore.'); ?></li>
			<li><?php echo _('FreePBX cannot fetch this unknown custom module automatically on a replacement PBX. Install slsmassnotifyserver before restoring its module data, verify that the backup job includes it, and keep an external .config backup.'); ?></li>
		<li><?php echo _('Danger Zone separates repair, complete uninstall, and configuration replacement into distinct confirmed actions. Repair and uninstall display protected queued/running/completed/failed status; config replacement displays upload and validation progress. Repair preserves the central .config; complete uninstall and configuration replacement are destructive.'); ?></li>
	</ul>

	<h3><?php echo _('Weather Alerts'); ?></h3>
	<ul>
		<li><?php echo _('The setup wizard defaults Weather Alerts to No and keeps weather-specific fields hidden and excluded from validation until Yes is selected.'); ?></li>
		<li><?php echo _('Weather polling is optional and can be disabled during setup or from Weather Alerts. It supports U.S. weather.gov zones only and is locked to the official https://api.weather.gov endpoint. Up to five named weather-zone groups can each have their own phones, desktops, email recipients, quiet hours, critical bypass events, and selected Discord or generic webhook destinations. A live zone may be email-only or webhook-only, but a manual test still needs a phone or enabled desktop because tests never contact external destinations. Only the matching zone routes receive that live alert.'); ?></li>
		<li><?php echo _('To find a zone, open the official maps, choose your state, and find the three-digit number covering your location. Enter the state abbreviation, Z, and that number; for example, Texas zone 163 is TXZ163.'); ?> <a href="https://www.weather.gov/pimar/PubZone" target="_blank" rel="noopener noreferrer"><?php echo _('Open official NWS zone maps'); ?> <i class="fa fa-external-link" aria-hidden="true"></i></a></li>
		<li><?php echo _('Supported event names are mapped internally to priorities, SIP NOTIFY colors, quiet-hour behavior, and TTS summaries.'); ?></li>
		<li><?php echo _('Heat Advisory is supported. First-seen alerts remain eligible when Weather.gov labels them Update; reference-based chain keys suppress later timestamp-only reissues only after that chain has actually been processed.'); ?></li>
		<li><?php echo _('Quiet hours suppress non-critical configured alerts. Critical bypass events can still notify during quiet hours.'); ?></li>
		<li><?php echo _('TTS is limited to short important alert summaries rather than reading the full NWS alert text.'); ?></li>
	</ul>

	<h3><?php echo _('Lightning Alerts'); ?></h3>
	<ul>
		<li><?php echo _('The setup wizard defaults Lightning Alerts to No and does not require credentials, recipients, or a Weather trigger zone unless the administrator opts in. The overall module remains beta software and should be verified with each deployment’s devices and provider plan.'); ?></li>
		<li><?php echo _('Lightning Alerts uses protected Xweather credentials. Up to five named trigger areas can independently select cloud-to-ground strikes, cloud-to-cloud strikes, or both; a forecast-aware Weather Alert trigger group; Xweather location and radius; phone, desktop, and email recipients; quiet hours; and an optional all-clear. Credentials, query period, tones, voice, volume, and the enabled shared webhook destinations remain service-wide.'); ?></li>
		<li><?php echo _('Adaptive protection is enabled by default and requires every enabled Lightning area to select a Weather Alert group. The green shield means an area stays idle until a qualifying current Weather.gov alert or the structured forecast period active at that time indicates thunder, then polls that location every five minutes through the grace period, which defaults to 60 minutes. A future thunder period is remembered but does not spend Xweather tokens before its start. Turning the toggle off changes the card to a red shield and polls every enabled area continuously; Lightning outside a Weather.gov signal can be missed while protection is enabled.'); ?></li>
		<li><?php echo _('Xweather usage is measured in cost tokens. Area state is isolated, but one protected quota governor is shared, so multiple storm-active areas consume tokens faster. The usage card shows the provider’s latest counters and labels an expired period as historical until a successful query refreshes it.'); ?></li>
		<li><?php echo _('The 5-minute default is the longest gap-free period for standard Xweather access. Periods from 6–10 minutes can miss strikes unless the subscription includes extended lightning history.'); ?></li>
		<li><?php echo _('A storm creates one entry alert using the nearest strike distance reported by Xweather, rounded to one decimal mile. Repeated strikes do not alert again until two clear queries reset the state; an optional all-clear can be sent. Lightning uses its own quiet-hours toggle and opening/closing tones.'); ?></li>
		<li><?php echo _('Regular announcements, Weather Alerts, and Lightning Alerts default to 25% audio volume and retain independent 1–200% controls. Coordinate locations are announced as “this area,” while named locations use the configured city. Every Lightning audio sequence retains one second of leading silence before the pre-tone and speech.'); ?></li>
		<li><?php echo _('The Lightning system test can select one or more enabled, applied trigger areas and has a dedicated 60-second anti-spam cooldown. It sends only simulated phone, audio, and authorized desktop tests, waits for Asterisk pickup and SIP NOTIFY submission, and does not send email, Discord, or generic webhook notifications.'); ?></li>
		<li><?php echo _('The saved Xweather Client Secret is masked on the page and can be revealed with the eye button by an authenticated FreePBX administrator. Diagnostics and Control API config responses redact both the Xweather Client ID and Client Secret.'); ?></li>
	</ul>

	<h3><?php echo _('Dashboard Announcements'); ?></h3>
	<p><?php echo _('The Dashboard shows one Send Announcement button, progress underneath, and a green check with the authenticated sender after all requested channels accept submission. Control API announcements identify the API, and new schedules retain their creator. Expand Delivery details for per-channel results. Queued audio and submitted SIP NOTIFY confirm PBX acceptance, not that a person heard or saw the message. Interrupted jobs are not replayed automatically.'); ?></p>
	<p><?php echo _('Normal is the default priority. Urgent moves prepared announcement audio ahead of waiting normal pages for the same phones. It does not interrupt active playback, change visual delivery, or bypass cooldown. A page waits at most five minutes for an audio slot.'); ?></p>
	<p><?php echo _('General Settings → Download diagnostics saves a redacted JSON support report with versions, check results, permissions, device-format/transport counts, and queue counts. It excludes configuration, credentials, addresses, device identifiers, raw logs, and message text. Saved Channel Checks are optional reusable local test profiles; the question-mark beside the heading explains their purpose.'); ?></p>
	<ul>
		<li><?php echo _('The dashboard widget can target online registered extensions, all phones, selected desktop clients, all desktops, announcement groups, or a combination.'); ?></li>
		<li><?php echo _('General Settings can define up to 10 optional named Discord or Discord-compatible HTTPS Dashboard announcement webhooks. Each enabled destination appears as a separate checkbox, may be used without a local target, and receives bounded branded Discord embed JSON only when selected. Local phone, audio, and desktop submission runs before external webhook I/O.'); ?></li>
		<li><?php echo _('Announcement groups can include online or offline extensions plus desktop app clients. Offline extensions are skipped when sending and the UI warns the sender.'); ?></li>
		<li><?php echo _('Announcement audio can be disabled, tones only, TTS only, or tones plus TTS. Opening and closing recordings can be selected per announcement, and either may be None without changing the dialplan.'); ?></li>
		<li><?php echo _('The Labs colored-announcement designer provides a title, background color, and preview. Colored image announcements are currently limited to compatible Yealink phones; other vendors receive their text format.'); ?></li>
		<li><?php echo _('A short cooldown prevents repeated accidental announcement sends.'); ?></li>
	</ul>

	<h3><?php echo _('SIP NOTIFY and Desktop API'); ?></h3>
	<p><?php echo _('Desktop stream protocol 2 preserves reconnect cursors, signals history gaps, and rechecks revoked credentials while connected. Updated desktop apps may POST an event_id to /api/sipnotify/desktop/ack using their own credentials to acknowledge a targeted event. Publication, current connection, and acknowledgement are separate states; sleeping desktops are not delivery errors.'); ?></p>
		<p><?php echo _('Phones receive SIP NOTIFY pushes directly from Asterisk/PJSIP. Audio pages every resolved PJSIP contact through Page/ConfBridge. Mixed phone families receive contact-specific vendor payloads when URI routing is available and one safe generic endpoint payload otherwise; unknown devices also use generic XML. Asterisk submission is not handset acceptance. Desktop clients authenticate with their assigned username and password and can use either the live event stream or the JSON endpoint. Sleeping or disconnected clients are not reported as live.'); ?></p>
	<ul>
		<li><code>/api/sipnotify/desktop</code> <?php echo _('returns JSON for the SLS Mass Notify desktop app. Use HTTP Basic authentication with the desktop client username and password configured in General Settings.'); ?></li>
		<li><code>/api/sipnotify/desktop/stream</code> <?php echo _('returns a live server-sent-event stream using the same Basic authentication and per-client target filtering. The authenticated handshake is flushed through Apache immediately; clients should reconnect after the server reconnect event and may send Last-Event-ID when resuming. Expired authorized records advance the cursor without being emitted so the next valid notification is not skipped.'); ?></li>
		<li><?php echo _('A desktop application must connect to the /stream endpoint to receive live pushes. Applications that continue requesting the /desktop JSON fallback remain polling clients by design.'); ?></li>
		<li><?php echo _('Live and JSON notification records contain a presentation object plus flat compatibility fields. Weather supplies priority-derived background, header, accent, and text colors; colored announcements preserve the selected title/background; Lightning supplies its branded warning color.'); ?></li>
		<li><?php echo _('Each desktop only receives events sent to all desktops or events explicitly targeted to its username. Legacy records without routing fields are denied.'); ?></li>
	</ul>
		<p><?php echo _('Vendor firmware and provisioning settings can affect auto-answer and XML push behavior. Yealink audio uses Alert-Info: Intercom and requires handset intercom auto-answer permission. Yealink XML requires Features > Remote Control > SIP Notify or provisioning value push_xml.sip_notify = 1. The installer warns when it detects Yealink contacts but does not change firmware or provisioning. Test each target model.'); ?></p>

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
			<li><code>POST {"action":"update_config","settings":{"mail_from_local_part":"alerts","mail_from_domain":"example.com"},"apply":false}</code> <?php echo _('stages a validated alert-email sender identity. This example produces alerts@example.com.'); ?></li>
	</ul>

		<h3><?php echo _('Email and Webhook Delivery'); ?></h3>
		<p><?php echo _('General Settings manages the sender local part/domain, optional recipients for deduplicated system and error notices, multiple Discord or generic HTTPS alert webhooks, and a separate protected list of Discord or Discord-compatible HTTPS Dashboard announcement webhooks. Weather and Lightning email recipients are configured independently on each zone or trigger area. Fresh installs default to no-reply at the local Postfix/PBX domain.'); ?></p>
		<p><?php echo _('Changing the email identity does not configure Postfix, an SMTP relay, DNS, SPF, DKIM, DMARC, or PTR/reverse DNS. Configure those separately so the selected identity is authorized to send from this PBX.'); ?></p>
		<p><?php echo _('Live alert and system/error email is submitted through the local sendmail path as a branded Southland Servers HTML card with a plain-text alternative. Repeated active system faults are deduplicated. Discord uses a compact branded embed. Generic webhooks receive bounded structured JSON with an event ID and idempotency header. Webhook delivery requires HTTPS and public DNS/address validation, verifies TLS, refuses redirects, and redacts stored URLs from results. Manual tests, previews, and dry runs do not contact external destinations.'); ?></p>

	<h3><?php echo _('TTS and Audio'); ?></h3>
	<ul>
		<li><?php echo _('Piper voices are selected separately: fresh regular announcements default to Lessac, while Weather and Lightning speech default to Amy.'); ?></li>
		<li><?php echo _('Volume controls are saved as percentages and applied to the final Asterisk WAV conversion.'); ?></li>
		<li><?php echo _('Generated Piper speech defaults to 30 seconds and can be capped anywhere from 1 to 600 seconds.'); ?></li>
		<li><?php echo _('Upload custom audio through FreePBX Admin > System Recordings, then select it globally or per announcement as the opening or closing tone. Either selection may be None. The installer registers uniquely named SLS Mass Notify paging-opening, paging-closing, NWS, and Lightning recordings and refuses a conflicting user-owned recording instead of overwriting it. Selected recordings are validated and converted into managed Asterisk audio.'); ?></li>
		<li><?php echo _('Generated TTS and combined announcement audio files are automatically removed after 15 minutes.'); ?></li>
			<li><?php echo _('Audio delivery uses the private Asterisk context sls-alert-audio, Page/ConfBridge fan-out, and the module-owned vendor-aware sls-alert-autoanswer handler. It does not require a public paging group such as *6767 or FreePBX-generated paging macros.'); ?></li>
	</ul>

	<h3><?php echo _('Updates and Removal'); ?></h3>
	<ul>
		<li><?php echo _('Update checks run through the root-owned updater and record their result for General Settings and Dashboard health. A newer release produces a yellow warning even when automatic installation is disabled.'); ?></li>
			<li><?php echo _('Update to Latest Release is shown only when a newer accepted release is available. It queues an immediate verified update and shows queued, installing, success, or failure status before refreshing the page.'); ?></li>
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
			<li><?php echo _('Audio missing or the phone rings normally: confirm both SLS dialplan contexts exist, Page and ConfBridge are available, the asterisk account can write to /var/spool/asterisk/tmp and outgoing, the two SLS sound links resolve to the protected sounds folder, Piper generated a WAV under the TTS folder, and the handset permits vendor auto-answer.'); ?></li>
			<li><?php echo _('Red installer or repair fault on Dashboard: read the reported stage and possible solution, then inspect /tmp/slsmassnotifyserver-install.log or the maintenance log. The fault remains until comprehensive integration, signature, runtime, and health verification succeeds.'); ?></li>
		<li><?php echo _('Asterisk provider repair stops before installation: wait for active calls to finish and rerun the installer. If it reports an unowned module path, unavailable exact package version, noload rule, or autoload-disabled provider, restore or explicitly enable the matching provider from that Asterisk build or repository; the installer intentionally will not mix modules from a different ABI.'); ?></li>
		<li><?php echo _('Module says Altered: remove generated caches such as __pycache__ if present, run the PBX-local signing helper, confirm verifyModule returns status 129 with no details, then run fwconsole reload.'); ?></li>
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
