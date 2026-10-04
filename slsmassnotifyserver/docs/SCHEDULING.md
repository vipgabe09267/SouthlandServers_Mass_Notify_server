# Scheduled delivery and recovery

Choose **Weekdays, holidays and overrides** in the schedule editor to define a
calendar pattern. The initial date/time supplies the normal local start time;
select weekdays and an inclusive final date. Add holiday ranges to skip dates,
or date overrides to replace the time for late starts and temporary changes.
Exclusions win over weekdays; an override on a holiday, unselected weekday or
date outside the range is rejected with a specific error.

**Review planned dates** expands the exact local and UTC times before saving.
There can be at most 366 occurrences within five years, 100 holiday ranges and
100 overrides. Excess dates are rejected, never cut off silently. The saved end
date does not extend automatically. Missing or ambiguous daylight-saving times
are rejected. Existing calendars retain their displayed saved timezone when
edited, even if the PBX timezone changes. Changing the PBX clock does not rewrite
their UTC occurrences.

**Read calendar** imports holiday exclusions into the editor, where they remain
editable until Save. Supported files are UTF-8 CSV (`start,end,reason`, with
inclusive YYYY-MM-DD dates) and iCalendar 2.0 all-day events. For example:

```csv
start,end,reason
2027-12-24,2027-12-26,Winter break
2028-01-01,2028-01-01,New year
```

An `.ics` event uses `DTSTART;VALUE=DATE:YYYYMMDD` and an optional exclusive
`DTEND;VALUE=DATE:YYYYMMDD`; without DTEND it excludes one day. Line unfolding and
escaped summary text follow [RFC 5545](https://www.rfc-editor.org/rfc/rfc5545#section-3.6.1).
Import is limited to 256 KiB and 100 events. Timed, recurring, cancelled,
duration-based and nested events require conversion to explicit all-day dates;
SLS rejects them rather than guessing their meaning. URLs and attachments are
not fetched. Imported exact duplicate ranges are kept once by the editor; time
overrides remain a separate explicit choice.

Calendar rules, reviewed date lists and reasons are stored with schedules in
the protected `.config`. Server validation regenerates the list and rejects a
mismatch. Editing future rules cannot rewrite a prepared announcement job.
Adding a new past date is rejected; unchanged saved past occurrences keep their
identities and execution history. To move an already submitted occurrence,
create a separate future schedule only if a second announcement is intended.

One-time and 7/14-day schedules continue to work as before. Scheduled messages
over 500 characters and names/image titles over 80 characters are rejected with
an actionable error instead of silently shortened.

Each schedule has a **Maximum start delay** from 1 through 15 minutes, defaulting to 15. The deadline is the original occurrence time plus this value. Editing a schedule affects future preparation; it does not rewrite an already prepared announcement or extend its deadline.

When an occurrence becomes due, SLS freezes its message, recipients and destination identities into a durable job. It saves the occurrence-to-job link before admitting any delivery. Scheduling shows **queued** or **running** until the job reports results; queue acceptance does not mean the announcement was delivered. Removing or disabling the schedule after preparation does not cancel the submitted job.

Scheduled admission coordinates recipient cooldowns, so a different audience can proceed independently. Interactive sends retain the configured global cooldown. Actual phone contact limits and active-call reservations still apply. The scheduling page warns about nearby overlapping saved audiences. These warnings cannot predict TTS preparation time, registration changes or unscheduled traffic.

The original deadline follows the job through speech preparation, audio waiting, phone admission, desktop journal publication, webhook retries and email/SMS submission. Unstarted destinations are rejected after the deadline. Calls and requests already started may finish; SLS does not interrupt playback. A partially submitted occurrence keeps its individual receipts instead of being reported as wholly missed. Phone visual workers must start before the deadline and retain their normal bounded execution timeout.

An interrupted preparation is reconciled against the exact stored job identity and request fingerprint. A committed prepared job can resume admission without renewing its reservation. A missing job is never rebuilt from changed settings. A worker lost after it starts has an uncertain result and is not automatically replayed. Explicit eligible retries retain the original deadline and cannot resend accepted or uncertain destinations.

The `.config` contains schedules, recipient selections and lateness settings. `schedule-executions.json`, announcement jobs and admission journals are separate operational records. Admission writes use a permanent lock, bounded validated state, atomic replacement and file/directory synchronization. Missing established state, corrupt JSON, unsafe paths or a backwards PBX clock stop new admission with an error; they do not reset the history.

Native configuration restore continues to disable restored schedules. Existing live delivery records are preserved and past occurrences are not replayed. Configuration backup is not a full delivery-history archive; retain operational journals separately when complete history is required.

Validation covers interrupted preparation at each durable boundary, immutable identity, disjoint and overlapping recipients, late channel admission, clock rollback, unsafe files, lost workers and partial outcomes using isolated fixtures. Production power-loss testing and full-fleet load validation require a separate test PBX.
