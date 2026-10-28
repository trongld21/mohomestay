<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

function recovery_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

recovery_expect(function_exists('confirm_booking_receipt'), 'Missing permission-audited booking receipt recovery.');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', fn() => gmdate('Y-m-d H:i:s'));
$pdo->exec("CREATE TABLE rooms (id TEXT PRIMARY KEY,status TEXT,is_active INTEGER);
CREATE TABLE bookings (id TEXT PRIMARY KEY,room_id TEXT,booking_code TEXT,status TEXT,payment_status TEXT,payment_method TEXT,total_price INTEGER,check_in TEXT,check_out TEXT,hold_expires_at TEXT,is_test INTEGER,booking_source TEXT,continued_to_booking_id TEXT);
CREATE TABLE payments (id TEXT PRIMARY KEY,booking_id TEXT UNIQUE,amount INTEGER,payment_method TEXT,status TEXT,paid_at TEXT,transaction_id TEXT,failure_reason TEXT);
CREATE TABLE booking_payment_receipts (id TEXT PRIMARY KEY,booking_id TEXT UNIQUE,confirmed_by TEXT,amount INTEGER,payment_method TEXT,confirmed_at TEXT);
CREATE TABLE booking_checkout_charges (booking_id TEXT,actual_checkout_at TEXT);
CREATE TABLE room_quote_holds (id TEXT,room_id TEXT,starts_at TEXT,ends_at TEXT,released_at TEXT,expires_at TEXT);
CREATE TABLE room_emergency_locks (id TEXT,room_id TEXT,starts_at TEXT,ended_at TEXT);
CREATE TABLE room_availability (id TEXT,room_id TEXT,date TEXT,available INTEGER);
INSERT INTO rooms VALUES ('r','ACTIVE',1);");
$start = gmdate('Y-m-d H:i:s', time() + 3600);
$end = gmdate('Y-m-d H:i:s', time() + 46800);
$pdo->prepare("INSERT INTO bookings VALUES ('one','r','MO-ONE','PENDING','UNPAID','payos',223000,?,?,?,0,'WEBSITE',NULL)")
    ->execute([$start, $end, gmdate('Y-m-d H:i:s', time() - 3600)]);
$pdo->exec("INSERT INTO payments VALUES ('p','one',223000,'payos','PENDING',NULL,NULL,NULL)");
confirm_booking_receipt($pdo, 'one', 'bank_transfer', 'admin');
$row = $pdo->query("SELECT * FROM bookings WHERE id='one'")->fetch();
recovery_expect($row['status'] === 'CONFIRMED' && $row['payment_status'] === 'PAID', 'Expired paid booking must reserve its room again.');
confirm_booking_receipt($pdo, 'one', 'bank_transfer', 'admin');
recovery_expect((int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() === 1, 'Receipt retry must not duplicate revenue.');
recovery_expect((int)$pdo->query('SELECT COUNT(*) FROM booking_payment_receipts')->fetchColumn() === 1, 'Receipt must be audited exactly once.');
$pdo->exec("UPDATE bookings SET status='PENDING',payment_status='UNPAID' WHERE id='one'");
$pdo->prepare("INSERT INTO room_quote_holds VALUES ('h','r',?,?,NULL,?)")->execute([$start, $end, $end]);
try {
    confirm_booking_receipt($pdo, 'one', 'bank_transfer', 'admin');
    throw new RuntimeException('A conflicting hold was overwritten.');
} catch (BookingException $error) {
    recovery_expect($error->httpStatus === 409, 'Conflict must be actionable.');
}
recovery_expect($pdo->query("SELECT status FROM bookings WHERE id='one'")->fetchColumn() === 'PENDING', 'Conflict must roll back confirmation.');
echo "Audited booking receipt recovery: OK\n";

recovery_expect(admin_api_permission('/api/admin/bookings/' . str_repeat('a', 32) . '/payment', 'POST') === 'bookings.payment', 'Receipt API must require its specific permission.');
$pdo->exec('DELETE FROM room_quote_holds');
$pdo->exec("ALTER TABLE bookings ADD COLUMN order_code TEXT;
ALTER TABLE bookings ADD COLUMN transaction_id TEXT;
ALTER TABLE rooms ADD COLUMN name TEXT;
CREATE TABLE staff_email_notifications (id TEXT,booking_id TEXT,event_type TEXT,recipient TEXT,payload TEXT);
UPDATE bookings SET order_code='1793190000000',status='PENDING',payment_status='UNPAID' WHERE id='one';");
$callback = ['orderCode'=>'1793190000000','amount'=>223000,'reference'=>'BANK-1'];
$paid = apply_payment_callback($pdo, $callback);
recovery_expect($paid['status'] === 'CONFIRMED' && $paid['payment_status'] === 'PAID', 'Verified payment discovered after hold expiry must recover a free room.');
apply_payment_callback($pdo, $callback);
recovery_expect((int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() === 1, 'Webhook retry after manual receipt must not duplicate a payment.');
$pdo->exec("UPDATE bookings SET status='CANCELLED',payment_status='UNPAID' WHERE id='one'");
$cancelled = apply_payment_callback($pdo, $callback);
recovery_expect($cancelled['status'] === 'CANCELLED' && $cancelled['payment_status'] === 'PAID', 'Record money without silently resurrecting a cancelled booking.');
$pdo->exec("UPDATE bookings SET payment_status='REFUNDED' WHERE id='one'");
recovery_expect(apply_payment_callback($pdo, $callback)['payment_status'] === 'REFUNDED', 'Late callback must not overwrite a refund.');
echo "Closed-browser expired-hold callback and idempotency: OK\n";
