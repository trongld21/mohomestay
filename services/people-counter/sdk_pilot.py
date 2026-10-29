"""Local SDK video trial. Never writes operational entry/exit events."""

import argparse
import json
import os
import threading
import time
from pathlib import Path

os.environ.setdefault('OPENCV_LOG_LEVEL', 'SILENT')
os.environ.setdefault('OPENCV_FFMPEG_LOGLEVEL', '-8')
os.environ.setdefault('OPENCV_FFMPEG_CAPTURE_OPTIONS', 'fflags;discardcorrupt|err_detect;explode')

from window_pilot import bridge_lock, save_atomic
from worker import LatestFrames, annotate, encode_preview, make_counter, send_loop, utc_now
from detection_region import DetectionRegion
from live_session import load_session
from trial_journal import TrialJournal, delivery_settings
from overlay import detection_overlay


def write_heartbeat(path, state):
    record = {'state': state, 'at': utc_now()}
    try:
        save_atomic(path, json.dumps(record).encode())
    except PermissionError:
        # Windows PHP readers may briefly prevent atomic replacement. Retry
        # naturally on the next frame; the existing heartbeat still expires.
        return False
    return True


def run(args):
    import cv2
    import torch
    from ultralytics import YOLO
    from ultralytics.utils import LOGGER

    root = Path(__file__).resolve().parents[2]
    config = json.loads(Path(args.config).read_text(encoding='utf-8'))
    region = DetectionRegion(config.get('detection_roi'), [config['line_start'], config['line_end']])
    weights = Path(config['model'])
    if not weights.is_file():
        raise ValueError('Existing local model weights are required')
    output = root / '.deploy/sdk-pilot'
    private = root / 'storage/people-counter'
    output.mkdir(parents=True, exist_ok=True)
    private.mkdir(parents=True, exist_ok=True)

    def heartbeat(state):
        write_heartbeat(private / 'entrance.source.json', state)

    LOGGER.setLevel('ERROR')
    cv2.setNumThreads(1)
    stop = threading.Event()
    model = YOLO(str(weights))
    session_file = output / 'session.json'
    session = load_session(session_file)
    journal = None
    sender = None
    health = {'status': 'OFFLINE', 'frameAt': None, 'monotonic': 0}
    health_lock = threading.Lock()
    if args.record_trial:
        endpoint, key = delivery_settings(args.env_file)
        journal = TrialJournal(output / 'trial.sqlite', session)
        session = journal.session.copy()
        sender = threading.Thread(target=send_loop,
                                  args=(journal, endpoint, key, config['camera_id'], health, health_lock, stop, True),
                                  daemon=True)
        sender.start()
    totals = {'IN': session['in'], 'OUT': session['out']}
    processed, last_preview = 0, 0
    inference_ms = []
    inference_threads_set = False
    max_frame_gap_ms = 0
    started = time.monotonic()
    capture, frames, reader_stop = None, None, None
    try:
        while not (output / 'stop.request').exists():
            heartbeat('STARTING')
            capture = cv2.VideoCapture(
                f'udp://127.0.0.1:{args.source_port}?buffer_size=4194304&fifo_size=16384&overrun_nonfatal=1&timeout=5000000',
                cv2.CAP_FFMPEG,
                [cv2.CAP_PROP_OPEN_TIMEOUT_MSEC, 20000, cv2.CAP_PROP_READ_TIMEOUT_MSEC, 5000,
                 cv2.CAP_PROP_N_THREADS, 2],
            )
            if not capture.isOpened():
                capture.release()
                heartbeat('CAPTURE_STALLED')
                if args.seconds and time.monotonic() - started >= args.seconds:
                    break
                stop.wait(2)
                continue
            counter = make_counter(config)
            if model.predictor is not None and hasattr(model.predictor, 'trackers'):
                model.predictor.trackers[0].reset()
            fps = capture.get(cv2.CAP_PROP_FPS)
            reader_stop = threading.Event()
            # UDP is already paced by the live producer. Artificial pacing here
            # retains old packets and can overflow the decoder's input queue.
            frames = LatestFrames(capture, reader_stop, fps=fps if 1 <= fps <= 60 else 25, pace=False)
            sequence, previous_at = 0, 0
            warm_until = time.monotonic() + 3
            while not (output / 'stop.request').exists():
                if args.seconds and time.monotonic() - started >= args.seconds:
                    return
                item = frames.next(sequence)
                if item is None:
                    break
                sequence, image, at, received = item
                now = time.monotonic()
                if now < warm_until or now - received > 1.5:
                    continue
                if image.shape[1] > 1280:
                    image = cv2.resize(image, (1280, round(image.shape[0] * 1280 / image.shape[1])),
                                       interpolation=cv2.INTER_AREA)
                if float(image.std()) < 5:
                    continue
                if previous_at and received - previous_at > config['max_gap_seconds']:
                    model.predictor.trackers[0].reset()
                    counter = make_counter(config)
                if previous_at:
                    max_frame_gap_ms = max(max_frame_gap_ms, (received - previous_at) * 1000)
                inference_image, offset = region.crop(image)
                inference_started = time.perf_counter()
                result = model.track(
                    inference_image, persist=True, classes=[0], tracker='bytetrack.yaml',
                    conf=config['confidence'], imgsz=config['image_size'],
                    device=config['device'], verbose=False,
                )[0]
                if not inference_threads_set:
                    # Ultralytics initializes its CPU pool on the first prediction.
                    torch.set_num_threads(4)
                    inference_threads_set = True
                inference_ms.append((time.perf_counter() - inference_started) * 1000)
                if len(inference_ms) > 60:
                    inference_ms.pop(0)
                points = []
                height, width = image.shape[:2]
                boxes = region.restore_boxes(result.boxes, offset, image.shape[:2])
                if boxes is not None and boxes.id is not None:
                    for coords, identity in zip(boxes.xyxy.cpu().tolist(), boxes.id.int().cpu().tolist()):
                        x1, _, x2, y2 = coords
                        points.append((identity, (x1 + x2) / (2 * width), y2 / height))
                crossed = counter.update(points, received)
                if crossed:
                    if journal:
                        session = journal.record(crossed, at)
                        totals = {'IN': session['in'], 'OUT': session['out']}
                    else:
                        for direction in crossed:
                            totals[direction] += 1
                        session.update({'in': totals['IN'], 'out': totals['OUT']})
                    save_atomic(session_file, json.dumps(session).encode())
                with health_lock:
                    health.update(status='RUNNING', frameAt=at, monotonic=received)
                previous_at = received
                processed += 1
                if now - last_preview >= 1 / 3:
                    image = annotate(image, boxes, counter, config, totals)
                    preview = encode_preview(image, at, max_side=1280, quality=85)
                    if preview:
                        preview['session'] = session.copy()
                        preview['overlay'] = detection_overlay(boxes, counter, config, image.shape, received)
                        if args.send_preview:
                            with health_lock:
                                health['preview'] = preview
                        try:
                            save_atomic(private / 'entrance.json', json.dumps(preview).encode())
                        except PermissionError:
                            continue
                        heartbeat('RUNNING')
                        cv2.imwrite(str(output / 'latest.jpg'), image)
                        print(json.dumps({'frames': processed, 'peopleVisible': len(points),
                                          'trialTotals': totals,
                                          'processingFps': round(processed / max(1, now - started), 1),
                                          'maxFrameGapMs': round(max_frame_gap_ms),
                                          'inferenceMs': round(sum(inference_ms) / len(inference_ms), 1)}),
                              flush=True)
                    last_preview = now
            heartbeat('CAPTURE_STALLED')
            reader_stop.set()
            frames.thread.join(timeout=7)
            if frames.thread.is_alive():
                raise RuntimeError('Decoder did not stop within timeout')
            capture.release()
            stop.wait(2)
    finally:
        stop.set()
        if sender:
            sender.join(timeout=21)
        if reader_stop:
            reader_stop.set()
        if frames:
            frames.thread.join(timeout=7)
        if capture:
            capture.release()
        heartbeat('STOPPED')
        summary = {'frames': processed, 'trialTotals': totals, 'operationalEventsSent': 0}
        save_atomic(session_file, json.dumps(session).encode())
        save_atomic(output / 'summary.json', json.dumps(summary).encode())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('--seconds', type=int, default=90, help='0 for continuous local trial')
    parser.add_argument('--source-port', type=int, default=19454, help='Loopback SDK video port')
    parser.add_argument('--record-trial', action='store_true', help='Persist new trial events and deliver signed batches')
    parser.add_argument('--send-preview', action='store_true', help='Upload signed camera previews to the configured website')
    parser.add_argument('--env-file', help='Private delivery settings file; no credentials in arguments')
    args = parser.parse_args()
    if args.send_preview and not args.record_trial:
        parser.error('--send-preview requires --record-trial and private delivery settings')
    if not 0 <= args.seconds <= 3600:
        parser.error('Use 0, or a duration of 1..3600 seconds')
    if not 1024 <= args.source_port <= 65535:
        parser.error('Use a local video port in 1024..65535')
    try:
        with bridge_lock():
            run(args)
    except KeyboardInterrupt:
        pass
    except Exception as error:
        print(json.dumps({'stopped': type(error).__name__}), flush=True)
        raise SystemExit(1) from None


if __name__ == '__main__':
    main()
