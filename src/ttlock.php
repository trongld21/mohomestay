<?php

declare(strict_types=1);

class TTLockException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain = false)
    {
        parent::__construct($message);
    }
}

function ttlock_window(array $booking): array
{
    $zone = new DateTimeZone('UTC');
    $start = new DateTimeImmutable($booking['check_in'], $zone);
    $end = new DateTimeImmutable($booking['check_out'], $zone);
    if ($end <= $start) throw new TTLockException('Thời gian lưu trú không hợp lệ.');
    return [
        'starts_at' => $start->format('Y-m-d H:i:s'),
        'ends_at' => $end->modify('+1 hour')->format('Y-m-d H:i:s'),
    ];
}

function ttlock_generated_window(array $booking): array
{
    $window = ttlock_window($booking);
    $start = strtotime($window['starts_at'] . ' UTC');
    $end = strtotime($window['ends_at'] . ' UTC');
    return [
        'starts_at' => gmdate('Y-m-d H:i:s', (int)(ceil($start / 3600) * 3600)),
        'ends_at' => gmdate('Y-m-d H:i:s', (int)(ceil($end / 3600) * 3600)),
    ];
}

function ttlock_options(): array
{
    $options = [];
    foreach (['base_url', 'client_id', 'client_secret', 'username', 'password_md5', 'lock_id'] as $key) {
        $options[$key] = (string)config('ttlock.' . $key, '');
    }
    return $options;
}

function ttlock_configured(): bool
{
    $o = ttlock_options();
    return in_array($o['base_url'], ['https://api.sciener.com', 'https://euapi.ttlock.com', 'https://api.ttlock.com'], true)
        && $o['client_id'] !== '' && $o['client_secret'] !== '' && $o['username'] !== ''
        && preg_match('/^[a-f0-9]{32}$/', $o['password_md5']) === 1
        && ctype_digit($o['lock_id']) && (int)$o['lock_id'] > 0;
}

final class TTLockClient
{
    private ?string $token = null;
    private Closure $transport;

    public function __construct(private array $options, ?callable $transport = null)
    {
        if (!in_array($options['base_url'], ['https://api.sciener.com', 'https://euapi.ttlock.com', 'https://api.ttlock.com'], true)) {
            throw new TTLockException('Địa chỉ máy chủ TTLock không hợp lệ.');
        }
        $this->transport = Closure::fromCallable($transport ?? static function (string $url, array $params): array {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($params),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body = curl_exec($curl);
            $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($body === false || $http < 200 || $http >= 300) {
                throw new TTLockException('Không xác định được kết quả từ TTLock. Cần đối soát trước khi tạo lại.', true);
            }
            $data = json_decode($body, true);
            if (!is_array($data)) throw new TTLockException('Phản hồi TTLock không hợp lệ.', true);
            return $data;
        });
    }

    private function request(string $path, array $params): array
    {
        if ($path !== '/oauth2/token') {
            if ($this->token === null) {
                $auth = $this->request('/oauth2/token', [
                    'client_id' => $this->options['client_id'],
                    'client_secret' => $this->options['client_secret'],
                    'username' => $this->options['username'],
                    'password' => $this->options['password_md5'],
                ]);
                if (empty($auth['access_token']) || !is_string($auth['access_token'])) {
                    throw new TTLockException('Không lấy được quyền truy cập TTLock. Kiểm tra cấu hình tài khoản.');
                }
                $this->token = $auth['access_token'];
            }
            $params += [
                'clientId' => $this->options['client_id'],
                'accessToken' => $this->token,
                'date' => (int)floor(microtime(true) * 1000),
            ];
        }
        $data = ($this->transport)($this->options['base_url'] . $path, $params);
        if (isset($data['errcode']) && (int)$data['errcode'] !== 0) {
            throw new TTLockException('TTLock từ chối thao tác (mã ' . (int)$data['errcode'] . ').');
        }
        return $data;
    }

    public function findPasscode(array $row, bool $requireUsable = true): ?int
    {
        for ($page = 1; $page <= 20; $page++) {
            $data = $this->request('/v3/lock/listKeyboardPwd', [
                'lockId' => (int)$row['lock_id'], 'pageNo' => $page, 'pageSize' => 100,
            ]);
            if (!isset($data['list']) || !is_array($data['list'])) {
                throw new TTLockException('Không đọc được danh sách mã từ TTLock.', true);
            }
            foreach ($data['list'] as $remote) {
                if ((string)($remote['keyboardPwd'] ?? '') !== $row['code']) continue;
                if (!in_array(($remote['keyboardPwdName'] ?? ''), [$this->passcodeName($row), 'MO-' . $row['booking_id']], true)
                    || (int)($remote['startDate'] ?? 0) !== $this->milliseconds($row['starts_at'])
                    || (int)($remote['endDate'] ?? 0) !== $this->milliseconds($row['ends_at'])
                    || (int)($remote['keyboardPwdId'] ?? 0) <= 0
                    || (isset($remote['status']) && !in_array((int)$remote['status'], $requireUsable ? [1] : [1, 2], true))) {
                    throw new TTLockException('Mã trên khóa chưa khớp đơn hoặc chưa đồng bộ xong. Cần kiểm tra TTLock.', true);
                }
                return (int)$remote['keyboardPwdId'];
            }
            if (count($data['list']) < 100 || (isset($data['pages']) && $page >= (int)$data['pages'])) return null;
        }
        throw new TTLockException('Danh sách mã quá dài, cần kiểm tra TTLock.', true);
    }

    public function ensurePasscode(array $row, bool $allowCreate = true, ?callable $beforeCreate = null): int
    {
        $existing = $this->findPasscode($row);
        if ($existing !== null) return $existing;
        if (!$allowCreate) throw new TTLockException('Chưa tìm thấy mã sau lần gửi bị gián đoạn. Kiểm tra khóa trước khi xử lý tiếp.', true);
        if ($beforeCreate !== null) $beforeCreate();
        $data = $this->request('/v3/keyboardPwd/add', [
            'lockId' => (int)$row['lock_id'], 'keyboardPwd' => $row['code'],
            'keyboardPwdName' => $this->passcodeName($row), 'keyboardPwdType' => 3,
            'startDate' => $this->milliseconds($row['starts_at']),
            'endDate' => $this->milliseconds($row['ends_at']), 'addType' => 2,
        ]);
        if ((int)($data['keyboardPwdId'] ?? 0) <= 0) {
            throw new TTLockException('TTLock chưa xác nhận mã đã tạo.', true);
        }
        return (int)$data['keyboardPwdId'];
    }

    public function ensureGeneratedPasscode(array $row, bool $allowCreate = true, ?callable $beforeCreate = null): array
    {
        $match = null;
        for ($page = 1; $page <= 20; $page++) {
            $data = $this->request('/v3/lock/listKeyboardPwd', [
                'lockId' => (int)$row['lock_id'], 'pageNo' => $page, 'pageSize' => 100,
            ]);
            if (!isset($data['list']) || !is_array($data['list'])) throw new TTLockException('Không đọc được danh sách mã TTLock.', true);
            foreach ($data['list'] as $remote) {
                if (!in_array(($remote['keyboardPwdName'] ?? ''), [$this->passcodeName($row), 'MO-' . $row['booking_id']], true)) continue;
                if ((int)($remote['keyboardPwdType'] ?? 0) !== 3
                    || (int)($remote['startDate'] ?? 0) !== $this->milliseconds($row['starts_at'])
                    || (int)($remote['endDate'] ?? 0) !== $this->milliseconds($row['ends_at'])
                    || (isset($remote['status']) && (int)$remote['status'] !== 1)) {
                    throw new TTLockException('Mã TTLock theo đơn chưa khớp thời hạn hoặc chưa sẵn sàng.', true);
                }
                $candidate = $this->generatedResult($remote);
                if ($match !== null && $match !== $candidate) throw new TTLockException('Có nhiều mã theo đơn. Cần đối soát TTLock.', true);
                $match = $candidate;
            }
            if (count($data['list']) < 100 || (isset($data['pages']) && $page >= (int)$data['pages'])) {
                if ($match !== null) return $match;
                if (!$allowCreate) throw new TTLockException('Chưa tìm thấy mã sau lần gửi gián đoạn. Không tự tạo thêm mã.', true);
                if ($beforeCreate !== null) $beforeCreate();
                return $this->generatedResult($this->request('/v3/keyboardPwd/get', [
                    'lockId' => (int)$row['lock_id'], 'keyboardPwdType' => 3,
                    'keyboardPwdName' => $this->passcodeName($row),
                    'startDate' => $this->milliseconds($row['starts_at']),
                    'endDate' => $this->milliseconds($row['ends_at']),
                ]));
            }
        }
        throw new TTLockException('Danh sách mã quá dài; cần đối soát.', true);
    }

    private function generatedResult(array $data): array
    {
        $code = $data['keyboardPwd'] ?? null;
        if (!is_string($code) || !preg_match('/^[0-9]{6,9}$/D', $code) || (int)($data['keyboardPwdId'] ?? 0) < 1) {
            throw new TTLockException('TTLock chưa trả về mã và ID hợp lệ.', true);
        }
        return ['code' => $code, 'remote_id' => (int)$data['keyboardPwdId']];
    }

    public function deletePasscode(array $row): void
    {
        $id = $this->findPasscode($row, false);
        if ($id === null) return;
        $data = $this->request('/v3/keyboardPwd/delete', [
            'lockId' => (int)$row['lock_id'], 'keyboardPwdId' => $id, 'deleteType' => 2,
        ]);
        if (!array_key_exists('errcode', $data) || (int)$data['errcode'] !== 0) {
            throw new TTLockException('TTLock chưa xác nhận thu hồi mã.', true);
        }
    }

    private function passcodeName(array $row): string
    {
        $name = trim((string)($row['booking_code'] ?? ''));
        return $name !== '' ? $name : 'MO-' . $row['booking_id'];
    }

    private function milliseconds(string $utc): int
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp() * 1000;
    }
}
