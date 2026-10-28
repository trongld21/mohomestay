import base64
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from worker import encode_preview
from sdk_pilot import write_heartbeat


class PreviewQualityTests(unittest.TestCase):
    def test_php_reader_lock_does_not_stop_video_producer(self):
        with patch('sdk_pilot.save_atomic', side_effect=PermissionError):
            self.assertFalse(write_heartbeat(Path('unused.json'), 'RUNNING'))

    def test_sharp_preview_preserves_1280_pixels_and_source_image(self):
        image = np.zeros((720, 1280, 3), dtype=np.uint8)
        cv2.putText(image, 'Camera detail 123456789', (30, 350),
                    cv2.FONT_HERSHEY_SIMPLEX, 2, (255, 255, 255), 2)
        original = image.copy()
        preview = encode_preview(image, '2026-10-28T00:00:00Z', max_side=1280, quality=85)
        decoded = cv2.imdecode(np.frombuffer(base64.b64decode(preview['jpeg']), dtype=np.uint8), cv2.IMREAD_COLOR)
        self.assertEqual(decoded.shape, image.shape)
        self.assertTrue(np.array_equal(image, original))
        self.assertEqual(preview['frameAt'], '2026-10-28T00:00:00Z')

    def test_complex_frame_stays_within_php_preview_limit(self):
        image = np.random.default_rng(12).integers(0, 256, (720, 1280, 3), dtype=np.uint8)
        preview = encode_preview(image, '2026-10-28T00:00:00Z', max_side=1280, quality=85)
        self.assertIsNotNone(preview)
        self.assertLessEqual(len(base64.b64decode(preview['jpeg'])), 130000)
        self.assertLessEqual(len(preview['jpeg']), 180000)


if __name__ == '__main__':
    unittest.main()
