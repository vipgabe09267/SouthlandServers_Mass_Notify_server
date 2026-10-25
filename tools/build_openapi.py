#!/usr/bin/python3
"""Generate the additive Control/desktop API description from action discovery."""
import argparse
import json
from pathlib import Path
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]


def description():
    code = 'require $argv[1]; $p=\\SLS\\MassNotify\\ApiSecurity::issue(["name"=>"Schema discovery","scopes"=>\\SLS\\MassNotify\\ApiSecurity::SCOPES,"audience"=>["unrestricted"=>true]])["credential"]; echo json_encode(\\SLS\\MassNotify\\ControlContract::capabilities($p),JSON_THROW_ON_ERROR);'
    capabilities = json.loads(
        subprocess.check_output(
            [
                "php",
                "-r",
                code,
                str(ROOT / "slsmassnotifyserver/api/sls-mass-notify/contract.php"),
            ],
            timeout=10,
        )
    )
    version = ET.parse(ROOT / "slsmassnotifyserver/module.xml").findtext("version")
    error = {
        "description": "Request refused or outcome could not be confirmed. Check the error code before retrying.",
        "headers": {
            "Retry-After": {
                "schema": {"type": "string"},
                "description": "Seconds before retrying a throttled or temporarily unavailable request.",
            }
        },
        "content": {
            "application/json": {"schema": {"$ref": "#/components/schemas/Error"}}
        },
    }
    responses = {
        "200": {
            "description": "Successful response. A transport acceptance is not a human acknowledgment.",
            "content": {
                "application/json": {
                    "schema": {"type": "object", "additionalProperties": True}
                }
            },
        }
    }
    responses.update(
        {
            str(status): error
            for status in [400, 401, 403, 404, 405, 409, 413, 422, 426, 429, 503]
        }
    )
    send_properties = {
        "action": {
            "enum": [
                "send_announcement",
                "preview_announcement",
                "rehearse_announcement",
            ]
        },
        "message": {"type": "string", "minLength": 1, "maxLength": 500},
        "extensions": {
            "type": "array",
            "maxItems": 1000,
            "items": {"type": "string", "pattern": "^[0-9]{1,20}$"},
        },
        "groups": {"type": "array", "maxItems": 200, "items": {"type": "string"}},
        "mass_notify": {"type": "boolean"},
        "tts_audio": {"type": "boolean"},
        "is_test": {"type": "boolean", "default": False},
        "options": {
            "type": "object",
            "additionalProperties": True,
            "description": "Validated delivery options; see CONTROL_API.md. Authorities and recipients are enforced by the current credential.",
        },
    }
    actions = {}
    for action in capabilities["actions"]:
        name = action["action"]
        schema = {
            "type": "object",
            "required": ["action"],
            "properties": {"action": {"const": name}},
            "additionalProperties": True,
            "x-sls-scope": action["scope"],
            "x-sls-maximum-body-bytes": action["maximum_body_bytes"],
            "x-sls-idempotency": action["idempotency"],
            "description": "See CONTROL_API.md for the complete validated request and response contract for "
            + name
            + ". Additional fields are not automatically permitted by the server.",
        }
        if name in (
            "send_announcement",
            "preview_announcement",
            "rehearse_announcement",
        ):
            schema["properties"].update(send_properties)
            schema["properties"]["action"] = {"const": name}
            schema["required"].append("message")
        if name == "rehearse_announcement":
            outcomes = {
                "enum": ["accepted", "refused", "uncertain", "unavailable", "expired"]
            }
            schema["properties"]["simulation"] = {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "default": outcomes,
                    "channels": {
                        "type": "object",
                        "additionalProperties": False,
                        "properties": {
                            channel: outcomes
                            for channel in (
                                "audio",
                                "sip_notify",
                                "desktop",
                                "external_voice",
                                "email",
                                "sms",
                                "webhook",
                                "browser_push",
                                "connector",
                            )
                        },
                    },
                },
                "description": "Synthetic planning outcomes only; no transport request or durable delivery receipt is created.",
            }
        actions[name] = schema
    notification = {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "$id": "https://southlandservers.xyz/schemas/sls/notification-1.json",
        "title": "SLS notification payload schema 1",
        "type": "object",
        "required": ["id", "created_at", "is_test"],
        "properties": {
            "id": {
                "type": "string",
                "minLength": 1,
                "maxLength": 2048,
                "x-sls-maximum-utf8-bytes": 2048,
            },
            "kind": {"enum": ["announcement", "alert"]},
            "created_at": {
                "type": "string",
                "format": "date-time",
                "pattern": "(?:Z|[+-][0-9]{2}:[0-9]{2})$",
            },
            "is_test": {"type": "boolean"},
            "title": {"type": "string"},
            "message": {"type": "string"},
            "image_url": {
                "anyOf": [
                    {"const": ""},
                    {"type": "string", "format": "uri", "pattern": r"^https://[^\s]+$"},
                ]
            },
            "image_authentication": {"const": "desktop_basic"},
            "display_expires_at": {
                "description": "An absolute server deadline, or null/empty when no server display deadline was set. Clients still enforce their maximum announcement lifetime.",
                "anyOf": [{"type": "null"}, {"const": ""}, {"type": "string", "format": "date-time"}]
            },
            "display_timeout_seconds": {"type": "integer", "minimum": 0},
            "incident": {
                "type": "object",
                "required": [
                    "schema",
                    "incident_id",
                    "sequence",
                    "kind",
                    "severity",
                    "is_test",
                ],
                "properties": {
                    "schema": {"const": 1},
                    "incident_id": {"type": "string", "pattern": "^inc_[a-f0-9]{32}$"},
                    "sequence": {"type": "integer", "minimum": 1, "maximum": 250},
                    "kind": {"enum": ["initial", "update", "all_clear", "escalation"]},
                    "severity": {"enum": ["information", "warning", "critical"]},
                    "is_test": {"type": "boolean"},
                },
                "additionalProperties": True,
            },
        },
        "additionalProperties": True,
    }
    api = {
        "openapi": "3.1.1",
        "jsonSchemaDialect": "https://json-schema.org/draft/2020-12/schema",
        "info": {
            "title": "SLS Mass Notifications API",
            "version": version,
            "description": "Additive Control API, notification schema 1 and desktop SSE protocol 2. Labs features require explicit configuration. Use the configured public HTTPS origin, including a forwarded port when applicable.",
            "license": {"name": "AGPL-3.0-or-later", "identifier": "AGPL-3.0-or-later"},
        },
        "servers": [
            {
                "url": "https://{pbx_origin}",
                "variables": {
                    "pbx_origin": {
                        "default": "pbx.example.test",
                        "description": "Configured PBX hostname with optional public HTTPS port, e.g. pbx.example.test:8443.",
                    }
                },
            }
        ],
        "paths": {},
        "components": {
            "securitySchemes": {
                "ControlKey": {
                    "type": "http",
                    "scheme": "bearer",
                    "description": "Use a named, scoped Control API credential. Expiry and enabled state are checked on every request.",
                },
                "DesktopLogin": {
                    "type": "http",
                    "scheme": "basic",
                    "description": "Dedicated desktop enrollment credentials. Original recipient enrollment IDs remain authoritative.",
                },
            },
            "schemas": {
                "Error": {
                    "type": "object",
                    "required": ["ok", "error"],
                    "properties": {
                        "ok": {"const": False},
                        "error": {"type": "string"},
                        "message": {"type": "string"},
                        "retryable": {"type": "boolean"},
                    },
                    "additionalProperties": True,
                },
                "Notification": {
                    k: v for k, v in notification.items() if k not in ("$schema", "$id")
                },
                "Acknowledgment": {
                    "type": "object",
                    "required": ["event_id"],
                    "properties": {"event_id": notification["properties"]["id"]},
                    "additionalProperties": False,
                },
                **{"Action_" + name: value for name, value in actions.items()},
            },
        },
    }
    api["paths"]["/api/sls-mass-notify/"] = {
        "get": {
            "operationId": "readControlResource",
            "security": [{"ControlKey": []}],
            "parameters": [
                {
                    "name": "resource",
                    "in": "query",
                    "required": False,
                    "schema": {"enum": capabilities["resources"], "default": "status"},
                },
                {
                    "name": "format",
                    "in": "query",
                    "schema": {"enum": ["json", "prometheus"], "default": "json"},
                    "description": "Prometheus is available for metrics only.",
                },
            ],
            "responses": responses,
            "x-sls-resources": capabilities["resources"],
        },
        "post": {
            "operationId": "controlAction",
            "security": [{"ControlKey": []}],
            "parameters": [
                {
                    "name": "Idempotency-Key",
                    "in": "header",
                    "schema": {"type": "string", "minLength": 16, "maxLength": 128},
                    "description": "Durable send_announcement retry identity. Incident operations use their request_id field.",
                }
            ],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "oneOf": [
                                {"$ref": "#/components/schemas/Action_" + name}
                                for name in actions
                            ],
                            "discriminator": {
                                "propertyName": "action",
                                "mapping": {
                                    name: "#/components/schemas/Action_" + name
                                    for name in actions
                                },
                            },
                        }
                    }
                },
            },
            "responses": responses,
        },
    }
    poll = {
        "operationId": "pollDesktop",
        "security": [{"DesktopLogin": []}],
        "parameters": [
            {
                "name": "last_event_id",
                "in": "query",
                "schema": {"type": "string", "maxLength": 2048},
            },
            {
                "name": "limit",
                "in": "query",
                "schema": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 100,
                    "default": 25,
                },
            },
            {
                "name": "If-None-Match",
                "in": "header",
                "schema": {"type": "string"},
                "description": "Optional returned feed validator. An unchanged authenticated response is still HTTP 200 with an empty events page.",
            },
        ],
        "responses": responses,
    }
    api["paths"]["/api/sipnotify/desktop"] = {"get": poll}
    api["paths"]["/api/sipnotify/desktop/ack"] = {
        "post": {
            "operationId": "recordDesktopReceipt",
            "security": [{"DesktopLogin": []}],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {"$ref": "#/components/schemas/Acknowledgment"}
                    }
                },
            },
            "responses": {
                "200": {
                    "description": "Received by desktop app; no human-read claim.",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "required": ["ok", "event_id"],
                                "properties": {
                                    "ok": {"const": True},
                                    "event_id": notification["properties"]["id"],
                                },
                                "additionalProperties": True,
                            }
                        }
                    },
                },
                **{key: value for key, value in responses.items() if key != "200"},
            },
        }
    }
    api["paths"]["/api/sipnotify/desktop/media"] = {
        "get": {
            "operationId": "downloadDesktopImage",
            "security": [{"DesktopLogin": []}],
            "parameters": [
                {
                    "name": "event_id",
                    "in": "query",
                    "required": True,
                    "schema": notification["properties"]["id"],
                }
            ],
            "responses": {
                "200": {
                    "description": "Same-origin PNG for this original recipient, before the alert expiry and maximum ten-minute announcement lifetime.",
                    "content": {
                        "image/png": {
                            "schema": {
                                "type": "string",
                                "format": "binary",
                                "maxLength": 5242880,
                            }
                        }
                    },
                },
                **{key: value for key, value in responses.items() if key != "200"},
            },
        }
    }
    api["paths"]["/api/sipnotify/desktop/stream"] = {
        "get": {
            "operationId": "streamDesktop",
            "security": [{"DesktopLogin": []}],
            "parameters": [
                {
                    "name": "Last-Event-ID",
                    "in": "header",
                    "schema": notification["properties"]["id"],
                }
            ],
            "responses": {
                "200": {
                    "description": "SSE protocol 2. Heartbeats approximately every 15 seconds. Shared PHP-FPM pools direct clients to cursor-aware polling.",
                    "content": {"text/event-stream": {"schema": {"type": "string"}}},
                },
                "429": responses["429"],
                "401": responses["401"],
                "503": responses["503"],
            },
            "x-sls-sse-protocol": 2,
        }
    }
    control_get = api["paths"]["/api/sls-mass-notify/"]["get"]
    control_get["responses"] = json.loads(json.dumps(responses))
    control_get["responses"]["200"]["content"]["text/plain"] = {
        "schema": {"type": "string"},
        "description": "Prometheus exposition for resource=metrics&format=prometheus only; requires monitor scope.",
    }
    incident_id = {"type": "string", "pattern": "^inc_[a-f0-9]{32}$"}
    api["paths"]["/api/sipnotify/desktop/incident"] = {
        "get": {
            "operationId": "readAssignedDesktopIncident",
            "security": [{"DesktopLogin": []}],
            "parameters": [
                {
                    "name": "incident_id",
                    "in": "query",
                    "required": True,
                    "schema": incident_id,
                }
            ],
            "responses": responses,
            "description": "Reads only incident data assigned to the current original desktop enrollment. Opening a record does not acknowledge or respond to it.",
        }
    }
    api["paths"]["/api/sipnotify/desktop/incident/respond"] = {
        "post": {
            "operationId": "respondToAssignedDesktopIncident",
            "security": [{"DesktopLogin": []}],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["incident_id", "request_id", "response"],
                            "properties": {
                                "incident_id": incident_id,
                                "request_id": {
                                    "type": "string",
                                    "pattern": "^[a-f0-9]{32}$",
                                },
                                "response": {
                                    "enum": ["received", "safe", "needs_assistance"]
                                },
                                "note": {"type": "string", "maxLength": 500},
                            },
                            "additionalProperties": False,
                        }
                    }
                },
            },
            "responses": responses,
            "description": "Explicit human response, separate from software receipt. The server rechecks current incident and audience authority.",
        }
    }
    return api, notification


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    api, notification = description()
    for name, value in [
        ("openapi.json", api),
        ("schemas/notification.schema.json", notification),
    ]:
        body = json.dumps(value, indent=2, sort_keys=True) + "\n"
        for base in [ROOT / "docs", ROOT / "slsmassnotifyserver/docs"]:
            path = base / name
            if args.check:
                if not path.is_file() or path.read_text() != body:
                    raise SystemExit(
                        "API description is stale: " + str(path.relative_to(ROOT))
                    )
            else:
                path.parent.mkdir(exist_ok=True, parents=True)
                path.write_text(body)
    print(
        "OpenAPI 3.1 description and notification JSON Schema match current action discovery."
    )


if __name__ == "__main__":
    main()
