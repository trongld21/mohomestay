<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
function tt_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
tt_expect(function_exists('ttlock_window'), 'Missing TTLock booking window');
$window = ttlock_window(['check_in' => '2027-01-15 07:00:00', 'check_out' => '2027-01-16 05:00:00']);
tt_expect($window === ['starts_at' => '2027-01-15 06:00:00', 'ends_at' => '2027-01-16 06:00:00'], 'One-hour buffer must apply on both sides');
$options = ['base_url' => 'https://api.sciener.com', 'client_id' => 'test', 'client_secret' => 'secret', 'username' => 'owner', 'password_md5' => str_repeat('a', 32), 'lock_id' => 7];
$row = ['code' => '12345678', 'booking_id' => str_repeat('b', 32), 'lock_id' => 7] + $window;
$calls = [];
$transport = function (string $url, array $params) use (&$calls): array {
    $calls[] = [$url, $params];
    if (str_ends_with($url, '/oauth2/token')) return ['access_token' => 'token', 'expires_in' => 7776000];
    if (str_ends_with($url, '/listKeyboardPwd')) return ['list' => isset($calls[2][1]['keyboardPwd']) ? [array_merge($calls[2][1], ['keyboardPwdId' => 42])] : [], 'pages' => 1];
    if (str_ends_with($url, '/add')) return ['keyboardPwdId' => 42];
    return ['errcode' => 0];
};
$client = new TTLockClient($options, $transport);
tt_expect($client->ensurePasscode($row) === 42, 'Successful creation returns remote ID');
$payload = $calls[2][1];
tt_expect($payload['addType'] === 2 && $payload['keyboardPwdType'] === 3, 'Must write a timed code remotely');
tt_expect($payload['startDate'] === 1799992800000 && $payload['endDate'] === 1800079200000, 'API dates must be UTC milliseconds');
tt_expect($payload['keyboardPwd'] === '12345678', 'PIN sent without transformation');
$client->deletePasscode($row + ['remote_id' => 42]);
tt_expect(end($calls)[1]['deleteType'] === 2, 'Deletion must reach physical lock');
$existing = new TTLockClient($options, function (string $url, array $params) use ($payload): array {
    if (str_ends_with($url, '/oauth2/token')) return ['access_token' => 'token'];
    if (str_ends_with($url, '/listKeyboardPwd')) return ['list' => [array_merge($payload, ['keyboardPwdId' => 91])], 'pages' => 1];
    throw new RuntimeException('Duplicate creation attempted');
});
tt_expect($existing->ensurePasscode($row) === 91, 'Retry must recover a code created before timeout');
foreach ([['errcode' => -3009, 'errmsg' => 'secret PIN token'], [], ['keyboardPwdId' => 0]] as $response) {
    $bad = new TTLockClient($options, function (string $url, array $params) use ($response): array {
        if (str_ends_with($url, '/oauth2/token')) return ['access_token' => 'token'];
        if (str_ends_with($url, '/listKeyboardPwd')) return ['list' => [], 'pages' => 1];
        return $response;
    });
    try {
        $bad->ensurePasscode($row);
        throw new LogicException('Invalid API response accepted');
    } catch (TTLockException $e) {
        tt_expect(!str_contains($e->getMessage(), 'secret PIN token'), 'API errors must not expose secrets');
    }
}
$unknown = new TTLockClient($options, $transport);
try {
    $unknown->ensurePasscode(array_replace($row, ['code' => '87654321']), false);
    throw new LogicException('Uncertain request must not be recreated');
} catch (TTLockException $e) {
    tt_expect($e->uncertain, 'Uncertain creation must require reconciliation');
}
tt_expect(function_exists('booking_access_allowed'), 'Missing payment eligibility guard');
tt_expect(booking_access_allowed(['status' => 'CONFIRMED', 'payment_status' => 'PAID']), 'Paid confirmed booking is eligible');
foreach ([['status' => 'PENDING', 'payment_status' => 'PAID'], ['status' => 'CANCELLED', 'payment_status' => 'PAID'], ['status' => 'CONFIRMED', 'payment_status' => 'PENDING']] as $booking) {
    tt_expect(!booking_access_allowed($booking), 'Unpaid or cancelled booking must not receive code');
}
echo "TTLock tests: OK\n";
