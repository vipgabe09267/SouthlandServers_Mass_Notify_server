#!/usr/bin/env python3
"""Pure release-channel, version-pin and automatic deployment time policy."""
from datetime import datetime, timezone, timedelta
import re

VERSION = r'[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}(?:-beta)?'


def validate(value):
    if not isinstance(value, dict):
        raise ValueError('Update policy must be an object')
    result = {'channel': 'beta', 'pinned_version': '', 'window_start': '', 'window_end': '', 'rollout_delay_hours': 0}
    result.update({key: value[key] for key in result if key in value})
    if result['channel'] not in ('stable', 'beta'):
        raise ValueError('Unknown update channel')
    pin = result['pinned_version']
    if not isinstance(pin, str) or (pin and not re.fullmatch(VERSION, pin)):
        raise ValueError('Invalid update version pin')
    if result['channel'] == 'stable' and pin.endswith('-beta'):
        raise ValueError('Beta pin is not eligible for the stable channel')
    for key in ('window_start', 'window_end'):
        if not isinstance(result[key], str) or (result[key] and not re.fullmatch(r'(?:[01][0-9]|2[0-3]):[0-5][0-9]', result[key])):
            raise ValueError('Invalid maintenance window time')
    a, b = result['window_start'], result['window_end']
    if bool(a) != bool(b) or (a and a == b):
        raise ValueError('Maintenance window requires two different times')
    if a:
        minutes = lambda value: int(value[:2]) * 60 + int(value[3:])
        if (minutes(b) - minutes(a)) % 1440 < 60:
            raise ValueError('Maintenance window must cover at least one hourly update check')
    if type(result['rollout_delay_hours']) is not int or not 0 <= result['rollout_delay_hours'] <= 168:
        raise ValueError('Update rollout delay must be an integer from 0 to 168 hours')
    return result


def release_allowed(release, policy):
    if not isinstance(release, dict) or release.get('draft') is not False:
        return False
    tag = release.get('tag_name', '')
    if not isinstance(tag, str) or not re.fullmatch('slsmassnotifyserver-' + VERSION, tag):
        return False
    version = tag[len('slsmassnotifyserver-'):]
    if type(release.get('prerelease')) is not bool:
        return False
    if policy['channel'] == 'stable' and (release['prerelease'] or version.endswith('-beta')):
        return False
    return not policy['pinned_version'] or version == policy['pinned_version']


def automatic_gate(release, policy, now=None):
    now = now or datetime.now(timezone.utc).astimezone()
    if now.tzinfo is None:
        raise ValueError('Update policy requires timezone-aware current time')
    try:
        published = datetime.fromisoformat(release['published_at'].replace('Z', '+00:00'))
        if published.tzinfo is None or published > now:
            raise ValueError('Release publication time is unavailable or in the future')
    except (KeyError, TypeError, AttributeError, ValueError):
        return {'automatic_eligible': False, 'deferred_reason': 'Release publication time could not be verified.', 'eligible_at': ''}
    eligible = published + timedelta(hours=policy['rollout_delay_hours'])
    if now < eligible:
        return {'automatic_eligible': False, 'deferred_reason': 'Waiting for the configured rollout delay.', 'eligible_at': eligible.isoformat()}
    start, end = policy['window_start'], policy['window_end']
    local = now.strftime('%H:%M')
    inside = not start or (start <= local < end if start < end else local >= start or local < end)
    return {'automatic_eligible': inside,
            'deferred_reason': '' if inside else 'Outside the configured PBX maintenance window.',
            'eligible_at': eligible.isoformat()}
