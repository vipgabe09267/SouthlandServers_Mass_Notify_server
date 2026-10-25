#!/usr/bin/python3
"""Shared strict Weather.gov payload validation; no network, state or delivery."""
import json
import re
from datetime import datetime
import sls_config_crypto as _config_crypto


def strict_json(raw):
    try:
        return json.loads(
            raw,
            object_pairs_hook=_config_crypto._pairs,
            parse_constant=_config_crypto._invalid_constant,
            parse_float=_config_crypto._finite_float,
        )
    except RecursionError as error:
        raise ValueError("Weather JSON nesting exceeds its limit") from error


def timestamp(value):
    try:
        result = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
        return result.timestamp() if result.tzinfo else 0
    except (ValueError, TypeError, OverflowError):
        return 0


def validate_collection(value):
    if (
        not isinstance(value, dict)
        or value.get("type") != "FeatureCollection"
        or not isinstance(value.get("features"), list)
    ):
        raise ValueError("Invalid Weather.gov FeatureCollection")
    if len(value["features"]) > 1000:
        raise ValueError("Weather.gov feature limit exceeded")
    for feature in value["features"]:
        if not isinstance(feature, dict) or not isinstance(
            feature.get("properties"), dict
        ):
            raise ValueError("Malformed Weather.gov feature")
        props = feature["properties"]
        for name, limit in [
            ("id", 4096),
            ("event", 120),
            ("severity", 32),
            ("status", 32),
            ("messageType", 32),
        ]:
            field = props.get(name)
            if field is not None and (
                not isinstance(field, str)
                or len(field.encode("utf-8")) > limit
                or re.search(r"[\x00-\x1f\x7f]", field)
            ):
                raise ValueError(
                    "Weather.gov identity/status field is invalid: " + name
                )
        if props.get("status") == "Actual" and props.get("messageType") not in {
            "Alert",
            "Update",
            "Cancel",
        }:
            raise ValueError("Weather.gov actual alert message type is invalid")
        identifier = feature.get("id")
        if identifier is not None and (
            not isinstance(identifier, str)
            or len(identifier.encode("utf-8")) > 4096
            or re.search(r"[\x00-\x1f\x7f]", identifier)
        ):
            raise ValueError("Weather.gov feature identity is invalid")
        if props.get("status") == "Actual" and props.get("messageType") != "Cancel":
            if (
                not (feature.get("id") or props.get("id"))
                or not props.get("event")
                or not timestamp(props.get("expires"))
            ):
                raise ValueError("Weather.gov alert is missing identity or expiration")
        if not isinstance(props.get("references") or [], list):
            raise ValueError("Weather.gov alert references are malformed")
        for reference in props.get("references") or []:
            if not isinstance(reference, dict):
                raise ValueError("Weather.gov alert reference is not an object")
            for name in ["identifier", "@id", "sent"]:
                field = reference.get(name)
                if field is not None and (
                    not isinstance(field, str)
                    or len(field.encode("utf-8")) > 4096
                    or re.search(r"[\x00-\x1f\x7f]", field)
                ):
                    raise ValueError("Weather.gov alert reference identity is invalid")
        if len(json.dumps(feature, allow_nan=False)) > 256 * 1024:
            raise ValueError("Weather.gov feature size limit exceeded")
    return value["features"]
