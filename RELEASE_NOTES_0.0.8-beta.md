# Southland Servers Mass Notifications Server 0.0.8-beta

Version 0.0.8-beta concentrates on weather-alert reliability, per-contact phone delivery, honest system-test results, and safer installation and maintenance. It is a beta release for FreePBX 17, Asterisk, and Debian 12.

## Important fixes

- Lightning system tests no longer finish the worker and then fail their own AJAX response. The cooldown-state method is now callable by the page controller, unexpected PHP failures are returned as sanitized JSON, and invalid UTF-8 cannot corrupt the response.
- Dashboard announcement targeting no longer disappears when a PBX's AMI contact response is empty but the Asterisk CLI sees registered phones. The Dashboard uses the canonical AMI inventory first and falls back to a broader CLI parser when needed.
- Same-vendor phones now receive SIP NOTIFY through Asterisk endpoint fan-out. This removes the hidden dependency on a site-specific `default_outbound_endpoint` that caused fresh PBXs to accept the AMI request but never deliver a Yealink window.
- Asterisk's documented `Uri` ContactList key is parsed correctly. Endpoint/ObjectName priority, response validation, mixed-contact safety, and PJSIPNotify permission probing are covered by release regression tests.
- Fresh installs and upgrades now succeed on PBXs with no registered PJSIP contacts. Asterisk 22's exact `No Contacts found` AMI response is recognized as an authorized empty inventory after successful login and Ping; authentication, permission, and unrelated AMI errors still fail installation.
- Community FreePBX systems with an unloaded, missing, or damaged Asterisk header provider no longer fail late with `Required Asterisk dialplan function is unavailable: PJSIP_HEADER`. The installer loads `res_pjsip_header_funcs.so`, verifies the actual function, and performs the same early load-and-recheck for every function and application used by direct paging. When a dpkg-owned provider still cannot register, it reinstalls only the exact installed owning package version and retries without upgrading Asterisk.
- Downstream/community Asterisk builds that capitalize capability headings differently no longer report an installed `PJSIP_HEADER` function as missing. The installer matches the complete quoted function or application heading case-insensitively, retains exact-name validation, and does not enter provider loading or package repair when the capability is already registered.
- PBX-local signing now follows the FreePBX web account, web root, spool directory, and GPG home reported by the installed system. It no longer assumes that every deployment uses the `asterisk` account or one of a short list of keyring paths.
- Existing GPG-home ownership is repaired before trust import. Signing and key generation are serialized, GPG work is bounded and retried, and a candidate signature is not left in place unless FreePBX returns the exact trusted result.
- Install, update, repair, update-drift recovery, and complete uninstall share one maintenance lock. This prevents the minute worker from signing or rewriting managed files during another deployment operation.
- Weather Alerts now recognizes `Heat Advisory`.
- The upgrade performs a one-time, narrowly scoped cleanup of stale Heat Advisory deduplication records written by 0.0.7-beta while that event was unsupported.
- A first-seen Weather.gov alert chain is no longer discarded merely because its `messageType` is `Update`.
- Reference and chain-key deduplication still suppresses timestamp-only reissues after a chain has actually been processed.
- Weather and Lightning tests run synchronously and return a real error when audio-call processing or SIP NOTIFY submission fails.
- Weather and Lightning tests no longer send email or Discord notifications.
- Weather dry-run fixture faults stay local to the selected status and log files and never use the live fault-email route.
- Test failures are shown on the FreePBX page with sanitized, actionable details for unreachable extensions, audio failures, AMI errors, payload errors, and timeouts.
- The Discord sender now normalizes embed timestamps to ISO UTC, fixing live webhook failures caused by human-readable dates.

## Phone and paging delivery

- Same-format registrations use one endpoint-targeted SIP NOTIFY. Asterisk then fans out to all registered contacts with the endpoint's own transport, NAT, and authentication behavior.
- Endpoint fan-out works whether or not `default_outbound_endpoint` is configured.
- Mixed-vendor contacts on one extension use contact-specific URI routing only when every registration has a usable URI and Asterisk has a valid default outbound endpoint.
- If mixed routing is unsafe, delivery returns a clear error instead of broadcasting one vendor's XML to incompatible phones.
- Full contact URIs use Asterisk's documented AMI `Uri` field, with bounded AOR lookup only as a compatibility fallback.
- AMI delivery counts only an explicit success response; a blank response or unrelated error can no longer be reported as sent.
- Sangoma P- and S-series audio pages use the documented `Alert-Info: intercom` auto-answer value.
- Weather and Lightning call files are created on Asterisk's spool filesystem and moved atomically into the outgoing queue.
- Manual-test call results are archived in a protected directory only long enough to verify them, then removed.

SIP NOTIFY display and auto-answer behavior still depends on handset model, firmware, provisioning, and local security policy. Yealink's XML push setting may be disabled until it is enabled on the handset or through provisioning. The installer does not modify phone provisioning. Asterisk accepting a NOTIFY request and completing a call file does not prove that every handset displayed or audibly played the alert.

## Installation and maintenance

- The release installer now validates the FreePBX bootstrap and database, the Asterisk control socket, required PJSIP/spool modules, available disk space, and spool permissions before changing module files.
- Asterisk paging capabilities are checked before package download or module activation and again after FreePBX reload. Asterisk can return process status zero for a failed module load, so the installer requires the exact function/application help response. If a provider cannot register, the installer resolves the package owning the canonical active module path and uses a pinned `package=installed-version` reinstall with `--no-remove`, then loads and verifies the provider again. It defers that repair during active calls. If the exact version cannot be retrieved or the running Asterisk module path is not owned by a Debian package, installation stops before SLS module activation instead of upgrading Asterisk or mixing module ABIs.
- FreePBX 17 plus Framework, Dashboard, and System Recordings modules, PHP OpenSSL, Apache rewrite/authorization support, active Apache/cron services, and the required filesystem capacity are verified before activation. Missing required modules are installed and disabled dependencies are enabled without silently upgrading an installed core module.
- The TGZ is unpacked into a private staging directory and every staged PHP, Bash, and Python source file is syntax-checked before activation.
- Existing module files are retained in a temporary rollback tree until installation and verification complete.
- If installation fails after activation, the new module's uninstall hook removes partial external integration before the previous module tree and protected central configuration are restored.
- The installer parses and validates the returned AMI health document, including authenticated login, Ping/Pong, `PJSIPShowContacts`, and `PJSIPNotify` authorization.
- Registered numeric phones returned by `pjsip show contacts` must also appear in the AMI inventory with at least one contact and a detected format.
- AMI authentication and contact discovery are repaired and retried before installation is reported as failed.
- The packaged signer is restored atomically as a root-owned executable before use. Module-hook signing is deferred during installer-managed work, and one final pass signs the stable SLS, Dashboard, and Framework trees after reload.
- Each `module.sig` update is transactional. A failed FreePBX verification restores the prior signature instead of leaving a rejected file behind.
- Final verification covers byte-identical runtime/API/assets, module state, Dashboard integration, menu placement, dialplan, contact inventory, adaptive SIP routing, all pinned Piper hashes and real synthesis with every voice, bundled System Recordings, Asterisk WAV support, authenticated desktop SSE, conditional authenticated Control API status, local HTTP/HTTPS public-image delivery, cron execution, and exact local signatures.
- Stale managed runtime/API/asset files are pruned during repair and install. Persistent configuration, Piper models, generated media, and user data remain outside the release package.
- A native Module Admin uninstall now removes executable runtime, public API routes, bundled System Recordings, and the persisted Dashboard hook while preserving the central configuration and persistent user data.
- Repair Installation and Complete Uninstall now expose queued, running, completed, and failed states in Danger Zone.
- Protected configuration replacement shows upload and validation progress without exposing stored values.
- Standalone and packaged uninstallers now verify root access and the FreePBX/database bootstrap before changing state. A normal non-purge uninstall that stops early restores the preserved central configuration, backups, and uploaded tones through an EXIT guard. Current installations snapshot the transactional signer before the module hook removes its normal copies, allowing repository-offline Dashboard and Framework cleanup to be signed and verified before the temporary copy is deleted.

The installer preserves:

`/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config`

## Install or upgrade

Run as `root` on the FreePBX server:

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/main/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.0.8-beta/slsmassnotifyserver-0.0.8-beta.tgz' \
  ./sls-install.sh
```

Release archive SHA-256:

`ec60a908c8ecdb8210082cff19346e41314214c597b33d335df31e420d3b6aee`

## Validation completed on the production/test PBX

- PHP, Bash, Python, XML, and release-package validation
- FreePBX module install, enable, reload, Dashboard hook render, and local signature verification
- byte-for-byte central-configuration preservation through module refreshes
- Asterisk audio dialplan and call-spool validation
- controlled unload and recoverable removal of `res_pjsip_header_funcs.so`, confirmation that `PJSIP_HEADER` disappeared, exact-version restoration of `asterisk22-core=22.8.2-1.sng12`, byte-identical provider recovery, and successful capability registration through the release installer
- authenticated AMI health and registered PJSIP contact discovery
- live endpoint-fan-out Yealink SIP NOTIFY submission to extension 1000
- live Lightning system test to extension 1000, including one completed audio call and one SIP NOTIFY submission
- manual Weather and Lightning test failure propagation
- controlled Heat Advisory and first-seen `Update` Weather.gov fixtures in dry-run mode
- pinned Amy/Lessac/Ryan model and metadata hashes plus real synthesis through the `asterisk` service account
- authenticated desktop live-SSE handshake, public 480×272 image fetch, Apache config, System Recordings rows, cron wrapper execution, permissions, UI render, and secret/package hygiene checks
- signer regression coverage for a wrong-owned FreePBX keyring, concurrent first-run signing, failed-verification rollback, exact signer replacement, inherited maintenance locks, and the uninstaller's protected signer snapshot

Live phone tests during final release validation are restricted to extension 1000. The controlled Weather.gov fixtures do not contact phones, email recipients, or Discord.

## Known residual risks

- This remains beta software and needs testing with the actual handset models and firmware used at each site.
- Vendor SIP NOTIFY presentation and auto-answer policies vary. Sangoma visual behavior is particularly dependent on provisioning and firmware.
- Weather delivery depends on Weather.gov availability, valid U.S. zones, DNS, TLS, and internet access.
- Lightning Alerts remains a Labs feature and depends on valid Xweather credentials, quota, the selected Weather zone, and the configured adaptive-protection policy.
- Dashboard integration modifies the local Dashboard module. The maintenance worker detects and reapplies the managed files after FreePBX updates, but administrators should verify the widget and trusted local signatures after major Dashboard or Framework changes.
- Local custom-module signatures can appear as `Unknown` in Module Admin even when FreePBX `verifyModule()` returns trusted status 129.
- Automatic provider-package repair is limited to files owned by an already-installed Debian package whose exact installed version is still obtainable. Custom/source-built Asterisk installations and never-installed split provider packages require the administrator to restore the matching provider from the same build or repository; the installer intentionally will not guess across an unknown ABI.
