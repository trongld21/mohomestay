<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE bookings (id TEXT, status TEXT, payment_status TEXT, payment_method TEXT, is_test INTEGER, order_code TEXT, total_price INTEGER, created_at TEXT, check_out TEXT)');
$insert = $pdo->prepare('INSERT INTO bookings VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$pdo->exec('ALTER TABLE bookings ADD COLUMN payment_checked_at TEXT');
$pdo->exec('ALTER TABLE bookings ADD COLUMN payment_next_check_at TEXT');
$insert = $pdo->prepare('INSERT INTO bookings (id,status,payment_status,payment_method,is_test,order_code,total_price,created_at,check_out) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    ['outage', 'PENDING', 'UNPAID', 'payos', 0],
    ['overnight', 'PENDING', 'UNPAID', 'payos', 0],
    ['mock', 'PENDING', 'UNPAID', 'payos', 1],
    ['manual', 'PENDING', 'UNPAID', 'transfer', 0],
    ['cancelled', 'CANCELLED', 'UNPAID', 'payos', 0],
    ['paid', 'CONFIRMED', 'PAID', 'payos', 0],
] as $row) {
    $insert->execute([...$row, '1793190000000', 223000, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 43200)]);
}
$calls = [];
$applied = [];
$fetch = function (string $orderCode) use (&$calls): array {
    $calls[] = $orderCode;
    if (count($calls) === 1) throw new RuntimeException('Provider timeout');
    return ['orderCode' => $orderCode, 'amount' => 223000, 'amountPaid' => 223000,
        'status' => 'PAID', 'transactions' => [['reference' => 'BANK-NIGHT']]];
};
$apply = function (PDO $connection, array $data) use (&$applied): void {
    $applied[] = $data;
};
if (!function_exists('reconcile_pending_payments')) {
    throw new RuntimeException('Background worker cannot reconcile pending payOS bookings.');
}
reconcile_pending_payments($pdo, $fetch, $apply);
if (count($calls) !== 3 || count($applied) !== 2 || $applied[0]['amount'] !== 223000) {
    throw new RuntimeException('Reconcile real pending payments and continue after a provider failure.');
}
$_SESSION['payment_status_checked'] = [];
reconcile_pending_payments($pdo, $fetch, $apply);
if (count($calls) !== 3) throw new RuntimeException('Persistent backoff must survive a new guest/cron session.');
[$start, $end] = package_window(['mode' => 'FIXED_TIME', 'checkInTime' => '21:00', 'checkOutTime' => '09:00'], '2026-10-28', '');
if ($start->format('Y-m-d H:i') !== '2026-10-28 21:00' || $end->format('Y-m-d H:i') !== '2026-10-29 09:00'
    || mysql_datetime($start) !== '2026-10-28 14:00:00' || mysql_datetime($end) !== '2026-10-29 02:00:00') {
    throw new RuntimeException('Overnight 21–09 must preserve next-day checkout and UTC conversion.');
}
echo "Payment worker and 21–09 overnight tests: OK\n";
