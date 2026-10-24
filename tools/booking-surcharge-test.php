<?php

declare(strict_types=1);

require __DIR__ . '/room-operations-db-test.php';

$bookingId = str_repeat('f', 32);
$pdo->prepare("INSERT INTO bookings (id,room_id,booking_code,status,check_in,check_out) VALUES (?,'r','MO-FEES','CHECKED_IN',?,?)")
    ->execute([$bookingId, gmdate('Y-m-d H:i:s', $now - 7200), gmdate('Y-m-d H:i:s', $now - 4200)]);
$input = ['bookingId' => $bookingId, 'requestId' => str_repeat('1', 32), 'name' => 'Nước uống', 'amount' => 30000];
save_booking_surcharge($pdo, $input, 'staff');
save_booking_surcharge($pdo, $input, 'staff');
operation_check(count(booking_surcharge_items($pdo, $bookingId)) === 1, 'Save retry must not duplicate the charge.');
$input['requestId'] = str_repeat('2', 32);
$input['name'] = 'Giặt khăn';
$input['amount'] = 20000;
save_booking_surcharge($pdo, $input, 'staff');
foreach ([-1, 0, '1.5', 'bad', true] as $invalid) {
    try {
        save_booking_surcharge($pdo, array_replace($input, ['amount' => $invalid]), 'staff');
        throw new RuntimeException('Invalid surcharge accepted.');
    } catch (BookingException $error) {
        operation_check($error->httpStatus === 422, 'Invalid amount must return 422.');
    }
}
$charge = confirm_room_checkout($pdo, $bookingId, 'staff', $now, true);
operation_check((int)$charge['amount'] === 100000, 'Combine 50000 late fee and 50000 saved items into one charge.');
$repeat = confirm_room_checkout($pdo, $bookingId, 'staff', $now + 7200, true);
operation_check($charge['order_code'] === $repeat['order_code'] && (int)$repeat['amount'] === 100000, 'Checkout retry must preserve QR identity and amount.');
operation_conflict(fn() => save_booking_surcharge($pdo, array_replace($input, ['requestId' => str_repeat('3', 32)]), 'staff'));
$otherId = str_repeat('e', 32);
$pdo->prepare("INSERT INTO bookings (id,room_id,booking_code,status,check_in,check_out) VALUES (?,'r','MO-ITEMS','CHECKED_IN',?,?)")
    ->execute([$otherId, gmdate('Y-m-d H:i:s', $now - 7200), gmdate('Y-m-d H:i:s', $now - 4200)]);
save_booking_surcharge($pdo, array_replace($input, ['bookingId' => $otherId, 'requestId' => str_repeat('4', 32)]), 'staff');
$onlyItems = confirm_room_checkout($pdo, $otherId, 'staff', $now, false);
operation_check((int)$onlyItems['amount'] === 20000 && (int)$onlyItems['billable_hours'] === 0, 'Waiving late fee must not discard saved items.');
$creates = 0;
$paymentFactory = static function (array $charge, array $booking) use (&$creates): array {
    $creates++;
    operation_check((int)$charge['amount'] === 100000, 'QR provider must receive the combined amount.');
    return ['checkoutUrl' => 'https://pay.payos.vn/web/custom', 'qrCode' => 'qr', 'paymentLinkId' => 'custom-link'];
};
$payment = ensure_checkout_payment($pdo, $bookingId, $paymentFactory);
ensure_checkout_payment($pdo, $bookingId, $paymentFactory);
operation_check($creates === 1, 'Payment retry must reuse the combined QR.');
$dto = checkout_charge_dto($payment, booking_surcharge_items($pdo, $bookingId));
operation_check(count($dto['items']) === 2 && $dto['itemsAmount'] === 50000, 'Popup must show individual saved items and separate late fee.');
operation_check(apply_checkout_payment_callback($pdo, [
    'orderCode' => $payment['order_code'], 'amount' => 100000, 'reference' => 'CUSTOM-TX', 'paymentLinkId' => 'custom-link',
]), 'Combined QR callback must mark the charge paid.');
operation_check(admin_api_permission('/api/admin/room-operations/surcharges', 'GET') === 'availability.checkout', 'Reading items needs checkout permission.');
operation_check(admin_api_permission('/api/admin/room-operations/surcharges', 'POST') === 'availability.checkout', 'Saving items needs checkout permission.');
echo "Custom surcharge validation, idempotency, combined QR and callback: OK\n";
