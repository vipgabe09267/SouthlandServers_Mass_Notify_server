# Enterprise operator portal and scoped Control API

These are unpublished Labs features. **DO NOT USE ON PRODUCTION SERVERS.** All enterprise configuration starts disabled. Use the FreePBX administrator Enterprise Labs page for configuration; the operator portal and Control API cannot activate features or accept a Labs safety receipt.

The existing `/mass-notify/` portal shows only an authenticated person's current assignments. When enterprise operations are enabled it includes assigned announcement approvals, assigned drill launches and objective reviews, an attributable JSON drill report, and site-assigned private PNG/JPEG floor plans. An expired approval disables approve/submit while retaining rejection of the unsubmitted request. Rejecting a pending incident review clears that operation's pending state so a later update can proceed. Already submitted announcements retain their history.

Floor plans are read through the existing portal POST endpoint after both site and incident permission checks. The image is returned as a bounded data URI; images remain outside document roots. Markers describe the assigned incident's human responses, independently of software delivery receipts. An unconfirmed marker never means safe. Drill launch and review responses project recipient state and omit private frozen configuration and authentication fingerprints.

Control API discovery preserves API version 1, notification schema 1 and desktop SSE version 2. Additions require a current named, revocable network credential; a legacy key cannot use them:

| Resource or action | Scope | Behavior |
| --- | --- | --- |
| `GET ?resource=enterprise_capabilities` | `read` | Feature switches and public contracts only; no providers, identity grants, addresses, credentials or configuration. |
| `GET ?resource=incident_templates` | `read` | Reviewed template wording and fields whose current shift route and every escalation fit the key's audience. Returns an empty list when enterprise operations are disabled. |
| `POST {"action":"enterprise_template_start",…}` | `send` | Launches one saved, permitted template with a 32-hex `request_id`; repeated identical requests do not submit another announcement. Requires enabled enterprise operations. |

Template launch input permits `template_id`, `request_id`, `fields`, optional JSON boolean `is_test`, reviewed `language_variant`, and optional future drill `planned_at`. It cannot override delivery, frozen roster, actor, approver or configuration. The response contains a bounded incident summary and submission operation states/job or review IDs, omitting private roster, human observations, delivery addresses, identity fingerprints and timeline. The credential is checked again against current saved authority before the launch.

API credentials cannot approve, submit or reject human reviews, launch assigned human drills, review drill objectives, or manage enterprise configuration. Config exports use the existing named `config` scope plus unrestricted audience and redact all saved enterprise IdP private keys/client secrets, directory bind passwords/SCIM bearer tokens, integration secrets and cluster signing secrets. Subscriber verification proofs and replay state live in protected operational journals and are not configuration exports.

Both portal and PBX Operations controllers require authenticated CSRF-protected object requests, including valid empty `{}` requests for reports. JSON lists and scalar payloads are rejected. Private responses are marked `no-store` and the portal retains its restricted Content Security Policy.

Validation uses `tools/run_isolated_tests.sh` with no PBX files, sockets or outbound network. `test_enterprise_operator_api.php` exercises real incident/approval/drill/image stores with inert submission; `test_enterprise_control_http.py` exercises the actual Control API HTTP entry point and real scoped template authorization. Existing operator access, contract, API security, controller and portal HTTP suites cover the additive contracts and CSRF boundaries.

An optional real Chromium audit is available in `tools/test_enterprise_browser_ui.py`. It uses the actual central enterprise page, identity revision writer, typed coordination editors, Labs warning dialog, portal password/authenticator session and portal frontend under a local TLS fixture. Two browser tests cover the five-second dangerous-feature gate, expired review controls, frozen review wording and action payloads, drill launch/review forms, private image/response overlays and narrow-screen layout. Provider contact and announcement submission remain inert; backend permissions and incident stores are covered separately by the PHP/HTTP suites. It downloads no browser or driver. Missing configured local dependencies produce an explicit skip with zero browser checks.

```sh
SLS_UI_PYTHON_PATH=/path/to/preinstalled/playwright-python \
SLS_UI_CHROMIUM=/path/to/preinstalled/chromium/chrome \
bash tools/run_isolated_tests.sh tools/test_enterprise_browser_ui.py
```

Set `LD_LIBRARY_PATH` only when a private Chromium installation needs its existing local libraries. This optional check is separate from the portable required build fixtures.
