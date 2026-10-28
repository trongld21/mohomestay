<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/imou.php';

$appId = trim((string)env_value('IMOU_APP_ID', ''));
$secret = trim((string)env_value('IMOU_APP_SECRET', ''));
$host = trim((string)env_value('IMOU_API_HOST', ''));
$missing = [];
foreach (['IMOU_APP_ID' => $appId, 'IMOU_APP_SECRET' => $secret, 'IMOU_API_HOST' => $host] as $name => $value) {
    if ($value === '') $missing[] = $name;
}
if ($missing) {
    fwrite(STDERR, 'Chưa cấu hình: ' . implode(', ', $missing) . PHP_EOL);
    fwrite(STDERR, "Lưu trực tiếp vào .env riêng trên máy; không gửi App Secret vào chat.\n");
    exit(1);
}
try {
    $client = new ImouReadClient($appId, $secret, $host);
    if (in_array('--check', $argv, true)) {
        echo "Cấu hình đã có. Chưa gọi API Imou.\n";
        exit;
    }
    $liveDevice = null;
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--live-check=')) {
            throw new InvalidArgumentException('Dùng --check hoặc --live-check=DEVICE_ID.');
        }
        $liveDevice = substr($argument, strlen('--live-check='));
    }
    if ($liveDevice !== null) {
        $checks = $client->liveAccess($liveDevice);
        $failed = false;
        foreach ($checks as $check) {
            $label = $check['code'] === '0' ? 'Yêu cầu được chấp nhận; chưa xác minh phát video'
                : ($check['code'] === 'OP1009' ? 'Không đủ quyền thực hiện thao tác' : 'Chưa lấy được quyền/luồng video');
            echo $check['method'] . ': ' . $check['code'] . ' — ' . $label . PHP_EOL;
            $failed = $failed || $check['code'] !== '0';
        }
        echo "Không tạo luồng công khai, không sửa quyền camera, không in token hoặc URL video.\n";
        exit($failed ? 2 : 0);
    }
    $devices = $client->devices();
    echo 'Kết nối Imou thành công. Camera tài khoản được phép truy cập: ' . count($devices) . PHP_EOL;
    if (!$devices) {
        echo "Danh sách trống. Quyền xem trong app chưa chứng minh quyền truy cập từ ứng dụng Open Platform này.\n";
    }
    foreach ($devices as $device) {
        echo implode(' | ', [$device['name'], $device['id'], $device['status'], $device['source']]) . PHP_EOL;
    }
    echo "Kiểm tra chỉ đọc đã xong; chưa lấy hoặc bật luồng video. Token không được in hay lưu.\n";
} catch (Throwable $error) {
    // Only this client's fixed messages may be printed; never dump transport responses.
    $safe = $error instanceof InvalidArgumentException || $error instanceof RuntimeException;
    fwrite(STDERR, $safe ? $error->getMessage() . PHP_EOL : "Chưa hoàn tất kiểm tra Imou.\n");
    exit(1);
}
