# Dial-in paging · Labs

Choose an unused internal extension, add a group, select its audio recipients,
optional SIP text recipients, saved text and authorized internal caller extensions.
New groups receive a random four-digit PIN. You can choose four to eight digits,
replace the PIN, or make a group PIN-free for authorized internal callers.

**Save group** saves the entire paging form to pending `.config` settings.
**Apply Config** prepares the spoken prompts and activates the routes. The page
shows whether paging is currently enabled and whether changes are pending.
Closing an editor with Cancel does not save it. Up to ten groups can be configured.
Each saved editor separates recipients and phone text, caller access and PINs,
and external caller approval. With no groups, the page shows an empty state;
configuration opens only after Add group or Edit.

The extension check recognizes FreePBX's standard generated `bad-number` fallback
as an invalid-number handler, allowing unused codes such as 799 or 7999 to save.
Actual extensions, outbound patterns, custom routes and modified fallbacks remain
conflicts. Saving does not activate paging: Save and Apply Config are both required.

## External callers through an IVR

1. Enable paging and **Offer an external paging destination**.
2. Select the allowed IVRs by name in the paging page. Edit each permitted group.
3. Add **Approved external caller numbers**, one per line, including `+` and the
   country code. For example, `+15125550123`. Save a group PIN, even if internal
   callers do not need one. Each group supports up to 100 approved numbers.
4. Save. Selected IVRs receive the paging code. For a single-key entry, select
   **SLS external paging · group PIN
   required** as the desired key destination. Then Apply Config. No existing IVR
   or inbound route is changed automatically.

The allowlist contains **calling phone numbers**, such as a cell phone, rather
than the inbound DID that reaches the PBX. An empty list grants no external access.
An unlisted, withheld, missing or screening-failed caller ID is rejected before
the paging menu or PIN prompt. An approved caller hears only groups listing that
number, chooses a group, then enters its PIN. Knowing a different group's menu
number or PIN does not grant access. Direct dialing of the internal paging code
from a trunk does not replace the explicit IVR destination.

Caller ID can be forged, so an approved number never bypasses the PIN. External
menus share two concurrent slots and a limit of ten failed PIN attempts per minute;
the overall paging authentication limit also applies. The current group, caller
allowlist and recipients are checked again after PIN entry and before delivery.
Changes during an in-progress menu cancel that attempt. An already-started page
is not retroactively recalled.

## Number matching

Saved numbers use international `+` format; spaces, parentheses, periods and
hyphens are removed when saving. Duplicate, wildcard, truncated and malformed
numbers are rejected. Incoming presentation may use `+countrycode`, country
code without `+`, or an international `00` prefix. An explicitly saved `+1` NANP
number also matches its complete ten-digit presentation when its area and
exchange codes are valid. Other national formats need normalization at the
inbound trunk; SLS does not guess their country or match only a suffix.

The phone-admission helper receives the exact approved international number
after the AGI verifies the caller and PIN. It rechecks the saved group before
reserving phones. SIP text has its own current-configuration check. Neither path
uses the cell number as an internal recipient or as an outgoing call target.
PINs and external caller numbers are withheld from redacted configuration exports.

## Validation

Automated tests cover full-number matching, private callers, empty lists,
per-group isolation, mandatory external PINs, changes during authentication,
exact recipients, menu privacy, PIN hashing, stale saves and CSRF. Browser tests
exercise actual PHP saves and edits at desktop and narrow widths. A disposable
Asterisk fixture exercises real caller-ID reads, prepared prompt playback and
DTMF; physical delivery is blocked in that fixture.

Test the selected IVR, carrier caller-ID presentation, DTMF transport, prepared
speech and intended phones during site acceptance before relying on external
paging. The fixture does not establish live carrier or handset compatibility.
The menu uses Asterisk's documented [Read application](https://docs.asterisk.org/Latest_API/API_Documentation/Dialplan_Applications/Read/)
to play only the prepared files for the caller's approved groups.

External entry uses a visible checklist of existing PBX IVRs. Enable the global IVR entry, select the IVR, and enable external callers in the desired group. Enter the callers’ own phone numbers in that group, save its PIN and Apply Config. An inbound PBX number is not the caller whitelist. A group checkbox alone does not activate an IVR route.

## Denied callers and page completion

Unapproved or withheld callers reaching a selected IVR hear “You are not authorized
to perform that function.” The call returns to that IVR’s main menu. Failed PIN
attempts return there after three tries; rate limits still apply. An unselected
or missing IVR never becomes an authorized return destination.

A live page ends when the paging caller hangs up or reaches the maximum duration.
Its phone text is then replaced by a quiet “Paging ended.” popup with a one-second
expiry. Yealink’s XML Timeout uses whole seconds, so 0.5 seconds is unsupported.
A per-extension ownership check protects newer SLS notifications and makes repeated
cleanup harmless. A finite original timeout also limits stale progress popups if
the PBX or paging process fails. Other vendors’ timeout behavior needs device acceptance.

The group editor has one approved-caller list. Saving a nonempty list allows those
calling phones; clearing it makes the group internal-only. The global external
paging switch and selected IVRs still control ingress. Viewing or cancelling an
editor never changes the existing group authorization or PIN.
