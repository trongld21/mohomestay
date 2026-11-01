<?php

declare(strict_types=1);

// Reuse the isolated booking writer fixture, including room inventory and continuation checks.
require __DIR__ . '/booking-continuation-test.php';

promotion_save($pdo, [
    'name' => 'Khách quay lại', 'type' => 'RETURNING', 'percent' => 10,
]);
promotion_save($pdo, [
    'name' => 'Ban ngày', 'type' => 'SCHEDULE', 'percent' => 20,
    'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'startTime' => '06:00', 'endTime' => '15:00',
]);
$pdo->prepare("INSERT INTO bookings (id,room_id,check_in,check_out,status,is_test,guest_phone,guest_email,guest_name) VALUES (?,'r','2020-01-01 01:00:00','2020-01-01 04:00:00','CHECKED_OUT',0,'0901234567','returning@example.test','Khách quay lại')")
    ->execute([uuid()]);
$date = $day->modify('+10 days')->format('Y-m-d');
$input = [
    'roomId' => 'r', 'packageId' => 'three', 'date' => $date, 'time' => '10:00',
    'numberOfGuests' => 1, 'guestName' => 'Khách quay lại',
    'guestPhone' => '0988888888', 'guestEmail' => 'RETURNING@example.test',
    'bookingSource' => 'PHONE', 'requestId' => uuid(),
];
$booking = create_manual_booking($pdo, $input);
continuation_check((int)$booking['total_price'] === 127000, '20% schedule wins over returning 10%, rounded once from 159000.');
$snapshot = $pdo->prepare('SELECT * FROM booking_promotion_snapshots WHERE booking_id=?');
$snapshot->execute([$booking['id']]);
$saved = $snapshot->fetch();
continuation_check((int)$saved['discount_percent'] === 20 && $saved['promotion_name'] === 'Ban ngày', 'Applied promotion is saved inside the booking transaction.');
$again = create_manual_booking($pdo, $input);
continuation_check($again['id'] === $booking['id'] && (int)$again['total_price'] === 127000, 'Retry preserves original discount and booking.');
$outside = create_manual_booking($pdo, array_replace($input, ['date' => $day->modify('+11 days')->format('Y-m-d'), 'time' => '16:00', 'requestId' => uuid()]));
continuation_check((int)$outside['total_price'] === 143000, 'Changed phone with matching name and email still receives returning discount outside schedule.');
$singleMatch = create_manual_booking($pdo, array_replace($input, [
    'date' => $day->modify('+13 days')->format('Y-m-d'), 'time' => '16:00',
    'guestName' => 'Khách khác', 'requestId' => uuid(),
]));
continuation_check((int)$singleMatch['total_price'] === 159000, 'Matching email alone does not qualify for a returning discount.');
$manual = create_manual_booking($pdo, array_replace($input, [
    'date' => $day->modify('+12 days')->format('Y-m-d'), 'requestId' => uuid(), 'agreedPrice' => 99000,
]));
continuation_check((int)$manual['total_price'] === 99000, 'Explicit manual price is not discounted a second time.');
$snapshot->execute([$manual['id']]);
$agreedSnapshot = $snapshot->fetch();
continuation_check((int)$agreedSnapshot['price'] === 99000 && (int)$agreedSnapshot['discount_percent'] === 0, 'Manual override audit must not claim the automatic discount was applied.');
echo "Promotion booking transaction, returning email, snapshot, retries and manual override: OK\n";
