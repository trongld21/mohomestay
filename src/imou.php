<?php

declare(strict_types=1);

// https://open.imoulife.com/book/http/develop.html (HMAC-SHA256 signing).
function imou_sign(int $time, string $nonce, string $secret): string
{
    $source = 'time:' . $time . ',nonce:' . $nonce . ',appSecret:' . $secret;
    return base64_encode(hash_hmac('sha256', $source, hash('sha256', $secret), true));
}

final class ImouApiException extends RuntimeException
{
    public function __construct(public readonly string $apiCode)
    {
        parent::__construct('Imou từ chối yêu cầu (' . $apiCode . ').');
    }
}

final class ImouReadClient
{
    private readonly ?Closure $transport;

    public function __construct(
        private readonly string $appId,
        private readonly string $secret,
        private readonly string $host,
        ?callable $transport = null
    ) {
        if (!in_array($host, [
            'https://openapi-sg.easy4ip.com',
            'https://openapi-fk.easy4ip.com',
            'https://openapi-or.easy4ip.com',
        ], true)) {
            throw new InvalidArgumentException('Chọn đúng máy chủ Imou sg/fk/or trong thông tin tài khoản.');
        }
        if ($appId === '' || $secret === '') {
            throw new InvalidArgumentException('Thiếu IMOU_APP_ID hoặc IMOU_APP_SECRET.');
        }
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    private function request(string $method, array $params): array
    {
        // This diagnostic client must never bind, unbind, or change a camera.
        if (!in_array($method, ['accessToken', 'listDeviceDetailsByPage', 'getLiveStreamInfo',
            'queryDeviceRtmpLive', 'getKitToken'], true)) {
            throw new InvalidArgumentException('API không nằm trong danh sách chỉ đọc.');
        }
        $time = time();
        $nonce = bin2hex(random_bytes(16));
        $request = [
            'system' => ['ver' => '1.0', 'appId' => $this->appId, 'time' => $time,
                'nonce' => $nonce, 'sign' => imou_sign($time, $nonce, $this->secret)],
            'id' => bin2hex(random_bytes(16)),
            'params' => (object)$params,
        ];
        $data = $this->transport !== null
            ? ($this->transport)($method, $request)
            : $this->post($method, $request);
        $code = $data['result']['code'] ?? null;
        if ($code !== '0' && $code !== 0) {
            $safeCode = is_scalar($code) && preg_match('/^[A-Z0-9_]{1,32}$/D', (string)$code) ? (string)$code : 'UNKNOWN';
            throw new ImouApiException($safeCode);
        }
        $result = $data['result']['data'] ?? null;
        if (!is_array($result)) throw new RuntimeException('Imou trả dữ liệu không hợp lệ.');
        return $result;
    }

    private function post(string $method, array $request): array
    {
        $body = '';
        $curl = curl_init($this->host . '/openapi/' . $method);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json;charset=UTF-8'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($curl) === false) {
                throw new RuntimeException('Không kết nối được Imou (cURL ' . curl_errno($curl) . ').');
            }
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($status !== 200) throw new RuntimeException('Imou trả HTTP ' . $status . '.');
        } finally {
            curl_close($curl);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('Imou trả JSON không hợp lệ.');
        return $data;
    }

    public function devices(): array
    {
        $auth = $this->request('accessToken', []);
        $token = $auth['accessToken'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 4096) {
            throw new RuntimeException('Imou không trả accessToken hợp lệ.');
        }
        $devices = [];
        for ($page = 1; $page <= 10; $page++) {
            $data = $this->request('listDeviceDetailsByPage', [
                'token' => $token, 'pageSize' => 50, 'page' => $page, 'source' => 'bindAndShare',
            ]);
            $rows = $data['deviceList'] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > 50) {
                throw new RuntimeException('Danh sách camera Imou không hợp lệ.');
            }
            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['deviceId'] ?? null) || $row['deviceId'] === '') {
                    throw new RuntimeException('Thông tin camera Imou không hợp lệ.');
                }
                $text = static fn(mixed $value): string => is_scalar($value)
                    ? preg_replace('/[\x00-\x1f\x7f]/', '', substr((string)$value, 0, 200)) : '';
                // Allowlist output; never expose playToken/devicePassword/salt or raw responses.
                $devices[] = ['id' => $text($row['deviceId']), 'name' => $text($row['deviceName'] ?? ''),
                    'status' => $text($row['deviceStatus'] ?? ''), 'source' => $text($row['source'] ?? '')];
            }
            if (count($rows) < 50) return $devices;
        }
        throw new RuntimeException('Danh sách vượt giới hạn 500 camera; không kết luận từ kết quả chưa đầy đủ.');
    }

    /** Diagnose authorization only. A successful check does not prove video playback. */
    public function liveAccess(string $deviceId, string $channelId = '0'): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $deviceId)
            || !preg_match('/^[0-9]{1,4}$/D', $channelId)) {
            throw new InvalidArgumentException('Mã camera hoặc kênh không hợp lệ.');
        }
        $auth = $this->request('accessToken', []);
        $token = $auth['accessToken'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 4096) {
            throw new RuntimeException('Imou không trả accessToken hợp lệ.');
        }
        $params = ['token' => $token, 'deviceId' => $deviceId, 'channelId' => $channelId];
        $checks = [];
        foreach (['getLiveStreamInfo', 'queryDeviceRtmpLive', 'getKitToken'] as $method) {
            try {
                // No creation, binding, unbinding or permission changes. Discard all credentials.
                $this->request($method, $method === 'getKitToken' ? [...$params, 'type' => '1'] : $params);
                $code = '0';
            } catch (ImouApiException $error) {
                $code = $error->apiCode;
            }
            $checks[] = ['method' => $method, 'code' => $code];
        }
        return $checks;
    }
}
