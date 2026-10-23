<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function lifecycle_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$booking = ['status' => 'CONFIRMED', 'payment_status' => 'UNPAID', 'check_in' => '2026-10-23 13:00:00', 'check_out' => '2026-10-23 16:00:00'];
$before = booking_lifecycle($booking, strtotime('2026-10-23 12:59:59 UTC'));
lifecycle_expect($before['status'] === 'CONFIRMED', 'Future bookings must remain confirmed.');
$during = booking_lifecycle($booking, strtotime('2026-10-23 13:00:00 UTC'));
lifecycle_expect($during['status'] === 'CHECKED_IN' && $during['statusSource'] === 'AUTO', 'Check-in boundary must automatically start the stay, even unpaid.');
$ended = booking_lifecycle($booking, strtotime('2026-10-23 16:00:00 UTC'));
lifecycle_expect($ended['status'] === 'CHECKED_IN' && $ended['isOverdue'], 'Checkout boundary must mark overdue until staff confirms departure.');
$manual = booking_lifecycle(array_replace($booking, ['status' => 'CHECKED_IN']), strtotime('2026-10-23 12:00:00 UTC'));
lifecycle_expect($manual['status'] === 'CHECKED_IN' && $manual['statusSource'] === 'MANUAL', 'Manual check-in must take precedence over the schedule.');
$manualEnd = booking_lifecycle(array_replace($booking, ['status' => 'CHECKED_OUT']), strtotime('2026-10-23 14:00:00 UTC'));
lifecycle_expect($manualEnd['status'] === 'CHECKED_OUT' && $manualEnd['statusSource'] === 'MANUAL', 'Manual checkout must not be undone by automatic check-in.');
foreach (['CANCELLED', 'PENDING'] as $status) {
    lifecycle_expect(booking_lifecycle(array_replace($booking, ['status' => $status]), strtotime('2026-10-23 14:00:00 UTC'))['status'] === $status, 'Unconfirmed and cancelled bookings must not automatically check in.');
}
lifecycle_expect(booking_lifecycle(array_replace($booking, ['status' => 'CHECKED_IN']), strtotime('2026-10-23 17:00:00 UTC'))['status'] === 'CHECKED_IN', 'Manual check-in must remain until confirmed checkout.');
foreach (['CHECKED_IN', 'CHECKED_OUT', 'EXPIRED', 'CANCELLED'] as $status) {
    lifecycle_expect(booking_transition_allowed($status, 'CANCELLED', true), 'Super Admin must be able to cancel ' . $status);
    lifecycle_expect(!booking_transition_allowed($status, 'CANCELLED', false), 'Staff must not cancel staying/finished bookings.');
}
$cancelledPaid = array_replace($booking, [
    'status' => 'CANCELLED', 'payment_status' => 'PAID', 'total_price' => 223000,
]);
$activePaid = array_replace($booking, ['payment_status' => 'PAID', 'total_price' => 159000]);
$overview = booking_overview([$cancelledPaid, $activePaid], strtotime('2026-10-23 14:00:00 UTC'));
lifecycle_expect($overview['paid'] === 159000 && $overview['reconcile'] === 1, 'Cancellation must subtract revenue without erasing the payment reconciliation record.');
lifecycle_expect($cancelledPaid['payment_status'] === 'PAID', 'Excluding cancelled revenue must not falsely mark an actual payment unpaid.');
echo "Booking lifecycle tests: OK\n";
