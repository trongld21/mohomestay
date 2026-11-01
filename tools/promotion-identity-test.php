<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/promotion-test-fixture.php';
$pdo = promotion_test_database();
$pdo->exec("INSERT INTO bookings (id,guest_name,guest_phone,guest_email,status,is_test,check_out)
    VALUES ('old','Nguyễn Văn An','+84 912-345-678','OLD@example.test','CHECKED_OUT',0,'2020-01-01')");
foreach ([
    ['0912345678', 'old@example.test', 'Tên khác', 1],
    ['0999999999', 'old@example.test', '  NGUYỄN   VĂN AN  ', 1],
    ['0912345678', 'new@example.test', 'Nguyễn Văn An', 1],
    ['0999999999', 'old@example.test', 'Tên khác', 0],
    ['0912345678', 'new@example.test', 'Tên khác', 0],
    ['', '', 'Nguyễn Văn An', 0],
    ['', '', '', 0],
] as [$phone, $email, $name, $expected]) {
    if (promotion_completed_stays($pdo, $phone, $email, $name) !== $expected) {
        throw new RuntimeException('Returning discount requires two matching nonblank identity fields on one completed booking.');
    }
}
$pdo->exec("INSERT INTO bookings (id,guest_name,guest_phone,guest_email,status,is_test,check_out)
    VALUES ('other','Tên khác','0988888888','another@example.test','CHECKED_OUT',0,'2020-01-01')");
if (promotion_completed_stays($pdo, '0988888888', 'old@example.test', 'Không trùng') !== 0) {
    throw new RuntimeException('Identity fields from separate bookings must not be combined.');
}
foreach ([
    ['future', 'CHECKED_OUT', 0, gmdate('Y-m-d H:i:s', time() + 3600)],
    ['test', 'CHECKED_OUT', 1, '2020-01-01'],
    ['cancelled', 'CANCELLED', 0, '2020-01-01'],
] as [$id, $status, $isTest, $checkout]) {
    $pdo->prepare('INSERT INTO bookings (id,guest_name,guest_phone,guest_email,status,is_test,check_out) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, 'Nguyễn Văn An', '0912345678', 'old@example.test', $status, $isTest, $checkout]);
}
if (promotion_completed_stays($pdo, '0912345678', 'old@example.test', 'Nguyễn Văn An') !== 1) {
    throw new RuntimeException('Only completed non-test stays count, each booking once even when all three fields match.');
}
echo "Returning guest identity requires two of name/email/phone on the same booking: OK\n";
