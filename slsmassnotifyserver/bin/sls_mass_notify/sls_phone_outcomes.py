#!/usr/bin/python3
"""Read-only, bounded phone evidence projection. No delivery or AMI actions."""
import argparse
import json
from pathlib import Path
import re
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_phone_admission import DATA, PhoneAdmissionStore, read_private_json

MAX_RESULTS = 1000
DIAL_STATUSES = {'ANSWER', 'BUSY', 'NOANSWER', 'CANCEL', 'CONGESTION', 'CHANUNAVAIL', 'DONTCALL', 'TORTURE', 'INVALIDARGS'}


def project(state, correlation):
    if not isinstance(correlation, str) or not re.fullmatch(r'[A-Za-z0-9_.:-]{1,128}', correlation):
        raise ValueError('invalid_correlation')
    PhoneAdmissionStore.validate(state)
    batches = [batch for batch in state['batches'].values() if batch.get('correlation') == correlation]
    batches.sort(key=lambda batch: batch['created_at'])
    result = {'ok': True, 'available': bool(batches), 'active': False, 'uncertain': not bool(batches),
              'truncated': False, 'targets': [], 'evidence': 'asterisk_channel_events', 'playback_confirmed': False}
    for attempt, batch in enumerate(batches, 1):
        result['active'] = result['active'] or batch['status'] != 'closed'
        for recipient, target in batch['targets'].items():
            channels = [(uid, row) for uid, row in batch['channels'].items()
                        if row.get('recipient') == recipient and row.get('kind') == 'contact']
            channels.sort(key=lambda pair: (pair[1]['created_at'], pair[0]))
            expected = len(target['dials'])
            for index in range(max(expected, len(channels))):
                if len(result['targets']) >= MAX_RESULTS:
                    result.update(truncated=True, uncertain=True)
                    return result
                row = channels[index][1] if index < len(channels) else {}
                status = row.get('dial_status', '')
                uncertain = bool(batch.get('outcome_uncertain') or row.get('outcome_uncertain') or not row or index >= expected)
                if batch['status'] == 'closed' and not status and row.get('answered_at') is None and row.get('joined_at') is None:
                    uncertain = True
                result['uncertain'] = result['uncertain'] or uncertain
                playback = target.get('playback', {}) if recipient.startswith('voice_') else {}
                acknowledgement = playback.get('ack_status', '')
                if acknowledgement == 'pending' and (batch['status'] == 'closed' or row.get('ended_at') is not None):
                    acknowledgement = 'interrupted'
                result['targets'].append({'recipient_id': recipient, 'attempt': attempt, 'contact_index': index + 1,
                    'dial_status': status if status in DIAL_STATUSES else '',
                    'answered': row.get('answered_at') is not None, 'joined': row.get('joined_at') is not None,
                    'ended': row.get('ended_at') is not None, 'uncertain': uncertain,
                    'playback_completed': playback.get('completed_at') is not None,
                    'keypad_acknowledged': playback.get('ack_status') == 'acknowledged',
                    'acknowledgement_status': acknowledgement,
                    'acknowledged_at': playback.get('acknowledged_at')})
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('correlation')
    args = parser.parse_args(argv)
    try:
        result = project(read_private_json('phone-admission.json', DATA), args.correlation)
    except Exception:
        result = {'ok': False, 'available': False, 'active': False, 'uncertain': True, 'truncated': False,
                  'targets': [], 'playback_confirmed': False, 'failure_code': 'phone_evidence_unavailable'}
    print(json.dumps(result, separators=(',', ':')))
    return 0 if result['ok'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
