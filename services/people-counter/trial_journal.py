"""Persist each trial crossing and its session totals in one transaction."""

import json
import os
import re
import uuid
from contextlib import closing
from pathlib import Path
from urllib.parse import urlsplit

from delivery import Outbox
from live_session import validate_session


class TrialJournal(Outbox):
    def __init__(self, path, initial):
        super().__init__(path)
        with closing(self.connect()) as connection, connection:
            connection.execute('CREATE TABLE IF NOT EXISTS session (singleton INTEGER PRIMARY KEY CHECK(singleton=1), payload TEXT NOT NULL)')
            connection.execute('INSERT OR IGNORE INTO session VALUES(1, ?)', (json.dumps(validate_session(initial)),))
        self.session = self.read_session()

    def read_session(self):
        with closing(self.connect()) as connection:
            return validate_session(json.loads(connection.execute('SELECT payload FROM session WHERE singleton=1').fetchone()[0]))

    def record(self, directions, occurred_at):
        if any(direction not in ('IN', 'OUT') for direction in directions):
            raise ValueError('Invalid crossing direction')
        with closing(self.connect()) as connection, connection:
            connection.execute('BEGIN IMMEDIATE')
            session = validate_session(json.loads(connection.execute('SELECT payload FROM session WHERE singleton=1').fetchone()[0]))
            if connection.execute('SELECT COUNT(*) FROM events').fetchone()[0] + len(directions) > 100000:
                raise RuntimeError('Outbox full; delivery requires attention')
            for direction in directions:
                connection.execute('INSERT INTO events VALUES(?,?,?)', (uuid.uuid4().hex, direction, occurred_at))
                session[direction.lower()] += 1
            validate_session(session)
            connection.execute('UPDATE session SET payload=? WHERE singleton=1', (json.dumps(session),))
        self.session = session
        return session.copy()


def delivery_settings(env_file):
    names = {'COUNTER_ENDPOINT', 'PEOPLE_COUNTER_KEY'}
    values = {name: os.environ[name] for name in names if name in os.environ}
    if env_file:
        for line in Path(env_file).read_text(encoding='utf-8-sig').splitlines():
            match = re.fullmatch(r'\s*(COUNTER_ENDPOINT|PEOPLE_COUNTER_KEY)\s*=\s*(.*?)\s*', line)
            if match and match[1] not in values:
                values[match[1]] = match[2].strip('\"\'')
    endpoint = values.get('COUNTER_ENDPOINT', '')
    url = urlsplit(endpoint)
    local = url.scheme == 'http' and url.hostname in ('127.0.0.1', 'localhost', '::1')
    if (url.scheme != 'https' and not local) or not url.hostname or url.username or url.password or url.fragment:
        raise ValueError('Delivery requires HTTPS, or a loopback-only local HTTP endpoint')
    key = values.get('PEOPLE_COUNTER_KEY', '')
    if len(key) < 32:
        raise ValueError('Delivery requires a signing key of at least 32 characters')
    return endpoint, key
