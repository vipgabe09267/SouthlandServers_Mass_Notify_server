# Operator portal — Labs

Administrators create dedicated logins in **SLS → Operator Access** in the PBX
admin panel. Operators sign in at **https://your-pbx/mass-notify/**. Use the same
hostname and forwarded HTTPS port as the PBX. Distribution defaults contain no
installation-specific port. A portal login grants no FreePBX or UCP session.

1. Select **Add account**, enter a username and an initial password of at least
   15 characters, choose a permission preset, and optionally register a recovery
   email address. **Keep changes** updates the draft; **Save accounts** applies it.
2. Review allowed actions and delivery channels. Grant sites, individual
   buildings/floors/rooms, saved audiences, or explicitly selected personal
   devices. Save accounts; these changes need no Apply Config.
3. At first sign-in, the operator chooses a personal password, adds the displayed
   setup key to a time-based authenticator, verifies its six-digit code, and
   saves the ten recovery codes. Enrollment is mandatory.
4. Later sign-ins require the password and a current authenticator code or one
   unused recovery code. Neither a password alone nor a PBX login opens Operations.

Site and location grants include descendants. Grants are combined; personal
assignments alone allow notifications only to those devices. A username never
implies device ownership. Delivery channel restrictions also apply after a saved
audience expands. Administrators have full SLS authority; use another preset for
recipient restrictions. Individual action checkboxes can combine sending,
scheduling, and roll call without granting administrator access.

| Preset | Default actions |
|---|---|
| Viewer | View assigned incidents and participants. |
| Sender | Send reviewed announcements and authorized incident updates. |
| Scheduler | Create and manage their own schedules. |
| Warden | Record assigned human responses. |
| Administrator | Manage SLS and use all portal actions. |

Operations uses the Dashboard's announcement jobs and delivery engine. Its
composer starts with saved tones and speech and supports text or colored phone
popups. Sending and scheduling use explicit recipients. Already submitted alerts
cannot be edited or cancelled. Incident updates create separate announcements.
Desktop receipts mean software delivery; they are separate from human responses.

Permissions are checked on each request and again before queued delivery. Disabled
or removed logins lose access immediately. Password resets revoke sessions.
Setting an initial password in the account editor requires a new personal password
at the next sign-in. An authenticator reset also requires a new
initial password and invalidates the old seed and recovery codes. Reset a lost
login here; do not delete protected authentication or delivery data.

Passwords use salted Argon2id with 64 MiB, four passes and one thread. Systems
without Argon2id use PBKDF2-HMAC-SHA256 with 600,000 iterations. Authenticator
seeds use account-bound AES-GCM encryption derived from the existing configuration
key. The key is in the protected `.config`; protect the whole file and backups.
Recovery codes contain 128 random bits and are stored as hashes. Successful OTP
and recovery consumption commits under the configuration lock to prevent reuse.
Hashes, encrypted seeds and recovery hashes are omitted from the account editor
and redacted exports. No authentication provider is required.

The independent session uses HTTPS, a Secure/HttpOnly/SameSite=Strict cookie
limited to `/mass-notify/`, session rotation, CSRF and strict origin checks,
no-store responses, CSP and frame blocking. Password/MFA setup expires after five
minutes. Idle expiry follows the PBX timeout (30 minutes when unset); sessions
have an eight-hour maximum. Password and verification attempts have separate
protected limits. Failed passwords are limited per IP to **6 in five minutes,
12 in ten minutes, and 20 in 24 hours**. The twentieth failure locks password
sign-in from that address for 24 hours from that failure. Pending checks reserve
slots so parallel requests cannot exceed these limits. Successful password checks
do not count as failures. Separate backstops limit password and MFA requests to
10 per account, 50 per source IP and 300 total per five minutes. Clients sharing
one public IP share the IP budget. A new cookie does not reset limits;
throttling returns Retry-After.
At most two password checks or changes run at once, bounding password-derivation
memory to 128 MiB. Busy sign-ins return HTTP 429 with a one-second Retry-After.
Kernel-managed locks release automatically when a request finishes or crashes.

The portal defaults to dark mode and uses local PBX branding CSS and logos where
configured, with a built-in dark fallback. It downloads no third-party theme script.
Full PBX administrators retain account-management recovery in the PBX admin panel.
Existing PBX role assignments are preserved for module permissions; create dedicated
portal logins for their independent password/TOTP access. Imported/restored accounts
start disabled and require administrator review before use.

## Password recovery

**Forgot password?** requires the operator username and registered email. Its
response is the same for matching, unknown, disabled and exhausted accounts.
SLS attempts at most **two recovery emails** per account. Two email attempts or
two failed self-service recovery verifications disable further recovery emails;
normal password sign-in remains available subject to its rate limits. Failed or
uncertain Postfix handoffs count toward the allowance and are never automatically
retried. Postfix acceptance does not confirm inbox delivery.

An administrator can use **Generate 24-hour reset link** in Operator Access,
including after self-service recovery is exhausted, and share the link privately.
The login must be enabled and have completed authenticator enrollment. A new
link replaces the previous link; **Revoke reset link** invalidates it immediately.
Links expire exactly 24 hours after issuance and work once. The raw credential
is shown only when generated or included in its email; `.config` stores its hash.
The URL uses the configured PBX HTTPS hostname and port. Its credential is a URL
fragment, removed by the browser before a CSRF-protected POST, so it is absent
from HTTP access logs and referrers. The reset form has a 30-minute session limit.

Saving a new password requires the **current code from the existing TOTP
authenticator**. Backup recovery codes do not satisfy this check. Two invalid or
reused codes revoke that link. Successful reset consumes the TOTP code, revokes
previous sessions and links, preserves authenticator enrollment, and clears the
recovering IP's failed-password lock and account/IP backstops. It does not sign
the operator in automatically or clear global traffic protection. Recovery email
quotas remain exhausted until administrator assistance; confirmation emails after
a completed change are separate from the two requested recovery emails.

For a lost authenticator or an unenrolled login, the PBX administrator must use
the account editor's reviewed initial-password/authenticator reset. A reset link
never bypasses TOTP. Authentication and recovery events are recorded in the
protected audit journal without passwords, authenticator codes or link credentials.
Announcements retain the verified operator identity, recipients and delivery
results in their durable jobs and activity history.
