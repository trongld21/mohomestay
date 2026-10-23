<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/availability.php';

function operation_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function operation_conflict(callable $fn): void
{
    try {
        $fn();
        throw new RuntimeException('Conflicting inventory accepted.');
    } catch (BookingException $e) {
        operation_check($e->httpStatus === 409, 'Conflict must return 409.');
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE rooms (id TEXT PRIMARY KEY,is_active INTEGER,status TEXT);
CREATE TABLE bookings (id TEXT PRIMARY KEY,room_id TEXT,booking_code TEXT,status TEXT,check_in TEXT,check_out TEXT,hold_expires_at TEXT,is_test INTEGER DEFAULT 0);
CREATE TABLE room_availability (id TEXT,room_id TEXT,date TEXT,available INTEGER);
CREATE TABLE room_emergency_locks (id TEXT PRIMARY KEY,booking_id TEXT UNIQUE,room_id TEXT,starts_at TEXT,ended_at TEXT,locked_by TEXT,ended_by TEXT);
CREATE TABLE booking_checkout_charges (booking_id TEXT PRIMARY KEY,actual_checkout_at TEXT,confirmed_by TEXT,late_seconds INTEGER,billable_hours INTEGER,amount INTEGER,order_code INTEGER UNIQUE,payment_status TEXT,qr_code TEXT,checkout_url TEXT,payment_link_id TEXT,transaction_reference TEXT UNIQUE,paid_at TEXT);
CREATE TABLE room_quote_holds (id TEXT PRIMARY KEY,room_id TEXT,package_id TEXT,starts_at TEXT,ends_at TEXT,expires_at TEXT,released_at TEXT,created_by TEXT,released_by TEXT);
INSERT INTO rooms VALUES ('r',1,'ACTIVE');");
$id = str_repeat('a', 32);
$now = time();
$scheduled = gmdate('Y-m-d H:i:s', $now - 4200);
$pdo->prepare("INSERT INTO bookings (id,room_id,booking_code,status,check_in,check_out) VALUES (?,'r','MO-TEST','CHECKED_IN',?,?)")
    ->execute([$id, gmdate('Y-m-d H:i:s', $now - 3 * 3600), $scheduled]);
lock_overstaying_booking($pdo, $id, 'staff', $now);
lock_overstaying_booking($pdo, $id, 'staff', $now);
operation_check((int)$pdo->query('SELECT COUNT(*) FROM room_emergency_locks')->fetchColumn() === 1, 'Repeated lock must be idempotent.');
$future = gmdate('Y-m-d H:i:s', $now + 86400);
operation_conflict(fn() => assert_room_inventory($pdo, 'r', $future, gmdate('Y-m-d H:i:s', $now + 90000)));
$charge = confirm_room_checkout($pdo, $id, 'staff', $now);
operation_check((int)$charge['amount'] === 50000, '70 minutes late minus grace must cost 50000.');
operation_check($pdo->query('SELECT ended_at FROM room_emergency_locks')->fetchColumn() === gmdate('Y-m-d H:i:s', $now), 'Checkout must release emergency lock.');
$repeat = confirm_room_checkout($pdo, $id, 'other', $now + 7200);
operation_check($repeat['actual_checkout_at'] === $charge['actual_checkout_at'] && $repeat['amount'] === $charge['amount'], 'Retry must not change charge/time.');
operation_conflict(fn() => assert_room_inventory($pdo, 'r', gmdate('Y-m-d H:i:s', $now + 3599), gmdate('Y-m-d H:i:s', $now + 7200)));
assert_room_inventory($pdo, 'r', gmdate('Y-m-d H:i:s', $now + 3600), gmdate('Y-m-d H:i:s', $now + 7200));
try {
    ensure_checkout_payment($pdo, $id, static function (): never { throw new RuntimeException('Provider offline'); });
} catch (RuntimeException) {
}
operation_check($pdo->query('SELECT payment_status FROM booking_checkout_charges')->fetchColumn() === 'DUE', 'Provider failure must preserve receivable.');
$creates = 0;
$create = static function () use (&$creates): array {
    $creates++;
    return ['checkoutUrl' => 'https://pay.payos.vn/web/test', 'qrCode' => 'test', 'paymentLinkId' => 'link'];
};
ensure_checkout_payment($pdo, $id, $create);
ensure_checkout_payment($pdo, $id, $create);
operation_check($creates === 1, 'QR retry must reuse existing link.');
operation_conflict(fn() => lock_overstaying_booking($pdo, $id, 'staff', $now));
$data = ['orderCode' => $charge['order_code'], 'amount' => 50000, 'reference' => 'TX-1', 'paymentLinkId' => 'link'];
operation_check(apply_checkout_payment_callback($pdo, $data), 'Callback must reconcile the surcharge.');
operation_check(apply_checkout_payment_callback($pdo, $data), 'Duplicate callback must be idempotent.');
operation_check($pdo->query('SELECT payment_status FROM booking_checkout_charges')->fetchColumn() === 'PAID', 'Charge must be paid.');
operation_check(!apply_checkout_payment_callback($pdo, array_replace($data, ['orderCode' => 1])), 'Unknown charge must fall through to normal booking callback.');
try {
    apply_checkout_payment_callback($pdo, array_replace($data, ['amount' => 1]));
    throw new LogicException('Wrong amount accepted.');
} catch (BookingException $e) {
    operation_check($e->httpStatus === 422, 'Wrong amount must be rejected.');
}
$holdId = str_repeat('b', 32);
$a = gmdate('Y-m-d H:i:s', $now + 86400);
$b = gmdate('Y-m-d H:i:s', $now + 90000);
$pdo->prepare("INSERT INTO room_quote_holds (id,room_id,starts_at,ends_at,expires_at,created_by) VALUES (?,'r',?,?,?,'staff')")
    ->execute([$holdId, $a, $b, gmdate('Y-m-d H:i:s', $now + 3600)]);
operation_conflict(fn() => assert_room_inventory($pdo, 'r', $a, $b));
operation_check(!room_quote_hold_conflict($pdo, 'r', $a, $b, $now + 3600), 'Hold must expire exactly at one hour.');
release_room_quote_hold($pdo, $holdId, 'staff');
release_room_quote_hold($pdo, $holdId, 'staff');
assert_room_inventory($pdo, 'r', $a, $b);
$nextId = str_repeat('c', 32);
$pdo->prepare("INSERT INTO bookings (id,room_id,booking_code,status,check_in,check_out) VALUES (?,'r','MO-NEXT','CONFIRMED',?,?)")
    ->execute([$nextId, $a, $b]);
operation_conflict(fn() => assert_room_inventory($pdo, 'r', gmdate('Y-m-d H:i:s', $now + 80000), gmdate('Y-m-d H:i:s', $now + 86400 - 3599)));
assert_room_inventory($pdo, 'r', gmdate('Y-m-d H:i:s', $now + 78000), gmdate('Y-m-d H:i:s', $now + 86400 - 3600));
$localDate = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d');
$public = availability_snapshot($pdo, [['id' => 'r', 'status' => 'ACTIVE']], $localDate, 3);
$json = json_encode($public);
operation_check(!str_contains($json, 'MO-TEST') && !str_contains($json, 'staff') && !str_contains($json, 'bookingId'), 'Public intervals must not disclose booking identities or staff.');
operation_check(str_contains($json, 'CLEANING'), 'Public calendar must show cleaning time.');
$_SESSION = ['admin_until' => time() + 3600];
$pdo->prepare("UPDATE bookings SET status='CHECKED_IN' WHERE id=?")->execute([$nextId]);
$pdo->prepare("INSERT INTO room_emergency_locks (id,booking_id,room_id,starts_at,locked_by) VALUES (?,?,'r',?,'staff')")
    ->execute([uuid(), $nextId, gmdate('Y-m-d H:i:s', $now - 60)]);
update_booking_lifecycle($pdo, $nextId, 'CANCELLED');
update_booking_lifecycle($pdo, $nextId, 'CANCELLED');
$cancelled = $pdo->prepare('SELECT status FROM bookings WHERE id=?');
$cancelled->execute([$nextId]);
operation_check($cancelled->fetchColumn() === 'CANCELLED', 'Super Admin cancellation must persist and allow safe retries.');
$hold = $pdo->prepare('SELECT ended_at FROM room_emergency_locks WHERE booking_id=?');
$hold->execute([$nextId]);
operation_check((bool)$hold->fetchColumn(), 'Cancelling a stay must release its emergency lock.');
assert_room_inventory($pdo, 'r', $a, $b);
update_booking_lifecycle($pdo, $id, 'CANCELLED');
operation_check($pdo->query('SELECT payment_status FROM booking_checkout_charges')->fetchColumn() === 'PAID', 'Cancellation must preserve financial history.');
operation_conflict(fn() => ensure_checkout_payment($pdo, $id));
echo "Room operations transactions, grace, locks, cleaning, retries, callbacks and privacy: OK
";
