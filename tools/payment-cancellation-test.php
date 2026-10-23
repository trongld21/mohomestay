<?php

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE bookings (id TEXT PRIMARY KEY, status TEXT, payment_status TEXT)');
$pdo->exec("INSERT INTO bookings VALUES ('test', 'PENDING', 'UNPAID')");
$booking = [
    'id' => 'test', 'status' => 'PENDING', 'payment_status' => 'UNPAID',
    'payment_method' => 'payos', 'is_test' => 0, 'order_code' => '123456789', 'total_price' => 150000,
];
$remote = ['orderCode' => 123456789, 'amount' => 150000, 'amountPaid' => 0, 'status' => 'CANCELLED'];
function cancellation_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
foreach ([
    ['status' => 'PENDING'], ['status' => 'PAID'], ['amountPaid' => 1],
    ['amount' => 999], ['orderCode' => 999],
] as $override) {
    $_SESSION['payment_status_checked'] = [];
    refresh_payment_cancellation($pdo, $booking, fn() => array_replace($remote, $override));
    cancellation_expect($pdo->query('SELECT status FROM bookings')->fetchColumn() === 'PENDING', 'Unverified or paid links must not cancel a booking.');
}
$_SESSION['payment_status_checked'] = [];
refresh_payment_cancellation($pdo, $booking, fn() => $remote);
cancellation_expect($pdo->query('SELECT status FROM bookings')->fetchColumn() === 'CANCELLED', 'A verified unpaid cancelled link must release its pending booking.');
$pdo->exec("UPDATE bookings SET status='CONFIRMED', payment_status='PAID'");
$_SESSION['payment_status_checked'] = [];
refresh_payment_cancellation($pdo, $booking, fn() => $remote);
cancellation_expect($pdo->query('SELECT status FROM bookings')->fetchColumn() === 'CONFIRMED', 'A concurrent payment must never be downgraded.');
$_SESSION['payment_status_checked'] = [];
refresh_payment_cancellation($pdo, array_replace($booking, ['is_test' => 1]), function () {
    throw new RuntimeException('Mock bookings must not contact payOS.');
});
$calls = 0;
$_SESSION['payment_status_checked'] = [];
$fetch = function () use (&$calls, $remote) { $calls++; return $remote; };
refresh_payment_cancellation($pdo, $booking, $fetch);
refresh_payment_cancellation($pdo, $booking, $fetch);
cancellation_expect($calls === 1, 'Payment polling must throttle provider reads.');
echo "Payment cancellation tests: OK\n";
