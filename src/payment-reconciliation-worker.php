<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
$pdo = db();
reconcile_pending_payments($pdo);
retry_paid_booking_provisioning($pdo);
echo "Đã chạy đối soát thanh toán và thử cấp mã/email.\n";
