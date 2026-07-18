#!/usr/bin/env python3
"""Build and send branded multipart alert email without exposing config secrets."""

import html
import json
import os
import re
import subprocess
import sys
from email.message import EmailMessage
from email.utils import formataddr
from pathlib import Path


DEFAULT_CONFIG = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config")
LOGO_PATHS = (
    Path("/var/www/html/sls_mass_notify/assets/SLS_Mass_Notif_Email.png"),
    Path("/var/www/html/admin/modules/slsmassnotifyserver/assets/SLS_Mass_Notif_Email.png"),
    Path("/var/www/html/sls_mass_notify/assets/SLS_Mass_Notif_Plugin.png"),
)
EMAIL_PATTERN = re.compile(r"[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}")


def alert_profile(subject, body, event="", severity=""):
    text = " ".join((subject, body, event, severity)).lower()
    if any(keyword in text for keyword in ("test only", "system test", "demo", "simulation", "simulated")):
        return "#6d28d9", "#ede9fe", "🧪", "SYSTEM TEST — NOT AN ACTUAL ALERT"
    profiles = (
        (("tornado",), "#991b1b", "#fee2e2", "🌪️", "EXTREME WEATHER"),
        (("severe thunderstorm", "thunderstorm warning", "severe storm"), "#c2410c", "#ffedd5", "⛈️", "SEVERE WEATHER"),
        (("flash flood", "flood warning", "coastal flood"), "#0369a1", "#e0f2fe", "🌊", "FLOOD WARNING"),
        (("winter storm", "blizzard", "ice storm", "snow squall"), "#1d4ed8", "#dbeafe", "❄️", "WINTER WEATHER"),
        (("fire warning", "red flag", "wildfire"), "#b91c1c", "#fee2e2", "🔥", "FIRE WEATHER"),
        (("lightning",), "#b45309", "#fef3c7", "⚡", "LIGHTNING WARNING"),
        (("test",), "#6d28d9", "#ede9fe", "🧪", "SYSTEM TEST — NOT AN ACTUAL ALERT"),
    )
    for keywords, color, pale, icon, label in profiles:
        if any(keyword in text for keyword in keywords):
            return color, pale, icon, label
    severity_text = str(severity).strip().upper()
    return "#6d28d9", "#ede9fe", "📢", severity_text or "MASS NOTIFICATION"


def body_sections(body):
    lines = [line.strip() for line in str(body).splitlines()]
    nonempty = [line for line in lines if line]
    lead = nonempty[0] if nonempty else "An alert was issued by the SLS Mass Notification System."
    details = []
    notes = []
    for line in nonempty[1:]:
        if ":" in line:
            label, value = line.split(":", 1)
            if label.strip() and value.strip() and len(label.strip()) <= 40:
                details.append((label.strip(), value.strip()))
                continue
        notes.append(line)
    return lead, details[:12], notes


def build_html(subject, body, event="", severity=""):
    color, pale, icon, urgency = alert_profile(subject, body, event, severity)
    lead, details, notes = body_sections(body)
    detail_rows = "".join(
        "<tr><td style='padding:9px 12px;border-bottom:1px solid #e5e7eb;color:#64748b;font-size:13px;width:35%'>"
        + html.escape(label)
        + "</td><td style='padding:9px 12px;border-bottom:1px solid #e5e7eb;color:#111827;font-weight:600'>"
        + html.escape(value)
        + "</td></tr>"
        for label, value in details
    )
    notes_html = "".join(
        "<p style='margin:10px 0 0;color:#475569;font-size:14px;line-height:1.55'>" + html.escape(note) + "</p>"
        for note in notes
    )
    details_html = (
        "<table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='margin-top:18px;border:1px solid #e5e7eb;border-radius:8px;border-collapse:separate;overflow:hidden'>"
        + detail_rows
        + "</table>"
        if detail_rows
        else ""
    )
    return f"""<!doctype html>
<html><body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#111827">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f7;padding:28px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:720px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 8px 28px rgba(15,23,42,.14)">
<tr><td style="padding:18px 24px;background:#111827"><table role="presentation" width="100%"><tr>
<td style="width:78px"><img src="cid:sls-mass-notify-logo" alt="Southland Servers Group" width="64" height="64" style="display:block;border-radius:10px;background:#fff"></td>
<td><div style="color:#fbbf24;font-size:12px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase">Southland Servers Group</div><div style="color:#fff;font-size:20px;font-weight:700;margin-top:4px">SLS Mass Notification System</div></td>
</tr></table></td></tr>
<tr><td style="height:8px;background:{color}"></td></tr>
<tr><td style="padding:26px 28px 10px"><span style="display:inline-block;padding:7px 11px;border-radius:999px;background:{pale};color:{color};font-size:12px;font-weight:800;letter-spacing:.7px">{icon} {html.escape(urgency)}</span>
<h1 style="margin:16px 0 10px;color:#111827;font-size:25px;line-height:1.25">{html.escape(subject)}</h1>
<div style="padding:16px 18px;border-left:5px solid {color};background:{pale};border-radius:7px;color:#111827;font-size:17px;font-weight:600;line-height:1.55">{html.escape(lead)}</div>
{details_html}{notes_html}</td></tr>
<tr><td style="padding:22px 28px 26px"><div style="border-top:1px solid #e5e7eb;padding-top:18px;color:#64748b;font-size:12px;line-height:1.5">Sent by the <strong>SLS Mass Notification System</strong><br>Southland Servers Group &bull; PBX-integrated alert delivery</div></td></tr>
</table></td></tr></table></body></html>"""


def send_branded_email(config, subject, body, event="", severity="", recipients_override=""):
    recipients = EMAIL_PATTERN.findall(str(recipients_override or config.get("mail_to") or ""))
    recipients = list(dict.fromkeys(recipients))
    if not recipients:
        return False
    sendmail = Path("/usr/sbin/sendmail")
    if not sendmail.is_file():
        raise RuntimeError("sendmail is unavailable")
    from_name = re.sub(r"[\r\n]+", " ", str(config.get("mail_from_name") or "SLS Mass Notification System"))[:80]
    from_addr = str(config.get("mail_from_addr") or "no-reply@localhost").replace("\r", "").replace("\n", "")
    if not EMAIL_PATTERN.fullmatch(from_addr):
        from_addr = "no-reply@localhost"
    _, _, subject_icon, _ = alert_profile(subject, body, event, severity)
    message = EmailMessage()
    message["Subject"] = f"{subject_icon} {str(subject).replace(chr(13), ' ').replace(chr(10), ' ')[:230]}"
    message["From"] = formataddr((from_name, from_addr))
    message["To"] = "Undisclosed recipients:;"
    message["Bcc"] = ", ".join(recipients)
    message.set_content(str(body), subtype="plain", charset="utf-8")
    message.add_alternative(build_html(subject, body, event, severity), subtype="html", charset="utf-8")
    logo = next((path for path in LOGO_PATHS if path.is_file() and path.stat().st_size > 0), None)
    if logo is not None:
        message.get_payload()[1].add_related(logo.read_bytes(), maintype="image", subtype="png", cid="<sls-mass-notify-logo>", filename="Southland-Servers-Group.png", disposition="inline")
    subprocess.run([str(sendmail), "-t", "-f", from_addr], input=message.as_bytes(), check=True, timeout=30)
    return True


def main():
    config_path = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_CONFIG
    with config_path.open("r", encoding="utf-8") as handle:
        config = json.load(handle)
    sent = send_branded_email(
        config,
        os.environ.get("SLS_EMAIL_SUBJECT", "Southland Servers Mass Notification"),
        os.environ.get("SLS_EMAIL_BODY", "A notification was issued."),
        os.environ.get("SLS_EMAIL_EVENT", ""),
        os.environ.get("SLS_EMAIL_SEVERITY", ""),
        os.environ.get("SLS_EMAIL_RECIPIENTS", ""),
    )
    return 0 if sent else 3


if __name__ == "__main__":
    raise SystemExit(main())
