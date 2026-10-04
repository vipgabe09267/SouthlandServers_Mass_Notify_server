# Enterprise Labs: notification continuity and remote sites

All cluster, mirroring, remote-site, automatic takeover and offline-cache switches default to **off**. Missing or disabled `enterprise_cluster` settings preserve the existing transport paths. This implementation is available for isolated home-lab qualification; it has not been qualified on a second physical PBX, remote site, independent witness, phone registrar or carrier trunk. The installed production configuration must remain disabled unless an operator separately commissions and qualifies a deployment.

## Choose the actual deployment

| Deployment | Local delivery | Authority and limits |
| --- | --- | --- |
| One standalone PBX | Existing local recipients and channels | No HA claim and no second machine required. |
| Notification HA | Two prepared PBXs at one owned site | Independent durable witness, exclusive leases and effect admission. This does not replicate a registrar, phone registrations, trunks or network addresses. |
| Per-site FreePBX dispatcher | Explicit recipient jobs at that site's PBX | Each site owns its recipient IDs, routes and credentials. Isolated phone service requires a working local PBX. |
| Linux / Pi LAN edge | Authenticated local displays and approved cached WAV audio | Independent local AES configuration, private queue and HTTPS device feed. No phone origination, trunk access, email, SMS or WAN webhook delivery. |

An enterprise coordinator can run on a prepared delivery PBX; configure its role as `coordinator` and its mode as `notification_ha` if it also sends under HA. The witness must remain an independent failure domain and never submits notification effects or site jobs. All peers share the cluster ID and reviewed epoch, but each node has its own node ID, certificate, private key and AES keyring. A standalone remote-only cluster may use an empty epoch consistently across its peers.

HA, witness authority and shared-policy mirroring require the Enterprise Labs `enterprise_cluster` danger acknowledgment. The server binds a single-use challenge to the administrator and session and checks five full seconds using its monotonic clock. The wording is exactly:

> DANGER! DO NOT USE ON PRODUCTION READY SERVERS!
>
> THIS FEATURE IS DANGEROUSLY EXPEREMENTAL AND HAS NOT BEEN THOROUGHLY TESTED OR EXAMINED AND IS STRONGLY DISCOURAGED TO BE USED IN SERIOUS ENVIROMENTS. ONLY USE THIS FOR NON-PRODUCTION READY HOME LAB / NON-MISSION CRITICAL SETUPS.
>
> I UNDERSTAND THE DANGEROUS RISKS AND AM WILLING TO ACCEPT THEM AND PROCEED ANYWAY.

Acknowledgment does not enable anything. The central settings writer and runtime both enforce the receipt. Remote-only site ownership and offline LAN cache do not confer HA authority.

## Authenticated peer protocol

The existing HTTPS API location hosts `api/sls-mass-notify/peer.php`. No new Apache alias, daemon or listener is started by saving settings, opening the Labs page or initializing a journal. An operator must configure the existing HTTPS server to verify client certificates and export Apache's actual `SSL_CLIENT_VERIFY=SUCCESS` and `SSL_CLIENT_CERT` environment to PHP for this endpoint. Forwarded headers cannot substitute for that evidence. See Apache's [SSLVerifyClient documentation](https://httpd.apache.org/docs/2.4/mod/mod_ssl.html#sslverifyclient) and [SSLOptions certificate export documentation](https://httpd.apache.org/docs/2.4/mod/mod_ssl.html#ssloptions).

Each explicit peer specifies its node ID, site ID, role, HTTPS endpoint, exact certificate SHA256 and a unique pair HMAC secret. Configure the same secret on both ends of that pair; use independent secrets for other pairs. Incoming requests require verified mutual TLS, the claimed node's certificate pin, exact cluster/epoch/target identity and HMAC. Canonical JSON prevents duplicate-key or ambiguous encodings. Messages have a fresh 48-hex nonce, a signed timestamp within 30 seconds and a persistent two-minute replay ledger. Keep clocks synchronized. A backwards witness clock suspends admission.

The outbound client verifies CA, hostname and certificate pin **before sending its application body**. It permits TLS 1.2/1.3, bounded headers and bodies, fixed HTTP framing, no redirects, and bounded connect/read waits. The signed response must match the original nonce, action and peer. The envelope is version 1 with exact fields `version, cluster_id, epoch, source, target, nonce, issued_at, action, payload, signature`. The wire limit is 1 MiB; immutable job and replica records are limited to 256 KiB.

Settings stay in the existing protected AES configuration at `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config`; local encryption keys stay in `/etc/sls-mass-notify/config-keys.json`. Pair secrets, TLS paths and device token hashes belong to this local configuration. Private operational state uses `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-cluster/worker-state.json` and the permanent `cluster-required.json` marker. A second permanent marker at `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/.enterprise-cluster-required.json` binds cluster/node/epoch outside the journal directory, so whole-directory loss cannot reset authority. The state includes lease/fencing tokens, boot identities, replay nonces, immutable effect intents, uncertainty, jobs, schedule occurrence IDs, incident IDs and snapshots, STOP, spending reservations, events, receipts and site queues. Local file locks serialize one node; they do not elect a leader or replicate a file.

## Exclusive notification authority

The configured witness is the only authority. This is a single durable witness design, not a consensus service or a majority-quorum claim. Witness failure suspends automatic sending. A heartbeat, a reachable peer, a copied journal or a native backup never grants sending authority.

The initial owner obtains the first lease. The witness binds every lease to node ID, kernel boot identity, a monotonically increasing fencing token and an expiry of 5–60 seconds. Before each actual transport effect, the worker replicates its full immutable request and asks the witness to durably consume its `(delivery ID, channel, target, site)` identity. The witness checks the current exclusive lease, STOP, expiry and spending ceiling, records the effect as uncertain, then acknowledges admission. Actual gates cover PBX call-file publication, AMI phone display actions, desktop publication, normal announcement email, normal SMS and webhook HTTP submission. Unsupported direct legacy weather, branded email and branded Discord transports fail with a clear HA compatibility error before their actual side effect.

The sender journals uncertainty locally before invoking the effect. A lost reply, interrupted process or unconfirmed external result retains uncertainty. A completed or uncertain effect identity is never automatically resent. A paused sender that obtained admission can prevent takeover: the witness refuses another owner, or a new boot of the same owner, while an outstanding uncertain action remains. This conservative rule protects the gap between software admission and an external device action that cannot understand a fencing token. It favors suspension over duplicate delivery and cannot guarantee exactly-once human receipt from an external provider.

Automatic takeover requires `auto_failover=true`, an expired prior lease, matching PBX prerequisites and no outstanding prior-owner uncertainty. The previous owner becomes retired and cannot automatically fail back. If exclusive authority or durable witness replication cannot be proved, the sending path stops. Pending job workers and reconciliation also stop before mutating a standby's queued jobs.

The PBX prerequisite measurement hashes the actual generated dialplan, PJSIP endpoint/AOR/transport files and FreePBX framework module metadata. It is rechecked when constructing an HA runtime and acquiring authority. Different or changed evidence fences sending. Matching evidence is a prerequisite, not hardware qualification. Operators remain responsible for reachable phones, registrar addresses, network changes, audio assets, carrier registrations, trunk reachability, capacity, permissions and compatible PBX behavior.

## Worker lifecycle and recovery

The existing minutely schedule worker invokes the cluster cycle before automation, incident and scheduled delivery. Its cadence means detection can take up to the next minute plus lease expiry and processing time. No 15-second end-to-end failover claim is made.

The packaged worker `/usr/local/bin/sls_mass_notify/sls_mass_notify_cluster_worker.php` offers:

| Argument | Behavior |
| --- | --- |
| `--help` | Print usage without reading configuration or starting work. |
| `--initialize` | Explicitly initialize the local journal and marker from protected local settings; no FreePBX bootstrap. |
| `--status` | Read bounded local status; no network call or FreePBX bootstrap. |
| `--once` | Apply staged shared policies, recover eligible HA work and process only approved offline site jobs. |
| `--connected-once` | One cycle; verify coordinator availability through authenticated HTTPS before connected delivery. |
| `--watch` | Optional externally supervised PBX loop. Apply staged policies and attempt renewal every lease/3 seconds between cycles; retain the dedicated worker maintenance lease through exit. |
| `--apply-mirrors` | Optional bounded invocation that applies staged shared policies under a short exclusive settings activity lease. |
| `--edge-once`, `--edge-watch` | Independent edge processing without FreePBX bootstrap; verify coordinator connectivity and use approved offline cache when unavailable. |

Every worker obtains a verified shared descriptor for the dedicated `enterprise-worker.lock` before loading mutable module code and retains it through watch sleep and process exit. The protected installer acquires that lock exclusively before ordinary activity and settings locks, so a newly restarted supervisor cannot load module code during replacement. Stop external watch supervisors before module upgrades or protected maintenance. Ordinary deliveries and mirror applications use their existing bounded activity leases; an idle watch permits administrator settings saves and automatic mirror application between cycles. Mirror application takes its short exclusive activity lease once and then the settings lock, without attempting a lock upgrade or reopening its own activity descriptor. No supervisor is installed or started automatically. A long dispatch cycle can delay the watch renewal attempt; each external effect still reacquires current witness authority, and outstanding uncertainty blocks competing takeover. Account for network waits, preparation and external provider delays when qualifying availability. Root-invoked workers drop privileges to the local `asterisk` runtime account. Independent Linux/Pi/witness hosts need PHP with OpenSSL, that protected runtime account, the module's library/runtime files, local AES keyring/configuration and an operator-configured HTTPS server. They do not need a running FreePBX application to serve the peer/edge endpoints or use independent initialization/status/edge modes.

Recovery reconstructs only a native job with a replicated **queued**, untouched, unexpired immutable request. The acquiring owner must prove authority and there must be no witness effect for the delivery ID. Existing local jobs are never replaced. Prepared, running, partially submitted, failed, expired and uncertain work is not blindly replayed. Local two-human approval journals are not mirrored; a standby cannot claim approval continuity and safely refuses an unavailable approval ticket. Full incident and schedule snapshots remain available for review; interrupted incidents and already-started schedule occurrences require operator review rather than automatic recreation of external actions.

The authority journal is bounded to 1.8 MiB, 2,000 effect identities, 500 site queue records and 4,096 recent nonces. Reaching a bound stops admission. Completed effect and queue history is retained; do not delete it to regain capacity. Export and review records, then commission a separately reviewed epoch with old nodes fenced if a new ledger is needed.

Both required markers are written and synced before the first state file. A missing established state file, either missing marker, whole journal-directory loss, wrong node/epoch, unsafe file identity or corrupt STOP/lease/effect schema suspends authority. Initialization cannot reset the same epoch after journal loss. Native backup includes the journal and both required markers as authority evidence but restores these features disabled. Restore retains the parent marker as passive evidence, does not activate witness journals or leases, and clears the configured witness epoch pending explicit commissioning review. A backup or an old snapshot is not permission to reactivate a witness: an older journal may omit consumed effects or nonces. Restore complete current authority history or use a separately reviewed epoch and fence all old nodes. Signed epoch binding rejects old-epoch peers. Never delete both state and marker and reuse the old epoch.

## STOP, spending and manual failback

Only an explicitly configured coordinator can change witness STOP/spending controls, reconcile an uncertain effect or authorize handoff. The coordinator can be one prepared PBX; no fourth machine is required. The Labs page submits these operations through administrator authentication and CSRF checks. Cluster configuration saves use a revision hash under the settings lock so a stale tab cannot revert newer peer, secret or mode settings.

The spending ceiling is cumulative for the epoch. SMS reserves its estimated charge in configured matching currency; outbound voice requires a positive configured worst-case reservation per call. No reservation is refunded after an uncertain action, and no live cost meter or carrier bill reconciliation is claimed. A ceiling cannot be reduced below already reserved spending.

For manual recovery, set STOP, wait for lease expiry, fence the prior node and review the exact effect ID and provider/application receipts. Record the fencing and outcome evidence through manual reconciliation. The identity remains permanently consumed, even when the actual outcome is uncertain. Reconciliation permits other work, not resending that identity. Then authorize the reviewed target node through handoff and clear STOP when appropriate. Automatic failback remains prohibited.

## Shared policy mirroring

Mirroring is an explicit approved-field operation, not a PBX filesystem copy. Allowed fields are announcement groups, scheduled announcement definitions, pronunciation/cooldown/timeout policy, quiet-hours policy and incident workflow templates. The receiver stages a signed revision in its private journal; its local worker validates the merged settings and writes them through the normal protected AES writer using **its own** keyring.

Node identities, peer membership, AMI, trunks, credentials, advertised origins, capacity, device registration and AES keyrings are excluded by default-deny schema. No source encrypted envelope or source encryption key is installed on the receiver. Groups and schedule definitions must reference valid recipients on the matching local site; receiving policy does not create phones, trunks or recipient credentials. Mirroring alone does not authorize notification takeover.

## Distributed site jobs and offline LAN service

A coordinator submits one immutable job per owned site. Each exact job contains `id, delivery_id, site_id, created_at, expires_at, recipients, channels, intent, content_sha256, offline_approved`. The canonical intent SHA256 must match the submitted hash. Jobs use explicit recipient IDs; broadcast/group expansion and remotely supplied addresses or credentials are forbidden. The receiver checks site ownership, channel support, bounded queue capacity and TTL of at most 900 seconds. Repeating the same ID and identical intent returns its existing state; changing its intent is rejected. Expired WAN jobs are never replayed after reconnect.

Per-site FreePBX jobs resolve approved explicit phone, desktop, saved email/SMS/voice and webhook IDs using that site's local settings and credentials. Phones remain local PBX devices. A Linux/Pi edge accepts only its configured `desktop`, `local_display` and `local_audio` channels and device inventory. Its approved WAV cache checks SHA256, safe local file identity and a 2 MiB size bound.

Offline dispatch requires all of: local cache enabled, `offline_approved=true`, exact canonical content hash on the local `approved_cache` list, owned recipients, supported local channels and an unexpired prequeued job. This is a short-lived approved cache, not indefinite WAN-history replay or remote telephony. Independent site configuration and cached content remain available when the central coordinator is unreachable. Each queue action is journaled uncertain before dispatch and is not reset to queued by reboot or reconnect.

The existing HTTPS location hosts `edge.php` independently of FreePBX. Each local device supplies its owned device ID and a bearer token whose SHA256 matches its local configuration. Supported operations are:

| Request | Result |
| --- | --- |
| `GET edge.php?action=poll&device_id=ID` | Up to 50 unexpired owned-device events. |
| `GET edge.php?action=media&device_id=ID&event_id=JOB&asset_id=ASSET` | Approved WAV for that active authorized event only. |
| `POST edge.php?action=receipt&device_id=ID` with `{ "id": "JOB", "kind": "displayed" }` | Immutable device receipt; allowed kinds are `application_received`, `displayed`, `audio_played`. |

All operations use `Authorization: Bearer TOKEN` over HTTPS; tokens are never URL parameters. An application receipt is explicitly not human acknowledgment.

`edge-view.php` is an actual standalone browser consumer for local displays and cached audio. Its GET renders no configuration or secrets and sends no peer/device requests until the user enters device credentials and connects. The token stays in memory. It persists a bounded local identity ledger **before** display/audio, posts the appropriate application receipt, stops cached audio at expiry and never automatically replays an uncertain event after a lost receipt response. Use one active viewer per owned device ID. Sound requires explicit local enablement and browser playback permission. Preserve its local delivery history when reviewing uncertainty; clearing browser storage is not evidence that an event was never displayed or played.

## Isolated validation and qualification limit

Run the cluster unit, TLS, deployment and viewer fixtures through `bash tools/run_isolated_tests.sh tools/test_enterprise_cluster.php tools/test_enterprise_cluster_tls.py tools/test_enterprise_cluster_deployment.py tools/test_enterprise_edge_view.js` from the source checkout. The TLS fixture enables loopback only inside a verified private network namespace; it retains CA, hostname, client-certificate and exact pin assertions. The deployment fixture copies the API files unchanged into the installed web/module layout, checks exact legacy-index dispatch through direct PHP and actual mutual TLS requests with CA, hostname and pin verification, checks encoded/lookalike rejection, and verifies an actual empty edge worker retains its dedicated maintenance lease across watch sleep, permits a short exclusive activity lease while idle, and blocks installer entry and worker restart during protected replacement. The viewer test runs the actual page script against an inert DOM/fetch/audio harness. The protocol tests use temporary AES keyrings, private journals, PBX evidence files and short-lived loopback HTTPS servers with a generated CA and distinct node/client certificates. They verify real PHP HTTPS requests, exact certificate/node and signed epoch binding, replay, tampering, oversize input, exclusive leases, stale fencing tokens, uncertainty, safe takeover, witness loss, persistent reboot state, new-boot isolation, native queued-job recovery, standby isolation, whole-directory witness loss, permanent parent-marker reset prevention, site ownership, idempotency, TTL and prohibited mirroring. They invoke an inert temporary-file callback only; no PBX service, active configuration or external notification is used.

These are authoritative protocol and software fault fixtures, not hardware acceptance tests. Full PBX HA, production SLA, phone registration convergence, carrier fencing, edge browser audio behavior on particular hardware and physical site WAN/power failures remain unqualified. The design's fencing/uncertainty rules follow the need to protect paused lease holders described in the [etcd lock documentation](https://github.com/etcd-io/etcd/blob/main/contrib/lock/README.md) and the distinction between cluster authority and external fencing in [Pacemaker documentation](https://clusterlabs.org/projects/pacemaker/doc/3.0/Pacemaker_Explained/pdf/Pacemaker_Explained.pdf); this code does not embed either product or claim their consensus guarantees.
