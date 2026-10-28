import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from live_session import new_session
from trial_journal import TrialJournal, delivery_settings


class TrialJournalTests(unittest.TestCase):
    def test_restart_retry_and_session_totals(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent) as directory:
            path = Path(directory) / 'trial.sqlite'
            journal = TrialJournal(path, new_session({'IN': 7, 'OUT': 10}))
            self.assertEqual(journal.pending(), [], 'Old aggregates must not become invented events')
            session = journal.record(['IN', 'OUT'], '2026-10-28T04:00:00Z')
            events = journal.pending()
            self.assertEqual((session['in'], session['out']), (8, 11))
            restored = TrialJournal(path, new_session())
            self.assertEqual(restored.pending(), events, 'Retries must preserve immutable IDs')
            restored.ack(events)
            self.assertEqual(restored.pending(), [])
            self.assertEqual(restored.read_session(), session, 'Acknowledgment must retain session totals')
            with self.assertRaises(ValueError):
                restored.record(['IN', 'BAD'], '2026-10-28T04:00:00Z')
            self.assertEqual(restored.read_session(), session)

    def test_cleartext_remote_endpoint_is_rejected(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent) as directory:
            path = Path(directory) / 'settings.env'
            path.write_text('COUNTER_ENDPOINT=http://example.test/ingest\nPEOPLE_COUNTER_KEY=' + 'a' * 32)
            from unittest.mock import patch
            with patch.dict('os.environ', {}, clear=True):
                with self.assertRaises(ValueError):
                    delivery_settings(path)


if __name__ == '__main__':
    unittest.main()
