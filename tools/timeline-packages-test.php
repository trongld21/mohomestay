<?php

declare(strict_types=1);

require __DIR__ . '/room-operations-db-test.php';
require_once APP_ROOT . '/src/availability.php';

$pdo->exec('DELETE FROM bookings; DELETE FROM room_quote_holds; DELETE FROM room_emergency_locks; DELETE FROM booking_checkout_charges;');
$day = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
$room = ['id' => 'r', 'status' => 'ACTIVE', 'packages' => [
    ['id' => 'short', 'name' => 'Short', 'mode' => 'DURATION', 'durationMinutes' => 180, 'price' => 100000, 'enabled' => true],
    ['id' => 'long', 'name' => 'Long', 'mode' => 'DURATION', 'durationMinutes' => 360, 'price' => 200000, 'enabled' => true],
    ['id' => 'night', 'name' => 'Night', 'mode' => 'FIXED_TIME', 'checkInTime' => '21:00', 'checkOutTime' => '09:00', 'price' => 300000, 'enabled' => true],
    ['id' => 'disabled', 'name' => 'Disabled', 'mode' => 'DURATION', 'durationMinutes' => 60, 'price' => 100, 'enabled' => false],
]];
$options = timeline_available_packages($pdo, $room, $day, '13:00');
operation_check(array_column($options, 'id') === ['short', 'long'], 'Only enabled packages starting in the selected hour may appear.');
$start = new DateTimeImmutable($day . ' 17:00', new DateTimeZone('Asia/Ho_Chi_Minh'));
$pdo->prepare("INSERT INTO bookings (id,room_id,check_in,check_out,status) VALUES ('busy','r',?,?,'CONFIRMED')")
    ->execute([mysql_datetime($start), mysql_datetime($start->modify('+2 hours'))]);
$options = timeline_available_packages($pdo, $room, $day, '13:00');
operation_check(array_column($options, 'id') === ['short'], 'Reject a package overlapping a later stay; allow exact cleaning boundary.');
operation_check(timeline_available_packages($pdo, $room, $day, '14:00') === [], 'New stay cleaning must fit before the next booking.');
operation_check(timeline_available_packages($pdo, $room, $day, '19:00') === [], 'Existing stay cleaning must block a package.');
$pdo->exec('DELETE FROM bookings');
$hold = create_room_quote_hold($pdo, ['roomId' => 'r', 'date' => $day, 'time' => '15:00'], 'staff');
operation_check(timeline_available_packages($pdo, $room, $day, '13:00') === [], 'Reject packages crossing staff holds.');
release_room_quote_hold($pdo, $hold['id'], 'staff');
$options = timeline_available_packages($pdo, $room, $day, '21:00');
operation_check(array_column($options, 'id') === ['short', 'long', 'night'], 'At 21:00 regular and overnight packages must be available.');
operation_check(strtotime($options[2]['end']) - strtotime($options[2]['start']) === 12 * 3600, 'Overnight must cross midnight.');
$pdo->prepare("INSERT INTO room_availability VALUES ('blocked','r',?,0)")->execute([(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d')]);
operation_check(array_column(timeline_available_packages($pdo, $room, $day, '21:00'), 'id') === ['short'], 'Allow a stay ending at midnight; reject packages entering a blocked date.');
$pdo->exec('DELETE FROM room_availability');
operation_check(array_column(timeline_available_packages($pdo, $room, $day, '23:00'), 'id') === ['short', 'long'], 'Regular packages must remain bookable at 23:00 across midnight.');
$pdo->exec("UPDATE rooms SET is_active=0 WHERE id='r'");
operation_check(timeline_available_packages($pdo, $room, $day, '13:00') === [], 'Inactive inventory must not offer packages.');
$pdo->exec("UPDATE rooms SET is_active=1 WHERE id='r'");
operation_check(timeline_available_packages($pdo, array_replace($room, ['status' => 'HIDDEN']), $day, '13:00') === [], 'Hidden room must not offer packages.');
$pdo->prepare("INSERT INTO room_emergency_locks (id,room_id,starts_at) VALUES ('lock','r',?)")
    ->execute([mysql_datetime(new DateTimeImmutable($day . ' 12:00', new DateTimeZone('Asia/Ho_Chi_Minh')))]);
operation_check(timeline_available_packages($pdo, $room, $day, '13:00') === [], 'Emergency locks must remove packages.');
$pdo->exec('DELETE FROM room_emergency_locks');
$discounted = timeline_available_packages($pdo, array_replace($room, ['discountPercent' => 10]), $day, '13:00');
operation_check($discounted[0]['price'] === 90000, 'Offer the payable discounted price.');
$clock = new DateTimeImmutable($day . ' 13:22:30', new DateTimeZone('Asia/Ho_Chi_Minh'));
$currentHour = timeline_available_packages($pdo, $room, $day, '13:00', $clock);
operation_check($currentHour[0]['time'] === '13:23', 'Current hour must offer a future minute instead of a past arrival.');
operation_check(timeline_available_packages($pdo, $room, $day, '12:00', $clock) === [], 'Past hours must have no packages.');
try {
    timeline_available_packages($pdo, $room, $day, '25:00');
    throw new RuntimeException('Invalid cell accepted.');
} catch (BookingException $error) {
    operation_check($error->httpStatus === 422, 'Invalid hour must return 422.');
}

$evening = timeline_available_packages($pdo, $room, $day, '18:00')[0];
operation_check((new DateTimeImmutable($evening['end']))->format('H:i') === '21:00', 'Three-hour stay at 18:00 must end at 21:00 without cleaning added.');
$pdo->prepare("INSERT INTO bookings (id,room_id,check_in,check_out,status) VALUES ('evening','r',?,?,'CONFIRMED')")
    ->execute([mysql_datetime(new DateTimeImmutable($evening['start'])), mysql_datetime(new DateTimeImmutable($evening['end']))]);
$afterBooking = availability_snapshot($pdo, [$room], $day, 2);
$cleaning = array_values(array_filter($afterBooking['rooms'][0]['intervals'], static fn($interval) => $interval['status'] === 'CLEANING'));
operation_check(count($cleaning) === 1, 'Cleaning appears after a booking exists.');
operation_check((new DateTimeImmutable($cleaning[0]['start']))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('H:i') === '21:00', 'Cleaning starts at checkout.');
operation_check(strtotime($cleaning[0]['end']) - strtotime($cleaning[0]['start']) === 1800, 'Cleaning occupies exactly 30 minutes after checkout.');

echo "Timeline server package availability: OK\n";
