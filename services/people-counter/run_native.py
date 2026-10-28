"""Bounded, unattended OpenSDK trial. Requires Windows and official SDK runtime."""

import argparse
import json
import os
import re
import subprocess
import threading
import time
from pathlib import Path

from imou_session import ImouSessionClient, session_payload


def read_credentials(path):
    names = {'IMOU_API_HOST', 'IMOU_APP_ID', 'IMOU_APP_SECRET', 'IMOU_DEVICE_KEY'}
    values = {name: os.environ[name] for name in names if name in os.environ}
    if path:
        for line in Path(path).read_text(encoding='utf-8-sig').splitlines():
            match = re.fullmatch(r'\s*(IMOU_API_HOST|IMOU_APP_ID|IMOU_APP_SECRET|IMOU_DEVICE_KEY)\s*=\s*(.*?)\s*', line)
            if match and match[1] not in values:
                values[match[1]] = match[2].strip('\"\'')
    return values


def run(args):
    if os.name != 'nt':
        raise ValueError('This native SDK build requires Windows x64')
    executable = Path(args.executable).resolve()
    if not executable.is_file():
        raise ValueError('Build the native SDK bridge first')
    credentials = read_credentials(args.env_file)
    client = ImouSessionClient(credentials.get('IMOU_API_HOST', ''),
                               credentials.get('IMOU_APP_ID', ''), credentials.get('IMOU_APP_SECRET', ''))
    payload = session_payload(client.request, args.device_id, args.channel,
                              credentials.get('IMOU_DEVICE_KEY'))
    payload.update(host=client.host.removeprefix('https://'), appId=client.app_id,
                   appSecret=client.secret, udpPort=args.udp_port, duration=args.duration)
    if args.stop_file:
        payload['stopFile'] = str(Path(args.stop_file).resolve())
    environment = {key: value for key, value in os.environ.items()
                   if key not in {'IMOU_APP_ID', 'IMOU_APP_SECRET', 'IMOU_DEVICE_KEY'}}
    # SDK stderr can contain vendor diagnostics. Keep it private and never echo it.
    private_log = executable.parent / 'sdk-native-stderr.log'
    with private_log.open('ab') as errors:
        process = subprocess.Popen([str(executable)], cwd=executable.parent, env=environment,
                                   stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=errors,
                                   creationflags=subprocess.CREATE_NO_WINDOW)
        deadline = threading.Timer(args.duration + 45, process.kill)
        deadline.start()
        try:
            process.stdin.write(json.dumps(payload).encode())
            process.stdin.close()
            allowed = {'CONNECTING', 'PLAYING', 'STOPPED', 'PLAYER_INIT_FAILED', 'PLAY_REJECTED'}
            for line in iter(lambda: process.stdout.readline(65537), b''):
                if len(line) > 65536:
                    continue
                try:
                    record = json.loads(line)
                except (ValueError, UnicodeDecodeError):
                    continue
                if not isinstance(record, dict) or record.get('state') not in allowed:
                    continue
                safe = {'state': record['state']}
                if type(record.get('bytes')) is int and record['bytes'] >= 0:
                    safe['bytes'] = record['bytes']
                print(json.dumps(safe), flush=True)
            code = process.wait(timeout=10)
            if code != 0:
                print(json.dumps({'state': 'NATIVE_EXIT', 'exitCode': code}), flush=True)
                raise RuntimeError('Native SDK trial did not receive video')
        finally:
            deadline.cancel()
            if process.poll() is None:
                process.kill()
            process.wait(timeout=10)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--executable', required=True)
    parser.add_argument('--env-file', help='Private environment file, never copied into a deploy package')
    parser.add_argument('--device-id', required=True)
    parser.add_argument('--channel', type=int, default=0)
    parser.add_argument('--udp-port', type=int, default=19456)
    parser.add_argument('--duration', type=int, default=60)
    parser.add_argument('--continuous', action='store_true', help='Refresh the session after each bounded native run')
    parser.add_argument('--stop-file', help='Creating this marker requests a graceful stop')
    args = parser.parse_args()
    if not 1 <= args.duration <= 3600 or not 1024 <= args.udp_port <= 65535:
        parser.error('Invalid trial duration or local UDP port')
    if not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', args.device_id):
        parser.error('Invalid device identifier')
    delay = 5
    try:
        while not args.stop_file or not Path(args.stop_file).exists():
            try:
                run(args)
                delay = 5
            except Exception as error:
                print(json.dumps({'state': 'FAILED', 'type': type(error).__name__}), flush=True)
                if not args.continuous:
                    raise SystemExit(1) from None
                delay = min(60, delay * 2)
            if not args.continuous:
                break
            for _ in range(delay):
                if args.stop_file and Path(args.stop_file).exists():
                    return
                time.sleep(1)
    except KeyboardInterrupt:
        pass


if __name__ == '__main__':
    main()
