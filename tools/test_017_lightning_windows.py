#!/usr/bin/python3
"""Actual provider and polling state machine, simulated clock/transports only."""
from pathlib import Path
from datetime import datetime, timezone
import importlib.util
import json
import os
import sys
import unittest
import urllib.parse
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'
sys.dont_write_bytecode = True
sys.path.insert(0, str(RUNTIME))
import sls_lightning_providers as provider

spec = importlib.util.spec_from_file_location('audit_tempest_poll', RUNTIME / 'sls_mass_notify_xweather_poll.py')
poll = importlib.util.module_from_spec(spec)
spec.loader.exec_module(poll)


class Response:
    status = 200
    def __init__(self, body): self.body = body
    def __enter__(self): return self
    def __exit__(self, *args): pass
    def read(self, limit): return self.body[:limit]


class WindowTransport:
    """Official minutes_offset contract: only strikes within the requested window."""
    def __init__(self, now, strikes): self.now, self.strikes, self.windows = now, strikes, []
    def open(self, request, timeout):
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(request.full_url).query)
        minutes = int(query['minutes_offset'][0])
        self.windows.append(minutes)
        return Response(json.dumps({'strikes': [row for row in self.strikes
            if self.now - 60 * minutes <= row['time'] <= self.now]}).encode())


class MeteomaticsTransport(WindowTransport):
    """Documented WFS TIME selects the preceding fixed five-minute period."""
    def open(self, request, timeout):
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(request.full_url).query)
        if query['REQUEST'] == ['DescribeFeatureType']:
            return Response(b'<schema xmlns="http://www.w3.org/2001/XMLSchema"><element name="time" type="dateTime"/><element name="kind" type="int"/></schema>')
        self.query = query
        end = int(datetime.fromisoformat(query['TIME'][0].replace('Z', '+00:00')).timestamp())
        self.windows.append(5)
        members = ''.join('<gml:featureMember><strike><time>' + datetime.fromtimestamp(row['time'], timezone.utc).isoformat()
            + '</time><kind>2</kind><gml:Point><gml:coordinates>' + str(row['lon']) + ',' + str(row['lat'])
            + '</gml:coordinates></gml:Point></strike></gml:featureMember>'
            for row in self.strikes if end - 300 <= row['time'] <= end)
        return Response(('<FeatureCollection xmlns:gml="http://www.opengis.net/gml">' + members + '</FeatureCollection>').encode())


class ProviderClock(datetime):
    @classmethod
    def now(cls, tz=None):
        return datetime.fromtimestamp(int(os.environ['XWEATHER_TEST_NOW']), timezone.utc)


class TempestWindow(unittest.TestCase):
    def test_tempest_backfill_covers_ten_minute_gap(self):
        self.run_provider_window('tempest')

    def test_meteomatics_backfill_covers_ten_minute_gap(self):
        self.run_provider_window('meteomatics')

    def run_provider_window(self, name):
        epoch = 1790942400
        area = {'id': 'fixture-area', 'name': 'Fixture', 'enabled': '1', 'location': '30,-97',
                'extensions': [], 'desktop_clients': [], 'email_recipients': ['fixture@example.invalid'],
                'radius_miles': 25, 'all_clear': 'send', 'all_clear_minutes': 10}
        root = {'enabled': '1', 'provider': name, 'client_id': 'fixture-key', 'client_secret': '' if name == 'tempest' else 'fixture-secret',
                'adaptive_free_tier': '0', 'query_interval_minutes': 10, 'groups': [area]}
        config = {'xweather': root, 'system_notification_emails': '', 'desktop_clients': [], 'nws_zones': []}
        merged = poll.select_group(root)
        state = {'configuration_identity': poll.lightning_area_identity(config, merged),
                 'active': True, 'notified': True, 'empty_polls': 0, 'cluster_started': epoch,
                 'last_strike_observed_at': epoch, 'last_observed_at': epoch, 'last_query': epoch}
        events, updates, windows = [], [], []
        # A strike one minute after each observation is older than five minutes
        # at the next ten-minute query, but inside the claimed observation period.
        strikes = [{'time': epoch + offset, 'lat': 30.0, 'lon': -97.0, 'strike_type': 'cg'}
                   for offset in (60, 660)]
        def update(path, patch_value):
            updates.append(dict(patch_value))
            if path == poll.STATE_FILE: state.update(patch_value)
        def fetch(settings):
            transport = (WindowTransport if name == 'tempest' else MeteomaticsTransport)(int(os.environ['XWEATHER_TEST_NOW']), strikes)
            result = provider.fetch(settings, opener=transport)
            windows.extend(transport.windows)
            return result
        with patch.object(poll, 'load_config', return_value=(config, root)), \
             patch.object(poll, 'configure_group_runtime', side_effect=lambda value: setattr(poll, 'CURRENT_GROUP_ID', value['id'])), \
             patch.object(poll, 'read_state', side_effect=lambda: dict(state)), \
             patch.object(poll, 'atomic_json_update', side_effect=update), \
             patch.object(poll, 'fetch_payload', side_effect=fetch), \
             patch.object(provider, 'datetime', ProviderClock), \
             patch.object(poll, 'append_event', side_effect=events.append), \
             patch.object(poll, 'log'), \
             patch.dict(os.environ, {'XWEATHER_DRY_RUN': '1', 'XWEATHER_TEST_EVENT': '',
                 'XWEATHER_TEST_PAYLOAD': '', 'XWEATHER_VERIFY_ONLY': '0', 'XWEATHER_ACTIVE_GROUP_ID': ''}):
            for offset in (600, 1200):
                os.environ['XWEATHER_TEST_NOW'] = str(epoch + offset)
                self.assertEqual(poll.main(), 0)
        self.assertEqual(windows, [12, 12] if name == 'tempest' else [5] * 6)
        self.assertFalse(any(event['event'] == 'Lightning All Clear' for event in events))
        self.assertEqual(state['last_strike_observed_at'], epoch+660)
        self.assertEqual(state['observation_gap_seconds'], 0)
        self.assertTrue(state['active'])

    def test_meteomatics_polar_box_contains_in_radius_strike(self):
        transport = MeteomaticsTransport(1790942400, [])
        with patch.dict(os.environ, {'XWEATHER_TEST_NOW': '1790942400'}), patch.object(provider, 'datetime', ProviderClock):
            provider.fetch({'provider':'meteomatics','client_id':'fixture','client_secret':'fixture',
                'location':'89.9,0','radius_miles':25}, opener=transport)
        west, south, east, north = map(float, transport.query['BBOX'][0].split(','))
        latitude, longitude = 89.8, 180.0
        self.assertLess(provider.distance((89.9, 0), latitude, longitude), 25)
        self.assertTrue(west <= longitude <= east and south <= latitude <= north)



if __name__ == '__main__': unittest.main()
