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
require dirname(__DIR__) . '/src/booking-access.php';
require dirname(__DIR__) . '/src/mail.php';
$testDb->exec("CREATE TABLE bookings (id TEXT PRIMARY KEY, room_id TEXT, booking_code TEXT, guest_name TEXT, guest_email TEXT, room_name TEXT, package_name_snapshot TEXT, stay_package TEXT, check_in TEXT, check_out TEXT, number_of_guests INT, total_price INT, status TEXT, payment_status TEXT, confirmation_email_status TEXT DEFAULT 'PENDING', confirmation_email_attempts INT DEFAULT 0, confirmation_email_sent_at TEXT, confirmation_email_error TEXT);
    CREATE TABLE rooms (id TEXT PRIMARY KEY, name TEXT);
    INSERT INTO rooms VALUES ('pink','Pink');
    CREATE TABLE booking_access_codes (booking_id TEXT PRIMARY KEY, lock_id INT, code TEXT, starts_at TEXT, ends_at TEXT, remote_id INT, sync_status TEXT DEFAULT 'PENDING', sync_error TEXT, attempts INT DEFAULT 0, last_attempt_at TEXT, UNIQUE(lock_id,code));");
$booking = ['id' => str_repeat('b',32), 'room_id' => 'pink', 'booking_code' => 'MO-TEST', 'guest_name' => 'An', 'guest_email' => 'an@example.com', 'room_name' => 'Pink', 'package_name_snapshot' => 'Qua đêm', 'stay_package' => 'overnight', 'check_in' => '2099-01-15 07:00:00', 'check_out' => '2099-01-16 05:00:00', 'number_of_guests' => 2, 'total_price' => 200000, 'status' => 'CONFIRMED', 'payment_status' => 'PAID'];
$testDb->prepare('INSERT INTO bookings (' . implode(',', array_keys($booking)) . ') VALUES (' . implode(',', array_fill(0,count($booking),'?')) . ')')->execute(array_values($booking));
$remote = [];
$adds = 0;
$timeout = false;
$client = new TTLockClient(ttlock_options(), function(string $url, array $params) use (&$remote, &$adds, &$timeout): array {
    if (str_ends_with($url, '/oauth2/token')) return ['access_token' => 'test-token'];
    if (str_ends_with($url, '/listKeyboardPwd')) return ['list' => array_values($remote), 'pages' => 1];
    if (str_ends_with($url, '/add')) {
        $adds++;
        $remote[$adds] = $params + ['keyboardPwdId' => $adds];
        if ($timeout) throw new TTLockException('Timeout', true);
        return ['keyboardPwdId' => $adds];
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
verify_access($access['sync_status'] === 'READY' && $adds === 1, 'Retry must recover remote code without second add');
verify_access($access['starts_at'] === '2099-01-15 06:00:00' && $access['ends_at'] === '2099-01-16 06:00:00', 'Persist buffered dates');
verify_access(sync_booking_access($id, $client)['code'] === $access['code'] && $adds === 1, 'Repeated payment processing must reuse PIN');
$sent = [];
$transport = function(string $to, string $subject, string $html, array $headers) use (&$sent): bool { $sent[] = $html; return true; };
verify_access(!dispatch_confirmation_email($id, static fn(): bool => false), 'Mail failure stays retryable');
verify_access(dispatch_confirmation_email($id, $transport), 'Ready code can be emailed');
verify_access(str_contains($sent[0], $access['code']) && str_contains($sent[0], 'Mã mở cổng của bạn'), 'Email must contain booking-specific PIN');
verify_access(!dispatch_confirmation_email($id, $transport) && count($sent) === 1, 'Repeated webhook must not resend successful email');
$testDb->prepare("UPDATE bookings SET status='CANCELLED' WHERE id=?")->execute([$id]);
verify_access(sync_booking_access($id, $client) === null && !$remote, 'Cancellation must remove PIN from lock');
verify_access($testDb->query('SELECT sync_status FROM booking_access_codes')->fetchColumn() === 'REVOKED', 'Track physical revocation');
verify_access(!dispatch_confirmation_email($id, $transport), 'Cancelled booking must not receive email');
// Paid but unconfirmed orders must not get a PIN.
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
echo "Booking access integration tests: OK (SQLite; MySQL locking not exercised)\n";
