import sys
import unittest
from pathlib import Path

import numpy as np
import torch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from detection_region import DetectionRegion
from counter import LineCounter


class Boxes:
    def __init__(self, data, shape):
        self.data = data
        self.orig_shape = shape


class DetectionRegionTests(unittest.TestCase):
    def test_hallway_pixels_never_reach_detector(self):
        image = np.zeros((100, 200, 3), dtype=np.uint8)
        image[:, 170:] = 255
        region = DetectionRegion([0, 0, 0.65, 1])
        crop, offset = region.crop(image)
        self.assertEqual(crop.shape, (100, 130, 3))
        self.assertFalse(crop.any())
        self.assertEqual(offset, (0, 0))
        self.assertTrue(image[:, 170:].all())

    def test_offset_preserves_track_id_confidence_and_footpoint(self):
        region = DetectionRegion([0.1, 0.2, 0.7, 1])
        _, offset = region.crop(np.zeros((100, 200, 3), dtype=np.uint8))
        data = torch.tensor([[10, 5, 40, 60, 7, 0.85, 0]], dtype=torch.float32)
        original = data.clone()
        restored = region.restore_boxes(Boxes(data, (80, 120)), offset, (100, 200))
        self.assertEqual(restored.data[0, :4].tolist(), [30, 25, 60, 80])
        self.assertTrue(torch.equal(restored.data[:, 4:], original[:, 4:]))
        self.assertTrue(torch.equal(data, original))
        self.assertEqual(restored.orig_shape, (100, 200))

    def test_default_region_keeps_whole_image(self):
        crop, offset = DetectionRegion(None).crop(np.zeros((100, 200, 3)))
        self.assertEqual(crop.shape, (100, 200, 3))
        self.assertEqual(offset, (0, 0))

    def test_cropped_tracks_still_cross_full_frame_line(self):
        line = [(0.2, 0.5), (0.6, 0.5)]
        region = DetectionRegion([0.1, 0.2, 0.7, 1], line)
        _, offset = region.crop(np.zeros((100, 200, 3)))
        counter = LineCounter(*line)
        events = []
        for index, foot_y in enumerate([20, 20, 40, 40]):
            data = torch.tensor([[20, 0, 40, foot_y, 7, 0.85, 0]], dtype=torch.float32)
            boxes = region.restore_boxes(Boxes(data, (80, 120)), offset, (100, 200))
            x1, _, x2, y2, identity, _, _ = boxes.data[0].tolist()
            events.extend(counter.update([(int(identity), (x1 + x2) / 400, y2 / 100)], index * 0.1))
        self.assertEqual(events, ['IN'])

    def test_invalid_or_incompatible_regions_fail_before_inference(self):
        for bounds in [[0, 0, float('nan'), 1], [0, 0, 2, 1], [0.7, 0, 0.2, 1], [0, 1]]:
            with self.assertRaises(ValueError):
                DetectionRegion(bounds)
        with self.assertRaises(ValueError):
            DetectionRegion([0, 0, 0.65, 1], [(0.8, 0.5), (0.2, 0.9)])
        with self.assertRaises(ValueError):
            DetectionRegion([0, 0, 0.01, 1]).crop(np.zeros((100, 200, 3)))


if __name__ == '__main__':
    unittest.main()
