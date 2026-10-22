<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
if (!config('ttlock.enabled') || !ttlock_configured()) {
    fwrite(STDERR, "TTLock chưa bật hoặc chưa cấu hình đủ.\n");
    exit(1);
}
$pdo = db();
// Run every minute. Retain records for audit; expired codes are removed from the lock.
$query = $pdo->query("SELECT b.id FROM bookings b LEFT JOIN booking_access_codes a ON a.booking_id=b.id
    WHERE (a.last_attempt_at IS NULL OR a.last_attempt_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE))
    AND ((b.status IN ('CONFIRMED','CHECKED_IN') AND b.payment_status='PAID'
        AND b.check_out > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
        AND (a.booking_id IS NULL OR a.sync_status<>'READY' OR (b.confirmation_email_status IN ('PENDING','FAILED') AND b.confirmation_email_attempts<3)))
        OR (a.booking_id IS NOT NULL AND a.sync_status<>'REVOKED'
            AND (b.status='CANCELLED' OR a.ends_at<=UTC_TIMESTAMP())))
    ORDER BY COALESCE(a.last_attempt_at, b.created_at) LIMIT 30");
$count = 0;
foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
    try {
        sync_booking_access((string)$id);
        dispatch_confirmation_email((string)$id);
        $count++;
    } catch (Throwable $e) {
        // Do not print tokens, credentials, API bodies, PINs or recipient addresses.
        fwrite(STDERR, "Không xử lý được một đơn. Kiểm tra trạng thái trong quản trị.\n");
    }
}
echo 'Đã kiểm tra ' . $count . " đơn.\n";
