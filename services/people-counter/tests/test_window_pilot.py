import sys
import unittest
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from window_pilot import FrameMailbox, crop_video


class WindowPilotTests(unittest.TestCase):
    def test_crop_excludes_surrounding_application_and_owns_pixels(self):
        image = np.zeros((100, 200, 4), dtype=np.uint8)
        image[10:90, 40:180, :3] = 123
        cropped = crop_video(image, (200, 100), (0.2, 0.1, 0.9, 0.9))
        self.assertEqual(cropped.shape, (80, 140, 3))
        image[:] = 0
        self.assertTrue(np.all(cropped == 123))

    def test_resizing_and_invalid_regions_stop_capture(self):
        image = np.zeros((100, 200, 4), dtype=np.uint8)
        for size, roi in [((201, 100), (0, 0, 1, 1)), ((200, 100), (-1, 0, 1, 1)),
                          ((200, 100), (0.9, 0, 0.2, 1)), ((200, 100), (0, 0, float('nan'), 1))]:
            with self.assertRaises(ValueError):
                crop_video(image, size, roi)

    def test_latest_frame_replaces_backlog(self):
        mailbox = FrameMailbox()
        mailbox.publish(np.zeros((10, 10, 3), dtype=np.uint8), 1)
        mailbox.publish(np.ones((10, 10, 3), dtype=np.uint8), 2)
        sequence, image, at = mailbox.next(0, 2)
        self.assertEqual((sequence, at), (2, 2))
        self.assertTrue(np.all(image == 1))
        self.assertIsNone(mailbox.next(sequence, 2))

    def test_repeated_frozen_pixels_are_not_a_fresh_camera_feed(self):
        mailbox = FrameMailbox()
        frame = np.zeros((10, 10, 3), dtype=np.uint8)
        mailbox.publish(frame, 1)
        mailbox.publish(frame, 12)
        self.assertIsNone(mailbox.next(0, 12))
        frame[:] = 1
        mailbox.publish(frame, 13)
        self.assertIsNotNone(mailbox.next(0, 13))

    def test_pause_discards_old_frame_and_fresh_frame_resumes(self):
        mailbox = FrameMailbox()
        frame = np.zeros((10, 10, 3), dtype=np.uint8)
        mailbox.publish(frame, 1)
        mailbox.invalidate('LAYOUT_CHANGED')
        self.assertEqual(mailbox.state(1), 'LAYOUT_CHANGED')
        self.assertIsNone(mailbox.next(0, 1))
        mailbox.publish(frame, 2)
        self.assertEqual(mailbox.state(2), 'RUNNING')
        self.assertIsNotNone(mailbox.next(0, 2))
        self.assertEqual(mailbox.state(5), 'CAPTURE_STALLED')


if __name__ == '__main__':
    unittest.main()
