"""Persistent trial totals for the live preview; never an operational event source."""

import json
import re
import uuid
from datetime import datetime, timezone

from window_pilot import save_atomic


def new_session(totals=None, started_at=None):
    totals = totals or {'IN': 0, 'OUT': 0}
    return validate_session({'id': uuid.uuid4().hex,
                             'startedAt': started_at or datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
                             'in': totals['IN'], 'out': totals['OUT'], 'trial': True})


def validate_session(value):
    if not isinstance(value, dict) or not isinstance(value.get('id'), str) or not re.fullmatch('[a-f0-9]{32}', value['id']):
        raise ValueError('Invalid trial session ID')
    at = value.get('startedAt', '')
    if not isinstance(at, str):
        raise ValueError('Invalid session start')
    parsed = datetime.strptime(at, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)
    if parsed.strftime('%Y-%m-%dT%H:%M:%SZ') != at or parsed.timestamp() > datetime.now(timezone.utc).timestamp() + 60:
        raise ValueError('Invalid session start')
    for key in ('in', 'out'):
        if type(value.get(key)) is not int or not 0 <= value[key] <= 10000000:
            raise ValueError('Invalid session totals')
    if value.get('trial') is not True:
        raise ValueError('Session metadata must be marked as trial')
    return {key: value[key] for key in ('id', 'startedAt', 'in', 'out', 'trial')}


def load_session(path):
    if path.exists():
        return validate_session(json.loads(path.read_text(encoding='utf-8')))
    session = new_session()
    save_atomic(path, json.dumps(session).encode())
    return session
