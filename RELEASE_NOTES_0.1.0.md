# Southland Servers Mass Notifications Server 0.1.0

Version 0.1.0 is a cross-PBX reliability and operations release. It expands notification destinations, changes audio paging to multi-contact Page/ConfBridge delivery, adds native FreePBX backup and restore support, and makes installer failures visible from Dashboard health.

The module remains beta-stage software. Validate every phone model, desktop client, external destination, and restore process on a non-critical PBX before using it in an emergency workflow.

## Install or update

Run as `root` on Debian 12 / FreePBX 17:

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.0/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.1.0/slsmassnotifyserver-0.1.0.tgz' \
./sls-install.sh
```

The installer preserves the protected central config during an update. Fresh installs open the setup wizard. The release asset is `slsmassnotifyserver-0.1.0.tgz`.

Release TGZ SHA-256: `90ae525738d117a8141722590b33dbd4f4b23c32f31cb6e761f329e98504d704`

## Notification destinations

- Weather Alert zone groups now select their own phone extensions, enabled desktop clients, and optional live-alert email recipients. A zone's email list receives only that zone's live alerts, while manual tests remain local and never contact email or webhooks.
- General Settings now manages the local Postfix sender identity, opt-in system/error email recipients, multiple Discord webhooks, and multiple generic HTTPS webhooks in one popup. The system/error list is separate from Weather and Lightning alert delivery.
- The email sender local part and domain are both editable. Fresh installations start with `no-reply` and derive the domain from the local Postfix/PBX identity.
- Existing valid sender settings and the legacy Discord destination migrate into the canonical protected lists. A pre-0.1.0 global live-alert email list migrates into the configured Weather zones and Lightning areas; the new system/error list remains opt-in.
- The one-minute maintenance worker can submit each active Weather, Lightning, scheduling, installation, update, or maintenance fault once to that opt-in system/error list. Recovery clears the deduplication marker so a later recurrence can notify again; per-area Lightning state avoids duplicate aggregate mail.
- Branded email continues through the local Postfix/sendmail path with a plain-text alternative.
- Discord receives a compact branded embed with event-aware presentation. Its profile and card images use stable public Southland Servers HTTPS PNG assets instead of PBX-hosted media that Discord may be unable to retrieve.
- Generic webhooks receive bounded JSON with a stable schema, event ID, timestamp, alert details, presentation color, and idempotency header.
- Webhook URLs must use HTTPS and public DNS. Resolution to private, loopback, link-local, or otherwise non-global addresses is rejected; TLS is verified against the original hostname, redirects are refused, payloads and retries are bounded, and secrets are not copied into result records.
- Manual Weather and Lightning tests, previews, dry runs, and direct dispatcher invocation cannot send external destination traffic.

The email identity controls do not configure Postfix relay policy, DNS, SPF, DKIM, DMARC, or reverse DNS. Those remain PBX/site administration tasks.

## Lightning trigger areas and usage reporting

- Lightning Alerts can now manage up to five named trigger areas from one popup instead of one global location/recipient block.
- Each area chooses its Weather.gov adaptive trigger group, Xweather location and radius, phone extensions, enabled desktop clients, live-alert email recipients, and optional all-clear behavior. An area's email list receives only that area's alerts.
- API credentials, polling cadence, independent Lightning quiet hours, tones, voice, and volume remain shared service settings.
- Storm, clear, cooldown, delivery, and retry state is isolated by stable area ID. Existing single-area configuration migrates to the first area without dropping its phone targets or prior all-desktop behavior.
- One shared quota governor protects the account. Several storm-active areas can make separate location queries in the same cycle, so the Lightning page includes a concise notice that multiple active areas consume tokens faster.
- Xweather usage is presented as compact provider-period and token cards without a storm-mode projection calculator. An account reset date in the past is marked as a historical snapshot, not current usage. The next successful storm query or **Verify Applied Areas** refreshes the provider period, and verification makes one query per enabled area.
- Manual Lightning testing can select applied areas, retains its 60-second cooldown, and never sends email, Discord, or generic webhooks.

Weather.gov zone setup now uses one direct link to the [official NWS Public Zone Maps](https://www.weather.gov/pimar/PubZone). Choose the state, find the three-digit zone number covering the location, then enter the two-letter state abbreviation, `Z`, and those three digits, such as `TXZ163`.

## Scheduled recurrence

- A schedule can remain one-time or repeat every 7 or 14 days from one future starting date and time.
- Repeating announcements run at the same PBX-local time and retain the selected phone, group, desktop, audio, voice, volume, tone, and Labs color settings.
- The module expands each recurrence into a validated, protected occurrence series covering up to five years. Nonexistent or ambiguous daylight-saving times are rejected before activation.
- The existing execution journal and restore protections continue to prevent completed, due, or uncertain occurrences from replaying automatically.

## Cross-PBX phone and audio work

- Audio now resolves all PJSIP contacts for an extension and pages them through Asterisk `Page()`/ConfBridge. A registered softphone no longer displaces a registered desk phone on the same extension.
- The call-file origin remains alive for the measured WAV plus a two-second teardown margin. A five-second participant-answer window preserves prompt auto-answer fan-out without leaving phones silently bridged for roughly 30 seconds after playback.
- The FreePBX device/AOR fallback is initialized before its secondary contact lookup, avoiding blank `PJSIP_DIAL_CONTACTS()` expansion on community Asterisk builds.
- The protected runtime-tree check now recognizes the exact root-owned, non-writable Piper compatibility environment as a trust boundary. It remains fail-closed for links, writable directories, unexpected ownership, and every other unsafe runtime entry.
- The installer verifies `Page`, ConfBridge, PJSIP contact/dial functions, their provider modules, restart persistence, spool access, audio paths, AMI discovery, and effective endpoint routes before declaring integration complete.
- Yealink auto-answer now uses `Alert-Info: Intercom`.
- Same-family visual delivery retains endpoint fan-out. Mixed-format registrations use per-contact routing only when every contact has a usable URI and Asterisk has a usable default outbound endpoint. Installation hard-fails when that safe route is unavailable; it never accepts an unsafe cross-vendor payload fallback.

Yealink still has two handset-side requirements the PBX cannot enable safely:

- Intercom auto-answer must be allowed for audio paging.
- XML display requires **Features > Remote Control > SIP Notify** or provisioning value `push_xml.sip_notify = 1`.

The installer prints a Yealink reminder when it discovers those contacts. It does not change firmware, provisioning, phone credentials, PJSIP peers, or unrelated FreePBX files.

## Dashboard and desktop responsiveness

- The announcement widget now places preparation and final delivery status beside **Send Announcement**, so the result remains visible at the bottom of the panel.
- A lost browser response leaves Send disabled until the cooldown status probe establishes whether a retry is safe, reducing accidental duplicate pages after an unknown outcome.
- Selected desktop events publish before synchronous TTS and phone work whenever their timeout is already known. Audio-duration timeouts publish immediately after the WAV is prepared and before the phone-notification delay.
- Phone and desktop submissions use isolated sender modes, preventing a combined alert or announcement from writing the same desktop event twice.
- Read-only page rendering no longer performs filesystem repair or AMI discovery during settings normalization. File-fingerprint-guarded request caches retain safe write invalidation while cutting the measured production Dashboard and Mass Notify page render path to a fraction of a second.

## Honest delivery status

User-facing and runtime results now say **queued** or **submitted to Asterisk** when that is all the PBX can prove. An accepted AMI action confirms that Asterisk accepted the command. A written call file confirms that Asterisk accepted the page job. Neither result proves that a handset answered, rendered XML, returned a final SIP response, or played the complete audio.

Manual Weather tests use the same contract. They require Asterisk to pick up each requested audio job within a bounded interval and require the requested SIP and/or desktop submission path to succeed. They no longer turn an otherwise successful test into an error merely because Asterisk later archives the Local/Page origin with an `Expired` flag.

Use Asterisk logs, the module delivery logs, handset logs, and real-device tests to verify the last hop.

## FreePBX backup and restore

Version 0.1.0 adds native FreePBX 17 `Backup.php` and `Restore.php` adapters. A module-based backup can include:

- the protected central configuration;
- the scheduled-announcement execution journal;
- custom module tones.

The v0.1.0 installer now treats FreePBX Backup as an adaptive prerequisite, verifies that FreePBX discovers the Mass Notify adapter, and confirms enrollment in every existing module-based job. A clean PBX with no backup jobs is reported as ready rather than faulty; the installer deliberately leaves schedule, storage, and retention choices to the administrator instead of creating an arbitrary backup policy.

The restore path validates the module/schema manifest, archive inventory, file names, size limits, SHA-256 values, config structure, protected credentials, schedule records, and WAV headers before activating data. Replacement is staged and rollback-protected. Completed or already-due scheduled occurrences are not replayed, and generated integration is repaired and verified after the wider FreePBX restore finishes.

There is an important FreePBX limitation: a stock restore does not know how to download an unknown custom module from this project. Install `slsmassnotifyserver` on a replacement PBX before restoring a backup that contains its module data. Dashboard health reports missing enrollment in an existing module-based job, but administrators should still inspect the job and retain an independent `.config` backup.

## Health and repair

- Failed installer and repair transactions write protected failure state and a FreePBX notification.
- Dashboard health shows a red fault with the failed stage and a possible next action.
- Failure state is not cleared by a partial run; it clears only after comprehensive integration, signature, runtime, and health verification succeeds.
- Native backup readiness and post-restore repair state are included in health reporting.
- The scheduled-announcement worker, cron, and journal are checked as actionable faults only while at least one schedule is enabled. Opening Scheduling on an unused installation no longer creates a false missing-worker warning.

## Compatibility and naming

User-facing references use **Module**. The established paths containing `SLS_Mass_Notifications_Plugin` remain unchanged so existing configuration, tones, sound links, backups, and upgrade paths continue to work.

The automatic updater now accepts the project tag format `slsmassnotifyserver-X.Y.Z` with an optional `-beta` suffix. It still requires an official repository release, a matching immutable-tag installer, and GitHub SHA-256 asset metadata. Automatic installation remains disabled by default.

## Operational notes

- The protected central config remains the only settings source of truth.
- A normal update preserves settings and credentials.
- A normal uninstall preserves configuration, backups, uploaded tones, and schedule execution history; an explicit purge removes them.
- Piper model files are downloaded by the installer and are not stored in the source repository or release TGZ.
- Generated TTS and combined paging audio retain the 15-minute cleanup policy.
- Weather Alerts continue to use the U.S. Weather.gov API. Lightning Alerts remain a Labs Xweather integration.
- Manual Weather and Lightning tests do not send email, Discord, or generic webhooks.

## Known limits

- SIP NOTIFY and auto-answer behavior varies by phone model, firmware, provisioning, and security policy.
- Yealink XML and intercom permissions must be enabled on the phone or through its provisioning system.
- Mixed-vendor contacts on one extension depend on complete Asterisk contact routing information.
- Generic webhook delivery is at least once around ambiguous network timeouts; receivers should deduplicate by event ID or idempotency key.
- Local sendmail acceptance does not prove inbox delivery.
- Native restore requires the custom module to be installed before its data can be restored on a replacement PBX.
- Dashboard integration modifies the stock Dashboard module and can require repair/re-signing after a Dashboard upgrade.
- Locally signed custom modules may display **Unknown** in Module Admin even when `verifyModule()` returns trusted status 129.

## Project links

- Repository: https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server
- Issues: https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues
- Project: https://southlandservers.xyz/projects
- Discord: https://southlandservers.xyz/discord
