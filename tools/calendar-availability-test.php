<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/availability.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE rooms (id TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE bookings (id TEXT, room_id TEXT, check_in TEXT, check_out TEXT, status TEXT, hold_expires_at TEXT)');
$pdo->exec('CREATE TABLE room_availability (room_id TEXT, date TEXT, available INTEGER)');
$pdo->exec('CREATE TABLE booking_checkout_charges (booking_id TEXT, actual_checkout_at TEXT)');
$pdo->exec('CREATE TABLE room_emergency_locks (room_id TEXT, starts_at TEXT, ended_at TEXT)');
$pdo->exec('CREATE TABLE room_quote_holds (room_id TEXT, starts_at TEXT, ends_at TEXT, expires_at TEXT, released_at TEXT)');

$pdo->exec("INSERT INTO rooms VALUES ('room', 1)");
$insert = $pdo->prepare('INSERT INTO bookings (room_id,check_in,check_out,status,hold_expires_at) VALUES (?, ?, ?, ?, ?)');
foreach ([
    ['room', '2027-01-18 01:00:00', '2027-01-18 04:00:00', 'CONFIRMED', null],
    ['room', '2027-02-15 01:00:00', '2027-02-15 04:00:00', 'CHECKED_IN', null],
    ['room', '2027-01-16 01:00:00', '2027-01-16 04:00:00', 'PENDING', '2027-01-15 01:15:00'],
    ['room', '2027-01-17 01:00:00', '2027-01-17 04:00:00', 'PENDING', '2027-01-15 00:59:00'],
    ['room', '2027-01-19 01:00:00', '2027-01-19 04:00:00', 'CANCELLED', null],
] as $booking) $insert->execute($booking);
$pdo->exec("INSERT INTO room_availability VALUES ('room', '2027-02-01', 0)");
$rooms = [['id' => 'room', 'status' => 'ACTIVE']];
$now = strtotime('2027-01-15 01:00:00 UTC');
$snapshot = availability_snapshot($pdo, $rooms, '2027-01-15', 62, $now);
$intervals = $snapshot['rooms'][0]['intervals'];
if (count($intervals) !== 7) throw new RuntimeException('Must include bookings beyond 2 days and next-month blocks, excluding cancelled/expired holds.');
$later = availability_snapshot($pdo, $rooms, '2027-01-15', 62, $now + 900);
if (count($later['rooms'][0]['intervals']) !== 5) throw new RuntimeException('Hold must disappear at its expiry.');
foreach ([0, 63] as $days) {
    try {
        availability_snapshot($pdo, $rooms, '2027-01-15', $days, $now);
        throw new RuntimeException('Unbounded range accepted.');
    } catch (BookingException) {
    }
}
echo "Calendar availability range, holds and block tests: OK\n";
