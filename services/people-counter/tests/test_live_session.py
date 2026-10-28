import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from live_session import load_session, new_session, validate_session
from window_pilot import save_atomic
import json


class LiveSessionTests(unittest.TestCase):
    def test_existing_counts_survive_restart_without_becoming_operational(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent) as directory:
            path = Path(directory) / 'session.json'
            session = new_session({'IN': 7, 'OUT': 10}, '2026-10-27T00:00:00Z')
            save_atomic(path, json.dumps(session).encode())
            loaded = load_session(path)
            self.assertEqual((loaded['in'], loaded['out']), (7, 10))
            self.assertEqual(loaded['id'], session['id'])
            self.assertTrue(loaded['trial'])

    def test_corrupt_counts_are_not_silently_reset_to_zero(self):
        for values in [{'IN': -1, 'OUT': 0}, {'IN': True, 'OUT': 0}]:
            with self.assertRaises(ValueError):
                new_session(values)
        session = new_session()
        with self.assertRaises(ValueError):
            validate_session({**session, 'trial': False})


if __name__ == '__main__':
    unittest.main()
