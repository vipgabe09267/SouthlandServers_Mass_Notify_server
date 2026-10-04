Southland Servers Mass Notifications Server - SIP NOTIFY Phone Formats
====================================================================

This beta exposes a primary authenticated live desktop stream under:

    https://<pbx-host>/api/sipnotify/desktop/stream

The stream is a server-sent-event login handshake with per-client targeting. The authenticated JSON endpoint remains available as a compatibility fallback:

    https://<pbx-host>/api/sipnotify/desktop

Phone notifications are pushed directly by Asterisk/PJSIP to registered endpoints. The sender detects the phone format from each registered PJSIP contact User-Agent and builds the matching XML payload before issuing AMI `PJSIPNotify`.

Manual overrides
----------------

If an endpoint is unknown or detected incorrectly, set a manual mapping in:

    Mass Notify > General Settings > Phone Format Overrides

Use the popup to enter a numeric extension and select a supported family. The Yealink choices are **Yealink - Color** (`yealink`) and **Yealink - Text Only** (`yealink_text`). Unknown/Safe Fallback is not a manual choice; unknown registered endpoints are flagged automatically in diagnostics.

Multiple contacts on one extension
----------------------------------

When every phone on an extension uses the same detected or manually overridden format, the sender submits one endpoint-targeted NOTIFY and lets Asterisk fan it out with that endpoint's own transport, NAT, and contact handling. This is the portable default and does not require `default_outbound_endpoint`. UDP, TCP, and TLS are inherited from each registered PJSIP contact; the module does not force a transport or rewrite phone endpoint settings.

When mixed phone formats share an extension, the sender routes each vendor payload to its matching contact only if every registration has a distinct full URI and Asterisk has a usable `default_outbound_endpoint` for arbitrary-URI NOTIFY requests. SIP/SIPS URIs retain their registered UDP, TCP, or TLS transport, port, URI parameters, and IPv6 syntax. If safe contact-specific routing is unavailable, the sender submits one conservative generic XML payload through portable endpoint fan-out instead of failing the installation or sending one vendor's XML to another vendor. A manual extension override intentionally makes all contacts on that extension use one format.

Implemented format families
---------------------------

- Desktop: authenticated live SSE event records for the SLS Mass Notify desktop/client app, with the JSON route retained as a fallback. Expired authorized events advance the stream cursor without being emitted so the next valid targeted event is not skipped.
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

For mixed-vendor contacts sharing one extension, the module uses contact-specific URI delivery only after proving that every contact is addressable and Asterisk has a usable default outbound endpoint. Otherwise it uses the generic endpoint fallback described above. Separate extensions remain the recommended and easiest-to-diagnose arrangement.


## Per-device overrides and capability labels

General Settings supports an extension-wide format override and a more specific registration override. The latter is bound to the extension and current contact URI; changing the registration address requires selecting it again. A per-device override takes priority, allowing different phone families on one extension.

The inventory distinguishes a detected or overridden template from a verified PBX route. Neither proves the handset accepts XML or auto-answers. UDP, TCP, and TLS are preserved as reported by PJSIP; encrypted transport must not be downgraded to force a test to pass. Unknown formats do not halt installation and receive the generic XML fallback.

The Page answer window defaults to five seconds and may be shortened to one through five seconds. Do not lengthen ringing or rewrite handset provisioning to conceal an auto-answer failure. Review Asterisk call outcomes and test every registration, especially mixed desk-phone/softphone extensions.

For Yealink phones, check **Features > Remote Control > SIP Notify** (`push_xml.sip_notify = 1`). To display XML during a paging call, **Block XML in Calling** must be disabled (`push_xml.block_in_calling = 0`). First compare a popup-only test while idle with an audio-and-popup test. An accepted Asterisk submission cannot establish these handset settings. [Yealink XML configuration reference](https://support.yealink.com/docs/sip-t73u/xml-browser/c33ac2d9104c4cd3961f90fb02845ad9).

**Yealink - Always Image (color phones)** is an optional per-device or per-extension override for color phones that accept image screens but do not reliably show text screens. It renders even plain announcements as phone images, preserving the configured phone timeout. Desktop plain text and other devices retain their selected presentation. Confirm the phone can fetch its configured media URL before selecting this override.

Optional [media network restrictions and download expiry](docs/MEDIA_ACCESS.md) also apply to existing phone image/XML URLs. Include every phone network before enabling a restriction and allow enough time for two-stage XML/image downloads. An expired or denied image can prevent the popup from displaying; this does not change SIP NOTIFY submission or audio playback.
