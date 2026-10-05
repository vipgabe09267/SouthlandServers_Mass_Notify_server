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
.sls-help-page { color:#334155; }
.sls-help-page h2 { font-size:29px; font-weight:700; margin:20px 0 12px; }
.sls-help-section { background:#fff; border:1px solid #dfe5ec; border-radius:8px; margin:14px 0; min-width:0; }
.sls-help-section > summary { padding:16px 20px; font-size:17px; font-weight:600; cursor:pointer; overflow-wrap:anywhere; }
.sls-help-section > summary:focus-visible { outline:2px solid #93c5fd; outline-offset:2px; }
.sls-help-section[open] > summary { border-bottom:1px solid #e8edf2; }
.sls-help-section-body { padding:18px 20px; min-width:0; overflow-wrap:anywhere; }
.sls-help-section-body > :last-child { margin-bottom:0; }
.sls-help-page p, .sls-help-page li { line-height:1.65; }
.sls-help-page a, .sls-help-page code { overflow-wrap:anywhere; }
.sls-help-page code { white-space:normal; }
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
<div class="container-fluid sls-help-page">
	<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image ?? '']); ?>
	<h2><i class="fa fa-life-ring text-primary" aria-hidden="true"></i> <?php echo _('Help'); ?></h2>
	<p class="lead"><?php echo _('Southland Servers Mass Notifications Server by the Southland Servers Group is an AGPL version 3-or-later FreePBX module for SIP NOTIFY alerts, desktop notifications, NWS weather alerts, dashboard announcements, and Piper TTS audio delivery.'); ?></p>

	<details class="sls-help-section" open><summary><?php echo _('Project Status'); ?></summary><div class="sls-help-section-body">
	<ul>
		<li><?php echo _('This is beta software. Test on a non-critical PBX before relying on it for emergency workflows.'); ?></li>
		<li><?php echo _('The module is designed to keep deployment settings outside module code in a centralized .config file so updates do not overwrite local configuration.'); ?></li>
		<li><?php echo _('Custom/local FreePBX module signatures normally show as Unknown. Altered means the module should be signed again on that PBX.'); ?></li>
		<li><?php echo _('General Settings shows the installed package version and whether the known release status is LATEST or an update is available.'); ?></li>
		<li><?php echo _('Version 0.1.5-beta adds saved sites and device audiences, scoped Operations roles, incident workflows, external voice, email, SMS and MMS announcements, dial-in paging, reviewed triggers and actions, deployment diagnostics and protected recovery. New integrations carry the Labs badge and require site acceptance.'); ?></li>
		<li><?php echo _('Version 0.1.5-beta adds device capacity checks, shared phone admission and call evidence, optional dial-in paging, and desktop cursor pagination. External voice remains experimental with restricted routing support. See the changelog for compatibility limits and deferred features.'); ?></li>
		<li><?php echo _('Local signing now uses the web account, module root, and GPG home reported by FreePBX. Install, update, repair, and uninstall share a maintenance lock; each candidate signature must return trusted status 129 before it replaces the previous module.sig.'); ?></li>
		<li><?php echo _('After a Dashboard or Framework upgrade, Repair Installation restores the managed announcement widget and menu placement, rebuilds the stored Dashboard hook index, and verifies that the announcement controls render. Framework 17.0.30 and earlier Framework 17 menu comparator forms are supported.'); ?></li>
	<li><a href="modules/slsmassnotifyserver/docs/ENTERPRISE_OPERATIONS.md">Enterprise Labs</a> <?php echo _('Optional continuity, identity, devices and incident coordination start disabled. Dangerous features require a timed acknowledgment and are for non-production labs only.'); ?></li>
</ul>
	<p><?php echo _('Generated phone images use the saved advertised hostname and phone image port in General Settings. Use the explicit address editor to change them after a domain or port-forward change. If HTTPS does not work try HTTP for legacy phones.'); ?></p>
	<p><?php echo _('Use the Phone Format Overrides manager in General Settings only when automatic endpoint detection is wrong. Enter the extension and select a supported phone family from the list; the saved value is written to the protected central config.'); ?></p>
	<p><?php echo _('Yealink overrides are labeled “Yealink - Color” and “Yealink - Text Only.” Panasonic KX phones are detected from registered User-Agent data and can also be selected manually. Unknown endpoints remain visible in diagnostics but are not offered as a manual format.'); ?></p>

	</div></details>

	<details class="sls-help-section" open><summary><?php echo _('Diagnostics'); ?></summary><div class="sls-help-section-body">
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
			<div class="panel-heading"><h4><?php echo _('Detected Phones'); ?></h4></div>
			<div class="panel-body">
			<?php if (empty($endpointDiagnostics)) { ?>
				<p class="text-muted"><?php echo _('No registered phone endpoints were detected or AMI endpoint detection is unavailable.'); ?></p>
			<?php } else { ?>
				<div class="sls-help-scroll-table">
					<table class="table table-condensed table-striped sls-help-endpoint-table">
						<?php include __DIR__ . '/detected_phones.php'; ?>
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
							<thead><tr><th><?php echo _('Client'); ?></th><th><?php echo _('App readiness'); ?></th><th><?php echo _('Last Seen'); ?></th><th><?php echo _('Connection'); ?></th><th><?php echo _('Last Acknowledgment'); ?></th></tr></thead>
							<tbody>
								<?php foreach ($desktopDiagnostics as $client) { ?>
									<tr>
										<td><?php echo htmlspecialchars(($client['name'] ?? '') . ' (' . ($client['client_id'] ?? '') . ')'); ?></td>
										<td>
											<?php $readinessLabels = ['not_reported'=>_('Not reported'), 'stale_report'=>_('Report older than 24 hours'), 'unsupported_protocol'=>_('Unsupported protocol reported'), 'upgrade_required'=>_('Upgrade required'), 'meets_minimum'=>_('Meets minimum version'), 'no_minimum'=>_('No minimum version set')];
											$readiness = $client['compatibility'] ?? 'not_reported'; ?>
											<strong><?php echo htmlspecialchars(($client['client_version'] ?? '') ?: _('Unknown version')); ?></strong><br>
											<span class="<?php echo in_array($readiness, ['upgrade_required', 'unsupported_protocol'], true) ? 'text-warning' : 'text-muted'; ?>"><?php echo htmlspecialchars($readinessLabels[$readiness] ?? _('Not reported')); ?></span>
										</td>
										<td><?php echo htmlspecialchars(($client['last_seen_at'] ?? '') ?: _('Never')); ?> <?php echo !empty($client['last_seen_ip']) ? htmlspecialchars(' from ' . $client['last_seen_ip']) : ''; ?></td>
										<td>
											<?php $connection = $client['connection'] ?? 'never'; $connectionLabels = ['disabled'=>_('Disabled'), 'never'=>_('Never seen'), 'clock_error'=>_('Check PBX clock'), 'streaming'=>_('Live stream active'), 'recent'=>_('Recent authenticated activity'), 'inactive'=>_('No recent activity')]; ?>
											<span class="label <?php echo in_array($connection, ['streaming', 'recent'], true) ? 'label-success' : 'label-default'; ?>"><?php echo htmlspecialchars($connectionLabels[$connection] ?? _('Unknown')); ?></span>
										</td>
										<td><?php echo htmlspecialchars(($client['last_acknowledged_at'] ?? '') ?: _('Not reported')); ?></td>
									</tr>
								<?php } ?>
							</tbody>
						</table>
					</div>
					<p class="text-muted"><?php echo _('Version and protocol information is reported by the authenticated app. Recent activity can include polling or diagnostics; it is not a delivery receipt. Acknowledgments confirm app receipt and do not prove the user read the message.'); ?></p>
				<?php } ?>
			</div>
		</div>
		<div class="panel panel-default">
			<div class="panel-heading"><h4><?php echo _('Recent Control API Use'); ?></h4></div>
			<div class="panel-body">
				<?php foreach ((array)($diagnostics['control_api_audit_notices'] ?? []) as $notice) { ?>
					<div class="alert alert-warning" role="status"><?php echo htmlspecialchars((string)$notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
				<?php } ?>
				<p class="text-muted"><?php echo _('Only requests using a Control API key appear here. Operator sign-ins, password recovery and administrator security events are shown in Operator Access.'); ?></p>
				<?php if (empty($controlApiAudit)) { ?>
					<p class="text-muted"><?php echo _('No API-key usage entries are available.'); ?></p>
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

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Dial-in Paging'); ?> <?php include __DIR__ . '/labs.php'; ?></summary><div class="sls-help-section-body">
	<ul>
		<li><?php echo _('Configure one unused internal extension and up to ten groups. Each group saves its audio phones, optional SIP text phones and message, authorized caller extensions, and PIN. New groups get a random four-digit PIN; choose four through eight digits. Save group stores the page settings, then Apply Config prepares speech and activates the menu. The page shows disabled and pending states.'); ?></li>
		<li><?php echo _('For external paging, enable the IVR destination, allow external access for each group, and enter its approved calling numbers with + and country code. These are callers such as your cell phone, not the PBX inbound DID. An empty list allows nobody. Unlisted or withheld numbers are rejected before prompts; approved callers hear only their permitted groups and must enter the group PIN. A PIN remains mandatory externally even if internal callers do not require it.'); ?></li>
		<li><?php echo _('Select “SLS external paging · group PIN required” as a destination in each intended FreePBX IVR and Apply Config. SLS does not edit existing IVRs automatically. Incoming caller ID can use +countrycode, country code without +, or 00; saved +1 numbers also accept complete valid ten-digit NANP presentation. Other national formats need trunk normalization. Caller ID can be forged, so the PIN is always required. External access shares two concurrent menu slots and ten failed PIN attempts per minute.'); ?></li>
	</ul>
	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Locations and Audiences'); ?> <?php include __DIR__ . '/labs.php'; ?></summary><div class="sls-help-section-body">
	<p><?php echo _('Build a site, building, floor and room directory, then assign existing phones, desktops and saved external recipients. Review a location and optional children before creating an audience group. Apply Config, then select that group on the Dashboard, in a schedule or in an incident template. These groups are snapshots: later location edits do not change them. Removed or replaced desktops require a new reviewed audience. An empty directory presents a Default site. Locations also manages saved external phone, email and SMS recipients and person/device audiences. Use the group or individual-device picker on announcements, Weather, Lightning and schedules; it shows unsupported producer channels explicitly. The directory stores configured locations and does not track devices. Operator Access assigns permissions separately.'); ?></p>
	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Core Workflows'); ?></summary><div class="sls-help-section-body">
	<ul>
		<li><?php echo _('The one-minute weather scheduler reads the centralized config, polls up to five independent NWS zone groups, routes each group to its selected phone extensions and desktop clients, deduplicates alert chains, applies quiet hours, and can also check the optional Xweather lightning API.'); ?></li>
			<li><?php echo _('Dashboard announcements can submit phone SIP NOTIFY text, publish to the SLS Mass Notify desktop API, and send branded Discord embed JSON to individually selected Discord or Discord-compatible HTTPS webhook destinations. A webhook can be the only target. Announcements independently use opening/closing tones, Piper TTS, both, or neither. Audio uses Page/ConfBridge and includes every resolved PJSIP contact, so a softphone registration does not displace a desk phone registration.'); ?></li>
		<li><?php echo _('Scheduling supports one-time dates, 7/14-day repeats and weekday calendar patterns with holidays and date-specific start times. Calendar import accepts reviewed all-day iCalendar events or CSV holiday ranges. Review planned dates before saving; the protected list is limited to 366 occurrences within five years and rejects unsafe daylight-saving times. Schedules use durable jobs with a configurable 1–15-minute maximum start delay and independent channel receipts. Changes affect future preparation; submitted jobs keep their frozen message and recipients. Interrupted or uncertain deliveries are not automatically replayed.'); ?></li>
			<li><?php echo _('Scheduling uses the PBX operating-system timezone and rejects missing or ambiguous daylight-saving times. Dashboard health reports a mismatch with the FreePBX PHP timezone. Worker, cron, and journal faults are enforced only while at least one schedule is enabled, so viewing an unused Scheduling page cannot create a false fault.'); ?></li>
		<li><?php echo _('General Settings can leave regular announcement screens without expiry, expire them with the generated page audio, or use a fixed 1–86,400 second timeout. The timeout is carried by regular Yealink XML and live desktop metadata; an expired desktop record advances the live cursor without being displayed, and weather alert validity is unchanged.'); ?></li>
			<li><?php echo _('Email and Webhook Delivery manages the Postfix sender identity, optional system/error email recipients, multiple Discord webhooks, and multiple generic HTTPS webhooks. Weather and Lightning email recipients are selected on the matching zone or trigger area. Fresh installs begin with no-reply at the local Postfix/PBX domain. Values and destination secrets remain in protected central config.'); ?></li>
			<li><?php echo _('Manual NWS and Lightning tests use the normal phone, audio, desktop, status, and popup paths. They remain local and do not send email, Discord, or generic webhook traffic. A queued or submitted result means Asterisk accepted the request; it is not proof that every handset answered or displayed the payload.'); ?></li>
		<li><?php echo _('Desktop clients can receive authenticated live server-sent events or use the backward-compatible JSON endpoint with their assigned username and password.'); ?></li>
	</ul>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Operators and Wardens'); ?> <?php include __DIR__ . '/labs.php'; ?></summary><div class="sls-help-section-body">
	<p><?php echo _('The operator portal is disabled by default. Enable it and save in Operator Access in the PBX admin panel. The portal opens in a new tab; while disabled it shows no login form and blocks all actions. Disabling or re-enabling it revokes old sessions. Create dedicated operator logins there. Operators sign in at /mass-notify/ using a personal password and mandatory authenticator verification. First sign-in changes the initial password, enrolls a time-based authenticator and displays one-use recovery codes. Portal accounts do not grant FreePBX or UCP access. Full PBX administrators retain account-management recovery.'); ?></p>
	<p><?php echo _('Choose a permission preset, then review allowed actions, delivery channels, sites, locations, saved audiences and personal devices. Sites and locations include descendants; grants are combined. Assign personal devices alone for notifications only to that person. Username matching never grants device ownership. Permissions apply immediately and are rechecked before queued delivery. Password and authenticator resets revoke sessions; authenticator resets require a new initial password.'); ?></p>
	<p><?php echo _('The portal defaults to dark mode and uses local PBX branding. Passwords use Argon2id with 64 MiB and four passes, or PBKDF2-SHA256 with 600,000 iterations where Argon2id is unavailable. The protected .config stores authentication and permission state. Keep the file and backups private. Wardens record human responses separately from desktop software receipts.'); ?></p>
	<p><?php echo _('Register a recovery email in each operator account. Forgot password requires that email and username and allows two recovery email attempts. Two failed recovery verifications also stop recovery emails. An administrator can generate or revoke a single-use reset link valid for 24 hours. Saving a new password requires the existing authenticator code; backup codes cannot replace it. Resetting signs out existing sessions and clears the recovering address’s failed-password lock. Postfix acceptance does not confirm inbox delivery, and failed or uncertain handoffs are not retried automatically.'); ?></p>
	<p><?php echo _('Failed passwords are limited per IP to 6 in five minutes, 12 in ten minutes and 20 in 24 hours. The twentieth failure locks password sign-in from that address for 24 hours; verified password recovery or administrator assistance can restore access earlier. Clients behind the same public IP share its limit. Authentication and recovery events are audited without credentials, and announcement history records the verified operator.'); ?></p>
	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Trigger Integrations and Actions'); ?> <?php include __DIR__ . '/labs.php'; ?></summary><div class="sls-help-section-body">
	<p><?php echo _('Triggers and Actions connects an enrolled panic identity, signed sensor, trusted CAP feed or designated internal emergency call to a saved incident message and its recipients. Optional actions support BrightSign UDP presentation events, PATLITE NHV light commands and reviewed local .sh/.js files. New items start disabled; save sends nothing and Apply Config activates them. Device/provider acceptance remains necessary.'); ?></p>
	<p><?php echo _('Panic sources require a saved location and deliberate confirmation. An optional unused internal shortcut accepts only listed PJSIP callers and requires pressing 1 after the configured prompt. Signed HTTPS clients obtain a confirmation challenge, review its trigger/location, then activate between two and sixty seconds later. Rotating enrollment revokes the old source. The same accepted request identifier cannot repeat an action.'); ?></p>
	<p><?php echo _('Signed source requests use POST /api/sls-mass-notify/trigger.php?rule_id=trg_ID with JSON, X-SLS-Timestamp and X-SLS-Signature. HMAC-SHA256 signs rule ID, a period, timestamp, a period and the exact JSON bytes using the literal enrollment secret. Include operation activate, stable request_id, integer sent_at/expires_at, exact event name, message and boolean is_test. Never place secrets in URLs. Panic first uses operation challenge with a 32-digit hexadecimal request ID. The packaged Trigger contract documents the complete fields and response behavior.'); ?></p>
	<p><?php echo _('Emergency-call observation is passive: select exact internal callers, dialled numbers, location and responder template. No emergency number or routing change is assumed. CAP accepts trusted HTTPS CAP 1.2 or inline Atom records with exact sender/event, freshness and expiry checks. Updates/cancellations require same-source references. Expired, superseded or revoked work is blocked; uncertain device/script submissions are preserved without automatic replay.'); ?></p>
	<p><?php echo _('Approved scripts run as the PBX account, never root. They require an absolute root-owned .sh/.js file and protected parents, explicit SHA-256 approval, fixed interpreter, bounded event input and a ten-second limit. Changed bytes require a new approval. Scripts remain trusted administrator code; they are not a sandbox for untrusted programs.'); ?></p>
	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Incidents, Drills and Escalation'); ?> <?php include __DIR__ . '/labs.php'; ?></summary><div class="sls-help-section-body">
    <p><?php echo _('Prepare templates with required sender fields, destinations, a person roster, observer checks and reviewed resource/language variants. Save and Apply Config before launching. Each incident freezes its wording and recipients. Updates and all-clear send separate immutable announcements; earlier alerts cannot be edited or cancelled.'); ?></p>
    <p><?php echo _('Incident archival is an optional Labs policy, off by default. Configure the age and archive allocation in Incident Workflows, then Apply Config. Closed incidents and missed drills move to a read-only archive in bounded batches; open incidents and uncertain submissions remain active. Reports and request identities are never automatically deleted. A full or damaged archive preserves active history. Native recovery retains blocked incident identities without activating old workflows. Large archives require a separate protected export because native operational evidence has explicit size bounds.'); ?></p>
    <p><?php echo _('The escalation ladder supports up to five follow-ups, each with its own name, increasing delay and selected destinations. Add a step, expand it to edit, or remove/reorder it. Delays are 60–86400 seconds from the initial job; a delayed worker preserves the interval between levels. Outstanding, missing or assistance responses allow follow-up. Everyone accounted for or an all-clear stops future steps. App receipts do not count as human responses. An uncertain submission pauses automatic progression and needs delivery review.'); ?></p>
    <p><?php echo _('Drills are explicitly marked as tests, including every follow-up. Scheduled drills more than fifteen minutes late are marked missed. Human response and panic controls in the desktop app require coordinated client support. Every enabled follow-up must fit the sender’s current assignments; revoked or changed recipients are rechecked before delivery.'); ?></p>
    </div></details>

    <details class="sls-help-section"><summary><?php echo _('Deployment Readiness and Device Tests'); ?> <?php include __DIR__ . '/labs.php'; ?></summary><div class="sls-help-section-body">
    <p><?php echo _('General Settings → Deployment readiness → Check now reads current local runtime, worker, queue, clock, HTTPS and capacity health without changing settings or sending alerts. A successful local check does not prove public port-forward access, complete handset playback, provider receipt or large-fleet capacity. Download report also includes recorded device observations, so treat it as confidential.'); ?></p>
    <p><?php echo _('Record device test describes a test you already performed against a saved destination. It does not run a test or send a notification. Administrators can record the actual model, known firmware/app version, test time, behavior checked, result and notes. Leave unknown firmware blank. Keep credentials and alert contents out of notes. Records activate immediately, preserve unrelated pending edits and require no Apply Config.'); ?></p>
    <p><?php echo _('The latest 200 observations are retained. Export the readiness report for longer retention. A newer observation supersedes the earlier result for that destination without erasing its history. Settings or software changes request review, including unpublished builds with the same version. Firmware, provider and remote route changes need an explicit retest. These are human observations, separate from local health or software delivery receipts.'); ?></p>
    </div></details>

	<details class="sls-help-section"><summary><?php echo _('Release Signing'); ?></summary><div class="sls-help-section-body">
	<p><?php echo _('The existing self-signing key remains in use. New release manifests include signed validity dates. Reviewed publisher overlap/revocation and offline recovery are root-only procedures described in the packaged Release Trust documentation; the updater never imports trust keys automatically. Protect root authority history separately from portable .config backups.'); ?></p>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('First-Run Setup'); ?></summary><div class="sls-help-section-body">
	<p><?php echo _('New installs show a mandatory setup modal the first time a Mass Notifications page is opened. Existing deployments that already have setup accepted in the central config are not forced through the wizard during normal updates.'); ?></p>
	<ol>
		<li><?php echo _('Accept the beta at-your-own-risk warning.'); ?></li>
		<li><?php echo _('Accept the AGPL-3.0 license notice.'); ?></li>
		<li><?php echo _('Read and accept the EULA.'); ?></li>
		<li><?php echo _('Choose whether to enable Weather Alerts. If enabled, configure the primary U.S. weather.gov zone/county group and select its phone and/or enabled desktop recipients. Add up to four more independently routed zones later from Weather Alerts.'); ?></li>
		<li><?php echo _('Choose whether to enable Lightning Alerts. If enabled, configure Xweather credentials and the first Lightning trigger area. Up to five areas can later select independent Weather Alert triggers, locations, radii, phones, desktops, email recipients, and all-clear behavior.'); ?></li>
		<li><?php echo _('Review Control API access, paging audio, TTS voices and volume, and log retention in setup. After setup, manage desktop clients, the Postfix sender identity, optional system/error recipients, and webhooks under General Settings. The desktop-client table keeps approximately five rows visible, then scrolls with a sticky header.'); ?></li>
	</ol>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Notification Logs'); ?></summary><div class="sls-help-section-body">
	<ul>
		<li><?php echo _('Use the Notification Type selector and calendar field together to filter history by origin and PBX-local date. Types distinguish Dashboard, Control API, Scheduling, Weather, Lightning, manual test, desktop, and system/error activity. Clear Filters returns to the complete recent view.'); ?></li>
		<li><?php echo _('The row limit is applied after the selected type and date filters, so the requested number of matching events is retained.'); ?></li>
	</ul>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Important Files'); ?></summary><div class="sls-help-section-body">
	<ul>
		<li><code><?php echo htmlspecialchars($modulePath); ?></code> <?php echo sprintf(_('FreePBX module UI and PHP class for module raw name %s.'), htmlspecialchars($moduleRaw)); ?></li>
		<li><code><?php echo htmlspecialchars($settingsPath); ?></code> <?php echo _('central applied configuration file. This AES-256-GCM encrypted .config file is the source of truth for local settings.'); ?></li>
		<li><code>/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.pending.config</code> <?php echo _('staged settings waiting for Apply Config.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh</code> <?php echo _('one-minute multi-zone NWS and Xweather scheduler.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_announcement_worker.php --health-check</code> <?php echo _('checks the general announcement worker, FreePBX bootstrap, and protected job storage without sending notifications. Startup, bootstrap, and channel submission failures are reported separately.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php</code> <?php echo _('one-minute scheduled-announcement worker.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh</code> <?php echo _('single-zone NWS worker launched by the scheduler.'); ?></li>
		<li><code>/usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py</code> <?php echo _('lightning observation worker (Xweather, Tempest or Meteomatics).'); ?></li>
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

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Central Config, Backup, and Restore'); ?></summary><div class="sls-help-section-body">
	<p><?php echo _('All user-facing Mass Notifications settings are stored in the central .config file. Runtime programs decrypt that source through the shared AES-256-GCM reader. There is no generated settings copy. The protected keyring rotates every 365 days. Native FreePBX backups include the key needed for restore; encrypted exports use a separate passphrase.'); ?></p>
	<ul>
		<li><?php echo _('Download the current .config from General Settings before major updates.'); ?></li>
			<li><?php echo _('The canonical mail sender local part and domain are included in protected config. Fresh installs default to no-reply at the local Postfix/PBX domain, and older valid sender values migrate forward.'); ?></li>
		<li><?php echo _('Upload a replacement .config only when intentionally restoring or transplanting a deployment. Replacing it overwrites credentials, desktop clients, phone overrides, voices, announcement groups, schedules, NWS settings, quiet hours, and retention settings. Imported schedules remain present but are disabled until reviewed and re-enabled.'); ?></li>
			<li><?php echo _('Native FreePBX 17 module backup includes protected config, scheduling state, custom tones, SMS continuity and bounded operational evidence. Restore verifies the manifest and staged files before atomic activation, prevents schedule and incident replay, and retains delivery history for review without loading old queues. Review providers and automation before rearming. Operational evidence is limited to 16 MiB per file and 64 MiB total; oversized history fails explicitly instead of being omitted.'); ?></li>
			<li><?php echo _('The installer verifies the FreePBX Backup module and native adapter. A healthy zero-job state means no backup policy has been created yet; choose the schedule, storage, and retention in Backup & Restore.'); ?></li>
			<li><?php echo _('FreePBX cannot fetch this unknown custom module automatically on a replacement PBX. Install slsmassnotifyserver before restoring its module data, verify that the backup job includes it, and keep an external .config backup.'); ?></li>
		<li><?php echo _('Danger Zone separates repair, complete uninstall, and configuration replacement into distinct confirmed actions. Repair and uninstall display protected queued/running/completed/failed status; config replacement displays upload and validation progress. Repair preserves the central .config; complete uninstall and configuration replacement are destructive.'); ?></li>
	</ul>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Weather Alerts'); ?></summary><div class="sls-help-section-body">
	<ul>
		<li><?php echo _('The setup wizard defaults Weather Alerts to No and keeps weather-specific fields hidden and excluded from validation until Yes is selected.'); ?></li>
		<li><?php echo _('Weather polling is optional and can be disabled during setup or from Weather Alerts. It supports U.S. weather.gov zones only and is locked to the official https://api.weather.gov endpoint. Up to five named weather-zone groups can each have their own phones, desktops, email recipients, quiet hours, critical bypass events, and selected Discord or generic webhook destinations. A live zone may be email-only or webhook-only, but a manual test still needs a phone or enabled desktop because tests never contact external destinations. Only the matching zone routes receive that live alert.'); ?></li>
		<li><?php echo _('To find a zone, open the official maps, choose your state, and find the three-digit number covering your location. Enter the state abbreviation, Z, and that number; for example, Texas zone 163 is TXZ163.'); ?> <a href="https://www.weather.gov/pimar/PubZone" target="_blank" rel="noopener noreferrer"><?php echo _('Open official NWS zone maps'); ?> <i class="fa fa-external-link" aria-hidden="true"></i></a></li>
		<li><?php echo _('Supported event names are mapped internally to priorities, SIP NOTIFY colors, quiet-hour behavior, and TTS summaries.'); ?></li>
		<li><?php echo _('Heat Advisory is supported. First-seen alerts remain eligible when Weather.gov labels them Update; reference-based chain keys suppress later timestamp-only reissues only after that chain has actually been processed.'); ?></li>
		<li><?php echo _('Quiet hours suppress non-critical configured alerts. Critical bypass events can still notify during quiet hours.'); ?></li>
		<li><?php echo _('TTS is limited to short important alert summaries rather than reading the full NWS alert text.'); ?></li>
	</ul>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Lightning Alerts'); ?></summary><div class="sls-help-section-body">
	<ul>
		<li><?php echo _('The setup wizard defaults Lightning Alerts to No and does not require credentials, recipients, or a Weather trigger zone unless the administrator opts in. The overall module remains beta software and should be verified with each deployment’s devices and provider plan.'); ?></li>
		<li><?php echo _('Lightning Alerts uses protected provider credentials. Xweather is the default; Tempest and Meteomatics adapters are Labs and require a provider account and acceptance test. Up to five named trigger areas can independently select cloud-to-ground strikes, cloud-to-cloud strikes, or both; a forecast-aware Weather Alert trigger group; location and radius; phone, desktop, email, SMS and external-call recipients; quiet hours; and an optional all-clear. Credentials, query period, tones, voice, volume, and the enabled shared webhook destinations remain service-wide.'); ?></li>
		<li><?php echo _('Adaptive protection is enabled by default and requires every enabled Lightning area to select a Weather Alert group. The green shield means an area stays idle until a qualifying current Weather.gov alert or the structured forecast period active at that time indicates thunder, then polls that location every five minutes through the grace period, which defaults to 60 minutes. A future thunder period is remembered but does not spend Xweather tokens before its start. Turning the toggle off changes the card to a red shield and polls every enabled area continuously; Lightning outside a Weather.gov signal can be missed while protection is enabled.'); ?></li>
		<li><?php echo _('Xweather usage is measured in cost tokens. Area state is isolated, but one protected quota governor is shared, so multiple storm-active areas consume tokens faster. The usage card shows the provider’s latest counters and labels an expired period as historical until a successful query refreshes it.'); ?></li>
		<li><?php echo _('The 5-minute default is the longest gap-free period for standard Xweather access. Periods from 6–10 minutes can miss strikes unless the subscription includes extended lightning history.'); ?></li>
		<li><?php echo _('A storm creates one entry alert using the nearest strike distance reported by Xweather, rounded to one decimal mile. Repeated strikes do not alert again until two clear queries reset the state; an optional all-clear can be sent. Lightning uses its own quiet-hours toggle and opening/closing tones.'); ?></li>
		<li><?php echo _('Regular announcements, Weather Alerts, and Lightning Alerts default to 25% audio volume and retain independent 1–200% controls. Coordinate locations are announced as “this area,” while named locations use the configured city. Every Lightning audio sequence retains one second of leading silence before the pre-tone and speech.'); ?></li>
		<li><?php echo _('The Lightning system test can select one or more enabled, applied trigger areas and has a dedicated 60-second anti-spam cooldown. It sends only simulated phone, audio, and authorized desktop tests, waits for Asterisk pickup and SIP NOTIFY submission, and does not send email, Discord, or generic webhook notifications.'); ?></li>
		<li><?php echo _('The saved Xweather Client Secret is masked on the page and can be revealed with the eye button by an authenticated FreePBX administrator. Diagnostics and Control API config responses redact both the Xweather Client ID and Client Secret.'); ?></li>
	</ul>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Dashboard Announcements'); ?></summary><div class="sls-help-section-body">
	<p><?php echo _('The Dashboard shows one Send Announcement button, progress underneath, and a green check with the authenticated sender after all requested channels accept submission. Control API announcements identify the API, and new schedules retain their creator. Expand Delivery details for per-channel results. Queued audio and submitted SIP NOTIFY confirm PBX acceptance, not that a person heard or saw the message. Interrupted jobs are not replayed automatically.'); ?></p>
	<p><?php echo _('Normal is the default priority. Urgent moves prepared announcement audio ahead of waiting normal pages for the same phones. It does not interrupt active playback, change visual delivery, or bypass cooldown. A page waits at most five minutes for an audio slot.'); ?></p>
	<p><?php echo _('General Settings → Download diagnostics saves a redacted JSON support report with versions, check results, permissions, device-format/transport counts, and queue counts. It excludes configuration, credentials, addresses, device identifiers, raw logs, and message text. Saved Channel Checks are optional reusable local test profiles; the question-mark beside the heading explains their purpose.'); ?></p>
	<ul>
		<li><?php echo _('The dashboard widget can target online registered extensions, all phones, selected desktop clients, all desktops, announcement groups, or a combination.'); ?></li>
		<li><?php echo _('General Settings can define up to 10 optional named Discord or Discord-compatible HTTPS Dashboard announcement webhooks. Each enabled destination appears as a separate checkbox, may be used without a local target, and receives bounded branded Discord embed JSON only when selected. Local phone, audio, and desktop submission runs before external webhook I/O.'); ?></li>
		<li><?php echo _('General Settings separates phones/address, delivery providers, speech/display, desktop clients, API/security and updates/storage. The capacity counter uses the same combined CPU, RAM and free-space check as saved device admission, with a green check for sufficient resources or red X for an insufficient requirement. Fresh installs round detected internal phone counts above 25 up to the next 50; upgrades preserve saved limits. New phone image settings default to HTTPS; try HTTP only when HTTPS does not work on a legacy phone. Existing saved transport is retained.'); ?></li>
		<li><?php echo _('Labs SMS supports Twilio, Telnyx and BulkVS for saved announcement recipients. Choose a provider before entering credentials or sending limits. BulkVS reports API acceptance only: delivery and STOP callbacks remain unsupported, so manage its opt-outs at the provider and disable affected SLS recipients before another send. No provider is contacted by saving or previewing.'); ?></li>
		<li><?php echo _('Phone and SMS contacts share one editor in Locations and Audiences. Enable Calls, SMS or both; SMS consent is separate. Use Check saved BulkVS sender in General Settings to verify number enablement and campaign assignment without sending SMS. Twilio and Telnyx messages include a STOP reply instruction and require verified inbound callbacks.'); ?></li>
		<li><?php echo _('Announcement groups can include online or offline extensions plus desktop app clients. Offline extensions are skipped when sending and the UI warns the sender.'); ?></li>
		<li><?php echo _('Announcement audio can be disabled, tones only, TTS only, or tones plus TTS. Opening and closing recordings can be selected per announcement, and either may be None without changing the dialplan.'); ?></li>
		<li><?php echo _('The Labs colored-announcement designer provides a title, background color, and preview. Colored image announcements are currently limited to compatible Yealink phones; other vendors receive their text format.'); ?></li>
		<li><?php echo _('A short cooldown prevents repeated accidental announcement sends.'); ?></li>
	</ul>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('SIP NOTIFY and Desktop API'); ?></summary><div class="sls-help-section-body">
	<p><?php echo _('Desktop stream protocol 2 preserves reconnect cursors, signals history gaps, and rechecks revoked credentials while connected. Updated desktop apps may POST an event_id to /api/sipnotify/desktop/ack using their own credentials to acknowledge a targeted event. Publication, current connection, and acknowledgement are separate states; sleeping desktops are not delivery errors.'); ?></p>
		<p><?php echo _('Phones receive SIP NOTIFY pushes directly from Asterisk/PJSIP. Audio pages every resolved PJSIP contact through Page/ConfBridge. Each registered phone is listed separately. Mixed phone families receive their own vendor payloads when Asterisk contact routing is available; an unknown device does not change another phone’s detected format. If individual routing is unavailable, Asterisk receives one generic endpoint payload. Asterisk submission is not handset acceptance. Desktop clients authenticate with their assigned username and password and can use either the live event stream or the JSON endpoint. Sleeping or disconnected clients are not reported as live.'); ?></p>
	<ul>
		<li><code>/api/sipnotify/desktop</code> <?php echo _('returns JSON for the SLS Mass Notify desktop app. Use HTTP Basic authentication with the desktop client username and password configured in General Settings.'); ?></li>
		<li><code>/api/sipnotify/desktop/stream</code> <?php echo _('returns a live server-sent-event stream using the same Basic authentication and per-client target filtering. The authenticated handshake is flushed through Apache immediately; clients should reconnect after the server reconnect event and may send Last-Event-ID when resuming. Expired authorized records advance the cursor without being emitted so the next valid notification is not skipped.'); ?></li>
		<li><?php echo _('A desktop application must connect to the /stream endpoint to receive live pushes. Applications that continue requesting the /desktop JSON fallback remain polling clients by design.'); ?></li>
		<li><?php echo _('Live and JSON notification records contain a presentation object plus flat compatibility fields. Weather supplies priority-derived background, header, accent, and text colors; colored announcements preserve the selected title/background; Lightning supplies its branded warning color.'); ?></li>
		<li><?php echo _('Each desktop only receives events sent to all desktops or events explicitly targeted to its username. Legacy records without routing fields are denied.'); ?></li>
	</ul>
		<p><?php echo _('Vendor firmware and provisioning settings can affect auto-answer and XML push behavior. Yealink audio uses Alert-Info: Intercom and requires handset intercom auto-answer permission. Yealink XML requires Features > Remote Control > SIP Notify or provisioning value push_xml.sip_notify = 1. To allow a popup during paging, also disable Features > Remote Control > Block XML in Calling (push_xml.block_in_calling = 0). Compare a popup-only test while idle with an audio-and-popup test. The installer warns when it detects Yealink contacts but does not change firmware or provisioning. Test each target model.'); ?></p>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Control API'); ?></summary><div class="sls-help-section-body">
	<p><?php echo _('Endpoint:'); ?> <code><?php echo htmlspecialchars($controlUrl); ?></code></p>
	<p><?php echo _('The Control API is disabled by default. Enable it only if remote administration is required. Authentication uses Authorization: Bearer <api-key> or X-API-Key. Recent Control API Use shows requests that supply an API key, including local health probes. Operator authentication and administrator security activity are shown under Operator Access.'); ?></p>
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

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Email and Webhook Delivery'); ?></summary><div class="sls-help-section-body">
		<p><?php echo _('General Settings manages the sender local part/domain, optional recipients for deduplicated system and error notices, multiple Discord or generic HTTPS alert webhooks, and a separate protected list of Discord or Discord-compatible HTTPS Dashboard announcement webhooks. Weather and Lightning email recipients are configured independently on each zone or trigger area. Fresh installs default to no-reply at the local Postfix/PBX domain.'); ?></p>
		<p><?php echo _('Changing the email identity does not configure Postfix, an SMTP relay, DNS, SPF, DKIM, DMARC, or PTR/reverse DNS. Configure those separately so the selected identity is authorized to send from this PBX.'); ?></p>
		<p><?php echo _('Live alert and system/error email is submitted through the local sendmail path as a branded Southland Servers HTML card with a plain-text alternative. Repeated active system faults are deduplicated. Discord uses the locally packaged webhook-builder artwork as one card-size image, served from the PBX public HTTPS origin and configured port. Old author/footer logo icons are removed. No alert image is fetched from the Southland Servers website. NWS email includes the actual spoken announcement, affected places, full description, protective instructions and validity times even with an older custom template. Notification Details shows email/webhook transport evidence and HTTP codes; older records without evidence remain unknown. Generic webhooks receive bounded structured JSON with an event ID and idempotency header. Webhook delivery requires HTTPS and public DNS/address validation, verifies TLS, refuses redirects, and redacts stored URLs from results. Manual tests, previews, and dry runs do not contact external destinations.'); ?></p>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('TTS and Audio'); ?></summary><div class="sls-help-section-body">
	<ul>
		<li><?php echo _('Piper supports US English, Spanish, French, German and Brazilian Portuguese through verified models. Select the voice appropriate to your message; this does not translate Weather.gov text. Voices are selected separately: regular announcements default to Lessac, while Weather and Lightning default to Amy.'); ?></li>
		<li><?php echo _('Volume controls are saved as percentages and applied to the final Asterisk WAV conversion.'); ?></li>
		<li><?php echo _('Generated Piper speech defaults to 30 seconds and can be capped anywhere from 1 to 600 seconds.'); ?></li>
		<li><?php echo _('Upload custom audio through FreePBX Admin > System Recordings, then select it globally or per announcement as the opening or closing tone. Either selection may be None. The installer registers uniquely named SLS Mass Notify paging-opening, paging-closing, NWS, and Lightning recordings and refuses a conflicting user-owned recording instead of overwriting it. Selected recordings are validated and converted into managed Asterisk audio.'); ?></li>
		<li><?php echo _('Generated TTS and combined announcement audio files are automatically removed after 15 minutes.'); ?></li>
			<li><?php echo _('Audio delivery uses the private Asterisk context sls-alert-audio, Page/ConfBridge fan-out, and the module-owned vendor-aware sls-alert-autoanswer handler. It does not require a public paging group such as *6767 or FreePBX-generated paging macros.'); ?></li>
	</ul>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Updates and Removal'); ?></summary><div class="sls-help-section-body">
	<ul>
		<li><?php echo _('Update checks run through the root-owned updater and record their result for General Settings and Dashboard health. A newer release produces a yellow warning even when automatic installation is disabled.'); ?></li>
			<li><?php echo _('Update to Latest Release is shown only when a newer accepted release is available. It queues an immediate verified update and shows queued, installing, success, or failure status before refreshing the page.'); ?></li>
		<li><?php echo _('Completely Uninstall in Danger Zone requires confirmation and permanently removes module code, runtime services, APIs, logs, credentials, backups, tones, and the central configuration. Download a config backup first if the deployment may be restored later.'); ?></li>
	</ul>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Logs and Health Checks'); ?></summary><div class="sls-help-section-body">
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

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Troubleshooting'); ?></summary><div class="sls-help-section-body">
	<ul>
		<li><?php echo _('Desktop app unauthorized: confirm the desktop client is enabled in General Settings and test /api/sipnotify/desktop with that client username and password.'); ?></li>
		<li><?php echo _('Phone SIP NOTIFY missing: confirm the target extension is registered, AMI user slsmassnotify exists, and /var/log/sls_mass_notify_push.log has no AMI errors.'); ?></li>
			<li><?php echo _('Audio missing or the phone rings normally: confirm both SLS dialplan contexts exist, Page and ConfBridge are available, the asterisk account can write to /var/spool/asterisk/tmp and outgoing, the two SLS sound links resolve to the protected sounds folder, Piper generated a WAV under the TTS folder, and the handset permits vendor auto-answer.'); ?></li>
			<li><?php echo _('Red installer or repair fault on Dashboard: read the reported stage and possible solution, then inspect /tmp/slsmassnotifyserver-install.log or the maintenance log. The fault remains until comprehensive integration, signature, runtime, and health verification succeeds.'); ?></li>
		<li><?php echo _('Asterisk provider repair stops before installation: wait for active calls to finish and rerun the installer. If it reports an unowned module path, unavailable exact package version, noload rule, or autoload-disabled provider, restore or explicitly enable the matching provider from that Asterisk build or repository; the installer intentionally will not mix modules from a different ABI.'); ?></li>
		<li><?php echo _('Module says Altered: remove generated caches such as __pycache__ if present, run the PBX-local signing helper, confirm verifyModule returns status 129 with no details, then run fwconsole reload.'); ?></li>
		<li><?php echo _('Setup wizard appears after update: verify setup.completed remains 1 in the central .config and no pending config reset it.'); ?></li>
	</ul>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('License and EULA'); ?></summary><div class="sls-help-section-body">
	<p><?php echo _('This software is licensed under the GNU Affero General Public License version 3 or later. You may use, study, modify, and share it under the AGPLv3 terms.'); ?></p>
	<p><strong><?php echo _('No warranty.'); ?></strong> <?php echo _('The software is provided as-is, without warranties or guarantees of merchantability, fitness for a particular purpose, uninterrupted operation, emergency suitability, or regulatory compliance. You use it at your own risk. The authors, contributors, and Southland Servers Group are not liable for damages, missed alerts, incorrect alerts, service interruption, data loss, device behavior, or any direct, indirect, incidental, special, consequential, or punitive damages.'); ?></p>
	<p><?php echo _('This system is an aid for notifications and should not be treated as the sole source for life-safety, legal, medical, weather, or emergency decisions. Maintain independent alerting paths.'); ?></p>

	</div></details>

	<details class="sls-help-section"><summary><?php echo _('Bugs, Support, and Credits'); ?></summary><div class="sls-help-section-body">
	<p><?php echo _('Report bugs at:'); ?> <a href="https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues" target="_blank" rel="noopener noreferrer">https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues</a></p>
	<p><?php echo _('Project information:'); ?> <a href="https://southlandservers.xyz/projects" target="_blank" rel="noopener noreferrer">https://southlandservers.xyz/projects</a></p>
	<p><?php echo _('Community/support Discord:'); ?> <a href="https://southlandservers.xyz/discord" target="_blank" rel="noopener noreferrer">https://southlandservers.xyz/discord</a></p>
		<p><?php echo _('Credits: Southland Servers Group, FreePBX/Asterisk, National Weather Service API, Piper TTS, and supported SIP phone vendors.'); ?></p>
	</div></details>
	</div>

<div class="well"><h3><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo _('Sites and weather routes'); ?></h3><p><?php echo _('Sites store devices, optional Weather.gov coverage and map coordinates. Weather and Lightning route editors can explicitly copy a location or saved audience, including SMS and external calls. Review and save the copied selections; later directory edits do not alter existing routes or queued alerts. Live-only source validation, recipient identity checks, quiet hours and expiry apply before channel submission. Manual weather tests use only selected internal phones and desktops.'); ?></p><p><?php echo _('The setup wizard selects the system timezone. An explicit change is applied by protected maintenance; review schedules after changing it. Capacity controls reject unsupported increases. Desktop enrollment stops at the saved device limit; phone destinations queue in batches and receive explicit timeout or failure records.'); ?></p></div>
