"""Windows Imou capture bridge. Trial counting never sends operational events."""

import argparse
import hashlib
import json
import math
import os
import threading
import time
from contextlib import contextmanager
from pathlib import Path

# Keep optional model-library settings inside this workspace during the trial.
os.environ.setdefault('YOLO_CONFIG_DIR', str(Path(__file__).resolve().parents[2] / '.deploy/window-pilot/ultralytics'))


def crop_video(image, expected_size, roi):
    height, width = image.shape[:2]
    if (width, height) != tuple(expected_size):
        raise ValueError('Window resized; select and verify the video region again')
    if len(roi) != 4 or any(not math.isfinite(v) or not 0 <= v <= 1 for v in roi):
        raise ValueError('Invalid normalized video region')
    left, top, right, bottom = roi
    if left >= right or top >= bottom:
        raise ValueError('Empty video region')
    x1, y1, x2, y2 = int(left * width), int(top * height), int(right * width), int(bottom * height)
    if x2 - x1 < 8 or y2 - y1 < 8:
        raise ValueError('Video region too small')
    # Capture buffers belong to the native callback; retain an independent BGR copy.
    return image[y1:y2, x1:x2, :3].copy()


class FrameMailbox:
    def __init__(self):
        self.lock = threading.Lock()
        self.latest = None
        self.sequence = 0
        self.digest = None
        self.changed_at = 0
        self.blocked = 'STARTING'

    def publish(self, image, at):
        digest = hashlib.sha256(image.tobytes()).digest()
        with self.lock:
            if digest != self.digest:
                self.changed_at, self.digest = at, digest
            self.sequence += 1
            self.latest = (self.sequence, image.copy(), at)
            self.blocked = None

    def invalidate(self, state):
        with self.lock:
            self.latest, self.digest, self.blocked = None, None, state

    def state(self, now):
        with self.lock:
            if self.blocked:
                return self.blocked
            if self.latest is None or now - self.latest[2] > 2 or now - self.changed_at > 10:
                return 'CAPTURE_STALLED'
            return 'RUNNING'

    def next(self, previous, now):
        with self.lock:
            if self.latest is None or self.sequence <= previous:
                return None
            if now - self.latest[2] > 2 or now - self.changed_at > 10:
                return None
            return self.latest


def save_atomic(path, data):
    temporary = path.with_name(path.name + '.tmp')
    temporary.write_bytes(data)
    os.replace(temporary, path)


def verify_imou_window(handle):
    import ctypes
    from ctypes import wintypes

    user = ctypes.WinDLL('user32', use_last_error=True)
    kernel = ctypes.WinDLL('kernel32', use_last_error=True)
    user.IsWindow.argtypes = [wintypes.HWND]
    user.IsIconic.argtypes = [wintypes.HWND]
    user.GetWindowThreadProcessId.argtypes = [wintypes.HWND, ctypes.POINTER(wintypes.DWORD)]
    if not user.IsWindow(handle):
        raise ValueError('The selected Imou window is closed')
    process_id = wintypes.DWORD()
    user.GetWindowThreadProcessId(handle, ctypes.byref(process_id))
    kernel.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
    kernel.OpenProcess.restype = wintypes.HANDLE
    kernel.QueryFullProcessImageNameW.argtypes = [wintypes.HANDLE, wintypes.DWORD, wintypes.LPWSTR,
                                                 ctypes.POINTER(wintypes.DWORD)]
    kernel.CloseHandle.argtypes = [wintypes.HANDLE]
    process = kernel.OpenProcess(0x1000, False, process_id.value)
    if not process:
        raise ValueError('Cannot verify the selected capture application')
    try:
        path, length = ctypes.create_unicode_buffer(32768), wintypes.DWORD(32768)
        if not kernel.QueryFullProcessImageNameW(process, 0, path, ctypes.byref(length)):
            raise ValueError('Cannot verify the selected capture application')
        if Path(path.value).name.lower() != 'imou_en.exe':
            raise ValueError('Capture target must belong to Imou PC')
    finally:
        kernel.CloseHandle(process)
    return 'MINIMIZED' if user.IsIconic(handle) else 'RUNNING'


@contextmanager
def bridge_lock():
    import msvcrt

    path = Path(__file__).resolve().parents[2] / '.deploy/window-pilot/bridge.lock'
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('a+b') as handle:
        if handle.tell() == 0:
            handle.write(b'0')
            handle.flush()
        handle.seek(0)
        msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
        try:
            yield
        finally:
            handle.seek(0)
            msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)


def run(args):
    import cv2
    from ultralytics import YOLO
    from ultralytics.utils import LOGGER
    from windows_capture import WindowsCapture
    from worker import annotate, encode_preview, make_counter, utc_now

    if os.name != 'nt':
        raise ValueError('Windows is required for this capture trial')
    if not 0 <= args.seconds <= 3600 or args.window_hwnd <= 0:
        raise ValueError('Use seconds 0 for continuous capture, or 1..3600 for a bounded trial')
    window_state = verify_imou_window(args.window_hwnd)
    config = json.loads(Path(args.config).read_text(encoding='utf-8'))
    weights = Path(config['model']).resolve()
    if not weights.is_file():
        raise ValueError('Supply existing local model weights; no automatic model downloads')
    output = Path(args.output).resolve()
    output.mkdir(parents=True, exist_ok=True)
    mailbox, closed = FrameMailbox(), threading.Event()
    failures = []
    expected = tuple(args.window_size)
    capture = WindowsCapture(cursor_capture=False, draw_border=True,
                             minimum_update_interval=100, window_hwnd=args.window_hwnd)

    @capture.event
    def on_frame_arrived(frame, control):
        if (frame.width, frame.height) != expected:
            mailbox.invalidate('LAYOUT_CHANGED')
            return
        try:
            mailbox.publish(crop_video(frame.frame_buffer, expected, args.roi), time.monotonic())
        except Exception as error:
            failures.append(type(error).__name__)
            print(json.dumps({'captureStopped': type(error).__name__, 'windowSize': [frame.width, frame.height]}), flush=True)
            closed.set()
            control.stop()

    @capture.event
    def on_closed():
        mailbox.invalidate('WINDOW_CLOSED')
        closed.set()

    LOGGER.setLevel('ERROR')
    model, counter = YOLO(str(weights)), make_counter(config)
    totals = {'IN': 0, 'OUT': 0}
    sequence, processed, detected, resets = 0, 0, 0, 0
    started = time.monotonic()
    last_processed, last_preview = started, 0
    last_verified, evidence_score = started, 0
    last_heartbeat, last_log, previous_state = 0, 0, None
    controller = capture.start_free_threaded()
    local_preview = None
    if args.publish_local_preview:
        # Fixed local target, protected by the existing authenticated PHP preview API.
        directory = Path(__file__).resolve().parents[2] / 'storage/people-counter'
        directory.mkdir(parents=True, exist_ok=True)
        local_preview = directory / 'entrance.json'

    def source_heartbeat(state):
        data = json.dumps({'state': state, 'at': utc_now()}).encode('utf-8')
        save_atomic(output / 'source.json', data)
        if local_preview:
            try:
                save_atomic(local_preview.with_name('entrance.source.json'), data)
            except PermissionError:
                pass

    def reset_tracking():
        if model.predictor is not None and hasattr(model.predictor, 'trackers'):
            model.predictor.trackers[0].reset()

    source_heartbeat('STARTING')
    try:
        while (args.seconds == 0 or time.monotonic() - started < args.seconds) and not closed.is_set():
            if (output / 'stop.request').is_file():
                break
            now = time.monotonic()
            if now - last_verified >= 1:
                try:
                    window_state = verify_imou_window(args.window_hwnd)
                except ValueError:
                    mailbox.invalidate('WINDOW_CLOSED')
                    break
                last_verified = now
            state = 'MINIMIZED' if window_state == 'MINIMIZED' else mailbox.state(now)
            if state != previous_state:
                if state == 'MINIMIZED':
                    mailbox.invalidate('MINIMIZED')
                if previous_state == 'RUNNING' or state == 'RUNNING':
                    reset_tracking()
                    counter = make_counter(config)
                    resets += 1
                print(json.dumps({'sourceState': state}), flush=True)
            if state != previous_state or now - last_heartbeat >= 1:
                source_heartbeat(state)
                last_heartbeat = now
            previous_state = state
            if state != 'RUNNING':
                closed.wait(0.1)
                continue
            item = mailbox.next(sequence, now)
            if item is None:
                if now - last_processed > 2 and counter.tracks:
                    reset_tracking()
                    counter = make_counter(config)
                    resets += 1
                closed.wait(0.05)
                continue
            sequence, image, captured_at = item
            # Reset both identity tracking and line state after a capture gap.
            if captured_at - last_processed > 1.5:
                reset_tracking()
                counter = make_counter(config)
                resets += 1
            result = model.track(image, persist=True, classes=[0], tracker='bytetrack.yaml',
                                 conf=config['confidence'], imgsz=config['image_size'],
                                 device=config['device'], verbose=False)[0]
            boxes = result.boxes
            points = []
            height, width = image.shape[:2]
            if boxes is not None and boxes.id is not None:
                for coords, identity in zip(boxes.xyxy.cpu().tolist(), boxes.id.int().cpu().tolist()):
                    x1, _, x2, y2 = coords
                    points.append((identity, (x1 + x2) / (2 * width), y2 / height))
            crossed = counter.update(points, captured_at)
            for direction in crossed:
                totals[direction] += 1
            detected = max(detected, len(points))
            processed += 1
            last_processed = captured_at
            if now - last_preview >= 1 or crossed:
                image = annotate(image, boxes, counter, config, totals)
                cv2.putText(image, 'PILOT / TEST LINE / NO OPERATIONAL EVENTS', (20, 65),
                            cv2.FONT_HERSHEY_SIMPLEX, 0.65, (0, 220, 255), 2)
                ok, jpeg = cv2.imencode('.jpg', image, [cv2.IMWRITE_JPEG_QUALITY, 80])
                if ok:
                    save_atomic(output / 'latest.jpg', jpeg.tobytes())
                    if crossed:
                        save_atomic(output / 'crossing.jpg', jpeg.tobytes())
                    if len(points) > evidence_score:
                        save_atomic(output / 'detection.jpg', jpeg.tobytes())
                        evidence_score = len(points)
                preview = encode_preview(image, utc_now())
                if preview is not None:
                    data = json.dumps(preview).encode('utf-8')
                    save_atomic(output / 'preview.json', data)
                    if local_preview:
                        try:
                            save_atomic(local_preview, data)
                        except PermissionError:
                            pass  # A PHP reader can briefly hold the old file on Windows.
                last_preview = now
                if now - last_log >= 10 or crossed:
                    print(json.dumps({'frames': processed, 'peopleVisible': len(points), 'trialTotals': totals}), flush=True)
                    last_log = now
    finally:
        controller.stop()
        source_heartbeat('WINDOW_CLOSED' if mailbox.state(time.monotonic()) == 'WINDOW_CLOSED' else 'STOPPED')
    summary = {'mode': 'windows-pilot', 'operationalEventsSent': 0, 'frames': processed,
               'maxPeopleVisible': detected, 'trialTotals': totals, 'trackingResets': resets,
               'seconds': round(time.monotonic() - started, 1), 'captureErrors': failures}
    save_atomic(output / 'summary.json', json.dumps(summary, indent=2).encode('utf-8'))
    print(json.dumps(summary), flush=True)
    if not processed or failures:
        raise RuntimeError('No valid trial completed; verify window size and capture support')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('--window-hwnd', required=True, type=int)
    parser.add_argument('--window-size', required=True, type=int, nargs=2, metavar=('WIDTH', 'HEIGHT'))
    parser.add_argument('--roi', required=True, type=float, nargs=4, metavar=('LEFT', 'TOP', 'RIGHT', 'BOTTOM'))
    parser.add_argument('--seconds', type=int, default=120)
    parser.add_argument('--output', default='.deploy/window-pilot')
    parser.add_argument('--publish-local-preview', action='store_true')
    args = parser.parse_args()
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
