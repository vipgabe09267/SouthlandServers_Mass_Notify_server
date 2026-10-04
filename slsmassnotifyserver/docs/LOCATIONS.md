# Locations and audiences

Open **Mass Notify → Locations and Audiences** after completing setup. Create a site, then buildings, floors, and rooms beneath it. Names must be distinct within the same parent and type. Up to 500 locations are supported, within a 512 KiB directory allocation and the central configuration's existing total size limit.

Assign configured phone extensions, desktop clients, and saved external voice, email, or SMS recipients. Each recipient can have one directory assignment. Remove its old assignment before moving it. Disabled or deleted recipients remain visible so an operator can resolve the problem. Desktop assignments use the stable client ID; reusing a username does not move an assignment to the new account.

Save the location. Changes are staged in the protected `.config` workflow; Apply Config activates them. Saving a directory never sends an announcement or enables an external provider.

The **Recipients** tab combines external calls and SMS in **Phone and SMS contacts**. Each number has separate Calls and SMS checkboxes; enable either or both. Existing channel IDs, different saved channel names, and SMS consent timestamps remain intact until explicitly edited. Disabling SMS does not disable calls. A new SMS channel requires consent before delivery; renewed consent needs an updated note and does not override a provider block. Provider credentials and routing remain in General Settings.

## Create a reviewed audience

1. Select a saved location and choose whether to include its child locations.
2. Select the recipient types to include. Channel delivery behavior still follows the eventual announcement, schedule, or incident settings.
3. Choose **Review audience**. Inspect every recipient and any unavailable assignments. A group cannot be created while an included recipient is unavailable.
4. Enter a distinct audience group name and choose **Create group**. This uses one of the existing 20 announcement-group slots.
5. Apply Config. Select the group on the Dashboard, in Scheduling, or in an incident template.

A location group is a saved snapshot. Later location edits, moves, and removal do not change its membership. To use a revised audience, create another reviewed group and explicitly choose it for future announcements or schedules. The ordinary Dashboard group editor cannot silently detach a location snapshot. Deleting an unused snapshot uses the existing group-deletion safeguards.

A snapshot preserves desktop username/client-ID pairs. If a desktop is renamed, disabled, removed, or replaced, the group cannot enter a new announcement or incident delivery; its group-scoped API permission also stops granting that audience. Create a new reviewed group after correcting the directory. Already submitted jobs retain their own immutable audiences and authorization checks.

External voice, email and SMS assignments use saved recipient IDs. Their current channel configuration is validated when used, and the address, route, consent and provider identity are frozen by the existing submission workflow. A directory assignment is not recipient consent and cannot enable a disabled channel.

## Geographic audiences

Open a location's **Map position** section to enter decimal latitude and longitude. Confirm that you reviewed the coordinates, then save. The server records the review time; changing a name does not refresh it. Without its own coordinates, a building, floor, or room inherits the closest ancestor's position and review date. Its own stale position never falls back to a fresher ancestor. Clearing its coordinates restores inheritance. A site without coordinates has no position.

Open **Geographic audience** above the directory. The offline map provides a land overview and saved location markers, with pan, zoom, fit, keyboard controls, and rectangle selection. It has no street or building imagery. You can enter exact north/south/west/east boundaries instead; west greater than east crosses the international date line. Points on the boundaries are included. Coordinates and recipient information are never sent to a map service, and the editor does not request browser or device location.

Choose the maximum acceptable coordinate review age (1–3650 days; the editor initially shows 365), then choose recipient types and **Review geographic audience**. The server reports included locations, outside-area locations, and exclusions for missing, old, or future-dated positions. Review every exclusion and the exact recipients, confirm that review, and create a distinctly named group. An unavailable selected recipient blocks creation rather than silently disappearing. A changed directory, recipient identity, channel, selection, or age boundary requires a new review.

Apply Config and choose the new group in an incident template, schedule, or Dashboard announcement. The group preserves its bounds, coordinate age policy, selected location coordinates and their source/review dates, and stable desktop identity bindings. It is a static audience: moving a location or changing its review date does not alter that group, and an old group does not automatically expire when its coordinates become stale. Create a freshly reviewed group when targeting a new geographic incident. Creating or reviewing an audience does not send an alert.

Coordinates live in the protected `location_directory.nodes[].position` configuration. `latitude` and `longitude` are JSON numbers; `reviewed_at` is a UTC Unix timestamp. Geographic group provenance uses `location_snapshot.schema: 2`; existing location-subtree snapshots retain schema 1. Both use the same immutable recipient and current authorization checks. This snapshot version is unrelated to notification payload schema 1 or SSE protocol 2, which remain unchanged.

The bundled [Natural Earth](https://www.naturalearthdata.com/about/terms-of-use/) land data is public domain. The source is `geojson/ne_110m_land.geojson` at [commit ca96624](https://github.com/nvkelso/natural-earth-vector/blob/ca96624a56bd078437bca8184e78163e5039ad19/geojson/ne_110m_land.geojson), SHA-256 `9e0729ee253ca7d7a5c4ae9395fb1902264c5377c52e224d13dd85010e2835d9`. `tools/build_location_basemap.py` accepts only that source hash and emits finite numeric SVG paths without scripts, remote references, or source text. The resulting `assets/location-basemap.svg` hash is `387074c963178803ba4e42ca686e994b2918e135d86a52c4daf7458bfe09fd0d`. No online tiles, map keys, or downloaded JavaScript are required.

## Limits and operational meaning

Configured locations describe administrator-entered assignments, not presence, GPS tracking, proof that a person is in a room, or proof of delivery. Offline phones can still be assigned; the existing delivery evidence reports whether submission succeeded. The directory does not itself provide directory synchronization, scoped human operators, panic-device enrollment, or mobile location collection.

The editor rejects duplicate assignments, invalid parents, unsupported fields, oversized input, stale browser revisions and storage failures. Review the saved state after an interrupted response before repeating a mutation. Deleting a parent requires moving or removing its children first. Existing announcement groups and historical delivery records remain intact when locations are removed.

Validation uses private fixture data: hierarchy and isolation tests, actual announcement/scheduler/incident resolution, group-scoped API checks, HTTP/CSRF and escaping tests, and browser interactions with 1,000 fixture desktops at 1440- and 390-pixel widths. No provider, phone, or desktop notification is needed to configure or validate the directory editor.
