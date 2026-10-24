<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function extra_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$package = ['id' => 'three', 'name' => 'Gói 3 giờ', 'price' => 159000];
$end = new DateTimeImmutable('2027-01-16 23:00', new DateTimeZone('Asia/Ho_Chi_Minh'));
foreach ([0, 1, 2] as $hours) {
    $quote = booking_extra_hours_details($package, $end, (string)$hours);
    extra_check($quote['total'] === 159000 + $hours * 50000, 'Server must add exactly 50000 per hour.');
    extra_check($quote['end']->getTimestamp() === $end->getTimestamp() + $hours * 3600, 'Extra hours extend checkout across midnight.');
    extra_check($quote['extraHoursAmount'] === $hours * 50000, 'Surcharge must be recorded separately.');
}
foreach ([-1, 3, '1.5', 'invalid', true, []] as $invalid) {
    try {
        booking_extra_hours_details($package, $end, $invalid);
        throw new RuntimeException('Invalid extra hours accepted.');
    } catch (BookingException $error) {
        extra_check($error->httpStatus === 422, 'Invalid extra hours must return 422.');
    }
}
$room = fallback_rooms()['pink'];
$input = [
    'packageId' => 'pink-3h', 'date' => date('Y-m-d', strtotime('+2 days')), 'time' => '22:00',
    'guestName' => 'Test Guest', 'guestPhone' => '0901234567', 'guestEmail' => 'guest@example.test',
    'numberOfGuests' => 2, 'acceptTerms' => true, 'extraHours' => 2, 'extraHoursAmount' => 1, 'total' => 1,
];
$quote = quote_booking_for_room($room, $input);
extra_check($quote['total'] === $quote['packagePrice'] + 100000, 'Ignore forged client surcharge/total.');
extra_check($quote['end']->getTimestamp() - $quote['start']->getTimestamp() === 5 * 3600, 'Three-hour package plus two hours must last five hours.');
$room['packages'][] = [
    'id' => 'night-extra', 'name' => 'Night', 'price' => 200000, 'enabled' => true,
    'mode' => 'FIXED_TIME', 'checkInTime' => '21:00', 'checkOutTime' => '09:00',
];
$night = quote_booking_for_room($room, array_replace($input, ['packageId' => 'night-extra']));
extra_check($night['end']->format('H:i') === '11:00', 'Overnight checkout must extend from 09:00 to 11:00.');
extra_check($night['packageCheckOut'] === '09:00', 'Preserve the original package snapshot.');
extra_check($night['requestedDate'] === $input['date'], 'Preserve the original overnight arrival date on recheck.');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE bookings (id TEXT,room_id TEXT,check_in TEXT,check_out TEXT,status TEXT,hold_expires_at TEXT);
CREATE TABLE booking_checkout_charges (booking_id TEXT,actual_checkout_at TEXT);
CREATE TABLE room_emergency_locks (id TEXT,room_id TEXT,starts_at TEXT,ended_at TEXT);
CREATE TABLE room_quote_holds (id TEXT,room_id TEXT,starts_at TEXT,ends_at TEXT,released_at TEXT,expires_at TEXT);");
$nextStart = $quote['end']->modify('+30 minutes');
$pdo->prepare("INSERT INTO bookings VALUES ('next','pink',?,?,'CONFIRMED',NULL)")
    ->execute([mysql_datetime($nextStart), mysql_datetime($nextStart->modify('+3 hours'))]);
try {
    assert_room_inventory($pdo, 'pink', mysql_datetime($quote['start']), mysql_datetime($quote['end']));
    throw new RuntimeException('Extension without enough cleaning time accepted.');
} catch (BookingException $error) {
    extra_check($error->httpStatus === 409, 'Extended checkout must leave the entire cleaning hour.');
}
echo "Booking extra hour pricing, validation, checkout and cleaning conflicts: OK\n";
