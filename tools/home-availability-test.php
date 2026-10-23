<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/home-availability.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE rooms (id TEXT, status TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE bookings (id TEXT, room_id TEXT, check_in TEXT, check_out TEXT, status TEXT, hold_expires_at TEXT)');
$pdo->exec('CREATE TABLE room_availability (room_id TEXT, date TEXT, available INTEGER)');
$pdo->exec('CREATE TABLE booking_checkout_charges (booking_id TEXT, actual_checkout_at TEXT)');
$pdo->exec('CREATE TABLE room_emergency_locks (room_id TEXT, starts_at TEXT, ended_at TEXT)');
$pdo->exec('CREATE TABLE room_quote_holds (room_id TEXT, starts_at TEXT, ends_at TEXT, expires_at TEXT, released_at TEXT)');

foreach (['free', 'busy', 'hold', 'expired', 'future', 'ended', 'cancelled', 'blocked'] as $id) {
    $pdo->prepare("INSERT INTO rooms VALUES (?, 'ACTIVE', 1)")->execute([$id]);
}
$pdo->exec("INSERT INTO rooms VALUES ('hidden', 'HIDDEN', 0), ('soon', 'COMING_SOON', 0)");
$add = $pdo->prepare('INSERT INTO bookings (room_id,check_in,check_out,status,hold_expires_at) VALUES (?, ?, ?, ?, ?)');
foreach ([
    ['busy', '2026-10-23 04:00:00', '2026-10-23 06:00:00', 'CONFIRMED', null],
    ['hold', '2026-10-23 04:00:00', '2026-10-23 06:00:00', 'PENDING', '2026-10-23 05:05:00'],
    ['expired', '2026-10-23 04:00:00', '2026-10-23 06:00:00', 'PENDING', '2026-10-23 05:00:00'],
    ['future', '2026-10-23 06:00:00', '2026-10-23 07:00:00', 'CONFIRMED', null],
    ['ended', '2026-10-23 04:00:00', '2026-10-23 05:00:00', 'CHECKED_IN', null],
    ['cancelled', '2026-10-23 04:00:00', '2026-10-23 06:00:00', 'CANCELLED', null],
] as $booking) $add->execute($booking);
$pdo->exec("INSERT INTO room_availability VALUES ('blocked', '2026-10-23', 0)");
$now = strtotime('2026-10-23 05:00:00 UTC');
$result = home_available_room_ids($pdo, $now);
sort($result);
$expected = ['cancelled', 'expired', 'free', 'future'];
if ($result !== $expected) throw new RuntimeException('Only rooms free at the current instant should be listed.');
$later = home_available_room_ids($pdo, strtotime('2026-10-23 08:00:00 UTC'));
if (!in_array('busy', $later, true) || !in_array('hold', $later, true) || !in_array('ended', $later, true)) {
    throw new RuntimeException('Rooms must reappear at checkout and disappear at check-in.');
}
echo "Homepage current availability tests: OK\n";
