<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function operations_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$end = strtotime('2026-10-24 05:00:00 UTC');
foreach ([[-60, 0], [0, 0], [899, 0], [900, 0], [901, 50000], [4200, 50000], [4500, 50000], [4501, 100000]] as [$late, $amount]) {
    $fee = late_checkout_fee('2026-10-24 05:00:00', $end + $late);
    operations_expect($fee['amount'] === $amount, 'Incorrect grace/rounding for ' . $late);
}
$b = ['status' => 'CHECKED_IN', 'check_in' => '2026-10-24 01:00:00', 'check_out' => '2026-10-24 05:00:00'];
$state = booking_lifecycle($b, $end + 1);
operations_expect($state['status'] === 'CHECKED_IN' && $state['isOverdue'], 'Scheduled end must not imply actual departure.');
operations_expect(booking_lifecycle(array_replace($b, ['status' => 'CHECKED_OUT']), $end + 1)['status'] === 'CHECKED_OUT', 'Confirmed departure must stay final.');
$pending = array_replace($b, ['status' => 'PENDING', 'payment_status' => 'PENDING', 'hold_expires_at' => '2026-10-24 05:00:00']);
operations_expect(booking_lifecycle($pending, $end - 1)['status'] === 'PENDING', 'Active hold stays pending until its deadline.');
operations_expect(booking_lifecycle($pending, $end)['status'] === 'EXPIRED', 'Admin must show expired at the exact hold deadline.');
operations_expect(booking_lifecycle($pending, $end + 86400)['status'] === 'EXPIRED', 'Historical unpaid hold must not still show pending.');
operations_expect(booking_lifecycle(array_replace($pending, ['payment_status' => 'PAID']), $end + 1)['status'] === 'PENDING', 'Do not hide a paid booking with an unresolved status.');
operations_expect(booking_transition_allowed('EXPIRED', 'CANCELLED', false), 'Staff retain ability to cancel a pending booking whose hold expired.');
operations_expect(admin_api_permission('/api/admin/room-operations', 'GET') === 'availability.view', 'Search must have its own read permission.');
operations_expect(admin_api_permission('/api/admin/room-operations/lock', 'POST') === 'availability.lock', 'Lock requires its own permission.');
operations_expect(admin_api_permission('/api/admin/room-operations/checkout', 'POST') === 'availability.checkout', 'Checkout requires its own permission.');
echo "Room operations fee, lifecycle and permission tests: OK\n";
