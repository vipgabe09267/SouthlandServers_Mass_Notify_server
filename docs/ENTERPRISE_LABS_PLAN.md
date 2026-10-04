# Enterprise Labs implementation

Introduced in `0.1.5-beta`. All additions are disabled by default. Existing PBX settings, notification schemas and endpoint paths remain compatible. Live provider/device activation requires explicit configuration.

## Accepted scope

- Notification active/standby failover and authenticated configuration mirroring. Keep node identities, AMI credentials, trunks, capacity and encryption keys local. Require exclusive sending authority and durable intent replication; ambiguous external deliveries cannot replay.
- Standalone, site-PBX and Linux/Raspberry Pi edge deployment options. Offline operation covers reachable local resources; cloud delivery and new weather data require connectivity.
- Site-owned distributed delivery with immutable audiences, expiry, deduplication, bounded queues and receipt reconciliation.
- Operator SAML/OIDC for five representative identity providers, explicit account grants and local recovery. No provider is configured automatically.
- Directory and roster synchronization with review, stable identities and deprovisioning.
- Verified subscriber browser access and attributable human responses.
- SIP speaker profiles for Algo, CyberData, Axis, Valcom and AtlasIED, with explicit model/firmware qualifications.
- Raspberry Pi/ESP32 sensor clients and Arduino gateway guidance, heartbeat supervision and approved event bindings.
- Documented access-control adapters with scoped device permissions and verified state.
- Private incident floor-plan overlays.
- OAuth meeting creation for Zoom, Google Meet and Webex.
- Verified two-way SMS and opt-in voice responses, separate from transport receipts.
- Central drill assignments, owners, completion reporting and overdue tracking.
- CAP authoring/export and bounded public-warning integration, subject to actual authority and certificate requirements.
- Documented endpoint telemetry with honest physical-delivery evidence.
- On-call shift routing and optional dual approval for announcements and incident messages.
- Isolated protocol, functionality, HTTP security, adversarial, load, UI and compatibility checks. Physical hardware, real provider accounts, external-network testing and real multi-host recovery are unavailable and must not be represented as completed.

## Excluded scope

Managed mustering, mobile push, dynamic geofencing, temporary visitors, wearable panic badges and camera threat detection. Full FreePBX telephone-service clustering is separate from SLS notification failover.

## Dangerous-feature acknowledgment

Use the following exact text for features whose activation can cause catastrophic duplication or physical/public action. Other Labs integrations have ordinary experimental notices.

**Title:** DANGER! DO NOT USE ON PRODUCTION READY SERVERS!

**Body:** THIS FEATURE IS DANGEROUSLY EXPEREMENTAL AND HAS NOT BEEN THOROUGHLY TESTED OR EXAMINED AND IS STRONGLY DISCOURAGED TO BE USED IN SERIOUS ENVIROMENTS. ONLY USE THIS FOR NON-PRODUCTION READY HOME LAB / NON-MISSION CRITICAL SETUPS.

**Checkbox:** I UNDERSTAND THE DANGEROUS RISKS AND AM WILLING TO ACCEPT THEM AND PROCEED ANYWAY.

The checkbox remains disabled for five seconds and displays the remaining time. The server independently enforces the delay, administrator authority, session binding and warning revision. Acceptance is recorded once for the current warning revision in the encrypted configuration. It does not enable a feature automatically.

## Completion evidence

Implemented candidate: **0.1.5-beta**, unpublished. The following describes available software checks, not provider/hardware qualification or a production SLA. Every new master and subordinate feature remains disabled until explicitly configured and enabled.

| Area | Implemented behavior and isolated checks | Remaining qualification |
| --- | --- | --- |
| Cluster and mirroring | Mutual TLS plus certificate pins, signed requests, witness authority, durable effect intent, queue/incident/schedule snapshots, takeover refusal after uncertainty, persistent replay fences and worker upgrade coordination. Real loopback TLS and disposable-node fault tests. | Separate PBXs/witness, real network partitions and power failure, carrier fencing and operational recovery. Full FreePBX telephony HA is outside this feature. |
| Remote sites | Standalone PBX, site ownership and independent Linux/Pi browser/audio edge modes; explicit approved local cache, expiry, deduplication and bounded receipts. | Actual site hardware, WAN loss and browser audio policies. Cloud channels and new weather data require connectivity. |
| Identity and directory | Explicit SAML/OIDC account grants for Entra, Okta, Google, Keycloak and OneLogin; maintained signature libraries, nonce/PKCE/issuer/audience checks; CSV, LDAP-TLS and SCIM preview/apply with stable identities. | Tenant credentials, actual IdP/directory policy, certificate rotation and deprovisioning acceptance. |
| Subscribers | Hashed expiring email-verification proofs, person-bound browser responses, CSRF and durable replay protection. | Actual email-domain delivery and subscriber acceptance. |
| Speakers and physical systems | Registered PJSIP speaker profiles for Algo, CyberData, Axis, Valcom and AtlasIED; signed sensor/heartbeat examples and constrained Axis VAPIX door requests. | Exact models/firmware and physical alarm/door behavior. No automatic fire-alarm certification or generic access-control compatibility claim. |
| Meetings and public warning | Fixed Zoom/Meet/Webex OAuth adapters, immutable request identities, CAP export and authority-provisioned SOAP bridge checks. | Provider credentials and real meetings; authorized warning authority, supplied WSDL/certificates and authority acceptance. No IPAWS certification claim. |
| Human responses | Verified Twilio/Telnyx SMS callbacks and opt-in voice DTMF after completed playback; actual disposable Asterisk playback/DTMF tests. | Real provider/carrier behavior. Unsigned BulkVS callbacks cannot authorize human responses. Transport receipts remain separate. |
| Coordination | Private normalized images and authorized overlays, central drills, timezone/DST shift routing, frozen two-person review and current identity checks. Actual stores/controllers and responsive Chromium interactions. | Multi-user operating drills and physical response validation. Dual approval covers resolved announcements and incident messages; weather, lightning, live paging and direct provider actions remain separate. Local approval journals are not mirrored. |
| Security and packaging | Isolated real HTTP authentication/CSRF/role tests, descriptor/permission/storage-loss fixtures, copied deployment paths, native backup fences, dependency inventory and signed local installer gates. | Independent external assessment, remote CI and separate-PBX disaster recovery. Local adversarial tests are not an external penetration test. |

Restore archives evidence and retains passive replay fences, disables the restored Labs masters and clears cluster witness epoch. It never restores active authority, sessions or queues. Established journals require reviewed recovery; deleting state is not a supported reset.

Individual contracts, examples and exact test commands are documented in [Cluster](ENTERPRISE_CLUSTER.md), [Identity](ENTERPRISE_IDENTITY.md), [Integrations](ENTERPRISE_INTEGRATIONS.md), [Coordination](ENTERPRISE_OPERATIONS.md) and [Operator/API](ENTERPRISE_OPERATOR_API.md).
