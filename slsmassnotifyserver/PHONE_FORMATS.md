Southland Servers Mass Notifications Server - SIP NOTIFY Phone Formats
====================================================================

This beta exposes one desktop JSON endpoint and multiple phone XML endpoints under:

    https://<pbx-host>/api/sipnotify/<endpoint>

Verified format families
------------------------

- Desktop: JSON event records for the SLS Mass Notify desktop/client app.
- Yealink: Yealink XML Browser `YealinkIPPhoneTextScreen` and generated `YealinkIPPhoneImageScreen` payloads.
- Cisco: Cisco IP Phone Services `CiscoIPPhoneText` payloads.
- Snom: Snom XML Minibrowser `SnomIPPhoneText` payloads.
- Poly/Polycom: Polycom push content wrapped in `PolycomIPPhone` with `Data priority="critical"`.
- Fanvil: Cisco-compatible text XML. Fanvil documentation states X-series phones support Cisco, Yealink, and Voismart XML text/menu/directory/execute families.
- Grandstream: GXP XML Application `xmlapp` payload with a mandatory `view` section.
- Mitel/Aastra: `AastraIPPhoneTextScreen` payload.

Experimental/generic format families
------------------------------------

Sangoma, Avaya, VTech, ALE, and Generic endpoints currently return a conservative `MassNotification` XML payload unless a better model-specific XML browser format is added and tested. These should be treated as experimental until verified on actual phones and firmware.

Hardware testing requirement
----------------------------

Vendor XML documentation describes the XML object shape, but actual SIP NOTIFY behavior depends on phone model, firmware, XML browser configuration, authentication settings, HTTPS trust, and whether the phone accepts push notifications while idle or in-call. Public beta support should only claim full production support for endpoints that have been tested against real target devices.
