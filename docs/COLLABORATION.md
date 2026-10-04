# Slack and Microsoft Teams

In General Settings → notification destinations, add the provider's secret webhook URL and select **Slack incoming webhook** or **Microsoft Teams Workflows**. Save and apply settings, then explicitly select that destination in an announcement group, incident template, or weather rule. The provider chooses its channel or chat. HTTPS, certificate validation, destination identity checks, bounded timeouts, and delivery uncertainty rules apply to every format.

Teams uses a Workflow configured to receive webhook requests from **Anyone** and post an Adaptive Card. Treat its generated URL as a credential, and assign a workflow co-owner. Tenant/OAuth-authenticated triggers and the retired Office 365 connectors are not supported. Slack uses an incoming webhook associated with the desired channel. Native SLS bearer/signature headers are not sent to either provider.

## Incident links

Explicit incident launches, updates, all-clear messages, and escalations retain their server-generated incident ID through the durable delivery job. Slack displays **Open incident in SLS** as a link; Teams adds an **Open incident in SLS** button. Both open the existing FreePBX incident page. The operator must sign in and have module access. Opening a link never changes incident state or records a human response. These are navigation links, not interactive acknowledgement callbacks.

The same typed context supplies incident severity, action, sequence and drill/test status. A critical incident is labeled Critical; it does not inherit the ordinary announcement's Information label. Wording cannot set these fields, and malformed context is rejected before submission.

Links use only the protected configuration's `public_pbx_host` and matching HTTPS `control_api.base_url`. A configured port is preserved; the standard default remains 443. Incoming HTTP headers, announcement text, arbitrary metadata, and webhook destination URLs cannot choose the link's host or path. If this configured address is absent, uses HTTP, or does not match the advertised hostname, the full message and incident ID are retained without an unsafe link. Review the advertised address in General Settings and use the FreePBX Incidents page directly in that case. A firewall may separately restrict administrative access, so the receiving operator also needs a network route to FreePBX.

Ordinary announcements and weather messages do not become incidents based on their wording. Alert text remains plain text in both formats; mention markup in a title or message does not become an active Slack mention. Payloads exceeding the size bound fail before submission instead of losing instructions. A provider's accepted response confirms webhook submission, not channel delivery, a person's reading, or acknowledgement.

## Validation

Isolated tests exercise both payloads, the real PHP-to-Python incident identity handoff, durable worker context, forwarded/default ports, forged or mismatched URLs, literal alert text, ordinary-message isolation, immutable destinations, provider errors, and bounded payloads. Actual tenant/channel acceptance requires a configured destination and an explicitly authorized test; no provider message is sent by the release fixtures.

Provider references: [Slack incoming webhooks](https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/), [Slack message and link formatting](https://docs.slack.dev/messaging/formatting-message-text/), [Microsoft Teams Workflows setup](https://support.microsoft.com/en-us/workflows/send-messages-in-teams-using-incoming-webhooks), and [Adaptive Cards OpenUrl action](https://learn.microsoft.com/en-us/adaptive-cards/schema-explorer/action-open-url).
