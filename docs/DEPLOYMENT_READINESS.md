# Deployment readiness and external monitoring

Open **General Settings > Deployment readiness > Check now** to inspect the
current PBX. The report includes runtime checks, current queue faults,
independently read maintenance/announcement-worker heartbeats, operating-system
clock synchronization, the certificate served over local HTTPS, and the existing
CPU/RAM/free-workspace check for the saved device capacities. Audit checks report
current local storage faults and optional system-logger forwarding separately,
retaining failure history after recovery. See [audit collection](AUDIT_FORWARDING.md). Download report
exports the same result as JSON. Running a check changes no configuration, starts
no repairs and sends no notifications.

The generated-media check requests a nonexistent random image URL and verifies
the media policy handler's explicit response. A generic Apache 404 does not pass.
An intentional network denial is healthy enforcement; unreadable configuration
or a bypassed/missing route produces a specific repair/routing warning. See
[generated media access](MEDIA_ACCESS.md). No probe file is created.

The HTTPS probe verifies the configured hostname and certificate while connecting
to the discovered local HTTPS listener. This supports a public forwarded port
that differs from Apache's local port. It cannot prove that the router or remote
desktop reaches that address. Certificates expiring within 30 days are flagged.
A clock service that cannot report synchronization is shown as unverified.

Historical delivery failures remain in their original records. Current queue
warnings use the same 15-minute failure window as System Overview; unreadable
queues, missing/stale maintenance, failed workers and resource shortages remain
faults while present. A maintenance snapshot older than five minutes or worker
probe older than ten minutes fails the readiness check. Reading the report does
not refresh either heartbeat.

## Monitor from a separate host

An unrestricted, named Control API credential with only the **read** permission
can request `GET /api/sls-mass-notify?resource=readiness` over the configured
HTTPS address, using the existing bearer authentication. Keep that credential in
the monitor's secret store. Enabling the Control API or creating credentials
remains an explicit administrator action.

The response contains `ok: true`, `resource: readiness`,
`schema: sls-deployment-readiness-v1`, timezone-aware `generated_at`, module
`version`, `operational_ready`, `attention_count`, `unknown_count`, `checks` and
`verification_scope`. Each check has an `id`, `label`, `state` (`ok`, `warning`
or `unknown`) and a readable `detail`. Runtime check IDs are positional; use the
aggregate readiness fields rather than assuming an index across releases.
Runtime checks exclude passwords, API keys and announcement bodies. Internal
paths, capacity measurements and administrator-entered device-test observations
are included, so treat downloaded reports as confidential operational information.

## Record device and route acceptance

The **Device acceptance** card carries a Labs badge. **Record device test** saves
your observation of a test already performed; it never sends an alert, runs a
script or changes delivery settings. Only an SLS administrator may read or add
records. Select a currently saved phone, desktop, external voice recipient,
email/SMS recipient, webhook, paging group or trigger action. Record the actual
test time, result, behavior checked, model, known firmware/app version and useful
notes. Leave an unknown firmware version blank. Confirm your own observation;
queue acceptance alone does not establish display, playback or human receipt.

All records remain in `device_acceptance` in the protected central `.config`;
they are mirrored into an existing pending configuration without replacing its
other edits. Recording needs no Apply Config. A stale form is rejected rather
than overwriting newer settings or test history. Saved settings are preserved
exactly; recording does not apply normalization defaults or re-encrypt legacy
credentials. Acceptance fingerprints use the stored values so normalization
alone cannot make a newly recorded test appear outdated. The latest 200 observations are
retained; the report shows how many older observations were retired. Export the
readiness report for longer-term evidence before adding more records.

The additive `device_acceptance` report lists observations, human-readable checks
and current result counts. A newer observation supersedes a destination's earlier
result, while both remain visible. Changes to settings or the installed payload
(including same-version unpublished builds) mark observations **Review required**.
Local signing timestamps do not invalidate unchanged software. Firmware, remote
routes, provider configuration and remote deployment changes cannot be inferred;
retest them explicitly. Imported records are historical operator observations,
not cryptographic attestations. Keep credentials and alert contents out of notes.

Manual observations do not alter `operational_ready` or certify the deployment.
An unrestricted read-only readiness API response includes these observations;
do not give its administrative credential to desktop clients. The separate
redacted support export continues to omit recipient identities and raw notes.

Poll once a minute from a different machine and alert on connection/authentication
failure, non-200 status, an invalid or stale report timestamp, or
`operational_ready: false`. A valid report with a warning still returns HTTP 200;
HTTP 503 means the report itself could not be generated. Existing API request
limits and `Retry-After` apply. A monitor on the PBX alone cannot detect the PBX's
total outage. SLS does not configure the external monitor automatically.

The packaged `bin/sls_mass_notify/sls_external_monitor.py` is a standalone Python 3
client for that separate machine. It performs one verified HTTPS GET, follows no
redirects and sends no notifications. A forwarded port may be included in the
configured origin; no site-specific port is hardcoded. Its owner-only token file
must contain a named, unrestricted Control API credential with only read scope.
It checks schema, aggregate/check agreement, timezone-aware freshness, HTTP Date,
uncached responses and bounded download size. Output omits device observations,
destinations and raw diagnostic details. Exit codes: `0` healthy, `1` PBX checks
require attention, `2` connection/authentication/report failure. Throttling includes
the server's numeric Retry-After guidance.

Example on a separate host, after placing the script and secret there:

```sh
python3 /opt/sls-monitor/sls_external_monitor.py \
  --origin https://pbx.example.com \
  --token-file /etc/sls-monitor/read-token
```

Sample systemd service/timer files are in [monitor/](monitor/). The service reads
`SLS_MONITOR_ORIGIN=https://pbx.example.com` from `/etc/sls-monitor/.config` and
uses `LoadCredential` for `/etc/sls-monitor/read-token`. Secure these files for
root access, install the units on the monitoring host and enable its timer only
after a manual check passes. Configure the host's existing monitoring system to
act on failures; the supplied client does not email, call or invoke a webhook.
Its private TLS-server tests exercise actual certificate verification, bearer
authentication, warnings, stale/invalid reports and redirect rejection. No
separate monitoring host was deployed during PBX validation.

Local success does not certify 1,000-client throughput, handset display, full
audio playback, provider acceptance or human receipt. Perform those acceptance
tests with the intended devices, routes and providers before operational use.
