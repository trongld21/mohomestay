"""Count stable track footpoints crossing a finite directed line (normalized XY)."""

import math


class LineCounter:
    def __init__(self, start, end, inside_side=1, margin=0.025, stable_frames=2, max_gap=1.5):
        if any(not math.isfinite(v) or not 0 <= v <= 1 for v in (*start, *end)):
            raise ValueError('Line coordinates must be between zero and one')
        self.start = start
        self.dx, self.dy = end[0] - start[0], end[1] - start[1]
        self.length = math.hypot(self.dx, self.dy)
        if self.length < 0.05 or inside_side not in (-1, 1) or not 0 < margin < 0.2 or stable_frames < 1 or max_gap <= 0:
            raise ValueError('Invalid line counter configuration')
        self.inside_side = inside_side
        self.margin = margin
        self.stable_frames = stable_frames
        self.max_gap = max_gap
        self.tracks = {}

    def distance(self, point):
        return (self.dx * (point[1] - self.start[1]) - self.dy * (point[0] - self.start[0])) / self.length

    def crosses_segment(self, before, after):
        d1, d2 = self.distance(before), self.distance(after)
        ratio = d1 / (d1 - d2)
        x = before[0] + ratio * (after[0] - before[0]) - self.start[0]
        y = before[1] + ratio * (after[1] - before[1]) - self.start[1]
        projection = (x * self.dx + y * self.dy) / self.length ** 2
        return 0 <= projection <= 1

    def update(self, detections, now):
        self.tracks = {key: value for key, value in self.tracks.items() if 0 <= now - value['seen'] <= self.max_gap}
        events = []
        for track_id, x, y in detections:
            if not all(math.isfinite(value) and 0 <= value <= 1 for value in (x, y)):
                continue
            point = (x, y)
            track = self.tracks.setdefault(track_id, {'seen': now, 'side': 0, 'candidate': 0, 'frames': 0, 'point': point})
            track['seen'] = now
            distance = self.distance(point)
            side = 1 if distance > self.margin else -1 if distance < -self.margin else 0
            if side == 0:
                track['candidate'], track['frames'] = 0, 0
                continue
            if side == track['side']:
                track['point'] = point
                track['candidate'], track['frames'] = 0, 0
                continue
            if track['candidate'] != side:
                track['candidate'], track['frames'] = side, 1
            else:
                track['frames'] += 1
            if track['frames'] < self.stable_frames:
                continue
            if track['side'] and self.crosses_segment(track['point'], point):
                direction = 'IN' if side == self.inside_side else 'OUT'
                track['direction'] = direction
                events.append(direction)
            track.update(side=side, candidate=0, frames=0, point=point)
        return events
