<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$booking = [
    'payment_method' => 'payos',
    'payment_status' => 'PENDING',
    'order_code' => '1234567890123',
    'total_price' => 200000,
];
$closed = ['orderCode' => $booking['order_code'], 'amount' => 200000, 'amountPaid' => 0, 'status' => 'CANCELLED'];
$never = static function (): never {
    throw new RuntimeException('Unexpected provider call');
};
close_booking_payment($booking, static fn() => $closed, $never);
close_booking_payment($booking, $never, static fn() => $closed);
close_booking_payment(array_replace($booking, ['is_test' => 1]), $never, $never);
close_booking_payment(array_replace($booking, ['payment_method' => 'cash']), $never, $never);
close_booking_payment(array_replace($booking, ['payment_status' => 'PAID']), $never, $never);
foreach ([
    ['status' => 'PENDING'],
    ['status' => 'PAID', 'amountPaid' => 200000],
    ['amountPaid' => 1000],
    ['orderCode' => '9999999999999'],
    ['amount' => 1],
] as $change) {
    $response = array_replace($closed, $change);
    try {
        close_booking_payment($booking, static fn() => $response, static fn() => $response);
        throw new RuntimeException('Unconfirmed cancellation must fail');
    } catch (BookingException $error) {
        if ($error->httpStatus !== 409) throw $error;
    }
}
try {
    close_booking_payment($booking, $never, $never);
    throw new RuntimeException('Provider outage must fail');
} catch (BookingException $error) {
    if ($error->httpStatus !== 409) throw $error;
}
echo "Booking QR cancellation, retries, payment races and provider failures: OK\n";
