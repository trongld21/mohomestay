<?php

declare(strict_types=1);

// Isolated SQLite harness: exercise real SQL/state transitions without local MySQL or TTLock.
final class AccessTestPDO extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->sqliteCreateFunction('IF', static fn($condition, $yes, $no) => $condition ? $yes : $no);
        $this->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => gmdate('Y-m-d H:i:s'));
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(' FOR UPDATE', '', $query);
        if (str_contains($query, 'GET_LOCK(') || str_contains($query, 'RELEASE_LOCK(')) $query = 'SELECT 1 WHERE ? IS NOT NULL';
        return parent::prepare($query, $options);
    }
}
$testDb = new AccessTestPDO();
$settings = ['ttlock.enabled' => true, 'ttlock.base_url' => 'https://api.sciener.com', 'ttlock.client_id' => 'test', 'ttlock.client_secret' => 'test', 'ttlock.username' => 'owner', 'ttlock.password_md5' => str_repeat('a', 32), 'ttlock.lock_id' => '7', 'mail.from' => 'booking@example.com'];
function db(): PDO { global $testDb; return $testDb; }
function config(string $key, mixed $default = null): mixed { global $settings; return $settings[$key] ?? $default; }
function verify_access(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
require dirname(__DIR__) . '/src/ttlock.php';
require dirname(__DIR__) . '/src/booking.php';
require dirname(__DIR__) . '/src/booking-access.php';
require dirname(__DIR__) . '/src/mail.php';
$testDb->exec("CREATE TABLE bookings (id TEXT PRIMARY KEY, room_id TEXT, booking_code TEXT, guest_name TEXT, guest_email TEXT, room_name TEXT, package_name_snapshot TEXT, stay_package TEXT, check_in TEXT, check_out TEXT, number_of_guests INT, total_price INT, status TEXT, payment_status TEXT, confirmation_email_status TEXT DEFAULT 'PENDING', confirmation_email_attempts INT DEFAULT 0, confirmation_email_sent_at TEXT, confirmation_email_error TEXT);
    CREATE TABLE rooms (id TEXT PRIMARY KEY, name TEXT);
    INSERT INTO rooms VALUES ('pink','Pink');
    CREATE TABLE booking_access_codes (booking_id TEXT PRIMARY KEY, lock_id INT, code TEXT, starts_at TEXT, ends_at TEXT, remote_id INT, code_mode TEXT DEFAULT 'CUSTOM', sync_status TEXT DEFAULT 'PENDING', sync_error TEXT, attempts INT DEFAULT 0, last_attempt_at TEXT);
    CREATE INDEX booking_access_pin_lookup ON booking_access_codes(lock_id,code);");
$booking = ['id' => str_repeat('b',32), 'room_id' => 'pink', 'booking_code' => 'MO-TEST', 'guest_name' => 'An', 'guest_email' => 'an@example.com', 'room_name' => 'Pink', 'package_name_snapshot' => 'Qua đêm', 'stay_package' => 'overnight', 'check_in' => '2099-01-15 07:00:00', 'check_out' => '2099-01-16 05:00:00', 'number_of_guests' => 2, 'total_price' => 200000, 'status' => 'CONFIRMED', 'payment_status' => 'PAID'];
$testDb->prepare('INSERT INTO bookings (' . implode(',', array_keys($booking)) . ') VALUES (' . implode(',', array_fill(0,count($booking),'?')) . ')')->execute(array_values($booking));
$remote = [];
$adds = 0;
$timeout = false;
$client = new TTLockClient(ttlock_options(), function(string $url, array $params) use (&$remote, &$adds, &$timeout): array {
    if (str_ends_with($url, '/oauth2/token')) return ['access_token' => 'test-token'];
    if (str_ends_with($url, '/listKeyboardPwd')) return ['list' => array_values($remote), 'pages' => 1];
    if (str_ends_with($url, '/keyboardPwd/get')) {
        $adds++;
        $remote[$adds] = $params + ['keyboardPwdId' => $adds, 'keyboardPwd' => '0563456'];
        if ($timeout) throw new TTLockException('Timeout', true);
        return ['keyboardPwdId' => $adds, 'keyboardPwd' => '0563456'];
    }
    if (str_ends_with($url, '/delete')) {
        unset($remote[$params['keyboardPwdId']]);
        return ['errcode' => 0];
    }
    throw new RuntimeException('Unexpected API path');
});
$id = $booking['id'];
$timeout = true;
verify_access(sync_booking_access($id, $client) === null, 'Timeout must not release code for email');
verify_access($testDb->query('SELECT sync_status FROM booking_access_codes')->fetchColumn() === 'UNKNOWN', 'Uncertain outcome must survive restart');
verify_access(sync_booking_access($id, $client) === null && $adds === 1, 'Polling must not hammer API after a timeout');
$timeout = false;
$access = sync_booking_access($id, $client, true);
verify_access($remote[1]['keyboardPwdName'] === 'MO-TEST', 'Name on TTLock matches visible order ID');
verify_access($access['sync_status'] === 'READY' && $adds === 1, 'Retry must recover remote code without second add');
verify_access($access['starts_at'] === '2099-01-15 07:00:00' && $access['ends_at'] === '2099-01-16 06:00:00', 'Persist activation at check-in');
verify_access(sync_booking_access($id, $client)['code'] === $access['code'] && $adds === 1, 'Repeated payment processing must reuse PIN');
$sent = [];
$transport = function(string $to, string $subject, string $html, array $headers) use (&$sent): bool { $sent[] = $html; return true; };
verify_access(!dispatch_confirmation_email($id, static fn(): bool => false), 'Mail failure stays retryable');
verify_access(dispatch_confirmation_email($id, $transport), 'Ready code can be emailed');
verify_access(str_contains($sent[0], $access['code']) && str_contains($sent[0], 'Mã mở cổng của bạn'), 'Email must contain booking-specific PIN');
verify_access(!dispatch_confirmation_email($id, $transport) && count($sent) === 1, 'Repeated webhook must not resend successful email');
$testDb->prepare("UPDATE bookings SET status='CANCELLED' WHERE id=?")->execute([$id]);
verify_access(sync_booking_access($id, $client) === null && count($remote) === 1, 'Offline generated code cannot be falsely marked physically revoked');
verify_access($testDb->query('SELECT sync_status FROM booking_access_codes')->fetchColumn() === 'FAILED', 'Warn administrator that physical revocation is unavailable');
verify_access(!dispatch_confirmation_email($id, $transport), 'Cancelled booking must not receive email');
// Paid but unconfirmed orders must not get a PIN.
$remote = [];
$testDb->exec('DELETE FROM booking_access_codes');
$testDb->exec("UPDATE bookings SET status='PENDING'");
verify_access(sync_booking_access($id, $client) === null && $adds === 1, 'Payment alone is not sufficient');
verify_access((int)$testDb->query('SELECT COUNT(*) FROM booking_access_codes')->fetchColumn() === 0, 'No PIN persisted for invalid booking');
$testDb->exec("UPDATE bookings SET status='CONFIRMED'");
$listTimeout = new TTLockClient(ttlock_options(), static function (string $url, array $params): array {
    if (str_ends_with($url, '/oauth2/token')) return ['access_token' => 'test-token'];
    throw new TTLockException('Read timeout', true);
});
verify_access(sync_booking_access($id, $listTimeout) === null, 'Read failure must withhold email');
verify_access($testDb->query('SELECT sync_status FROM booking_access_codes')->fetchColumn() === 'FAILED', 'Failure before add must remain retryable');
verify_access(sync_booking_access($id, $client, true)['sync_status'] === 'READY' && $adds === 2, 'Recover read timeout by creating exactly once');
// Gateway/custom mode writes the exact minute, without rounding offline codes earlier.
$settings['ttlock.code_mode'] = 'CUSTOM';
$customBooking = array_replace($booking, [
    'id' => str_repeat('c', 32), 'booking_code' => 'MO-CUSTOM',
    'check_in' => '2099-01-15 07:30:00',
]);
$testDb->prepare('INSERT INTO bookings (' . implode(',', array_keys($customBooking)) . ') VALUES ('
    . implode(',', array_fill(0, count($customBooking), '?')) . ')')->execute(array_values($customBooking));
$customStart = null;
$customClient = new TTLockClient(ttlock_options(), static function (string $url, array $params) use (&$customStart): array {
    if (str_ends_with($url, '/oauth2/token')) return ['access_token' => 'test-token'];
    if (str_ends_with($url, '/listKeyboardPwd')) return ['list' => [], 'pages' => 1];
    if (str_ends_with($url, '/keyboardPwd/add')) {
        $customStart = $params['startDate'];
        verify_access($params['addType'] === 2, 'Custom PIN must physically write through gateway.');
        return ['keyboardPwdId' => 99];
    }
    throw new RuntimeException('Unexpected custom API path.');
});
$customAccess = sync_booking_access($customBooking['id'], $customClient);
verify_access($customAccess['code_mode'] === 'CUSTOM' && $customAccess['starts_at'] === '2099-01-15 07:30:00', 'Custom mode must preserve exact check-in.');
verify_access($customStart === strtotime('2099-01-15 07:30:00 UTC') * 1000, 'Gateway must receive exact activation milliseconds.');

// Explicit recovery is allowed only after remote reconciliation, never by cron.
$settings['ttlock.code_mode'] = 'GENERATED';
$testDb->exec('DELETE FROM booking_access_codes');
$testDb->exec("UPDATE bookings SET status='CONFIRMED',payment_status='PAID'");
$remote = [];
$testDb->prepare("INSERT INTO booking_access_codes(booking_id,lock_id,code,starts_at,ends_at,code_mode,sync_status) VALUES(?,7,NULL,?,?,'GENERATED','UNKNOWN')")
    ->execute([$id, '2099-01-15 07:00:00', '2099-01-16 06:00:00']);
$before = $adds;
verify_access(sync_booking_access($id, $client, true) === null && $adds === $before, 'Ordinary retry must not recreate uncertain code');
$recreated = sync_booking_access($id, $client, true, true);
verify_access($recreated !== null && $recreated['sync_status'] === 'READY' && $adds === $before + 1, 'Explicit regeneration recovers UNKNOWN after complete empty scan');
verify_access(sync_booking_access($id, $client, true, true)['code'] === $recreated['code'] && $adds === $before + 1, 'Repeated regeneration preserves READY code');
$testDb->exec("UPDATE booking_access_codes SET code=NULL,remote_id=NULL,sync_status='UNKNOWN'");
verify_access(sync_booking_access($id, $client, true, true)['code'] === $recreated['code'] && $adds === $before + 1, 'Regeneration recovers remote code without creating a duplicate');
$testDb->exec("UPDATE booking_access_codes SET code=NULL,remote_id=NULL,sync_status='UNKNOWN'");
$remote = [];
verify_access(sync_booking_access($id, $listTimeout, true, true) === null && $adds === $before + 1, 'Failed remote listing must never permit creation');
verify_access($testDb->query('SELECT sync_status FROM booking_access_codes')->fetchColumn() === 'UNKNOWN', 'Failed explicit recovery must preserve uncertainty for cron');
try {
    retry_booking_access($id, false, $client);
    throw new LogicException('Retry must not report success while code is missing');
} catch (BookingException $error) {
    verify_access($error->httpStatus === 409 && str_contains($error->getMessage(), 'Chưa tìm thấy mã'), 'API reports actual reconciliation failure');
}
$retryResult = retry_booking_access($id, true, $client);
verify_access($retryResult['sync_status'] === 'READY' && $adds === $before + 2, 'API regeneration returns persisted READY result');
foreach ([['CANCELLED', 'PAID'], ['CONFIRMED', 'PENDING']] as [$status, $payment]) {
    $testDb->prepare('UPDATE bookings SET status=?,payment_status=? WHERE id=?')->execute([$status, $payment, $id]);
    try {
        retry_booking_access($id, true, $client);
        throw new LogicException('API must reject ineligible booking');
    } catch (BookingException $error) {
        verify_access($error->httpStatus === 422 && $adds === $before + 2, 'Regeneration API checks eligibility before contacting TTLock');
    }
}
$testDb->prepare("UPDATE bookings SET status='CONFIRMED',payment_status='PAID' WHERE id=?")->execute([$id]);
$testDb->exec("UPDATE booking_access_codes SET code=NULL,remote_id=NULL,sync_status='UNKNOWN'");
$before = $adds;
$remote = [];
foreach ([['CANCELLED', 'PAID'], ['CONFIRMED', 'PENDING']] as [$status, $payment]) {
    $testDb->prepare('UPDATE bookings SET status=?,payment_status=? WHERE id=?')->execute([$status, $payment, $id]);
    verify_access(sync_booking_access($id, $client, true, true) === null && $adds === $before, 'Regeneration must reject cancelled or unpaid bookings');
}
$testDb->prepare("UPDATE bookings SET status='CONFIRMED',payment_status='PAID' WHERE id=?")->execute([$id]);
$testDb->exec("UPDATE booking_access_codes SET ends_at='2020-01-01 00:00:00'");
verify_access(sync_booking_access($id, $client, true, true) === null && $adds === $before, 'Regeneration must reject expired access');

// TTLock may return a PIN already recorded for another booking on the same lock.
// Booking identity, rather than PIN uniqueness, owns provisioning idempotency.
$testDb->exec('DELETE FROM booking_access_codes');
$testDb->prepare("UPDATE bookings SET status='CONFIRMED',payment_status='PAID' WHERE id=?")->execute([$id]);
$remote = [];
$firstAccess = sync_booking_access($id, $client, true);
verify_access($firstAccess !== null && $firstAccess['sync_status'] === 'READY', 'First generated PIN is persisted');
$secondBooking = array_replace($booking, [
    'id' => str_repeat('d', 32), 'booking_code' => 'MO-REUSED-PIN',
    'check_in' => '2099-02-15 07:00:00', 'check_out' => '2099-02-16 05:00:00',
]);
$testDb->prepare('INSERT INTO bookings (' . implode(',', array_keys($secondBooking)) . ') VALUES ('
    . implode(',', array_fill(0, count($secondBooking), '?')) . ')')->execute(array_values($secondBooking));
$secondAccess = sync_booking_access($secondBooking['id'], $client, true);
verify_access($secondAccess !== null && $secondAccess['sync_status'] === 'READY', 'A reused TTLock PIN must not strand a second paid booking');
verify_access($secondAccess['code'] === $firstAccess['code'], 'Regression must exercise identical PINs');
verify_access((int)$testDb->query("SELECT COUNT(*) FROM booking_access_codes WHERE sync_status='READY'")->fetchColumn() === 2, 'Both booking access records are retained');
$before = $adds;
verify_access(sync_booking_access($secondBooking['id'], $client, true)['code'] === $secondAccess['code'] && $adds === $before, 'Repeated sync keeps each booking idempotent even when PINs repeat');

echo "Booking access integration tests: OK (SQLite; MySQL locking not exercised)\n";
