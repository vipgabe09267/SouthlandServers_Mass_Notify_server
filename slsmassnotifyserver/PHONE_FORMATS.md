Southland Servers Mass Notifications Server - SIP NOTIFY Phone Formats
====================================================================

This beta exposes one authenticated desktop JSON endpoint under:

    https://<pbx-host>/api/sipnotify/desktop

Phone notifications are pushed directly by Asterisk/PJSIP to registered endpoints. The sender detects the phone format from each registered PJSIP contact User-Agent and builds the matching XML payload before issuing AMI `PJSIPNotify`.

Manual overrides
----------------

If an endpoint is unknown or detected incorrectly, set a manual mapping in:

    Mass Notify > General Settings > Phone Format Overrides

Use the popup to enter a numeric extension and select a supported family. The Yealink choices are **Yealink - Color** (`yealink`) and **Yealink - Text Only** (`yealink_text`). Unknown/Safe Fallback is not a manual choice; unknown registered endpoints are flagged automatically in diagnostics.

Multiple contacts on one extension
----------------------------------

When every phone on an extension uses the same detected or manually overridden format, the sender submits one endpoint-targeted NOTIFY and lets Asterisk fan it out with that endpoint's own transport, NAT, and contact handling. This is the portable default and does not require `default_outbound_endpoint`.

Mixed phone formats on one extension require a distinct full contact URI for every registration and an Asterisk `default_outbound_endpoint` that can originate arbitrary-URI NOTIFY requests. Only then does the sender route each vendor payload to its matching contact. If either requirement is missing, delivery fails clearly instead of sending incompatible XML to every phone. A manual extension override intentionally makes all contacts on that extension use one format.

Implemented format families
---------------------------

- Desktop: JSON event records for the SLS Mass Notify desktop/client app.
- Yealink: Yealink XML Browser `YealinkIPPhoneTextScreen` and generated `YealinkIPPhoneImageScreen` payloads. NWS color alerts and the Dashboard colored-announcement Labs feature use this image format. The `yealink_text` override avoids image retrieval on models that cannot load the hosted PNG. Yealink's SIP-NOTIFY XML push control is disabled by default on some firmware and must be enabled on the handset or through provisioning; this PBX module does not change phone provisioning.
- Cisco Multiplatform/3PCC: an `XML-Service` SIP NOTIFY containing `CiscoIPPhoneExecute`, which directs the phone to a randomized hosted `CiscoIPPhoneText` document. Cisco documents a 401 digest challenge for this event; the endpoint/firmware must be provisioned so Asterisk can satisfy that authentication requirement.
- Snom: Snom XML Minibrowser `SnomIPPhoneText` payloads.
- Poly/Polycom: Polycom push content wrapped in `PolycomIPPhone` with `Data priority="critical"`.
- Fanvil: Cisco-compatible text XML. Fanvil documentation states X-series phones support Cisco, Yealink, and Voismart XML text/menu/directory/execute families.
- Grandstream: GXP XML Application `xmlapp` payload with a mandatory `view` section.
- Mitel/Aastra: `AastraIPPhoneTextScreen` payload.
- Panasonic KX: Panasonic `ppxml` screen payload with `Event: xml` and `Content-Type: text/xml`. Detection covers Panasonic and KX-HDV/KX-UT/KX-TGP/KX-UDS/KX-UDT User-Agents. Panasonic's documented push behavior remains model-, firmware-, and provisioning-dependent, so validate it on the actual handset.
- Sangoma: conservative `MassNotification` XML visual payload plus the documented `Alert-Info: intercom` value for P- and S-series audio-page auto-answer. Visual display still requires compatible phone/DPMA application behavior.

Provisioning-dependent and experimental families
------------------------------------------------

Poly/Polycom push, Grandstream XML applications, Snom, Mitel/Aastra, Fanvil, Panasonic, Sangoma, Avaya, VTech, and ALE behavior depends heavily on model-specific provisioning. Sangoma, Avaya, VTech, ALE, and automatically detected Unknown endpoints currently receive a conservative `MassNotification` XML body. Treat every family as experimental until it is verified on the actual phone model and firmware in use. A Sangoma AMI success confirms Asterisk submitted the request; it does not install or configure a Sangoma phone application.

Hardware testing requirement
----------------------------

Vendor XML documentation describes object shapes, but it does not guarantee that every firmware accepts those objects through an unsolicited SIP NOTIFY. Actual behavior depends on phone model, firmware, XML browser/push configuration, authentication settings, HTTPS trust, and whether the phone accepts a push while idle or in-call. An AMI `PJSIPNotify` success means Asterisk queued the request; it does not prove that the handset displayed it.

For mixed-vendor contacts sharing one extension, 0.0.8 uses contact-specific URI delivery only after proving that every contact is addressable and Asterisk has a usable default outbound endpoint. Separate extensions remain the recommended and easiest-to-diagnose arrangement.
