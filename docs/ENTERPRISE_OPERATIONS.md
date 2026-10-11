# Incident coordination — Labs

Configure these tools in **Enterprise Labs → Incident coordination**. The master switch and each section start disabled. Enabling coordination does not alter an existing announcement, automatically launch a drill or enroll an operator.

## Private floor plans

Upload a PNG/JPEG, assign it to a saved site, and place location/device markers. Files are decoded and re-encoded outside every web document root. Limits are 5 MiB per image, 4096 pixels per side, eight megapixels, 100 images and 32 MiB total. SVG, links and executable content are rejected. Content hashes identify immutable images.

An incident overlay combines the saved markers with responses from the roster currently visible to the requesting account. `received`, `safe`, `needs_assistance` and `missing` are human response values, not inferred from a phone answer, playback completion or a desktop receipt. A marker without authorized response evidence remains `unconfirmed`. The feature is an incident overlay, not managed mustering or physical occupancy tracking.

FreePBX administrators can configure and preview plans. Operators see only enabled plans belonging to their assigned sites, and overlays require access to the incident's audience. Private images use an authenticated administrator request or a bounded, authorized operator response; they never receive a public download URL.

## Central drills

Create an assignment using an applied incident template, owner, optional reviewers, sites, deadline and up to 25 review objectives. The owner or an authorized administrator launches a drill using the template's required fields. Every announcement is explicitly marked `DRILL / TEST`.

Each launch uses a caller-generated 32-character hexadecimal `request_id`. Repeating the same request returns its existing run; changing its contents is rejected. A durable claim precedes incident creation. An uncertain launch is retained for review and is never replayed automatically.

Owners/reviewers record objective results and attributable notes. Reports identify unassigned progress, overdue work, launch state and recorded reviews. Review completion does not prove carrier/inbox delivery, a person's safety or successful playback. A pending two-person review is shown as `awaiting_approval`, not a completed drill delivery.

Reports include live and archived runs. Repeating an archived launch request still returns its original run without another incident or announcement. A reviewed run can be archived through the administrator's retention control; unresolved or uncertain work remains active.

## Shift routing

Save a timezone, start weekdays, start/end times, incident templates and an on-duty saved audience. An overnight shift belongs to the weekday on which it starts. Daylight-saving changes use the saved IANA timezone. The routing decision is made when the incident template is resolved, and its resulting audience remains frozen for that incident.

A configured template with no active shift, or two overlapping active shifts, cannot be submitted. Templates without shift assignments retain their own saved recipients. Later roster/audience changes do not add recipients to a previously queued announcement.

## Two-person review

Save policies for selected channels and at least two distinct enabled human accounts. A request matching a policy is stored with its exact wording, media and frozen recipient identities before any channel begins. A designated requester may count as one approval; another designated person must approve. API and automated triggers cannot impersonate human approvers.

Policy selectors are `phones`, `desktops`, `webhooks`, `voice_recipient_ids`, `email_recipient_ids`, `sms_recipient_ids`, `browser_recipient_ids` and `connector_ids`. Browser/connector delivery remains subject to its explicit enablement and sender restrictions; adding a review policy does not grant channel permission.

All matching policies must be satisfied. Reviews expire after the configured 60–900 seconds. The final sender must be assigned to every policy, and every approving account must still have the same login identity and permission for the entire audience. Policy edits, recipient/content changes, identity replacement, revocation and clock rollback block submission.

Submission records a permanent claim before admitting a delivery job. The worker rechecks approval before execution. An expired approval can therefore stop a delayed queue entry; it does not silently authorize a later send. Rejection closes only an unsubmitted review. Already sent messages remain immutable. An incident awaiting review must be submitted or rejected before another update can be sent.

Reviewed language cohorts retain one incident operation and a shared absolute deadline. Each review must match its frozen request, sequence, kind, locale and batch. Expired, consumed or closed incident batches cannot submit. Rejecting a review clears only that matching pending cohort; other reviews and accepted jobs remain intact. All-clear closes the incident only when every required cohort has a durable accepted job.

## Storage and recovery

Configuration lives in the central AES-protected `.config`; operational evidence is separate. The private `enterprise-operations` active journal retains up to 200 reviews and 500 drill runs, bounded to 1.8 MiB. Missing/corrupt established journals fail closed; deleting a journal is not a supported reset procedure.

The administrator's `operations_archive` action accepts `{retention_days}` from 1–365, default 30. It archives old expired reviews in `submitted`, `rejected` or `not_submitted` state, and reviewed drill runs in `queued`, `submitted` or `not_submitted` state. Pending, unreviewed and uncertain records remain active. Archive writes precede live compaction; conflicts preserve active evidence.

Private `archive/approvals.json` and `archive/drills.json` each retain at most 20,000 records and 4 MiB, with corresponding `.required` markers and the parent `.archive-required` marker. Preserve complete archives and markers with operational backups. A missing or full established archive requires reviewed recovery, not deletion of request identities. Reports include archived drill history; exporting it does not reset replay protection.

Native FreePBX backups capture coordination evidence and private image assets. Recovery archives operational journals and disables the new Labs features. Only passive private images and outside replay-loss markers are restored into live use; pending approvals, peer authority and subscriber sessions are not reactivated. Restored Labs ledgers remain suspended until complete reviewed evidence recovery. Peer epochs are cleared on configuration import/restore and require reviewed commissioning.

## API and operator access

Named Control API credentials can discover enabled Labs capabilities and templates permitted by their current audience, then launch a template using `enterprise_template_start` and an immutable `request_id`. They cannot activate Labs, accept dangerous-feature warnings or approve a message as a human. The operator portal exposes assigned reviews, drills and private site plans through its existing authenticated/CSRF-protected actions. See [Control API](CONTROL_API.md).

## Qualification

The repository checks timing, replay, journal loss, policy changes, current identity, recipient isolation, overnight/DST routing, image normalization, backup handling and additive API/operator behavior in isolation. These checks do not replace a site drill, independent external penetration test or production recovery exercise. No alert, provider call or physical action is needed to configure these tools while disabled.

Focused fixtures: `test_enterprise_operations.php`, `test_enterprise_journal_maintenance.php`, `test_enterprise_operator_api.php`, `test_incident_languages.php` and `test_enterprise_browser_ui.py`. Browser checks require the configured local Chromium/driver; a skipped browser fixture does not count as UI acceptance. Use [Enterprise acceptance](ENTERPRISE_ACCEPTANCE.md) for deployment drills.

Two-person review covers resolved announcements and incident messages. Weather/Lightning producers, live paging and direct provider controls retain their own authentication and are not governed by these review policies. Approval ledgers/person identities are not mirrored between peers; a standby cannot automatically resume a job requiring a local human review.
