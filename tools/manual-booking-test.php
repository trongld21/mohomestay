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

$checkoutDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$checkoutDb->exec('CREATE TABLE payments (booking_id TEXT,qr_code TEXT)');
$checkoutBooking = [
    'id' => 'checkout-test', 'booking_code' => 'MO-CHECKOUT', 'access_token' => 'secret-token',
    'status' => 'PENDING', 'payment_status' => 'PENDING', 'payment_method' => 'payos',
    'hold_expires_at' => gmdate('Y-m-d H:i:s', time() + 900), 'order_code' => 1234567890123,
    'total_price' => 159000,
];
$checkoutDb->exec("INSERT INTO payments VALUES ('checkout-test','cached-qr')");
$guestUrl = prepare_manual_guest_checkout($checkoutDb, $checkoutBooking, static function (): never {
    throw new RuntimeException('A retry must reuse the existing QR.');
});
manual_expect($guestUrl === '/payment?code=MO-CHECKOUT#secret-token', 'Guest checkout must use the public payment page with a fragment token.');
try {
    prepare_manual_guest_checkout($checkoutDb, array_replace($checkoutBooking, ['hold_expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]));
    throw new RuntimeException('Expired holds must not generate a QR.');
} catch (BookingException $error) {
    manual_expect($error->httpStatus === 409, 'Expired checkout must return a conflict.');
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
manual_expect(quote_manual_booking($room, $input + ['guestCheckout' => true])['guestCheckout'] === true, 'Guest checkout must be an explicit option.');
manual_expect($quote['guestCheckout'] === false, 'Normal manual bookings must remain the default.');
foreach ([['guestCheckout' => 'true'], ['guestCheckout' => true, 'agreedPrice' => 0]] as $invalidCheckout) {
    try {
        quote_manual_booking($room, array_replace($input, $invalidCheckout));
        throw new RuntimeException('Invalid guest checkout accepted.');
    } catch (BookingException $error) {
        manual_expect($error->httpStatus === 422, 'Invalid checkout options must return 422.');
    }
}

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
$customInput = array_replace($input, [
    'timingMode' => 'CUSTOM', 'packageId' => '', 'agreedPrice' => 555000,
    'startNow' => '1',
    'endDate' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d'),
    'endTime' => '17:00',
]);
$custom = quote_manual_booking($room, $customInput);
manual_expect(quote_manual_booking(array_replace($room, ['packages' => []]), $customInput)['total'] === 555000, 'Custom stay must work even when no packages exist.');
manual_expect($custom['packageId'] === null && $custom['stayPackage'] === 'CUSTOM', 'Custom stays must not depend on a package foreign key.');
manual_expect($custom['end']->format('Y-m-d H:i') === $customInput['endDate'] . ' 17:00', 'Admin must choose tomorrow at 17:00 independently of configured duration.');
manual_expect(abs($custom['start']->getTimestamp() - time()) < 2 && $custom['total'] === 555000, 'Now means the server creation time and uses the agreed price.');
$explicit = quote_manual_booking($room, array_replace($customInput, [
    'startNow' => false, 'date' => $input['date'], 'time' => '10:15',
    'endDate' => (new DateTimeImmutable($input['date']))->modify('+2 days')->format('Y-m-d'),
    'endTime' => '17:00',
]));
manual_expect($explicit['start']->format('H:i') === '10:15' && $explicit['packageDuration'] === 3285, 'Explicit multi-day custom times must be retained.');
foreach ([
    ['endDate' => '2020-01-01'],
    ['endDate' => '2027-02-30'],
    ['endTime' => '25:00'],
    ['timingMode' => 'INVALID'],
] as $bad) {
    try {
        quote_manual_booking($room, array_replace($customInput, $bad));
        throw new RuntimeException('Invalid custom stay accepted.');
    } catch (BookingException) {
    }
}
$withoutPrice = $customInput;
unset($withoutPrice['agreedPrice']);
try {
    quote_manual_booking($room, $withoutPrice);
    throw new RuntimeException('Custom stay without agreed price accepted.');
} catch (BookingException) {
}
$historical = array_replace($customInput, [
    'startNow' => false, 'date' => '2020-01-01', 'time' => '10:00',
    'endDate' => '2020-01-02', 'endTime' => '17:00',
    'allowAnyDate' => true, 'root' => true,
]);
try {
    quote_manual_booking($room, $historical);
    throw new RuntimeException('Client fields must not grant Super Admin date override.');
} catch (BookingException) {
}
$rootQuote = quote_manual_booking($room, $historical, true);
manual_expect($rootQuote['start']->format('Y-m-d H:i') === '2020-01-01 10:00', 'Trusted Super Admin must retain the requested past check-in.');
manual_expect($rootQuote['end']->format('Y-m-d H:i') === '2020-01-02 17:00', 'Historical checkout must remain valid after check-in.');
$rootPackage = quote_manual_booking($room, array_replace($input, ['date' => '2020-01-01']), true);
manual_expect($rootPackage['start']->format('Y-m-d H:i') === '2020-01-01 14:00', 'Super Admin package bookings must allow past dates.');
$farDate = (new DateTimeImmutable('+2 years'))->format('Y-m-d');
manual_expect(quote_manual_booking($room, array_replace($input, ['date' => $farDate]), true)['start']->format('Y-m-d') === $farDate, 'Super Admin must not have the staff future-date limit.');
try {
    quote_manual_booking($room, array_replace($historical, ['endDate' => '2019-12-31']), true);
    throw new RuntimeException('Super Admin must still choose checkout after check-in.');
} catch (BookingException) {
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
foreach (['<dialog', 'data-manual-booking-form', 'name="roomId"', 'name="packageId"', 'name="timingMode"', 'name="startNow"', 'name="endDate"', 'name="endHour"', 'name="guestCheckout"', 'name="guestPhone"', 'name="bookingSource"', 'value="UNPAID"', 'data-manual-error', 'data-manual-discard'] as $contract) {
    manual_expect(str_contains($form, $contract), 'Manual booking form is missing: ' . $contract);
}
manual_expect(str_contains($form, 'name="arrivalHour"') && str_contains($form, 'name="arrivalMinute"'), 'Arrival time must have separate hour and minute controls.');
manual_expect(str_contains($form, 'data-time-hours') && str_contains($form, 'data-time-minutes') && str_contains($form, 'data-time-exact'), 'The picker must offer hours, quick minutes and exact minutes.');
manual_expect(!str_contains($form, 'type="time"'), 'The form must not use the ambiguous AM/PM time picker.');

echo "Manual booking validation tests: OK\n";
