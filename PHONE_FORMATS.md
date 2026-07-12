Southland Servers Mass Notifications Server - SIP NOTIFY Phone Formats
====================================================================

This beta exposes one authenticated desktop JSON endpoint under:

    https://<pbx-host>/api/sipnotify/desktop

Phone notifications are pushed directly by Asterisk/PJSIP to registered endpoints. The sender detects the phone format from each registered PJSIP contact User-Agent and builds the matching XML payload before issuing AMI `PJSIPNotify`.

Manual overrides
----------------

If an endpoint is unknown or detected incorrectly, set a manual mapping in:

    Mass Notify > General Settings > Phone Format Overrides

Use one mapping per line:

    1190=cisco
    1000=yealink

Multiple contacts on one extension
----------------------------------

When multiple phones register to one extension, the sender preserves every reachable contact and records the detected formats in diagnostics. Asterisk sends NOTIFY at the endpoint level, so if mixed phone vendors share one extension the module sends each detected payload format for that extension. For best results, avoid mixing phone vendors on the same extension or set a manual override when all contacts on that extension should use one format.

Implemented format families
---------------------------

- Desktop: JSON event records for the SLS Mass Notify desktop/client app.
- Yealink: Yealink XML Browser `YealinkIPPhoneTextScreen` and generated `YealinkIPPhoneImageScreen` payloads. The `yealink_text` override avoids image retrieval on models that cannot load the generated HTTPS PNG.
- Cisco Multiplatform/3PCC: an `XML-Service` SIP NOTIFY containing `CiscoIPPhoneExecute`, which directs the phone to a randomized hosted `CiscoIPPhoneText` document. Cisco documents a 401 digest challenge for this event; the endpoint/firmware must be provisioned so Asterisk can satisfy that authentication requirement.
- Snom: Snom XML Minibrowser `SnomIPPhoneText` payloads.
- Poly/Polycom: Polycom push content wrapped in `PolycomIPPhone` with `Data priority="critical"`.
- Fanvil: Cisco-compatible text XML. Fanvil documentation states X-series phones support Cisco, Yealink, and Voismart XML text/menu/directory/execute families.
- Grandstream: GXP XML Application `xmlapp` payload with a mandatory `view` section.
- Mitel/Aastra: `AastraIPPhoneTextScreen` payload.

Provisioning-dependent and experimental families
------------------------------------------------

Poly/Polycom push, Grandstream XML applications, Snom, Mitel/Aastra, Fanvil, Sangoma, Avaya, VTech, ALE, and Generic behavior depends heavily on model-specific provisioning. Sangoma, Avaya, VTech, ALE, Unknown, and Generic endpoints currently receive a conservative `MassNotification` XML body. Treat every family as experimental until it is verified on the actual phone model and firmware in use.

Hardware testing requirement
----------------------------

Vendor XML documentation describes object shapes, but it does not guarantee that every firmware accepts those objects through an unsolicited SIP NOTIFY. Actual behavior depends on phone model, firmware, XML browser/push configuration, authentication settings, HTTPS trust, and whether the phone accepts a push while idle or in-call. An AMI `PJSIPNotify` success means Asterisk queued the request; it does not prove that the handset displayed it.

For mixed-vendor contacts sharing one extension, Asterisk sends at the endpoint level. The module emits each detected format for that extension, which means every contact may see every format attempt. Separate extensions are more reliable when different vendors require incompatible push behavior.
