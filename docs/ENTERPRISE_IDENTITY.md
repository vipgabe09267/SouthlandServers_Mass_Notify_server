# Enterprise identity, directory sync and browser subscribers (Labs)

Federation verifies the external identity and starts a pending local session.
The mapped operator must complete current local TOTP, use an unused recovery
code or verify an explicitly enabled enrolled passkey before access is granted. Provider/grant changes revoke pending and
established federated sessions. Remote MFA is not inferred from account names,
roles or unverified claims.

**DO NOT USE ON PRODUCTION SERVERS.** These independent Labs features start disabled. No provider registration, directory credential or recipient invitation is supplied automatically. Tenant-specific acceptance remains necessary: no Entra, Okta, Google Workspace, Keycloak or OneLogin credentials were available during development.

Settings are namespaces of the existing AES-256-GCM central `.config`: `enterprise_identity`, `directory_sync`, and `subscriber_browser`. Provider/client/private-key/bind/SCIM secrets belong there, never in a new credential file. Save actions require an authenticated FreePBX administrator through Operator Access for SSO, or Enterprise Labs for directory and subscriber settings, with the corresponding page’s CSRF token. They take the existing exclusive announcement activity lease before the settings lock and preserve unrelated active and pending settings.

## Read-only cloud directories — Labs

Add a disabled **Entra ID** or **Google Workspace** source in Enterprise Labs.
For Entra, enter the tenant UUID, application UUID and application secret value;
grant only application `User.Read.All` with administrator consent. For Google,
use a service account with domain-wide delegation for
`https://www.googleapis.com/auth/admin.directory.user.readonly`, its RSA private
key, a delegated directory administrator and the Workspace customer ID.

Save before running a dry run. SLS accepts only the documented provider HTTPS
origins and complete paginated snapshots, with a 45-second deadline, ten-page
limit and 1,000 people per source. Review affected people and audiences before
applying. A partial response changes nothing. Imported phone values are review
candidates; they create no operator grants, call recipients or SMS consent.
Changing connector type requires a new source and reviewed deprovisioning of
the previous source.

## Operator federation

Manage SSO under **Operator Access > Operator single sign-on** in the FreePBX administrator panel. It signs users into the separate SLS operator dashboard at `/mass-notify/`; it does not replace or modify FreePBX or UCP authentication. The dedicated operator portal exposes provider sign-in, not identity-provider administration. Enabling SSO requires the Labs danger acknowledgment, available directly in Operator Access, and an enrolled local operator administrator for recovery.

Enterprise login authenticates an identity and looks up an explicit grant `{provider_id,subject,operator_account_id,enabled}`. It grants exactly the saved local portal account's role, channel permissions and audience. Claims named role, groups, administrator or email never create accounts or confer permissions. The local portal account must be enabled, have completed password/TOTP enrollment, and remain current. Enabling federation also requires an enabled, enrolled and unexpired local portal administrator for recovery. Local password, authenticator, recovery codes, FreePBX administrator access and existing recovery flows remain available.

Documented OIDC callback `scope` metadata is accepted as bounded text and is not an authorization source. Roles and recipients still come from the mapped local account. [Google’s OIDC server flow](https://developers.google.com/identity/openid-connect/openid-connect) includes this field in its redirect example.

Register the exact callback shown in Operator Access. It is derived from the protected advertised Control API HTTPS origin, including its saved compatibility port, and always ends `/mass-notify/sso.php`. The callback rejects a different Host, port or path and does not trust forwarded-origin headers. The public operator page adds a button for each enabled provider. Sign-in starts with a same-origin CSRF-protected POST. A separate short-lived `SLSIDP` Secure/HttpOnly/SameSite=None cookie binds the cross-site return to a browser; it cannot authorize operations. Successful federation rotates the existing `SLSOPS` session. Every subsequent portal request rechecks the provider revision, exact subject grant, current local account identity and current permissions. Disabling or changing a grant/provider invalidates its sessions.

Provider URLs must use HTTPS without credentials, a query or a fragment. Percent-encode spaces and special characters in paths; raw quotes, angle brackets, backslashes and malformed percent escapes are rejected. Explicit HTTPS ports are supported.

OIDC supports authorization code with S256 PKCE and requests only the required `openid` scope. Discovery requires list-form capability metadata, exact issuer/code/RS256/auth support and approved endpoint hosts; only issuer, authorization endpoint, token endpoint and JWKS URI are retained in the one-use transaction. JWKS must contain a list of at most 50 keys. RS256 keys use canonical JWK integers, unique bounded key IDs, an odd safe exponent and a 2048–8192 bit modulus. Tokens require the exact issuer and client audience, nonce, `azp` for multiple audiences, bounded `iat`/`exp`/`nbf`, 30 seconds of clock skew, one-use browser-bound state, and a durable replay ledger. There is no implicit flow, HMAC token acceptance, unsigned token acceptance, wildcard issuer, refresh token, or automatic identity matching. HTTPS calls verify certificates/hostname, require TLS 1.2 or later, use canonical fully qualified endpoint allowlists, reject redirects, disable ambient proxies and bound time/body size. Every public DNS answer is checked and one approved result is pinned through connection completion. Loopback, link-local, private and special-use answers fail closed. A private Keycloak or IdP requires an exact separately saved RFC 1918 or unique-local IP pin. A public OIDC registration can select `none`; confidential registrations select `client_secret_basic` or `client_secret_post` according to their discovery metadata.

| Provider | OIDC registration and discovery | SAML registration |
| --- | --- | --- |
| Entra ID | Single tenant issuer `https://login.microsoftonline.com/<tenant UUID>/v2.0`; discovery appends `/.well-known/openid-configuration`. Register a web redirect and matching token authentication. Allowlist `login.microsoftonline.com`. Common/organizations issuers are rejected. | Copy the enterprise app's exact Microsoft Entra Identifier, Login URL and signing certificate. Configure a signed assertion and persistent pairwise NameID. |
| Okta | Use the exact org or custom authorization-server issuer and its discovery URL. Allowlist the actual Okta/custom-domain endpoints returned by reviewed metadata. Register a web application with authorization code and PKCE. | Register a custom SAML app with the saved SP entity ID and ACS URL; copy the exact IdP issuer, SSO URL and signing certificate; use a stable NameID. |
| Google Workspace | Exact issuer `https://accounts.google.com`, discovery `https://accounts.google.com/.well-known/openid-configuration`; reviewed standard endpoint hosts are `accounts.google.com`, `oauth2.googleapis.com`, `www.googleapis.com`. Supply the required Workspace hosted domain; signed `hd` must match it. Bind immutable `sub`, not email. | Use a custom Workspace SAML app, ACS/entity ID and IdP certificate. Match the configured NameID format exactly. Workspace often uses primary email; email renames/reassignment require explicit local binding review and cannot be treated as immutable employee identity. |
| Keycloak | Issuer is the exact HTTPS realm URL, discovery `/realms/<realm>/.well-known/openid-configuration`. Register the exact redirect, authorization code and S256 PKCE. Allowlist the configured realm endpoint hostname(s). | Register a SAML client with the exact entity/ACS values, RSA-SHA256 signed assertions and persistent NameID; pin its realm signing certificate. |
| OneLogin | Use the exact issuer returned by the reviewed `https://<subdomain>.onelogin.com/oidc/2/.well-known/openid-configuration`. Its documented PKCE registration selects “None (PKCE)” token authentication, represented here as `none`; confidential methods require corresponding provider support. | Register a SAML connector with exact ACS/entity/NameID settings and a pinned signing certificate. |

These recipes follow the providers' documentation, not a certification claim: [Entra authorization code](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [Entra SAML protocol](https://learn.microsoft.com/en-us/entra/identity-platform/single-sign-on-saml-protocol), [Okta application registration](https://developer.okta.com/docs/guides/build-sso-integration/openidconnect/main/), [Okta authorization servers](https://developer.okta.com/docs/concepts/auth-servers/), [Google OIDC](https://developers.google.com/identity/openid-connect/openid-connect), [Google custom SAML apps](https://knowledge.workspace.google.com/admin/apps/set-up-your-own-custom-saml-app), [Keycloak discovery](https://www.keycloak.org/securing-apps/oidc-layers), [OneLogin discovery](https://developers.onelogin.com/docs/openid-connect/api/provider-config/), [OneLogin PKCE](https://developers.onelogin.com/docs/openid-connect/guides/auth-flow-pkce/).

SAML uses the maintained OneLogin toolkit and xmlseclibs rather than a local XML signature implementation. Strict schema, assertion signatures, issuer/audience/destination/recipient, requested `InResponseTo`, NameID format, assertion freshness, signed Conditions and SubjectConfirmation expiry, and response/assertion replay identifiers are checked. Security timestamps must use the supported UTC SAML lexical form (`YYYY-MM-DDThh:mm:ss[.fraction]Z`) and must round-trip to the same real calendar instant; natural-language, normalized invalid dates and offsets are rejected. AuthnRequests use the administrator's matching 2048–8192 bit RSA SP certificate/private key and SHA-256 signing. One to three unique, currently valid IdP certificate pins permit an overlapping reviewed rotation; a configured key-usage extension must allow digital signatures. The UI warns when a pin or SP certificate expires within 30 days, and validity is rechecked on every login. SHA-1, parsed XML DTD/entities in any encoding, unsigned assertions, multiple/wrapped assertions, unsolicited/IdP-initiated responses and encrypted assertions are rejected. Configure signed, unencrypted assertions over HTTPS. SAML requested authentication-context restrictions are omitted so provider MFA policies can operate; this module does not infer or enforce an IdP MFA policy from untrusted attributes. Set that policy in the provider. Logout ends the local portal session; provider-wide logout/SLO is not implemented. See the [OneLogin PHP toolkit](https://github.com/SAML-Toolkits/php-saml) for library security behavior.

Every SAML audience restriction must include the saved SP entity ID. A signed bearer confirmation must contain the exact ACS URL, the current request ID and a bounded expiry. The response destination must also equal the ACS URL. Empty audiences, omitted bindings and callback prefix/substring matches are rejected. Each signature must reference its complete parent assertion or response; XPath and other filtering transforms are rejected. Standard enveloped signatures and inclusive/exclusive canonicalization remain supported.

Exact immutable subjects must be obtained from the provider's reviewed account/application administration or a separate tenant test. A failed unbound login does not disclose its subject publicly. OIDC signing algorithms other than RS256, custom token authentication methods, multi-tenant Entra login, OIDC back-channel logout and SAML encrypted assertions require additional work and are not advertised as supported.

## Directory and roster imports

Each source has a permanent `dir_` identifier, explicit incident `template_ids`, an optional `sync_subscribers` flag, and a saved record registry. External IDs determine immutable local person IDs `dirp_<40 hex>` using source ID plus external ID. Never reuse an external ID for a new person. Import affects future incident templates and optional subscriber configuration; it does not create PBX accounts, infer operator grants, mutate a saved announcement job, or rewrite an existing incident's frozen roster.

CSV is a UTF-8 file up to 512 KiB with at most 1000 people. Required columns: `external_id,name`. Optional: `email,location,location_id,desktop_username,active`. Active values are `true`, `false`, `1` or `0`. Duplicate stable IDs, invalid addresses, mismatched columns and oversized results are rejected. A sample complete import is:

```csv
external_id,name,email,location,desktop_username,active
employee-42,Alex Morgan,alex@example.org,North building,alex.desktop,true
employee-87,Jordan Lee,jordan@example.org,Workshop,,false
```

LDAP is pull-only, using LDAPS or LDAP with mandatory StartTLS, a certificate-verifying TLS 1.2 minimum, explicit bind/search settings, disabled referrals, connection/search timeouts and mandatory bounded paging. A failed/partial/size-limited search never becomes an empty snapshot. Map an immutable `entryUUID` or `objectGUID`; binary GUIDs are encoded consistently before deriving local IDs. A configured filter is passed literally, with no interpolated user input. Map name/email/location/desktop fields only to the saved attribute names. Map Active Directory's `userAccountControl` to the active field and set `ldap_inactive_mask: 2` to interpret its disabled bit; mask 0 uses the saved inactive string values. A configured active attribute must be present and valid. Alternatively, use an explicit disabled-account exclusion filter. Verify the server CA is trusted by the PHP/OpenLDAP runtime. LDAP runtime options/paging follow [PHP LDAP options](https://www.php.net/manual/en/function.ldap-set-option.php) and [PHP paged searches](https://www.php.net/manual/en/function.ldap-search.php).

SCIM reads a configured HTTPS SCIM 2.0 service's `/Users?startIndex=<n>&count=200` with an encrypted bearer read token. It requires a valid complete ListResponse, stable total count across pages, boolean `active`, unique stable `id` values and at most 1000 users. Email is the primary address, or the sole address when no primary is marked. It does not expose an inbound SCIM provisioning server and does not claim that identity providers which only push SCIM offer a readable `/Users` service. No Entra/Google/Okta directory API is silently substituted. Protocol assumptions follow [SCIM 2.0 RFC 7644](https://www.rfc-editor.org/rfc/rfc7644.html).

`scim_attributes` maps `external_id`, `name`, `location` and `desktop_username` through supported core/enterprise JSON pointers. Defaults use `/id` and `/displayName`; optional location/desktop mappings start empty. Ambiguous primary emails, malformed activity or mapped values, changing page counts and partial results reject the import.

Run **dry run** to obtain an expiring operational plan and visible add/update/deactivate diff. Apply consumes that plan once and validates the current active configuration revision under the settings lock. Concurrent edits or expired plans require a new preview. The same reviewed records are reconciled into pending templates while retaining pending wording, delivery and manually managed roster edits. Collisions with local IDs or duplicate desktop assignments fail before writes. Empty complete snapshots deprovision prior imported people; they do not remove manual participants. An explicit **Preview all source deprovisioning** option supports disabling/removing any source without contacting its provider. Apply that reviewed empty snapshot before deleting a source or changing its template assignments.

Each source sets `maximum_deactivation_percent` from 1–100, default 25. The preview returns `deactivation_count`, `deactivation_percent` and `requires_deactivation_confirmation`. When confirmation is required, Apply must include boolean `confirm_deactivation: true`. A missing confirmation consumes the one-use plan without changing rosters; run another preview, review its current diff and explicitly confirm. A preview expires after fifteen minutes.

## Browser subscribers

`subscriber_browser.people` are administrator-managed `{id,person_id,name,email,enabled,version,registered_at}` rows. The server assigns identity versions and registration time; the browser cannot backdate enrollment or select the version. Names and exact person IDs must match the incident roster. Enrollment/identity changes must predate the incident's opened timestamp; people enrolled in the same second as opening require a new incident. Directory subscriber sync follows the same rules. Deprovisioned people cannot open new links or use existing browser sessions.

An administrator explicitly selects one subscriber and one open incident, then chooses **Send one verification email**. The reservation journal is persisted before calling the existing bounded local Postfix helper once. Links are random 256-bit one-use proofs bound to the subscriber identity version, person ID, frozen incident roster fingerprint and incident ID. Expiry is 5–60 minutes, default 15 minutes. Proofs travel in a URL fragment, are removed by the page before a same-origin CSRF-protected POST, and are absent from HTTP query/access logs. Operational state holds their SHA-256 hash, not the link secret. Local Postfix acceptance is not confirmed delivery; failed/uncertain invitations revoke their link and do not retry automatically. Invitations are bounded per subscriber and global storage. Reissuing revokes unused prior links for that person/incident. **Revoke links and browser sessions** also rotates the saved identity version.

The public `/mass-notify/subscriber.php` endpoint has a separate Secure/HttpOnly/SameSite=Strict session, verified HTTPS requirement, no public signup, no account enumeration, no cross-person roster listing, CSRF/origin checks, attempt limits, HTML escaping, no-referrer policy and no-store responses. Verified email possession opens only the bound participant's current incident wording and response. Supported human responses are `received`, `safe`, `needs_assistance` (“I need help”). Notes are bounded to 500 characters. Responses update the existing incident human response field with `source=human_browser_response`; delivery receipt records remain separate. Repeated response request IDs are idempotent; a reused ID for different details is rejected. Closed incidents reject new responses.

An administrator can also choose **Send browser enrollment email** before opening an incident (`subscriber_enroll` in the Control API). This one-use email proof opens only that subscriber's browser preferences and push enrollment. It grants no incident roster or participant access. The existing invitation expiry, identity revocation, hourly email limit and no-automatic-retry rules apply. Incident responses still require a separate incident-bound invitation.

## Optional browser Web Push

`subscriber_browser.push` contains `enabled` (default false), `private_key_pem` and `subject`. Generate the P-256 VAPID key once in Enterprise Labs; its private key remains in encrypted `.config`, and public settings expose only its public key and configured status. Preserve it with backups. Set a `mailto:` or HTTPS contact and explicitly enable both subscribers and push before enrollment. No native mobile application is supplied.

After opening a verified email session, the subscriber grants browser notification permission and enrolls through the CSRF-protected subscriber page. Only that bound person can add or remove their endpoint. Enrollment is limited to four browsers/person and 1000 endpoints overall. Identity revocation, removal and changed subscription keys prevent older queued snapshots from authorizing replacement endpoints.

Payloads use RFC 8291 AES-GCM encryption and RFC 8292 VAPID with OpenSSL and the pinned JWT library. Supported HTTPS push-service origins are limited to Google, Mozilla, Apple and Microsoft. Cloud push requires Internet connectivity; it is not an offline LAN service. Browser and operating-system policy can suppress display. Apple mobile-browser enrollment and background behavior require deployment-specific acceptance.

Browser announcements accept titles of at most 80 characters and complete encoded
JSON payloads of at most 3,000 bytes. Queued admission reserves the supported event
ID and expiry width, and counts JSON escaping and UTF-8 bytes. Oversized content is
rejected before any announcement channel is sent; shorten the message or remove
browser recipients. Connectors allow messages of at most 4,000 UTF-8 bytes.

Queued announcements use explicit `browser_recipient_ids`, freeze exact enrolled endpoint/key fingerprints, and recheck current permission, enrollment and expiry before effects. Newly enrolled browsers do not receive older jobs. Announcements retain their absolute expiry, at most ten minutes; duplicates and expired events are discarded by the worker. The `browser_push` receipt reports push-service acceptance, not browser display or a human response. Unknown completion never triggers automatic resend.

SMS login, public enrollment, managed mustering, visitors, geofencing and native mobile application push remain outside this feature. See [Enterprise acceptance](ENTERPRISE_ACCEPTANCE.md) for browser qualification steps.

## Runtime state, dependencies and integration

`PLUGIN_DATA_DIR/enterprise-state` is created only for enabled feature actions and must be owned by the runtime account, mode 0700. Files are regular single-link mode 0600. `.enterprise-state-required` in its parent permanently marks initialized storage. Every ledger has its own `.required` marker; missing established JSON fails closed instead of resetting replay protection. Symlinks, FIFOs, hardlinks, writable/unowned directories, corrupt/noncanonical JSON and duplicate keys are rejected before state use. Ledger updates take a protected lock, replace a bounded file atomically and synchronize file/directory state.

Operational ledgers are `login`, `replay`, `identity_audit`, `directory_plans`, `subscriber_tokens`, `browser_subscriptions`, `browser_push_claims`, `browser_test_events`, `connector_claims` and `oauth_refresh` (`.json`, `.required`, `.lock`). Every callback result is revalidated for root shape, row count, structural depth and the ledger's exact named row schema before an atomic replacement; unknown fields, list/object substitutions and invalid identifier types are rejected. Login proofs expire after five minutes; directory plans after fifteen minutes. Read paths and scheduled maintenance remove expired PKCE transactions, plans, proofs, tests and claims. Replay tombstones remain through their complete security window. Browser/connector claims prevent automatic replay; bounded test-event records keep timestamps stable across duplicate test requests. Successful SSO audit rows contain only provider/protocol/local-account ID/time. Native operational backup omits conclusively expired transient rows without altering live evidence, preserves active replay history and markers, and never activates historical sessions during restore. Restored Labs features remain disabled. None is a replacement configuration authority.

Enabling a saved LDAP source requires LDAP in the PHP runtime serving FreePBX. Browser key generation requires cURL, OpenSSL P-256 ECDH and AES-GCM in that runtime. Missing dependencies produce an actionable error rather than enabling an unusable connector.

Composer dependencies are isolated under `slsmassnotifyserver/identity-libs`, pinned by its `composer.lock`, shipped with their licenses and denied public HTTP access. Protocol and push adapters use the shipped autoloader; rendering settings contacts no provider and starts no delivery. The lock resolves OneLogin php-saml 4.3.2, xmlseclibs 4.0.0, Firebase PHP-JWT 7.2.1 and League OAuth2 client 2.9.1 plus dependencies. xmlseclibs 4.0.0 is installed through a documented Composer inline alias because OneLogin 4.3.2 still declares a 3.x constraint; the SLS signature fixtures qualify the combination. Update and audit this dedicated lock during module dependency maintenance, then rerun the signature fixtures; do not run Composer on the PBX during login.

Integration entrypoints: `EnterpriseIdentityConfig`, `DirectoryConfig`, `SubscriberConfig` expose `defaults()` and `normalize()`. Their `publicConfig()` methods redact secrets. Operator Access renders the SSO editor through `renderOperatorIdentitySettings($endpoint,$csrf)` and dispatches its requests through `operatorIdentityAction($action,$input)`. Enterprise Labs uses `renderEnterpriseLabsPage($endpoint,$csrf)` and `enterpriseLabsAction($action,$input)` for directory and subscriber tools. Both administrator controllers enforce their own page CSRF token. These actions are not public enrollment or unrestricted remote Control API endpoints.

| Action | Input | Effect |
| --- | --- | --- |
| `identity_save` — Operator Access | `{config:<namespace object>,revision:<normalized namespace SHA256>}` | Saves operator SSO under activity/settings locks; stale grants, secrets and revocations are preserved. No provider contact. |
| `directory_save` / `subscriber_save` — Enterprise Labs | `{config:<namespace object>,revision:<normalized namespace SHA256>}` | Revision checked under activity/settings locks; pending edits and secrets are preserved. No provider contact or invitation. |
| `directory_preview` | `{source_id,csv?}` or `{source_id,deprovision_all:true}` | Reads a complete source or explicit empty deprovision snapshot, returns diff and one-use `plan_id`. |
| `directory_apply` | `{plan_id,confirm_deactivation?:boolean}` | Consumes the preview; requires explicit true for a large deactivation. Preserves previous incidents/jobs. |
| `subscriber_invite` | `{subscriber_id,incident_id}` | One explicit email to the saved address; does not return bearer proof to the browser. |
| `subscriber_enroll` | `{subscriber_id}` | One expiring verification email for browser preferences; requires no incident and grants no incident-response authority. |
| `subscriber_revoke` | `{subscriber_id}` | Revokes proofs and rotates saved subscriber session identity. |
| `subscriber_push_key` | `{}` | Generates one VAPID key in encrypted configuration; refuses replacement of an existing key and leaves push disabled. |
| `subscriber_push_test` | `{subscriber_id,request_id,title?,message?}` | Standalone-only marked browser test; 32-hex request identity freezes its content and five-minute expiry. Cluster-enabled deployments must use the queued test so witness authority is enforced. |

On `/mass-notify/subscriber.php`, `push_enroll` accepts a browser `subscription` and `push_remove` its hashed `endpoint_id`. Both require the verified subscriber session, HTTPS, same-origin checks and `portal_csrf`; callers cannot select another person's identity. The Devices and providers queued test also uses `integrations_labs_send` with explicit saved subscriber IDs.

Fixture commands use private mount/network/PID namespaces, masked PBX config/state and inert transports:

```sh
bash tools/run_isolated_tests.sh tools/test_enterprise_identity.php tools/test_enterprise_identity_management.php tools/test_enterprise_identity_http.py
bash tools/run_isolated_tests.sh tools/test_operator_portal_http.py
bash tools/run_isolated_tests.sh tools/test_browser_push.php tools/test_enterprise_announcement_channels.php tools/test_subscriber_worker.js tools/test_directory_mapping.php
```

For dependency maintenance, run `composer audit --working-dir=slsmassnotifyserver/identity-libs --no-dev --format=plain` in a development checkout with Composer available. The PBX login flow does not install or update packages.

The first command covers five synthetic signed OIDC provider flows, real SAML signatures and invalid callback/audience bindings, filtered signatures, time, XML, tamper and replay cases. Other fixtures cover protected-ledger failures, reviewed directory plans, subscriber identity/consent, deployed HTTP entrypoints and operator password/TOTP/recovery. These checks do not qualify LDAP/SCIM interoperability, live IdP tenants, certificate rollover, provider MFA policies or inbox delivery.

After backup restore or native recovery, enterprise features remain passive and disabled until a FreePBX administrator reviews the restored configuration and runtime journals. Replay protection and established-state loss markers remain protected; missing or suspended established ledgers must not be replaced with empty JSON to revive login or proofs. Retain the replay-loss marker, preserve evidence, and use the reviewed recovery procedure before enabling identity or issuing new participant invitations. Restored transient login requests, directory plans and subscriber proofs must remain invalid.

