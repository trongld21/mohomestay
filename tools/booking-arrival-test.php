<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = new PDO('sqlite::memory:');
$_ENV['WIFI_NAME'] = 'Mơ & Home';
$_ENV['WIFI_PASSWORD'] = 'test-only-secret';
$_ENV['CHECKIN_GUIDE_URL'] = 'javascript:alert(1)';
$_ENV['HOUSE_RULES_URL'] = 'https://example.com/rules';
$booking = [
    'id' => 'test',
    'status' => 'CONFIRMED',
    'payment_status' => 'PAID',
    'is_test' => 1,
    'check_out' => gmdate('Y-m-d H:i:s', time() + 3600),
];
$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$test = booking_arrival_details($booking, $pdo);
$expect($test['doorCodes'] === [], 'Test bookings must not receive real door codes.');
$expect($test['wifiName'] === 'Mơ & Home' && $test['wifiPassword'] === 'test-only-secret', 'Confirmed test bookings must display configured Wi-Fi.');
$expect($test['guideUrl'] === '' && $test['rulesUrl'] === 'https://example.com/rules', 'Only HTTPS guide links are allowed.');
foreach (['PENDING', 'CANCELLED', 'EXPIRED'] as $status) {
    $expect(booking_arrival_details(array_replace($booking, ['status' => $status]), $pdo) === null, 'Unconfirmed bookings must not receive arrival details.');
}
$expect(booking_arrival_details(array_replace($booking, ['payment_status' => 'UNPAID']), $pdo) === null, 'Unpaid bookings must not receive arrival details.');
$past = booking_arrival_details(array_replace($booking, ['is_test' => 0, 'status' => 'CHECKED_OUT']), $pdo);
$expect($past['doorCodes'] === [], 'Checked-out bookings must not receive door codes.');
$expect($past['wifiName'] === 'Mơ & Home', 'Configured Wi-Fi must be returned for real confirmed bookings.');

$_ENV['APP_ENV'] = 'development';
$_ENV['TTLOCK_ENABLED'] = 'false';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION['admin_until'] = time() + 60;
$pdo->exec('CREATE TABLE door_codes (code TEXT, period TEXT, status TEXT, starts_at TEXT, ends_at TEXT, created_at TEXT, id TEXT)');
$insert = $pdo->prepare('INSERT INTO door_codes VALUES (?, ?, ?, ?, ?, ?, ?)');
$start = gmdate('Y-m-d H:i:s', time() - 3600);
$end = gmdate('Y-m-d H:i:s', time() + 7200);
foreach ([['active', 'ACTIVE', $end], ['disabled', 'DISABLED', $end], ['expired', 'ACTIVE', $start]] as [$code, $status, $until]) {
    $insert->execute([$code, 'DAY', $status, $start, $until, $start, $code]);
}
$booking['check_in'] = gmdate('Y-m-d H:i:s');
$local = booking_arrival_details($booking, $pdo);
$expect(array_column($local['doorCodes'], 'code') === ['active'], 'Local admin test must show only enabled, valid stored codes.');
$_SESSION = [];
$expect(booking_arrival_details($booking, $pdo)['doorCodes'] === [], 'Guest test lookup must not receive real codes.');
$_SESSION['admin_until'] = time() + 60;
$_ENV['APP_ENV'] = 'production';
$expect(booking_arrival_details($booking, $pdo)['doorCodes'] === [], 'Production test bookings must not receive real codes.');
$_ENV['APP_ENV'] = 'development';
$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
$expect(booking_arrival_details($booking, $pdo)['doorCodes'] === [], 'Non-local test requests must not receive real codes.');
echo "Booking arrival access and configuration tests: OK\n";
