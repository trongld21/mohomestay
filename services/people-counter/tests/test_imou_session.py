import sys
import unittest
import contextlib
import io
import tempfile
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from imou_session import ImouSessionClient, session_payload, signature
from run_native import main, read_credentials


class ImouSessionTests(unittest.TestCase):
    def test_untrusted_hosts_are_rejected_before_network_access(self):
        for host in ['http://openapi-sg.easy4ip.com', 'https://example.com',
                     'https://openapi-sg.easy4ip.com.evil.test', 'https://openapi-sg.easy4ip.com/redirect']:
            with self.assertRaises(ValueError):
                ImouSessionClient(host, 'id', 'secret')

    def test_only_imou_keys_are_loaded_and_environment_has_precedence(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent) as directory:
            path = Path(directory) / 'private.env'
            path.write_text('DB_PASSWORD=unrelated\nIMOU_APP_ID=file-id\nIMOU_APP_SECRET="file-secret"\n')
            with patch.dict('os.environ', {'IMOU_APP_ID': 'environment-id'}, clear=True):
                values = read_credentials(path)
            self.assertEqual(values, {'IMOU_APP_ID': 'environment-id', 'IMOU_APP_SECRET': 'file-secret'})

    def test_cli_hides_secret_in_exception_text(self):
        output = io.StringIO()
        with patch('sys.argv', ['run_native', '--executable', 'unused', '--device-id', 'CAM1']), \
                patch('run_native.run', side_effect=RuntimeError('private-secret-must-not-appear')), \
                contextlib.redirect_stdout(output):
            with self.assertRaises(SystemExit):
                main()
        self.assertNotIn('private-secret-must-not-appear', output.getvalue())
        self.assertIn('RuntimeError', output.getvalue())

    def test_official_signature_vector(self):
        self.assertEqual(signature(1706511734, 'f5a1ae2d-c09c-4d39-a744-83a5c2c653c2',
                                   'test123456789test123456789'),
                         'xjhCQBoJ9hRDsCjyDcHjtDNzRZ3ZJezcawsfWeiaoxU=')

    def test_only_selected_device_and_channel_are_forwarded(self):
        calls = []

        def request(method, params):
            calls.append(method)
            if method == 'accessToken':
                return {'accessToken': 'private-token'}
            return {'deviceList': [
                {'deviceId': 'OTHER', 'playTokenV2': 'never-forward-this'},
                {'deviceId': 'CAM1', 'encryptMode': '0', 'playTokenV2': 'selected',
                 'channelList': [{'channelId': '0', 'channelStatus': 'online'}]},
            ]}

        payload = session_payload(request, 'CAM1')
        self.assertEqual(payload['playToken'], 'selected')
        self.assertEqual(payload['deviceId'], 'CAM1')
        self.assertEqual(payload['psk'], 'CAM1')
        self.assertNotIn('never-forward-this', str(payload))
        self.assertEqual(calls, ['accessToken', 'listDeviceDetailsByPage'])

    def test_custom_encryption_is_never_guessed(self):
        def request(method, params):
            return {'accessToken': 't'} if method == 'accessToken' else {
                'deviceList': [{'deviceId': 'CAM1', 'encryptMode': '1', 'channelList': [{'channelId': '0'}]}]}

        with self.assertRaises(ValueError):
            session_payload(request, 'CAM1')


if __name__ == '__main__':
    unittest.main()
