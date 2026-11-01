<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$room = fallback_rooms()['pink'];
$room['packages'] = [[
    'id' => 'late-night', 'name' => 'Qua đêm', 'mode' => 'FIXED_TIME',
    'checkInTime' => '21:00', 'checkOutTime' => '09:00',
    'price' => 279000, 'enabled' => true,
]];
$input = [
    'packageId' => 'late-night', 'date' => '2027-01-15', 'time' => '21:30',
    'numberOfGuests' => 1, 'guestName' => 'Khách qua đêm', 'guestPhone' => '0901234567',
    'bookingSource' => 'PHONE', 'requestId' => str_repeat('a', 32),
];
$expect = static function (bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};
$quote = quote_manual_booking($room, $input, true);
$expect($quote['start']->format('Y-m-d H:i') === '2027-01-15 21:30', 'Manual overnight arrival must allow 21:30.');
$expect($quote['end']->format('Y-m-d H:i') === '2027-01-16 09:00', 'Delayed arrival must retain original checkout.');
$expect($quote['total'] === 279000 && $quote['packageId'] === 'late-night', 'Keep package identity and price.');
$quote = quote_manual_booking($room, array_replace($input, ['time' => '00:30']), true);
$expect($quote['start']->format('Y-m-d H:i') === '2027-01-16 00:30', 'After-midnight arrival belongs to the same night.');
foreach (['20:30', '09:00', '21:15', 'invalid'] as $time) {
    try {
        quote_manual_booking($room, array_replace($input, ['time' => $time]), true);
        throw new RuntimeException('Invalid arrival accepted: ' . $time);
    } catch (BookingException $error) {
        $expect($error->httpStatus === 422, 'Invalid manual arrival must return 422.');
    }
}
echo "Manual overnight delayed arrival, unchanged checkout/price and invalid times: OK\n";
