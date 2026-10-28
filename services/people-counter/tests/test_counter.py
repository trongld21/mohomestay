import sys
import tempfile
import unittest
import threading
from types import SimpleNamespace
from unittest.mock import patch
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from counter import LineCounter
from delivery import Outbox, signed_request
from worker import LatestFrames, send_loop


class CounterTests(unittest.TestCase):
    def move(self, counter, points, start=0):
        events = []
        for index, (x, y) in enumerate(points):
            events.extend(counter.update([(1, x, y)], start + index * 0.1))
        return events

    def test_entry_exit_and_repeat_trip(self):
        counter = LineCounter((0.2, 0.5), (0.8, 0.5))
        path = [(0.5, y) for y in [0.3, 0.3, 0.7, 0.7, 0.7, 0.3, 0.3, 0.7, 0.7]]
        self.assertEqual(self.move(counter, path), ['IN', 'OUT', 'IN'])

    def test_jitter_and_spawn_on_line_do_not_count(self):
        for ys in [[0.3, 0.3, 0.49, 0.51, 0.495, 0.505, 0.3], [0.5, 0.7, 0.7]]:
            self.assertEqual(self.move(LineCounter((0.2, 0.5), (0.8, 0.5)), [(0.5, y) for y in ys]), [])

    def test_line_extension_does_not_count(self):
        self.assertEqual(self.move(LineCounter((0.2, 0.5), (0.8, 0.5)), [(0.95, y) for y in [0.3, 0.3, 0.7, 0.7]]), [])

    def test_gap_drops_old_track(self):
        counter = LineCounter((0.2, 0.5), (0.8, 0.5))
        self.move(counter, [(0.5, 0.3)] * 2)
        self.assertEqual(self.move(counter, [(0.5, 0.7)] * 2, start=10), [])

    def test_reversed_direction_and_diagonal(self):
        counter = LineCounter((0.2, 0.5), (0.8, 0.5), inside_side=-1)
        self.assertEqual(self.move(counter, [(0.5, y) for y in [0.3, 0.3, 0.7, 0.7]]), ['OUT'])
        counter = LineCounter((0.2, 0.2), (0.8, 0.8))
        self.assertEqual(self.move(counter, [(0.6, 0.3)] * 2 + [(0.3, 0.6)] * 2), ['IN'])

    def test_two_people_cross_opposite_directions(self):
        counter = LineCounter((0.2, 0.5), (0.8, 0.5))
        events = []
        for index, positions in enumerate([(0.3, 0.7), (0.3, 0.7), (0.7, 0.3), (0.7, 0.3)]):
            events.extend(counter.update([(1, 0.4, positions[0]), (2, 0.6, positions[1])], index * 0.1))
        self.assertEqual(events, ['IN', 'OUT'])

    def test_invalid_geometry(self):
        for start, end in [((0.5, 0.5), (0.5, 0.5)), ((-1, 0), (1, 1))]:
            with self.assertRaises(ValueError):
                LineCounter(start, end)


class DeliveryTests(unittest.TestCase):
    def test_push_stream_drains_burst_without_waiting_for_declared_fps(self):
        ready = threading.Event()
        release = threading.Event()

        class Capture:
            def __init__(self):
                self.sequence = 0

            def read(self):
                self.sequence += 1
                if self.sequence <= 10:
                    return True, self.sequence
                ready.set()
                release.wait(2)
                return False, None

        frames = LatestFrames(Capture(), threading.Event(), fps=1, pace=False)
        try:
            self.assertTrue(ready.wait(1), 'Push streams must drain queued frames immediately')
            self.assertEqual(frames.next(0)[0:2], (10, 10))
        finally:
            release.set()
            frames.thread.join(2)

    def test_queue_survives_restart_and_ack_only_removes_sent_ids(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent) as directory:
            path = Path(directory) / 'queue.sqlite'
            queue = Outbox(path)
            queue.add('IN', '2026-10-27T12:00:00Z')
            pending = Outbox(path).pending()
            self.assertEqual(len(pending), 1)
            self.assertEqual(pending[0]['direction'], 'IN')
            queue.add('OUT', '2026-10-27T12:00:01Z')
            queue.ack(pending)
            self.assertEqual([row['direction'] for row in queue.pending()], ['OUT'])

    def test_signature_covers_exact_bytes(self):
        import hashlib
        import hmac
        body, headers = signed_request({'cameraId': 'entrance', 'events': []}, 's' * 32, 1234567890)
        expected = hmac.new(b's' * 32, b'1234567890.' + body, hashlib.sha256).hexdigest()
        self.assertEqual(headers['X-Counter-Signature'], expected)
        self.assertEqual(headers['X-Counter-Timestamp'], '1234567890')

    def test_failed_or_partial_response_preserves_queue(self):
        class OneIteration:
            def __init__(self):
                self.done = False

            def is_set(self):
                return self.done

            def wait(self, delay):
                self.done = True

        class Queue:
            def __init__(self):
                self.acknowledged = False

            def pending(self):
                return [{'id': 'a' * 32, 'direction': 'IN', 'occurredAt': '2026-10-27T12:00:00Z'}]

            def ack(self, events):
                self.acknowledged = True

        for status, receipt, expected in [(401, {}, False), (500, {}, False), (302, {}, False), (200, {'accepted': 0}, False), (200, {'accepted': True}, False), (200, {'accepted': 1}, True)]:
            queue = Queue()
            response = SimpleNamespace(status_code=status, json=lambda: receipt)
            transport = SimpleNamespace(post=lambda *args, **kwargs: response)
            health = {'status': 'OFFLINE', 'frameAt': None, 'monotonic': 0}
            with patch.dict(sys.modules, {'requests': transport}):
                send_loop(queue, 'https://example.test/counter', 's' * 32, 'entrance', health, threading.Lock(), OneIteration())
            self.assertEqual(queue.acknowledged, expected)

    def test_trial_delivery_is_explicitly_marked(self):
        import json
        stop = threading.Event()
        payloads = []

        def post(url, **kwargs):
            payloads.append(json.loads(kwargs['data']))
            stop.set()
            return SimpleNamespace(status_code=200, json=lambda: {'accepted': 0})

        queue = SimpleNamespace(pending=lambda: [], ack=lambda events: None)
        health = {'status': 'OFFLINE', 'frameAt': None, 'monotonic': 0}
        with patch.dict(sys.modules, {'requests': SimpleNamespace(post=post)}):
            send_loop(queue, 'https://example.test/counter', 's' * 32, 'entrance', health, threading.Lock(), stop, True)
        self.assertTrue(payloads[0]['trial'])
        self.assertNotIn('preview', payloads[0])

    def test_sdk_preview_delivery_retains_image_and_session_metadata(self):
        import json
        import time
        stop = threading.Event()
        payloads = []
        preview = {'jpeg': 'synthetic-jpeg', 'frameAt': '2026-10-28T06:00:00Z',
                   'session': {'id': 'a' * 32, 'startedAt': '2026-10-28T05:00:00Z',
                               'in': 7, 'out': 10, 'trial': True}}

        def post(url, **kwargs):
            payloads.append(json.loads(kwargs['data']))
            self.assertFalse(kwargs['allow_redirects'])
            stop.set()
            return SimpleNamespace(status_code=200, json=lambda: {'accepted': 0})

        queue = SimpleNamespace(pending=lambda: [], ack=lambda events: None)
        health = {'status': 'RUNNING', 'frameAt': preview['frameAt'],
                  'monotonic': time.monotonic(), 'preview': preview}
        with patch.dict(sys.modules, {'requests': SimpleNamespace(post=post)}):
            send_loop(queue, 'https://example.test/counter', 's' * 32, 'entrance', health, threading.Lock(), stop, True)
        self.assertEqual(payloads[0]['preview'], preview)
        self.assertTrue(payloads[0]['trial'])

    def test_sdk_preview_upload_requires_signed_recording_mode(self):
        import contextlib
        import io
        from sdk_pilot import main
        with patch('sys.argv', ['sdk_pilot', '--config', 'unused', '--send-preview']), contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as error:
                main()
        self.assertEqual(error.exception.code, 2)

    def test_capture_keeps_latest_frame_and_stops_on_gap(self):
        ready = threading.Event()
        release = threading.Event()

        class Capture:
            def __init__(self):
                self.sequence = 0

            def read(self):
                self.sequence += 1
                if self.sequence <= 3:
                    return True, f'frame-{self.sequence}'
                ready.set()
                release.wait(2)
                return False, None

        frames = LatestFrames(Capture(), threading.Event())
        try:
            self.assertTrue(ready.wait(2))
            sequence, frame, _, _ = frames.next(0)
            self.assertEqual((sequence, frame), (3, 'frame-3'))
        finally:
            release.set()
            frames.thread.join(2)
        self.assertIsNone(frames.next(3))


if __name__ == '__main__':
    unittest.main()
