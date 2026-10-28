"""Read-only Imou authentication. Returned values are private IPC data, not logs."""

import base64
import hashlib
import hmac
import json
import secrets
import time


def signature(stamp, nonce, secret):
    key = hashlib.sha256(secret.encode()).hexdigest().encode()
    message = f'time:{stamp},nonce:{nonce},appSecret:{secret}'.encode()
    return base64.b64encode(hmac.new(key, message, hashlib.sha256).digest()).decode()


class ImouSessionClient:
    def __init__(self, host, app_id, secret):
        if host not in {'https://openapi-sg.easy4ip.com', 'https://openapi-fk.easy4ip.com',
                        'https://openapi-or.easy4ip.com'}:
            raise ValueError('Unsupported Imou API region')
        if not app_id or not secret:
            raise ValueError('Imou application credentials are required')
        self.host, self.app_id, self.secret = host, app_id, secret

    def request(self, method, params):
        import requests

        if method not in {'accessToken', 'listDeviceDetailsByPage'}:
            raise ValueError('Only read-only session requests are supported')
        stamp, nonce = int(time.time()), secrets.token_hex(16)
        body = {'id': secrets.token_hex(16), 'params': params, 'system': {
            'ver': '1.0', 'appId': self.app_id, 'time': stamp, 'nonce': nonce,
            'sign': signature(stamp, nonce, self.secret),
        }}
        with requests.post(self.host + '/openapi/' + method, json=body, stream=True,
                           timeout=(5, 20), allow_redirects=False) as response:
            if response.status_code != 200:
                raise RuntimeError('Imou session HTTP request rejected')
            chunks, size = [], 0
            for chunk in response.iter_content(16384):
                size += len(chunk)
                if size > 1048576:
                    raise ValueError('Imou response exceeds allowed size')
                chunks.append(chunk)
        result = json.loads(b''.join(chunks))['result']
        if str(result.get('code')) != '0':
            raise RuntimeError('Imou session API request rejected')
        if not isinstance(result.get('data'), dict):
            raise ValueError('Invalid Imou session response')
        return result['data']


def session_payload(request, device_id, channel=0, device_key=None):
    if not isinstance(channel, int) or not 0 <= channel <= 255:
        raise ValueError('Invalid camera channel')
    auth = request('accessToken', {})
    token = auth.get('accessToken')
    if not isinstance(token, str) or not token:
        raise ValueError('Missing access token')
    for page in range(1, 51):
        data = request('listDeviceDetailsByPage', {
            'token': token, 'source': 'bindAndShare', 'page': page, 'pageSize': 10,
        })
        devices = data.get('deviceList', [])
        if not isinstance(devices, list):
            raise ValueError('Invalid camera list')
        for device in devices:
            if device.get('deviceId') != device_id:
                continue
            channels = device.get('channelList', [])
            if not any(str(item.get('channelId')) == str(channel) for item in channels):
                raise ValueError('Camera channel not available to this account')
            if str(device.get('encryptMode')) != '0' and not device_key:
                raise ValueError('Camera requires its configured encryption key')
            return {'token': token, 'deviceId': device_id, 'channel': channel,
                    'productId': device.get('productId', ''),
                    'playToken': device.get('playTokenV2') or device.get('playToken', ''),
                    'psk': device_key or device_id, 'tls': device.get('tlsEnable') in (True, 'true', '1', 1)}
        if len(devices) < 10:
            break
    raise ValueError('Selected camera was not found within this account')
