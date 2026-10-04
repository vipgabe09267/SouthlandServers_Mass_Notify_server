# Control API audit collection

SLS records each audited API decision locally before returning the existing action
result. Records contain a random `audit_` event ID, a UTC creation time, source IP,
HTTP method, action, response status, success flag and, when authenticated, a
credential identifier. They exclude bearer credentials and message contents.
The event ID identifies the audit record, not an announcement or delivery receipt.

Local retention keeps the newest 10,000 eligible records for up to 30 days within
an 8 MiB bound. Compaction preserves a synchronized recovery copy before changing
the original log. A failure increments a durable missing-record count when
storage permits. The next verified successful append clears the current write
fault, preserving its count, last failure time and reason. Recovery cannot
reconstruct missing records. A failed or unreadable recovery marker remains a
fault; maintenance does not simply age it out.

## Optional forwarding

In **General Settings > Audit forwarding**, enable **Send API audit events to the
system logger**, then Submit and Apply Config. The setting is
`control_api.audit_syslog` in `mass-notifications.config`, default `"0"`.
The Control API configuration endpoint accepts a JSON boolean for this field.
This setting also covers audited denied API requests. The existing exclusion for
successful legacy loopback health probes remains unchanged.

SLS hands the identical JSON record to `/dev/log`, using a local Unix datagram,
tag `sls-mass-notify` and facility/severity `local5.info`. A handoff waits at most
50 ms; the complete API audit helper has a two-second deadline. The independent
local append still runs if forwarding fails, and forwarding still runs if local
storage rejects an append. Neither path resubmits the API action.

Configure an existing system logging agent to select that tag and forward it to
the approved separate collector. Use authenticated TLS, a durable bounded queue,
certificate/name validation, retention controls and collector access restrictions.
Source IPs and credential identifiers are operational data. Routing, certificates,
remote queue limits and remote acknowledgements belong to that agent. SLS does
not install/restart logging services or alter firewall rules. Follow your logging
agent's supported configuration, for example the official
[rsyslog forwarding module](https://docs.rsyslog.com/doc/configuration/modules/omfwd.html)
and [TLS setup documentation](https://docs.rsyslog.com/doc/tutorials/tls.html).
Collectors can use the unchanged event ID to detect duplicates.

## Verification and failure handling

After configuring the collector, make an authorized read-only Control API request
from a separate host. Compare its event ID in the protected local audit history
and the collector. Check that the record is complete and contains no credentials.
Verify the agent's queue/recovery behavior during a controlled collector outage.
These checks require the actual logging deployment; SLS's private-socket tests
do not certify a remote collector.

Deployment readiness reports the latest local handoff and historical failures.
Before the first successful handoff, enabled forwarding is unverified. Local
socket acceptance does **not** prove remote or durable receipt. Monitor agent
queue health and the collector independently, including missing-log detection.
Disabling forwarding removes its current-health warning without erasing its
private history. `control-api-audit-forwarding.json` keeps that history.

If storage or forwarding cannot be confirmed, the API supplies
`X-SLS-Audit-Status: unavailable` and a bounded `X-SLS-Audit-Error`. The action's
original status remains unchanged: an accepted announcement must not be resent
to repair its audit trail. Preserve audit and recovery files when investigating
storage failures. A full disk can also prevent the failure counter being saved;
response headers and the secret-free PHP error log provide independent evidence.
