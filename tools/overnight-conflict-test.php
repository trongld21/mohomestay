<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/availability.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE bookings (id TEXT,room_id TEXT,check_in TEXT,check_out TEXT,status TEXT,hold_expires_at TEXT)');
$pdo->exec('CREATE TABLE booking_checkout_charges (booking_id TEXT,actual_checkout_at TEXT)');
$pdo->exec("CREATE TABLE rooms (id TEXT,is_active INTEGER);
CREATE TABLE room_availability (room_id TEXT,date TEXT,available INTEGER);
CREATE TABLE room_emergency_locks (room_id TEXT,starts_at TEXT,ended_at TEXT);
CREATE TABLE room_quote_holds (room_id TEXT,starts_at TEXT,ends_at TEXT,released_at TEXT,expires_at TEXT);
INSERT INTO rooms VALUES ('pink',1)");

function conflict_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function local_utc(string $value): string
{
    return mysql_datetime(new DateTimeImmutable($value, new DateTimeZone('Asia/Ho_Chi_Minh')));
}

$insert = $pdo->prepare('INSERT INTO bookings VALUES (?,?,?,?,?,?)');
$matrixFixtures = [];
foreach (['22:00', '10:00'] as $checkIn) {
    [$start, $end] = package_window([
        'mode' => 'FIXED_TIME', 'checkInTime' => $checkIn, 'checkOutTime' => '10:00',
    ], '2027-01-15', '');
    $a = mysql_datetime($start);
    $b = mysql_datetime($end);
    conflict_expect($end->format('Y-m-d H:i') === '2027-01-16 10:00', 'Checkout must be next morning.');
    foreach ([180, 360] as $minutes) {
        $otherStart = local_utc('2027-01-16 01:00');
        $otherEnd = gmdate('Y-m-d H:i:s', strtotime($otherStart . ' UTC') + $minutes * 60);
        foreach (['CONFIRMED', 'CHECKED_IN', 'PENDING'] as $status) {
            $pdo->exec('DELETE FROM bookings');
            $insert->execute(['night', 'pink', $a, $b, $status, gmdate('Y-m-d H:i:s', time() + 900)]);
            conflict_expect(room_booking_conflict($pdo, 'pink', $otherStart, $otherEnd), 'Night must block overlapping hourly booking.');
            conflict_expect(!room_booking_conflict($pdo, 'black', $otherStart, $otherEnd), 'Other room must remain independent.');
            conflict_expect(room_booking_conflict($pdo, 'pink', local_utc('2027-01-16 10:00'), local_utc('2027-01-16 13:00')), 'Checkout still needs one hour cleaning.');
            conflict_expect(!room_booking_conflict($pdo, 'pink', local_utc('2027-01-16 11:00'), local_utc('2027-01-16 14:00')), 'Exactly one hour after checkout is available.');
            $pdo->exec('DELETE FROM bookings');
            $insert->execute(['hourly', 'pink', $otherStart, $otherEnd, $status, gmdate('Y-m-d H:i:s', time() + 900)]);
            conflict_expect(room_booking_conflict($pdo, 'pink', $a, $b), 'Hourly booking must also block later night request.');
        }
    }
    $pdo->exec('DELETE FROM bookings');
    $insert->execute(['matrix-night', 'pink', $a, $b, 'CONFIRMED', null]);
    $snapshot = availability_snapshot($pdo, [['id' => 'pink', 'status' => 'ACTIVE']], '2027-01-15', 2, strtotime('2027-01-14 UTC'));
    $checks = [];
    $rangeStart = strtotime($snapshot['rangeStart']);
    for ($offset = 0; $offset < 48; $offset++) {
        $slotStart = $rangeStart + $offset * 3600;
        $slotEnd = $slotStart + 3600;
        $checks[] = [
            'start' => $slotStart * 1000,
            'end' => $slotEnd * 1000,
            'available' => !room_booking_conflict($pdo, 'pink', gmdate('Y-m-d H:i:s', $slotStart), gmdate('Y-m-d H:i:s', $slotEnd)),
        ];
    }
    $matrixFixtures[] = ['room' => $snapshot['rooms'][0], 'checks' => $checks];
}

if (in_array('--json', $argv, true)) {
    echo json_encode($matrixFixtures, JSON_THROW_ON_ERROR);
    exit;
}
echo "22:00–10:00 and 10:00–10:00 packages block overlapping 3h/6h stays in both directions, including cleaning: OK\n";
