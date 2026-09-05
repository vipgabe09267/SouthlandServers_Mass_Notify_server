# Southland Servers Mass Notifications Server 0.1.2-beta

This prerelease focuses on delivery reliability, clearer results, and safer installation and updates. It remains beta software: test the complete route to each phone family, desktop app, and external receiver before depending on it.

## Install or update

Run as `root` on FreePBX 17 / Debian 12:

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.2-beta/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.1.2-beta/slsmassnotifyserver-0.1.2-beta.tgz' \
./sls-install.sh
```

The installer preserves existing settings and credentials. Fresh installs open the setup wizard. Use the attached `slsmassnotifyserver-0.1.2-beta.tgz` module package, not GitHub’s automatically generated source-code archives. The two small manifest files are for signed update verification; keep them alongside the TGZ when mirroring the release.

## Announcements and desktop delivery

- Simplified the Dashboard footer to one Send button, compact progress, a green check with the sender after successful submission, and expandable delivery details. Removed the extra Review destinations button and raw HTTP-error text; duplicate submits are blocked.
- Added Normal/Urgent audio priority. Ready urgent audio moves ahead of waiting normal pages for the same phones, without interrupting a page already playing or bypassing cooldown.
- Dashboard sends now run as durable background jobs. The interface stays available while audio is prepared and shows progress plus separate results for audio, SIP NOTIFY, desktop publication, and selected webhooks.
- Announcements identify the authenticated sender. Control API announcements use the API identity; newly saved schedules retain their creator. User-supplied text cannot substitute for the trusted sender identity.
- One failed channel no longer prevents other requested channels from being attempted. Deliberate retry targets only confirmed failed announcement destinations and rejects duplicate retry clicks. Interrupted or uncertain deliveries are not automatically replayed.
- Fixed first-event and reconnect-cursor handling in live desktop streams. Added stream limits, authentication throttling, credential revocation checks, and optional app acknowledgments. Help distinguishes an active connection from an acknowledgment; neither means a person has read the message.
- Added per-registration phone-format overrides alongside existing extension-wide overrides. On-demand device inventory shows detected formats and transports. UDP, TCP, and TLS contact routing remains supported without downgrading existing transports.
- Added up to ten optional saved local channel-check profiles with explicit phones/desktops and audio-only, visual-only, or combined checks. The collapsed heading now includes a question-mark explanation. These do not send email/webhooks or consume Xweather queries.

## Weather, Lightning, and scheduling

- Weather.gov and Lightning observations are separated from delivery and external retries. A slow page or unavailable webhook cannot hold the weather observation lock.
- NWS jobs are selected chronologically across zones, with current routing, cancellation, expiration, and observation freshness checked before submission. Unsent updates replace the queued version of the same chain; a time-only edit does not automatically replay a completed alert.
- A shared, recipient-aware audio reservation system coordinates Dashboard, scheduled, Weather, and Lightning pages. Generated media remains available through queued playback and is cleaned up fifteen minutes after its reserved playback ends.
- Lightning now rejects malformed provider observations, invalidates area-dependent caches after location changes, and handles observation gaps conservatively. All-clear messages describe observed conditions, with a configurable per-area observation period instead of claiming a storm has disappeared.
- Lightning query order rotates fairly between areas. Coverage timestamps and queue health make stale observations, pending delivery, and uncertain outcomes visible.
- Added a one-to-five-second paging answer window, default five, separate from visual-message expiry and prepared audio length. Handset auto-answer still needs correct provisioning.
- Recurring schedules show their creator and last occurrence. Recurrence remains a finite series of up to five years, not an indefinitely renewing calendar rule.

## Security, installation, and maintenance

- Added a redacted diagnostic download in General Settings. Its allowlisted JSON contains versions, health checks, permissions, anonymous device counts, and queue counts—not configuration, credentials, addresses, message text, or raw logs. Downloads require an authenticated, CSRF-protected request.
- Release manifests are signed with the publisher’s Ed25519 key and bind both installer and TGZ hashes. The updater resolves the release to a commit and installs the exact verified local archive. This release includes the TGZ, `release-manifest.json`, and `release-manifest.sig`.
- New release fixes use a new version. Equal-version replacements intentionally do not trigger updates; older unsigned versions require their own tagged installer.
- Generic HTTPS receivers can use Bearer authentication, HMAC-SHA256 signatures, or both. Destination secrets remain in the protected configuration and are redacted from API responses.
- All portable settings and revocable encrypted desktop credentials remain in the central `.config`. Protect its backups: possession of the complete file permits recovery of those credentials.
- Added bounded API auditing, external retry expiry, weather queue limits, media leases, operational log rotation, and storage/queue diagnostics.
- Installer checks now include precise unsupported-layout failures, pinned Piper dependencies, functional voice checks, runtime ownership, and new worker/package parity. Disabled-weather installation checks leave no background workers behind.
- Updated the private Piper environment’s pip dependency to 26.2.0 for [CVE-2026-13346](https://mail.python.org/archives/list/security-announce@python.org/thread/L2BNQGGVQCEV7DROOORQ7WFKKFF2OOQX/), a malicious-package-index path traversal issue. This does not replace OS/FreePBX patching or a deployment-specific security review.
- General Settings, Help, and test responses provide clearer feedback. Weather and Lightning test requests release the FreePBX session while waiting, and errors return bounded JSON instead of an HTML exception page. The README is shorter with installation instructions near the top.

## Verification and boundaries

The release gate includes PHP/Bash/Python syntax validation and behavioral tests for reconnects, targeting, sender attribution, independent channel failures, duplicate retries, media leases, chronological multi-zone work, cancellation/expiry, stale Lightning jobs, unsafe paths, malformed inputs, webhook signing, manifest tampering, configuration preservation, and installer/signing/uninstaller fixtures.

PBX acceptance is not handset acceptance. A queued page may still fail to auto-answer, a phone may reject XML, a sleeping desktop cannot display immediately, and an accepted email/webhook may not reach its final recipient. Client ACK support requires a compatible desktop-app implementation. Mixed-vendor hardware, browser layout, and complete fresh-install/uninstall/restore behavior must still be checked on representative disposable deployments.

Settings are preserved during upgrades. Back up the protected configuration and verify FreePBX backup enrollment, Dashboard health, and local signature status after installation.

### Release verification — September 5, 2026

- 44 Python/PHP regression suites and five Bash installer/signing/uninstaller fixture suites passed, along with syntax and package-content checks. The additional suites cover priority ordering and media retention, diagnostic redaction, and Dashboard result transitions.
- The final release TGZ completed a full reinstall on the development PBX. The installer verified Piper voices, registered PJSIP endpoint discovery/routing, the desktop live SSE authentication handshake, runtime integration, and the America/Chicago system timezone. The live authenticated Control API probe was skipped because this PBX has that optional API disabled; isolated authentication/schema tests passed.
- All 77 files in the final TGZ match repository and installed module bytes. Runtime, API, Dashboard, public assets, and signer parity also passed the installer checks. Configuration, generated state, voice models, caches, and PBX-local signatures are not release source files.
- The existing central configuration remained byte-identical at `0640 asterisk:asterisk`. Module, Dashboard, and Framework signatures returned trusted status `129`; all 25 health checks passed.
- Earlier candidate announcement and Weather tests were restricted to extension 1000 and the designated test desktop; local submissions were accepted. No further live alerts, email, webhook, or paid Xweather test query was sent during the final packaging pass.
- Settings, Help, and setup wizard rendered successfully as the Asterisk service account; Dashboard rendering and executable JavaScript checks also passed. Redacted diagnostics worked as the service account. This is not a real-browser visual interaction test or a disposable fresh-install/uninstall/restore test.
- Apache reported duplicate module-load warnings on this host while its configuration syntax check passed. No unrelated Apache/core settings were changed for those warnings.

TGZ SHA-256: `c5e7f190f1e109393eed58d753e02958367b4382323ffa6355f2ef3a62e08aea`
