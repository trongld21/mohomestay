import csv
import json
import sys
import tempfile
import unittest
import contextlib
import io
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import cv2
import numpy as np
import torch
from ultralytics.engine.results import Boxes

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from video_report import main, parse_start, run_video


class VideoReportTests(unittest.TestCase):
    def test_interactive_path_with_spaces_and_optional_zero_reference(self):
        output = io.StringIO()
        with patch('sys.argv', ['video_report', '--interactive', '--output', 'unused-report']), \
                patch('builtins.input', side_effect=['"D:\\Videos\\ca toi.mp4"', '', '0', '']), \
                patch('video_report.run_video', return_value={'status': 'complete'}) as process, \
                patch('os.startfile', create=True), contextlib.redirect_stdout(output):
            self.assertEqual(main(), 0)
        self.assertEqual(process.call_args.args[1], 'D:\\Videos\\ca toi.mp4')
        self.assertIsNone(process.call_args.args[3])
        self.assertEqual(process.call_args.args[4], 0)
        self.assertIsNone(process.call_args.args[5])

    def test_naive_camera_time_is_vietnam_time(self):
        self.assertEqual(parse_start('2026-10-28T20:00:00').isoformat(), '2026-10-28T20:00:00+07:00')
        self.assertEqual(parse_start('2026-10-28T13:00:00Z').hour, 20)

    def test_video_time_crosses_midnight_and_exports_reconciliation(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent) as directory:
            root = Path(directory)
            video = root / 'input.mp4'
            writer = cv2.VideoWriter(str(video), cv2.VideoWriter_fourcc(*'mp4v'), 1, (128, 96))
            self.assertTrue(writer.isOpened())
            for _ in range(4):
                writer.write(np.full((96, 128, 3), 80, dtype=np.uint8))
            writer.release()
            weights = root / 'local.pt'
            weights.touch()

            class Model:
                def __init__(self, path):
                    self.frame = 0

                def track(self, image, **kwargs):
                    foot = [24, 24, 72, 72][self.frame]
                    self.frame += 1
                    data = torch.tensor([[40, 0, 60, foot, 7, .9, 0]], dtype=torch.float32)
                    return [SimpleNamespace(boxes=Boxes(data, image.shape[:2]))]

            config = {'camera_id': 'entrance', 'model': str(weights), 'line_start': [.1, .5],
                      'line_end': [.9, .5], 'inside_side': 1, 'margin': .025, 'stable_frames': 2,
                      'max_gap_seconds': 1.5, 'confidence': .4, 'image_size': 512,
                      'device': 'cpu', 'calibrated': False}
            report = run_video(config, video, root / 'report',
                               start='2026-10-28T23:59:58+07:00', expected_in=2, expected_out=0,
                               with_preview=False, model_factory=Model)
            self.assertEqual(report['totals'], {'IN': 1, 'OUT': 0})
            self.assertEqual(report['differences'], {'IN': -1, 'OUT': 0})
            self.assertEqual(report['status'], 'complete')
            self.assertFalse(report['calibrationVerified'])
            with (root / 'report/events.csv').open(encoding='utf-8-sig', newline='') as stream:
                rows = list(csv.DictReader(stream))
            self.assertEqual(rows[0]['video_time'], '00:00:03.000')
            self.assertEqual(rows[0]['camera_time'], '2026-10-29T00:00:01+07:00')
            self.assertEqual(json.loads((root / 'report/report.json').read_text())['operationalEventsSent'], 0)
            self.assertFalse((root / 'report/annotated.mp4').exists())
            capture_factory = cv2.VideoCapture

            class MissingTail:
                def __init__(self, *args):
                    self.capture = capture_factory(*args)

                def isOpened(self):
                    return self.capture.isOpened()

                def get(self, prop):
                    return 100 if prop == cv2.CAP_PROP_FRAME_COUNT else self.capture.get(prop)

                def read(self):
                    return self.capture.read()

                def release(self):
                    self.capture.release()

            with patch('cv2.VideoCapture', MissingTail):
                incomplete = run_video(config, video, root / 'incomplete',
                                       with_preview=False, model_factory=Model)
            self.assertEqual(incomplete['status'], 'incomplete')
            with self.assertRaises(FileExistsError):
                run_video(config, video, root / 'report', with_preview=False, model_factory=Model)

    def test_unreadable_video_does_not_generate_a_zero_count_report(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent) as directory:
            root = Path(directory)
            (root / 'bad.mp4').write_bytes(b'not a video')
            (root / 'weights.pt').touch()
            with self.assertRaises(ValueError):
                run_video({'model': str(root / 'weights.pt')}, root / 'bad.mp4', root / 'report')
            self.assertFalse((root / 'report/report.json').exists())


if __name__ == '__main__':
    unittest.main()
