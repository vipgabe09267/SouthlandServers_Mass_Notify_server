#!/usr/bin/python3
"""Public descriptions track real discovery and preserve legacy wire values."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "openapi_source", ROOT / "tools/build_openapi.py"
)
generator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(generator)


class PublicApiSchemas(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api, cls.notification = generator.description()

    def test_generated_copies_match_source_discovery(self):
        subprocess.run(
            [sys.executable, str(ROOT / "tools/build_openapi.py"), "--check"],
            check=True,
            timeout=20,
        )
        self.assertEqual(self.api["openapi"], "3.1.1")
        operations = [
            operation["operationId"]
            for path in self.api["paths"].values()
            for operation in path.values()
        ]
        self.assertEqual(len(operations), len(set(operations)))
        self.assertIn("/api/sipnotify/desktop/media", self.api["paths"])
        self.assertIn("/api/sipnotify/desktop/incident/respond", self.api["paths"])
        self.assertIn(
            "text/plain",
            self.api["paths"]["/api/sls-mass-notify/"]["get"]["responses"]["200"][
                "content"
            ],
        )

    def test_local_references_resolve_and_actions_have_scopes(self):
        def walk(value):
            if isinstance(value, dict):
                if "$ref" in value:
                    self.assertTrue(value["$ref"].startswith("#/"))
                    target = self.api
                    for part in value["$ref"][2:].split("/"):
                        target = target[part.replace("~1", "/").replace("~0", "~")]
                for child in value.values():
                    walk(child)
            elif isinstance(value, list):
                for child in value:
                    walk(child)

        walk(self.api)
        actions = {
            key: value
            for key, value in self.api["components"]["schemas"].items()
            if key.startswith("Action_")
        }
        self.assertGreater(len(actions), 10)
        for value in actions.values():
            self.assertIn("x-sls-scope", value)
            self.assertIn("x-sls-idempotency", value)
        self.assertEqual(
            actions["Action_send_announcement"]["properties"]["message"]["maxLength"],
            500,
        )

    def test_notification_schema_validates_legacy_and_current_fixtures(self):
        additional = os.environ.get("SLS_SCHEMA_PYTHON_PATH")
        if additional:
            sys.path.insert(0, additional)
        try:
            import jsonschema
        except ImportError:
            sys.path.append("/usr/lib/python3/dist-packages")
            try:
                import jsonschema
            except ImportError:
                if os.environ.get("SLS_SCHEMA_REQUIRED") == "1":
                    self.fail(
                        "Install python3-jsonschema for the required schema qualification."
                    )
                self.skipTest(
                    "Optional JSON Schema engine absent; structural/discovery checks still ran."
                )
        jsonschema.Draft202012Validator.check_schema(self.notification)
        validator = jsonschema.Draft202012Validator(
            self.notification, format_checker=jsonschema.FormatChecker()
        )
        rehearsal = jsonschema.Draft202012Validator(
            self.api["components"]["schemas"]["Action_rehearse_announcement"]
        )
        request = {
            "action": "rehearse_announcement",
            "message": "Fixture",
            "simulation": {"default": "accepted", "channels": {"sms": "uncertain"}},
        }
        rehearsal.validate(request)
        for scenario in (
            {"default": "delivered"},
            {"channels": {"unknown": "accepted"}},
            {"channels": {"sms": "read"}},
        ):
            with self.assertRaises(jsonschema.ValidationError):
                rehearsal.validate(dict(request, simulation=scenario))
        sample = {
            "id": "fixture-1",
            "kind": "announcement",
            "created_at": "2026-10-23T12:00:00Z",
            "is_test": True,
            "image_url": "",
            "display_expires_at": "",
        }
        validator.validate(sample)
        sender_spec = importlib.util.spec_from_file_location(
            'schema_announcement_producer', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py'
        )
        sender = importlib.util.module_from_spec(sender_spec)
        sender_spec.loader.exec_module(sender)
        for timeout in (0, 30):
            record = sender.announcement_api_record(
                'schema-real-producer-' + str(timeout), 'Actual zero-timeout producer fixture', '', [],
                desktop_targets=['fixture'], timeout_seconds=timeout, is_test=True,
            )
            # The journal assigns creation time once at publication.
            record['created_at'] = sample['created_at']
            validator.validate(record)
            if timeout == 0:
                self.assertIsNone(record['display_expires_at'])
        validator.validate(
            dict(
                sample,
                image_url="https://pbx.example.test:8443/api/sipnotify/desktop/media?event_id=fixture-1",
                image_authentication="desktop_basic",
                display_expires_at="2026-10-23T12:01:00-05:00",
            )
        )
        for changes in [
            {"is_test": "false"},
            {"created_at": "2026-10-23T12:00:00"},
            {"id": ""},
            {"image_url": "not a URI"},
        ]:
            with self.subTest(changes=changes), self.assertRaises(
                jsonschema.ValidationError
            ):
                validator.validate(dict(sample, **changes))


if __name__ == "__main__":
    unittest.main()
