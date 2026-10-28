"""Count a recorded local video and export reviewable reports. No camera or API access."""

import argparse
import csv
import hashlib
import json
import math
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

from detection_region import DetectionRegion
from worker import annotate, make_counter

VIETNAM = timezone(timedelta(hours=7))


def parse_start(value):
    if not value:
        return None
    result = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if result.tzinfo is None:
        result = result.replace(tzinfo=VIETNAM)
    return result.astimezone(VIETNAM)


def media_time(seconds):
    millis = round(seconds * 1000)
    hours, millis = divmod(millis, 3600000)
    minutes, millis = divmod(millis, 60000)
    secs, millis = divmod(millis, 1000)
    return f'{hours:02}:{minutes:02}:{secs:02}.{millis:03}'


def file_digest(path):
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1048576), b''):
            digest.update(chunk)
    return digest.hexdigest()


def run_video(config, video, output, start=None, expected_in=None, expected_out=None,
              with_preview=True, model_factory=None):
    import cv2

    video, output = Path(video).resolve(), Path(output).resolve()
    if output.exists():
        raise FileExistsError('Choose a new report directory; existing reports are never overwritten')
    if not video.is_file() or video.suffix.lower() not in {'.mp4', '.avi', '.mkv', '.mov', '.m4v', '.ts'}:
        raise ValueError('Supply an exported local video, preferably MP4')
    weights = Path(config['model']).resolve()
    if not weights.is_file():
        raise ValueError('Existing local YOLO weights are required; no automatic downloads')
    started_at = parse_start(start)
    expected = {'IN': expected_in, 'OUT': expected_out}
    if any(value is not None and (type(value) is not int or value < 0) for value in expected.values()):
        raise ValueError('Operational reference counts must be nonnegative integers')
    capture = cv2.VideoCapture(str(video), cv2.CAP_FFMPEG)
    if not capture.isOpened():
        capture.release()
        raise ValueError('Video cannot be decoded; export an MP4 from Imou before processing')
    writer = None
    totals = {'IN': 0, 'OUT': 0}
    processed, previous_at, event_count = 0, None, 0
    timestamp_origin = None
    original_stat = video.stat()
    try:
        fps = capture.get(cv2.CAP_PROP_FPS)
        if not math.isfinite(fps) or not 1 <= fps <= 120:
            raise ValueError('Video has no usable frame rate; export a standard MP4')
        declared_frames = capture.get(cv2.CAP_PROP_FRAME_COUNT)
        declared_frames = round(declared_frames) if math.isfinite(declared_frames) and declared_frames > 0 else None
        counter = make_counter(config)
        region = DetectionRegion(config.get('detection_roi'), [config['line_start'], config['line_end']])
        if model_factory is None:
            from ultralytics import YOLO
            from ultralytics.utils import LOGGER
            LOGGER.setLevel('ERROR')
            model_factory = YOLO
        model = model_factory(str(weights))
        digest = file_digest(video)
        output.mkdir(parents=True, exist_ok=False)
        with (output / 'events.csv.tmp').open('w', encoding='utf-8-sig', newline='') as stream:
            events = csv.writer(stream)
            events.writerow(['event', 'direction', 'video_seconds', 'video_time', 'camera_time', 'total_in', 'total_out'])
            while True:
                ok, image = capture.read()
                if not ok:
                    break
                position = capture.get(cv2.CAP_PROP_POS_MSEC) / 1000
                fallback = processed / fps
                if math.isfinite(position) and position >= 0:
                    if timestamp_origin is None:
                        timestamp_origin = position
                    position -= timestamp_origin
                if not math.isfinite(position) or position < 0 or (previous_at is not None and position <= previous_at):
                    position = max(fallback, (previous_at + 1 / fps) if previous_at is not None else 0)
                if previous_at is not None and position - previous_at > config['max_gap_seconds']:
                    if model.predictor is not None and hasattr(model.predictor, 'trackers'):
                        model.predictor.trackers[0].reset()
                    counter = make_counter(config)
                if image.shape[1] > 1280:
                    image = cv2.resize(image, (1280, round(image.shape[0] * 1280 / image.shape[1])),
                                       interpolation=cv2.INTER_AREA)
                inference, offset = region.crop(image)
                result = model.track(inference, persist=True, classes=[0], tracker='bytetrack.yaml',
                                     conf=config['confidence'], imgsz=config['image_size'],
                                     device=config['device'], verbose=False)[0]
                boxes = region.restore_boxes(result.boxes, offset, image.shape[:2])
                points = []
                height, width = image.shape[:2]
                if boxes is not None and boxes.id is not None:
                    for coords, identity in zip(boxes.xyxy.cpu().tolist(), boxes.id.int().cpu().tolist()):
                        x1, _, x2, y2 = coords
                        points.append((identity, (x1 + x2) / (2 * width), y2 / height))
                for direction in counter.update(points, position):
                    totals[direction] += 1
                    event_count += 1
                    camera_time = (started_at + timedelta(seconds=position)).isoformat() if started_at else ''
                    events.writerow([event_count, direction, round(position, 3), media_time(position), camera_time,
                                     totals['IN'], totals['OUT']])
                if with_preview:
                    if writer is None:
                        writer = cv2.VideoWriter(str(output / 'annotated.mp4'), cv2.VideoWriter_fourcc(*'mp4v'),
                                                 fps, (width, height))
                        if not writer.isOpened():
                            raise RuntimeError('Cannot write annotated video')
                    annotated = annotate(image, boxes, counter, config, totals)
                    cv2.putText(annotated, f'VIDEO {media_time(position)} / REVIEW', (20, 70),
                                cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 220, 255), 2)
                    writer.write(annotated)
                processed += 1
                previous_at = position
                if processed % 300 == 0:
                    print(json.dumps({'processedFrames': processed, 'videoTime': media_time(position),
                                      'totals': totals}), flush=True)
    finally:
        capture.release()
        if writer:
            writer.release()
    if processed == 0:
        raise ValueError('No decoded frames; no zero-count report was generated')
    changed = (video.stat().st_size, video.stat().st_mtime_ns) != (original_stat.st_size, original_stat.st_mtime_ns)
    complete = not changed and (declared_frames is None or processed >= declared_frames - 2)
    report = {'status': 'complete' if complete else 'incomplete', 'sourceFile': video.name,
              'sourceSha256': digest, 'cameraId': config.get('camera_id', 'entrance'),
              'cameraStart': started_at.isoformat() if started_at else None,
              'processedFrames': processed, 'declaredFrames': declared_frames,
              'videoSeconds': round((previous_at or 0) + 1 / fps, 3), 'totals': totals,
              'expected': expected,
              'differences': {key: totals[key] - expected[key] if expected[key] is not None else None for key in totals},
              'calibrationVerified': config.get('calibrated') is True, 'sourceChanged': changed,
              'operationalEventsSent': 0}
    (output / 'events.csv.tmp').replace(output / 'events.csv')
    (output / 'report.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    lines = ['BÁO CÁO ĐẾM TỪ VIDEO', f'Video: {video.name}', f'Trạng thái xử lý: {report["status"]}',
             f'Lượt vào: {totals["IN"]}', f'Lượt ra: {totals["OUT"]}',
             f'Tổng lượt: {totals["IN"] + totals["OUT"]}',
             f'Thời lượng đã xử lý: {media_time(report["videoSeconds"])}',
             f'Đã xác minh vạch đếm: {"Có" if report["calibrationVerified"] else "Chưa"}',
             f'Giờ bắt đầu camera: {report["cameraStart"] or "Chưa nhập; xem mốc phút/giây trong events.csv"}']
    for direction, label in [('IN', 'vào'), ('OUT', 'ra')]:
        if expected[direction] is not None:
            lines.append(f'Vận hành thực tế lượt {label}: {expected[direction]}; AI trừ thực tế: {report["differences"][direction]}')
    lines.append('Báo cáo để kiểm tra lại; không tự cộng vào dữ liệu vận hành trên web.')
    (output / 'summary.txt').write_text('\n'.join(lines) + '\n', encoding='utf-8')
    return report


def main():
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--video')
    parser.add_argument('--interactive', action='store_true', help='Prompt for a video and optional reference counts')
    parser.add_argument('--config', default=str(Path(__file__).with_name('config.offline.example.json')))
    parser.add_argument('--output', help='New local report directory')
    parser.add_argument('--start', help='Recording start, e.g. 2026-10-28T19:00:00+07:00')
    parser.add_argument('--expected-in', type=int)
    parser.add_argument('--expected-out', type=int)
    parser.add_argument('--no-preview', action='store_true')
    args = parser.parse_args()
    if not args.interactive and not args.video:
        parser.error('Supply --video, or use --interactive')
    if not args.output:
        import uuid
        args.output = root / 'storage/people-counter/offline' / uuid.uuid4().hex
    try:
        if args.interactive:
            print('ĐẾM LƯỢT VÀO / RA TỪ VIDEO ĐÃ GHI')
            args.video = input('Dán đường dẫn file MP4 (có thể kéo file vào cửa sổ này): ').strip().strip('"')
            args.start = input('Giờ bắt đầu video, ví dụ 2026-10-28T19:00:00 (bỏ trống nếu chưa biết): ').strip() or None
            reference_in = input('Số lượt vào thực tế để đối soát (bỏ trống nếu chưa có): ').strip()
            reference_out = input('Số lượt ra thực tế để đối soát (bỏ trống nếu chưa có): ').strip()
            args.expected_in = int(reference_in) if reference_in else None
            args.expected_out = int(reference_out) if reference_out else None
        config = json.loads(Path(args.config).read_text(encoding='utf-8-sig'))
        report = run_video(config, args.video, args.output, args.start, args.expected_in, args.expected_out,
                           with_preview=not args.no_preview)
        print(json.dumps({'reportDirectory': str(Path(args.output).resolve()), **report}, ensure_ascii=False))
        if args.interactive:
            print(f'Đã xuất báo cáo: {Path(args.output).resolve()}')
            if sys.platform == 'win32':
                import os
                os.startfile(str(Path(args.output).resolve()))
        return 0 if report['status'] == 'complete' else 2
    except KeyboardInterrupt:
        print('Đã dừng. Báo cáo chưa hoàn tất không được coi là kết quả cuối.', file=sys.stderr)
        return 1
    except Exception as error:
        print(f'Không xử lý được video ({type(error).__name__}). Kiểm tra file MP4, cấu hình và thư mục kết quả mới.',
              file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
