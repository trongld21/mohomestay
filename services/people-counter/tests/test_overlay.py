from pathlib import Path
import sys
import time
from types import SimpleNamespace
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from overlay import detection_overlay


class Vector:
    def __init__(self, values):
        self.values = values

    def cpu(self):
        return self

    def int(self):
        return self

    def tolist(self):
        return self.values


class DetectionOverlayTests(unittest.TestCase):
    config = {'line_start': [0.04, 0.52], 'line_end': [0.3, 0.98]}

    def test_coordinates_clipped_and_direction_preserved(self):
        boxes = SimpleNamespace(xyxy=Vector([[-5, 10, 100, 90]]), id=Vector([7]))
        result = detection_overlay(boxes, SimpleNamespace(tracks={7: {'direction': 'IN'}}),
                                   self.config, (100, 200, 3), time.monotonic())
        self.assertEqual(result['boxes'], [{'id': 7, 'rect': [0, 0.1, 0.5, 0.9], 'direction': 'IN'}])
        self.assertLess(abs(result['atMs'] - time.time() * 1000), 100)

    def test_empty_scene_does_not_fabricate_people(self):
        result = detection_overlay(None, SimpleNamespace(tracks={}), self.config,
                                   (100, 200, 3), time.monotonic())
        self.assertEqual(result['boxes'], [])
        self.assertEqual(result['line'], [self.config['line_start'], self.config['line_end']])

    def test_payload_is_bounded(self):
        boxes = SimpleNamespace(xyxy=Vector([[0, 0, 20, 40]] * 40), id=Vector(list(range(40))))
        result = detection_overlay(boxes, SimpleNamespace(tracks={}), self.config,
                                   (100, 200, 3), time.monotonic())
        self.assertEqual(len(result['boxes']), 20)


if __name__ == '__main__':
    unittest.main()
