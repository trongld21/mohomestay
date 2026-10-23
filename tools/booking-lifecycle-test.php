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
echo "Booking lifecycle tests: OK\n";
