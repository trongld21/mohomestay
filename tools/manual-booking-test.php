<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/manual-bookings.php';

function manual_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$room = fallback_rooms()['pink'];
$input = [
    'packageId' => 'pink-3h',
    'date' => (new DateTimeImmutable('+2 days'))->format('Y-m-d'),
    'time' => '14:00',
    'numberOfGuests' => 2,
    'guestName' => 'Khách điện thoại',
    'guestPhone' => '0901 234 567',
    'bookingSource' => 'PHONE',
    'requestId' => str_repeat('a', 32),
];
$quote = quote_manual_booking($room, $input);
manual_expect(quote_manual_booking($room, $input + ['agreedPrice' => 175000])['total'] === 175000, 'Admins must be able to set an agreed price.');
manual_expect(quote_manual_booking($room, $input + ['agreedPrice' => 0])['total'] === 0, 'An explicit zero price must be supported.');
manual_expect($quote['email'] === '', 'Email must be optional for telephone bookings.');
manual_expect($quote['paymentStatus'] === 'UNPAID', 'New manual bookings must default to unpaid.');
manual_expect($quote['total'] === $room['packages'][0]['price'], 'Prices must come from the configured package.');
manual_expect($quote['end']->getTimestamp() - $quote['start']->getTimestamp() === 10800, 'Duration must come from the package.');
foreach ([
    ['bookingSource' => 'WEBSITE'],
    ['paymentStatus' => 'REFUNDED'],
    ['paymentMethod' => 'payos'],
    ['requestId' => 'invalid'],
    ['guestEmail' => 'invalid'],
    ['guestName' => '   '],
    ['guestPhone' => ''],
    ['packageId' => ''],
    ['date' => ''],
    ['time' => ''],
    ['agreedPrice' => -1],
    ['agreedPrice' => 1.5],
    ['agreedPrice' => 1000000001],
    ['agreedPrice' => 'invalid'],
    ['numberOfGuests' => ''],
    ['numberOfGuests' => 50],
    ['date' => '2020-01-01'],
] as $invalid) {
    try {
        quote_manual_booking($room, array_replace($input, $invalid));
        throw new RuntimeException('Invalid manual booking was accepted: ' . json_encode($invalid));
    } catch (BookingException $error) {
        manual_expect($error->httpStatus >= 400, 'Invalid requests need a client error.');
    }
}
$paid = quote_manual_booking($room, $input + ['paymentStatus' => 'PAID', 'paymentMethod' => 'bank_transfer']);
manual_expect($paid['paymentStatus'] === 'PAID', 'Admins must explicitly choose paid.');
try {
    quote_booking_for_room($room, $input);
    throw new RuntimeException('Website bookings must still require email and terms.');
} catch (BookingException $error) {
    manual_expect(str_contains($error->getMessage(), 'email'), 'Optional admin email must not loosen public validation.');
}
$room['status'] = 'HIDDEN';
try {
    quote_manual_booking($room, $input);
    throw new RuntimeException('Hidden rooms must not be booked.');
} catch (BookingException $error) {
    manual_expect($error->httpStatus === 409, 'Hidden rooms must return a conflict.');
}

require_once APP_ROOT . '/src/admin-layout.php';
require_once APP_ROOT . '/src/manual-booking-view.php';
ob_start();
render_manual_booking_dialog();
$form = (string)ob_get_clean();
foreach (['<dialog', 'data-manual-booking-form', 'name="roomId"', 'name="packageId"', 'name="guestPhone"', 'name="bookingSource"', 'value="UNPAID"', 'data-manual-error', 'data-manual-discard'] as $contract) {
    manual_expect(str_contains($form, $contract), 'Manual booking form is missing: ' . $contract);
}
manual_expect(str_contains($form, 'name="arrivalHour"') && str_contains($form, 'name="arrivalMinute"'), 'Arrival time must have separate hour and minute controls.');
manual_expect(str_contains($form, 'data-time-hours') && str_contains($form, 'data-time-minutes') && str_contains($form, 'data-time-exact'), 'The picker must offer hours, quick minutes and exact minutes.');
manual_expect(!str_contains($form, 'type="time"'), 'The form must not use the ambiguous AM/PM time picker.');

echo "Manual booking validation tests: OK\n";
