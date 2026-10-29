"""Small normalized detection snapshots; no images or inference work."""

import time


def detection_overlay(boxes, counter, config, shape, received):
    height, width = shape[:2]
    detections = []
    if boxes is not None and boxes.id is not None:
        for coords, identity in zip(boxes.xyxy.cpu().tolist(), boxes.id.int().cpu().tolist()):
            x1, y1, x2, y2 = coords
            rect = [round(max(0, min(1, value)), 4) for value in
                    (x1 / width, y1 / height, x2 / width, y2 / height)]
            if rect[0] >= rect[2] or rect[1] >= rect[3]:
                continue
            direction = counter.tracks.get(identity, {}).get('direction')
            detections.append({'id': int(identity), 'rect': rect,
                               'direction': direction if direction in ('IN', 'OUT') else None})
            if len(detections) == 20:
                break
    return {'atMs': round((time.time() - time.monotonic() + received) * 1000),
            'line': [config['line_start'], config['line_end']], 'boxes': detections}
