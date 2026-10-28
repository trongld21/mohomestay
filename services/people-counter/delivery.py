"""Durable at-least-once delivery. Server deduplicates immutable event IDs."""

import hashlib
import hmac
import json
import sqlite3
import uuid
from contextlib import closing


class Outbox:
    def __init__(self, path):
        self.path = str(path)
        with closing(self.connect()) as connection, connection:
            connection.execute('CREATE TABLE IF NOT EXISTS events (id TEXT PRIMARY KEY, direction TEXT NOT NULL, occurred_at TEXT NOT NULL)')

    def connect(self):
        return sqlite3.connect(self.path, timeout=10)

    def add(self, direction, occurred_at):
        with closing(self.connect()) as connection, connection:
            # Fail closed instead of silently discarding unacknowledged counts.
            if connection.execute('SELECT COUNT(*) FROM events').fetchone()[0] >= 100000:
                raise RuntimeError('Outbox full; delivery requires attention')
            connection.execute('INSERT INTO events VALUES(?,?,?)', (uuid.uuid4().hex, direction, occurred_at))

    def pending(self):
        with closing(self.connect()) as connection:
            return [{'id': row[0], 'direction': row[1], 'occurredAt': row[2]} for row in
                    connection.execute('SELECT id,direction,occurred_at FROM events ORDER BY occurred_at,id LIMIT 100')]

    def ack(self, events):
        with closing(self.connect()) as connection, connection:
            connection.executemany('DELETE FROM events WHERE id = ?', [(event['id'],) for event in events])


def signed_request(payload, key, timestamp):
    body = json.dumps(payload, separators=(',', ':'), ensure_ascii=False).encode('utf-8')
    stamp = str(timestamp)
    signature = hmac.new(key.encode('utf-8'), stamp.encode('ascii') + b'.' + body, hashlib.sha256).hexdigest()
    return body, {'Content-Type': 'application/json', 'X-Counter-Timestamp': stamp, 'X-Counter-Signature': signature}
