<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/booking.php';
require dirname(__DIR__) . '/src/room-operations.php';
require dirname(__DIR__) . '/src/room-inventory.php';
require dirname(__DIR__) . '/src/ttlock.php';
require dirname(__DIR__) . '/src/booking-stay.php';
require dirname(__DIR__) . '/src/availability.php';

$actor = ['id' => 'admin', 'root' => true];
function current_account(): ?array
{
    global $actor;
    return $actor;
}
function uuid(): string
{
    return bin2hex(random_bytes(16));
}
function expect_stay(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function reject_stay(callable $action, int $status): void
{
    try {
        $action();
        throw new RuntimeException('Expected rejection: ' . $status);
    } catch (BookingException $error) {
        expect_stay($error->httpStatus === $status, $error->getMessage());
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE rooms (id TEXT PRIMARY KEY,status TEXT,is_active INTEGER,max_guests INTEGER);
CREATE TABLE bookings (id TEXT PRIMARY KEY,room_id TEXT,check_in TEXT,check_out TEXT,status TEXT,hold_expires_at TEXT,number_of_guests INTEGER,total_price INTEGER,payment_status TEXT,booking_code TEXT,created_at TEXT);
CREATE TABLE booking_checkout_charges (booking_id TEXT PRIMARY KEY,actual_checkout_at TEXT);
CREATE TABLE room_emergency_locks (id TEXT,booking_id TEXT,room_id TEXT,starts_at TEXT,ended_at TEXT);
CREATE TABLE room_quote_holds (id TEXT,room_id TEXT,starts_at TEXT,ends_at TEXT,released_at TEXT,expires_at TEXT);
CREATE TABLE room_availability (id TEXT,room_id TEXT,date TEXT,available INTEGER);
CREATE TABLE booking_access_codes (booking_id TEXT PRIMARY KEY,starts_at TEXT,ends_at TEXT,sync_status TEXT,remote_id INTEGER,attempts INTEGER,code_mode TEXT);
CREATE TABLE booking_stay_changes (id TEXT PRIMARY KEY,booking_id TEXT,actor_id TEXT,old_room_id TEXT,new_room_id TEXT,old_check_in TEXT,old_check_out TEXT,new_check_in TEXT,new_check_out TEXT,reason TEXT);
INSERT INTO rooms VALUES ('mo','ACTIVE',1,2),('black','ACTIVE',1,2),('hidden','HIDDEN',0,2);");
$id = str_repeat('a', 32);
$pdo->prepare("INSERT INTO bookings VALUES (?,'mo','2026-10-23 15:00:00','2026-10-24 03:00:00','CONFIRMED',NULL,2,330000,'PAID','MO-TEST','2026-10-20 01:00:00')")->execute([$id]);
$input = [
    'roomId' => 'black', 'checkIn' => '2026-10-23T22:00', 'checkOut' => '2026-10-24T10:00',
    'expectedRoomId' => 'mo', 'expectedCheckIn' => '2026-10-23 15:00:00', 'expectedCheckOut' => '2026-10-24 03:00:00',
    'reason' => 'Khách yêu cầu chuyển phòng',
];
$actor = null;
reject_stay(fn() => correct_booking_stay($pdo, $id, $input), 403);
$actor = ['id' => 'staff', 'root' => false];
reject_stay(fn() => correct_booking_stay($pdo, $id, $input + ['root' => true]), 403);
$actor = ['id' => 'admin', 'root' => true];
reject_stay(fn() => correct_booking_stay($pdo, $id, array_replace($input, ['checkIn' => '2026-02-30T22:00'])), 422);
foreach (['checkIn' => '2026-10-23T22:15', 'checkOut' => '2026-10-24T10:45'] as $key => $value) {
    reject_stay(fn() => correct_booking_stay($pdo, $id, array_replace($input, [$key => $value])), 422);
}
reject_stay(fn() => correct_booking_stay($pdo, $id, array_replace($input, ['checkOut' => '2026-10-23T21:00'])), 422);
reject_stay(fn() => correct_booking_stay($pdo, $id, array_replace($input, ['roomId' => 'hidden'])), 409);
$pdo->exec("UPDATE rooms SET max_guests=1 WHERE id='black'");
reject_stay(fn() => correct_booking_stay($pdo, $id, $input), 409);
$pdo->exec("UPDATE rooms SET max_guests=2 WHERE id='black'");
$pdo->exec("INSERT INTO room_availability VALUES ('block','black','2026-10-23',0)");
reject_stay(fn() => correct_booking_stay($pdo, $id, $input), 409);
$pdo->exec('DELETE FROM room_availability');
$pdo->prepare("INSERT INTO room_emergency_locks VALUES ('lock',?,'mo','2026-10-24 03:00:00',NULL)")->execute([$id]);
reject_stay(fn() => correct_booking_stay($pdo, $id, $input), 409);
$pdo->exec('DELETE FROM room_emergency_locks');
$pdo->prepare('INSERT INTO booking_checkout_charges VALUES (?,?)')->execute([$id, '2026-10-24 03:00:00']);
reject_stay(fn() => correct_booking_stay($pdo, $id, $input), 409);
$pdo->exec('DELETE FROM booking_checkout_charges');
$pdo->prepare("INSERT INTO room_quote_holds VALUES ('hold','black','2026-10-23 14:00:00','2026-10-24 04:00:00',NULL,?)")
    ->execute([gmdate('Y-m-d H:i:s', time() + 3600)]);
reject_stay(fn() => correct_booking_stay($pdo, $id, $input), 409);
$pdo->exec('DELETE FROM room_quote_holds');
$pdo->prepare("INSERT INTO bookings VALUES (?,'black','2026-10-24 03:29:00','2026-10-24 07:00:00','CONFIRMED',NULL,1,1,'PAID','MO-OTHER','2026-10-20 00:00:00')")->execute([str_repeat('b', 32)]);
reject_stay(fn() => correct_booking_stay($pdo, $id, $input), 409);
expect_stay($pdo->query('SELECT COUNT(*) FROM booking_stay_changes')->fetchColumn() === 0, 'Rejected edit must not be audited as successful');
$pdo->exec("DELETE FROM bookings WHERE booking_code='MO-OTHER'");
$pdo->prepare("INSERT INTO booking_access_codes VALUES (?,?,?,'READY',123,1,'GENERATED')")->execute([$id, '2026-10-23 15:00:00', '2026-10-24 03:00:00']);
$changedTime = array_replace($input, ['checkOut' => '2026-10-24T11:00']);
reject_stay(fn() => correct_booking_stay($pdo, $id, $changedTime), 409);
$pdo->exec('DELETE FROM booking_access_codes');
$pdo->prepare("INSERT INTO booking_access_codes VALUES (?,?,?,'PENDING',NULL,0,'GENERATED')")
    ->execute([$id, '2026-10-23 15:00:00', '2026-10-24 04:00:00']);
$input['checkOut'] = '2026-10-24T11:00';
correct_booking_stay($pdo, $id, $input);
$booking = $pdo->query('SELECT * FROM bookings')->fetch();
expect_stay($booking['room_id'] === 'black' && $booking['check_in'] === '2026-10-23 15:00:00', 'Root can transfer a historical stay');
expect_stay((int)$booking['total_price'] === 330000 && $booking['payment_status'] === 'PAID', 'Price and payment must be preserved');
expect_stay($booking['created_at'] === '2026-10-20 01:00:00', 'Booking creation date must be preserved');
expect_stay($pdo->query('SELECT ends_at FROM booking_access_codes')->fetchColumn() === '2026-10-24 05:00:00', 'Unissued code keeps the updated stay plus one-hour access buffer');
expect_stay((int)$pdo->query('SELECT COUNT(*) FROM booking_stay_changes')->fetchColumn() === 1, 'Successful edit needs one audit entry');
reject_stay(fn() => correct_booking_stay($pdo, $id, $input), 409);
// The matrix must immediately release the old room and show the new stay plus cleaning.
$snapshot = availability_snapshot($pdo, [['id' => 'mo', 'status' => 'ACTIVE'], ['id' => 'black', 'status' => 'ACTIVE']], '2026-10-23', 2);
$oldRoom = $snapshot['rooms'][0];
$newRoom = $snapshot['rooms'][1];
expect_stay(count($oldRoom['intervals']) === 0, 'Old room matrix must be released after transfer');
expect_stay(count($newRoom['intervals']) === 2, 'New room matrix must contain stay and cleaning');
expect_stay($newRoom['intervals'][0]['start'] === '2026-10-23T15:00:00+00:00', 'Matrix uses corrected Vietnam arrival in UTC');
expect_stay($newRoom['intervals'][1]['status'] === 'CLEANING'
    && $newRoom['intervals'][1]['start'] === '2026-10-24T04:00:00+00:00'
    && $newRoom['intervals'][1]['end'] === '2026-10-24T04:30:00+00:00', 'Matrix derives exactly 30 minutes cleaning from corrected checkout');
// Stay corrections require half-hour boundaries, including zero seconds.
$seconds = [
    'roomId' => 'mo', 'checkIn' => '2026-10-23T22:00:25', 'checkOut' => '2026-10-24T11:00:25',
    'expectedRoomId' => 'black', 'expectedCheckIn' => '2026-10-23 15:00:00', 'expectedCheckOut' => '2026-10-24 04:00:00',
    'reason' => 'Kiểm tra độ chính xác thời gian',
];
reject_stay(fn() => correct_booking_stay($pdo, $id, $seconds), 422);
$halfHour = array_replace($seconds, ['checkIn' => '2026-10-23T22:30:00', 'checkOut' => '2026-10-24T11:30:00']);
correct_booking_stay($pdo, $id, $halfHour);
expect_stay($pdo->query('SELECT check_out FROM bookings')->fetchColumn() === '2026-10-24 04:30:00', 'Half-hour corrections must be accepted exactly.');
echo "Superadmin stay correction permissions, past dates, price preservation, conflicts, audit and stale edits: OK\n";
$pdo->exec("UPDATE booking_access_codes SET sync_status='READY',remote_id=123,attempts=1");
$accessBeforeMove = $pdo->query('SELECT * FROM booking_access_codes')->fetch();
$move = [
    'roomId' => 'black', 'preserveTimes' => true,
    'checkIn' => '2026-10-01T00:00', 'checkOut' => '2026-10-01T01:00',
    'expectedRoomId' => 'mo', 'expectedCheckIn' => '2026-10-23 15:30:00', 'expectedCheckOut' => '2026-10-24 04:30:00',
    'reason' => 'Chuyển phòng giữ nguyên mã cổng',
];
correct_booking_stay($pdo, $id, $move);
$moved = $pdo->query('SELECT * FROM bookings')->fetch();
expect_stay($moved['room_id'] === 'black' && $moved['check_in'] === $move['expectedCheckIn'] && $moved['check_out'] === $move['expectedCheckOut'], 'Room-only transfer must use persisted times, ignoring submitted dates.');
expect_stay($pdo->query('SELECT * FROM booking_access_codes')->fetch() === $accessBeforeMove, 'Issued gate credential must remain unchanged when moving rooms.');
reject_stay(fn() => correct_booking_stay($pdo, $id, $move), 409);
echo "Issued-code room-only transfer, preserved dates and stale edits: OK\n";
