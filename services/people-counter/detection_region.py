"""Camera-specific inference region; coordinates remain relative to the full frame."""

import math


class DetectionRegion:
    def __init__(self, bounds=None, line=None):
        bounds = [0, 0, 1, 1] if bounds is None else bounds
        if not isinstance(bounds, (list, tuple)) or len(bounds) != 4:
            raise ValueError('Detection region requires four normalized coordinates')
        if any(isinstance(v, bool) or not isinstance(v, (int, float))
               or not math.isfinite(v) or not 0 <= v <= 1 for v in bounds):
            raise ValueError('Detection region coordinates must be finite and in 0..1')
        self.left, self.top, self.right, self.bottom = bounds
        if self.left >= self.right or self.top >= self.bottom:
            raise ValueError('Detection region must have positive area')
        for x, y in line or []:
            if not self.left <= x <= self.right or not self.top <= y <= self.bottom:
                raise ValueError('Counting line must stay inside the detection region')

    def crop(self, image):
        height, width = image.shape[:2]
        left, top = int(self.left * width), int(self.top * height)
        right, bottom = int(self.right * width), int(self.bottom * height)
        if right - left < 8 or bottom - top < 8:
            raise ValueError('Detection region is too small at this resolution')
        return image[top:bottom, left:right], (left, top)

    def restore_boxes(self, boxes, offset, full_shape):
        if boxes is None:
            return None
        # Preserve the tracker ID, confidence and class columns. Clone so the
        # tracker's internal crop coordinates are not changed by annotation.
        data = boxes.data.clone()
        left, top = offset
        data[:, [0, 2]] += left
        data[:, [1, 3]] += top
        return type(boxes)(data, full_shape)
