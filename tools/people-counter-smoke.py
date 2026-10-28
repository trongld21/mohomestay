"""Exercise the real model and offline worker with an explicitly supplied sample image.

This is a synthetic motion test, not a measurement of accuracy on the home camera.
Run with the worker's venv: python tools/people-counter-smoke.py --image ... --weights ...
"""

import argparse
import json
import subprocess
import sys
from pathlib import Path

import cv2
import numpy as np


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', required=True)
    parser.add_argument('--weights', required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    output = root / '.deploy' / 'counter-smoke'
    output.mkdir(parents=True, exist_ok=True)
    sample = cv2.imread(str(Path(args.image).resolve()))
    if sample is None:
        raise ValueError('Sample image cannot be read')
    sample = cv2.resize(sample, (240, 320))
    video_path = output / 'synthetic-motion.mp4'
    writer = cv2.VideoWriter(str(video_path), cv2.VideoWriter_fourcc(*'mp4v'), 12, (640, 640))
    if not writer.isOpened():
        raise RuntimeError('Cannot write test clip')
    try:
        positions = [0] * 10 + list(np.linspace(0, 220, 30)) + [220] * 10 + list(np.linspace(220, 0, 30)) + [0] * 10
        for position in positions:
            frame = np.full((640, 640, 3), 100, dtype=np.uint8)
            y = int(position)
            frame[y:y + 320, 200:440] = sample
            writer.write(frame)
    finally:
        writer.release()
    source = root / 'services' / 'people-counter'
    config = json.loads((source / 'config.example.json').read_text(encoding='utf-8'))
    config.update(line_start=[0.1, 0.6], line_end=[0.9, 0.6], model=str(Path(args.weights).resolve()), state_directory=str(output / 'state'))
    config_path = output / 'config.json'
    config_path.write_text(json.dumps(config), encoding='utf-8')
    result = subprocess.run([sys.executable, str(source / 'worker.py'), '--config', str(config_path),
                             '--video', str(video_path), '--preview', str(output / 'annotated.mp4')],
                            capture_output=True, text=True, timeout=180, check=True)
    summary = json.loads(result.stdout.strip().splitlines()[-1])
    assert summary['mode'] == 'offline-test'
    assert summary['totals']['IN'] > 0 and summary['totals']['OUT'] > 0, summary
    assert (output / 'annotated.mp4').stat().st_size > 1000
    # Exercise the same overlay-to-JPEG path used for the web camera monitor.
    sys.path.insert(0, str(source))
    from worker import encode_preview
    capture = cv2.VideoCapture(str(output / 'annotated.mp4'))
    capture.set(cv2.CAP_PROP_POS_FRAMES, 45)
    ok, frame = capture.read()
    capture.release()
    assert ok
    jpeg = encode_preview(frame, '2026-10-27T12:00:00Z')
    assert jpeg is not None and len(jpeg['jpeg']) <= 180000
    cv2.imwrite(str(output / 'frame.jpg'), frame)
    print(json.dumps({'test': 'synthetic sample motion, real detector', 'result': summary}))


if __name__ == '__main__':
    main()
