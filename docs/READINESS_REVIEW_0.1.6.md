# 0.1.6-beta readiness review

> **Historical evidence:** This October 2026 record applies only to
> `0.1.6-beta` and is superseded. It does not qualify the `0.1.7-beta` candidate.
> See [Qualification matrix](QUALIFICATION_MATRIX.md) for current requirements.

This is the corrected 0.1.6-beta prerelease. Source validation uses disposable
mount, process and network namespaces;
production credentials, Asterisk sockets and notification routes are excluded.

## Changes checked

| Area | Implementation and verification |
| --- | --- |
| Installer | Early platform and served-PHP checks; authenticated stock bootstrap; measured CPU, RAM, free-space and inode admission; same-filesystem activation; TERM/INT/HUP rollback; persistent recovery after forced termination; interrupted-commit cleanup; preserved private history permissions. |
| Runtime cost | Read-only cached phone discovery; fresh registration checks when sending; shared bounded speech queue, deadlines and media reservations; byte-bounded generated-media cleanup; numeric Chart.js without bundled Moment. |
| Operator access | Optional date/time expiry in the PBX timezone; DST validation; expired login/session, queued delivery and SSO rejection; password recovery retains the account cutoff. |
| Health | Explicit collector heartbeat, identity, permission and PHP failure codes; current worker readiness separated from completed-job history; missing, stale, future and failed probe tests. |
| API | Ordinary announcement idempotency; permission checks before claims; paginated recording discovery; additive browser/connector selectors; existing desktop schema, SSE and acknowledgment contracts retained. |
| Scheduling | Named A/B and weekly programmes, exclusions and date substitutions; timezone validation; saved snapshots; bounded calendar and recording editors. |
| Labs | Browser push encryption, immutable endpoint consent, scoped ntfy/Gotify sends, reviewed language cohorts, OAuth lifecycle, directory deactivation review, archived journals and replay fences, timed-door checks and local CAP Test/Exercise validation. |
| UI | Real Chromium checks at desktop/mobile widths, operator sign-in/MFA, safety acknowledgment, directory review, browser key generation and incident-language editing. |
| Permission recovery | Recognized legacy mode `0644` files and root-owned central settings recovered through root-only no-follow descriptors; missing settings mutex created after configuration validation; ciphertext/key/inode preservation, hostile-link rejection, native FreePBX chown fixtures and actual shell-to-keeper lease checks. Collector readiness is checked before worker guards. |
| Mail | Shared durable quotas, bounded local queue admission, pre-submission fault fencing and Weather mail uncertainty protection. Synthetic large-backlog, timeout, corrupt-state and concurrent-reservation tests use no real MTA. |
| Cron | Fixed authenticated job commands, bounded private diagnostics, process-group cleanup and silent outer cron entries. Exact install/restore/trust checks preserve unrelated jobs. |
| Uninstall | Included offline command; retained publisher helper authentication without the operational installer dependency, worker drain before teardown, active phone refusal, captured AMI cleanup identity, default history preservation, stopped-PBX handling and exact approved teardown retries. Cron failures cannot erase unrelated jobs. |
| Beta notice | Administrator/session-bound five-second challenge, one-use per-account receipt, legacy-actor migration, concurrent administrator preservation and encrypted settings preservation. Actual Chromium checks include desktop/mobile layouts, viewport focus loss, hidden/unfocused pauses, repeated Escape, backdrop, network failure and Beta/Labs ordering. |

The installer is adaptive within its documented Debian 12 / FreePBX 17 amd64
profile. It does not claim to support every distribution, architecture, custom
web handler or relocated PBX layout. Unknown layouts are rejected before
activation rather than approved from untrusted live files.

The final reconciliation also checks decoded-recording and raw-speech output
bounds, cached paging-group readiness, current desktop identity in operator
counts, and direct-test failover gates. The installation doctor reports actual
served PHP capabilities, authenticated collector readiness, release-trust
evidence and durable transaction stages; unavailable evidence is explicit.

## Unimplemented wider scope

- Relocated FreePBX runtime layouts need a separate qualified installer adapter.
- BulkVS authenticated inbound delivery, STOP and human-response callbacks need
  a verified provider contract. Outbound SMS/MMS and the supported signed
  Twilio/Telnyx callbacks are separate capabilities.
- Speaker profiles use PJSIP; a generic multicast transmitter and multicast
  network-readiness tools are not implemented.
- The Linux/Pi edge does not have an independent enduring template, schedule or
  weather engine. Its approved cached jobs expire within 900 seconds.
- Full Asterisk registration, trunk, call and voicemail failover is outside the
  notification HA implementation. Standby approval-journal continuity and the
  coordinated desktop response/panic client work remain incomplete.

## Verification boundaries

- Installer filesystem and interruption fixtures exercise real local files and
  subprocesses. Package-manager/PBX activation adapters remain inert. The
  complete FreePBX VM harness needs a reviewed disposable image and signed
  offline bundle; it has not been executed here.
- Private Asterisk tests exercise DTMF/PIN, contact registration, authenticated
  AMI admission and Page dispatch. They do not certify a physical handset,
  speaker, trunk, codec or carrier.
- Fleet fixtures cover 100, 250, 500 and 1000 configured desktop identities,
  exact receipts, polling, stream cleanup and the cached inventory path. They
  do not certify sustained fleet throughput or a complete FreePBX dashboard.
- Provider transports use private fixtures. Live SMS/MMS, meeting/IdP accounts,
  browser cloud services, hardware access control, multi-host fencing and FEMA
  acceptance require the specific deployment's qualification.
- The other PBX's collector/worker warning has not been diagnosed remotely.
  The candidate provides precise failure codes and read-only diagnostics;
  a successful local test does not establish that host's cause or repair it.
- Local adversarial tests cover authentication, authorization, replay,
  malformed input, unsafe files and callback contracts. This is not an
  independent external penetration test or a guarantee of no vulnerabilities.

See [installer operations](INSTALLER_OPERATIONS.md) and
[Enterprise Labs acceptance](ENTERPRISE_ACCEPTANCE.md) for concrete deployment
checks. Experimental features remain disabled; dangerous features retain their
required acknowledgment. Provider acceptance, device evidence, desktop receipt
and human response remain separate results.

## Desktop fixture measurements

The October 9 private HTTPS run used eight parallel request workers and three
polling rounds per configured identity, followed by exact durable receipts and
stream cleanup. The dashboard column measures only the phone-inventory read
path; it is not a full FreePBX page measurement.

| Configured desktop identities | Poll p95 | Inventory read p95 |
| ---: | ---: | ---: |
| 100 | 13.649 ms | 11.788 ms |
| 250 | 19.345 ms | 14.912 ms |
| 500 | 30.035 ms | 19.393 ms |
| 1000 | 45.546 ms | 18.947 ms |

Observed summed Apache RSS peaked at 354.6 MB. These fixture measurements do
not establish total host memory requirements, a sustained arrival rate or a
safe production capacity increase.

## Release handling

The final source/archive checks must pass before signing. An unsigned test
archive is not installation authority. Generate a fresh release manifest,
verify its signature and artifact hashes, and authorize publication separately.
Local installation acceptance requires the signed matching installer and archive,
protected-state preservation checks, and explicit owner authorization. It does
not constitute publication or qualification on other PBXs.

## Withdrawn first prerelease

The October 10 prerelease was withdrawn after a separate PBX exposed a legacy permission failure before activation. Its replacement qualified authenticated mode `0644` recovery, preservation of file contents and lock inodes, native FreePBX chown/startup exclusion, collector health before worker pause, interrupted preflight recovery and rollback/retry in isolated fixtures. The first local install did not cover that legacy state.

Two hosted CI fixtures also failed: a hardware test used host Framework metadata, and the image lacked the pinned native voicemail module. The fixtures are corrected locally. The replacement passed 281 isolated release suites and a signed local installation, including 830 deployed-file comparisons, 15 doctor checks and eight live beta-warning page/view checks. Hosted CI is verified separately during publication; local passing fixtures do not establish its result or qualify other PBX environments.
