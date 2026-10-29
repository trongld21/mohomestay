"""One Linux worker per camera. Video URLs and signing keys come from environment."""

import argparse
import json
import logging
import os
import re
import signal
import threading
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit

from counter import LineCounter
from delivery import Outbox, signed_request

os.environ.setdefault('OPENCV_LOG_LEVEL', 'SILENT')
os.environ.setdefault('OPENCV_FFMPEG_LOGLEVEL', '-8')
LOG = logging.getLogger('people-counter')


def utc_now():
    return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def make_counter(config):
    return LineCounter(tuple(config['line_start']), tuple(config['line_end']),
                       inside_side=config['inside_side'], margin=config['margin'],
                       stable_frames=config['stable_frames'], max_gap=config['max_gap_seconds'])


class LatestFrames:
    """Drain live capture continuously; never let slow inference build a frame backlog."""

    def __init__(self, capture, stop, fps=25, pace=True):
        self.capture = capture
        self.stop = stop
        self.condition = threading.Condition()
        self.latest = None
        self.sequence = 0
        self.done = False
        self.interval = 1 / fps if pace else 0
        self.thread = threading.Thread(target=self.read, daemon=True)
        self.thread.start()

    def read(self):
        try:
            next_frame = time.monotonic()
            while not self.stop.is_set():
                # HLS delivers segments in bursts. Pace decoding to the source FPS
                # so a whole segment is not discarded between inference calls.
                if self.stop.wait(max(0, next_frame - time.monotonic())):
                    break
                read_started = time.monotonic()
                ok, frame = self.capture.read()
                if not ok:
                    break
                next_frame = read_started + self.interval
                with self.condition:
                    self.sequence += 1
                    self.latest = (self.sequence, frame, utc_now(), time.monotonic())
                    self.condition.notify_all()
        finally:
            with self.condition:
                self.done = True
                self.condition.notify_all()

    def next(self, previous):
        with self.condition:
            self.condition.wait_for(lambda: self.done or self.sequence > previous or self.stop.is_set(), timeout=12)
            if self.done or self.stop.is_set() or self.sequence <= previous:
                return None
            return self.latest


def send_loop(queue, endpoint, key, camera_id, health, lock, stop, trial=False):
    import requests

    delay = 1
    while not stop.is_set():
        try:
            events = queue.pending()
            with lock:
                status, frame_at, last_frame = health['status'], health['frameAt'], health['monotonic']
                preview = health.get('preview')
            if status == 'RUNNING' and time.monotonic() - last_frame > 30:
                status = 'OFFLINE'
            payload = {'cameraId': camera_id, 'status': status, 'frameAt': frame_at, 'events': events}
            if trial:
                payload['trial'] = True
            if status == 'RUNNING' and preview is not None and time.monotonic() - last_frame <= 20:
                payload['preview'] = preview
            body, headers = signed_request(payload, key, int(time.time()))
            response = requests.post(endpoint, data=body, headers=headers, timeout=(5, 15), allow_redirects=False)
            # Never acknowledge an HTML login page, redirect, or partial/invalid receipt.
            receipt = response.json() if response.status_code == 200 else {}
            if response.status_code != 200 or type(receipt.get('accepted')) is not int or receipt['accepted'] != len(events):
                LOG.warning('Delivery rejected (HTTP %s); queue retained', response.status_code)
                delay = min(60, delay * 2)
            else:
                queue.ack(events)
                delay = 0.2 if events else 1 if 'preview' in payload else 2 if trial else 10
        except Exception as error:
            # Exception text may contain a video URL or credentials. Log only its type.
            LOG.warning('Delivery unavailable (%s); queue retained', type(error).__name__)
            delay = min(60, max(2, delay * 2))
        stop.wait(delay)


def annotate(frame, boxes, counter, config, totals):
    import cv2

    image = frame.copy()
    height, width = image.shape[:2]
    if boxes is not None and boxes.id is not None:
        for coords, identity in zip(boxes.xyxy.cpu().tolist(), boxes.id.int().cpu().tolist()):
            x1, y1, x2, y2 = [int(value) for value in coords]
            direction = counter.tracks.get(identity, {}).get('direction')
            color = (70, 200, 70) if direction == 'IN' else (60, 60, 240) if direction == 'OUT' else (0, 200, 255)
            label = f"#{identity} {direction or 'TRACKING'}"
            cv2.rectangle(image, (x1, y1), (x2, y2), color, 2)
            cv2.putText(image, label, (x1, max(20, y1 - 8)), cv2.FONT_HERSHEY_SIMPLEX, 0.6, color, 2)
    start = tuple(int(v * dimension) for v, dimension in zip(config['line_start'], (width, height)))
    end = tuple(int(v * dimension) for v, dimension in zip(config['line_end'], (width, height)))
    cv2.line(image, start, end, (0, 200, 255), 2)
    return image


def encode_preview(image, at, max_side=960, quality=65):
    import base64
    import cv2

    height, width = image.shape[:2]
    if not 1 <= max_side <= 1280 or not 55 <= quality <= 95:
        raise ValueError('Preview dimensions or JPEG quality are outside supported limits')
    # Preserve detail first; only reduce quality/size when PHP's payload limit
    # requires it. Resize once from the source, never from a previous JPEG.
    sizes = sorted({max_side, min(max_side, 960), min(max_side, 720)}, reverse=True)
    for size in sizes:
        ratio = min(1, size / max(height, width))
        resized = image if ratio == 1 else cv2.resize(
            image, (max(1, int(width * ratio)), max(1, int(height * ratio))),
            interpolation=cv2.INTER_AREA,
        )
        for jpeg_quality in range(quality, 54, -10):
            ok, jpeg = cv2.imencode('.jpg', resized, [cv2.IMWRITE_JPEG_QUALITY, jpeg_quality])
            if ok and len(jpeg) <= 130000:
                return {'frameAt': at, 'jpeg': base64.b64encode(jpeg.tobytes()).decode('ascii')}
    return None


def run(config, video=None, preview=None):
    import cv2
    from ultralytics import YOLO
    from ultralytics.utils import LOGGER

    LOGGER.setLevel(logging.ERROR)
    if not video and config.get('calibrated') is not True:
        raise ValueError('Calibrate the counting line with a test video before enabling live mode')
    source = video or os.environ.get('COUNTER_STREAM_URL', '')
    if not video and urlsplit(source).scheme not in ('rtsp', 'rtsps', 'rtmp', 'rtmps', 'https'):
        raise ValueError('Set COUNTER_STREAM_URL to an authorized live stream')
    endpoint = os.environ.get('COUNTER_ENDPOINT', '')
    key = os.environ.get('PEOPLE_COUNTER_KEY', '')
    if not video and (urlsplit(endpoint).scheme != 'https' or len(key) < 32):
        raise ValueError('Live mode requires HTTPS endpoint and a signing key of at least 32 characters')
    if not re.fullmatch('[a-z0-9_-]{1,64}', config['camera_id']):
        raise ValueError('Invalid camera ID')
    counter = make_counter(config)
    state = Path(config['state_directory']).resolve()
    state.mkdir(parents=True, exist_ok=True)
    lock_file = None
    if not video:
        # Live deployment is Linux; offline calibration also works on Windows.
        import fcntl
        lock_file = (state / (config['camera_id'] + '.lock')).open('a')
        fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
    stop = threading.Event()
    signal.signal(signal.SIGTERM, lambda *_: stop.set())
    signal.signal(signal.SIGINT, lambda *_: stop.set())
    health = {'status': 'OFFLINE', 'frameAt': None, 'monotonic': 0}
    lock = threading.Lock()
    sender = None
    queue = None
    if not video:
        queue = Outbox(state / (config['camera_id'] + '.sqlite'))
        sender = threading.Thread(target=send_loop, args=(queue, endpoint, key, config['camera_id'], health, lock, stop), daemon=True)
        sender.start()
    totals = {'IN': 0, 'OUT': 0}
    writer = None
    capture = None
    live_frames = None
    last_preview = 0
    try:
        while not stop.is_set():
            # A fresh model also resets ByteTrack IDs after every stream discontinuity.
            model = YOLO(config['model'])
            counter = make_counter(config)
            capture = cv2.VideoCapture(source, cv2.CAP_FFMPEG, [
                cv2.CAP_PROP_OPEN_TIMEOUT_MSEC, 10000,
                cv2.CAP_PROP_READ_TIMEOUT_MSEC, 10000,
            ])
            if not capture.isOpened():
                if video:
                    raise RuntimeError('Cannot open test video')
                LOG.warning('Video unavailable; retrying in 10 seconds')
                capture.release()
                stop.wait(10)
                continue
            fps = capture.get(cv2.CAP_PROP_FPS)
            fps = fps if 1 <= fps <= 120 else 25
            frame_index = 0
            live_frames = LatestFrames(capture, stop, fps) if not video else None
            while not stop.is_set():
                if live_frames:
                    captured = live_frames.next(frame_index)
                    if captured is None:
                        break
                    frame_index, frame, at, captured_at = captured
                else:
                    ok, frame = capture.read()
                    if not ok:
                        break
                    frame_index += 1
                    at, captured_at = utc_now(), frame_index / fps
                height, width = frame.shape[:2]
                results = model.track(frame, persist=True, classes=[0], tracker='bytetrack.yaml',
                                      conf=config['confidence'], imgsz=config['image_size'], device=config['device'], verbose=False)
                boxes = results[0].boxes
                detections = []
                if boxes is not None and boxes.id is not None:
                    for coordinates, identity in zip(boxes.xyxy.cpu().tolist(), boxes.id.int().cpu().tolist()):
                        x1, _, x2, y2 = coordinates
                        detections.append((identity, (x1 + x2) / (2 * width), y2 / height))
                crossed = counter.update(detections, captured_at)
                for direction in crossed:
                    if queue:
                        queue.add(direction, at)
                    totals[direction] += 1
                with lock:
                    health.update(status='RUNNING', frameAt=at, monotonic=captured_at)
                live_preview = not video and config.get('send_preview', True) and time.monotonic() - last_preview >= 1
                if preview or live_preview:
                    annotated = annotate(frame, boxes, counter, config, totals)
                if live_preview:
                    encoded = encode_preview(annotated, at)
                    with lock:
                        health['preview'] = encoded
                    last_preview = time.monotonic()
                if preview:
                    if writer is None:
                        writer = cv2.VideoWriter(preview, cv2.VideoWriter_fourcc(*'mp4v'), fps, (width, height))
                        if not writer.isOpened():
                            raise RuntimeError('Cannot write preview video')
                    writer.write(annotated)
            if live_frames:
                live_frames.thread.join(timeout=12)
                if live_frames.thread.is_alive():
                    raise RuntimeError('Capture did not stop within its read timeout')
            capture.release()
            with lock:
                health.update(status='OFFLINE', frameAt=None, preview=None)
            if video:
                print(json.dumps({'mode': 'offline-test', 'totals': totals}))
                break
            LOG.warning('Video interrupted; tracking reset')
            stop.wait(5)
    finally:
        stop.set()
        if live_frames:
            live_frames.thread.join(timeout=12)
        if capture:
            capture.release()
        if writer:
            writer.release()
        if sender:
            sender.join(timeout=25)
        if lock_file:
            lock_file.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', default='config.json')
    parser.add_argument('--video', help='Local test video; never sends counts to the website')
    parser.add_argument('--preview', help='Optional annotated MP4, only with --video')
    args = parser.parse_args()
    if args.preview and not args.video:
        parser.error('--preview requires --video')
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    try:
        with open(args.config, encoding='utf-8') as source:
            config = json.load(source)
        run(config, args.video, args.preview)
    except Exception as error:
        LOG.error('Worker stopped (%s). Check configuration, dependencies and private service logs.', type(error).__name__)
        raise SystemExit(1) from None


if __name__ == '__main__':
    main()
