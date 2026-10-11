# 0.1.6-beta installer review

This tracks the installer and paging findings from the October 6 audit. Changes are validated in disposable mount, network and process namespaces; production configuration and services are not test fixtures.

| Area | Required behavior |
| --- | --- |
| Resource admission | Accept bounded kernel reservations on nominal 4 GiB hosts; enforce cgroup limits and explain the minimum tier accurately. |
| Storage | Separate operating and installation budgets; measure authenticated payloads, logical recovery sizes and required inodes on each actual filesystem. Reject volatile persistent targets. |
| Storage scans | Avoid overlapping scans, report explicit time and entry limits, and completely recalculate relocated scratch reservations. |
| Activation | Stage the activation copy and previous module on the module filesystem; retain recovery evidence after failure. |
| Recovery | Allow independently verified idle collector repair without clearing history; reject unrestorable service states before changes. |
| Trust | Rebuild changed stock-version inventories from signed upstream packages. Authenticate root dependencies before importing code. |
| Preparation | Perform approval and platform rejection checks before optional repairs. Provide a genuinely nonmutating inspection mode. |
| Compatibility | Recognize Asterisk required-module directives, preflight Piper namespace support, and validate the served PHP runtime separately from CLI PHP. |
| Filesystem security | Validate spool paths through held descriptors before ownership or mode changes. |
| Downloads | Enforce voice and updater byte limits independently of response headers; bound deadlines and clean interrupted voice transfers. |
| Concurrency | Preserve installation logs and acquire coordination before starting a mutating transaction. |
| Paging | Bind origin exclusion to a verified channel/contact, use one audience definition across PHP/Python, preserve safe failure details and explain groups without registered receiving phones. |
| Release | Use a new immutable version, update installer pins and signed manifests, and reconcile README, INSTALL and SECURITY documentation. |
| Verification | Add regression cases for the reported RAM, separate mounts, sparse recovery, inodes, stale collectors, service states and real paging admission. |
| Legacy permissions | Authenticate the previous release before descriptor-based mode recovery; preserve lock inodes and admission/configuration bytes, protect them from native recursive chown and verify the collector before acquiring installation guards. |
| Offline uninstall | Use included authenticated helpers even when the operational installer helper is missing; drain notification workers and refuse active phone delivery before teardown. Preserve history by default, distinguish completed removal from failed reload and support exact approved partial-teardown states. Refuse unexpected cron reads/writes without replacing unrelated jobs. |
| Mail | Apply shared durable limits and bounded Postfix queue checks; fence uncertain email handoffs. Keep all SLS cron output in bounded private diagnostics rather than cron mail. |
| Beta notice | Require administrator/session-bound acceptance after five seconds, preserve encrypted settings and prevent dismissal or stacked safety dialogs. |

Unsupported operating systems or relocated runtime layouts require a qualified adapter. The installer must explain those limits before changing the host; it must not guess paths, adopt unknown live hashes, disable authorization, or overwrite administrator settings.

## Validation

The earlier October 8 candidate passed 244 isolated release fixture suites. The expanded October 9 source adds interruption recovery, early served-PHP checks, private-ledger permission preservation and operator expiry. Its final verification and remaining deployment limits are tracked in [the current readiness review](READINESS_REVIEW_0.1.6.md). Earlier package hashes and signatures do not authenticate the changed candidate; it requires a newly verified manifest before installation or publication.

| Check | Evidence |
| --- | --- |
| Reported RAM | Resource tests accept 4,068,581,376 usable bytes on a nominal 4 GiB allocation, retain actual cgroup limits and reject genuine shortages. |
| Storage variants | Tests cover split mounts, relocated scratch allocation units, sparse recovery, low inodes, volatile persistent targets, scan limits and measured candidate payloads. |
| Transactions | Disposable filesystem tests exercise same-filesystem activation from a separate staging mount, failures, rollback and retry. PBX and package-manager phases use inert adapters. |
| Collector recovery | Idle tests reject live channels, processes and queued work; stale collector repair preserves closed history and requires orphan reconciliation. Service tests reject unsupported enablement, masks and custom overrides before writes. |
| Root execution | Signed generation, stock-version transition, source tampering, missing-helper recovery and publisher revocation tests pass. A timestamp-valid malicious publisher bytecode cache is ignored without weakening signature or key-revocation checks. |
| Downloads | Tests reject oversize and continuing flood streams before commit, preserve existing models, reject unsafe temporary files and remove interrupted voice downloads. Updater tests confirm flooded installer and signature transfers never execute the candidate. The shared writer enforces byte limits independently of curl or response headers. Tests also reject hard links, unsafe parents, non-root ownership, nonprivate modes and FIFOs, and confirm fresh retries truncate prior partial output. |
| Served PHP | Private HTTP tests verify the loopback prerequisite route, no-cache headers, required process functions, the Asterisk runtime account and rejection of forwarded or non-GET probe requests. Apache/FPM profile fixtures reject unqualified handlers before activation. |
| Paging | Unit tests cover current channel IDs, device/user aliases, multiple contacts, IPv6 and ambiguous or spoofed origins. Offline groups with and without PINs create no audio reservation; mixed groups retain registered recipients. Registration loss during admission plays its own prompt while collector faults retain the general failure prompt. A real private Asterisk exercises DTMF menu/PIN, allowlists, actual contact removal/restoration, authenticated AMI admission, origin binding and Page dispatch. Its recipients are loopback fixtures, not physical phones. |
| Existing interfaces | Release fixtures cover phone, desktop, API, operator, scheduling, weather, SMS, email, backup and disabled Labs contracts with private transports. |
| Existing PBX | The earlier candidate's read-only resource and signed inspection checks passed. A changed candidate requires fresh signed evidence and explicit installation acceptance; earlier signatures must not be reused to authorize its changed files. |

The supported adapter remains Debian 12 / FreePBX 17 at the documented paths. An isolated transaction is not a full install on every distribution or architecture. CLI and private HTTP tests do not certify carrier delivery, real handset playback, storage power-loss behavior or fleet throughput. The October 8 report says paging resumed after a receiving phone registered. Private Asterisk tests reproduce the unavailable-group behavior and subsequent recovery without changing the group or PIN; the affected remote PBX was not independently accessed. New paging diagnostics preserve a safe `phone_error` code for that check.

The source-validation pass compared active/pending configuration, encryption keys and installed version with the baseline. Apache and collector service states/process IDs also matched. Authorized local installation is a separate acceptance step and must record its own preservation and operational checks. The source fixtures send no production notifications or external calls and do not publish a release.

## October 10 withdrawal and replacement

The first prerelease was withdrawn after an upgrade on another PBX exposed mode0644 on the enterprise worker lock and phone-admission journal. No candidate activation occurred on that PBX. FreePBX recursive chown/startup was identified as the recurring permission-changing path. The replacement adds authenticated descriptor-based legacy recovery, native chown exclusion and collector readiness before worker pause. Configuration, journal contents and existing lock inodes must remain intact.

Both hosted CI runs passed 272 of 274 suites. The failures were a resource fixture dependent on installed Framework metadata and a missing native voicemail package in the CI image. The resource fixture now supplies private metadata without bypassing platform validation; the workflow pins the matching voicemail package. Replacement qualification must include link/ownership/size refusals, real native chown handling, collector failure, rollback/retry and complete isolated release checks. Hosted CI cannot be confirmed until the corrected source is pushed with owner approval.
