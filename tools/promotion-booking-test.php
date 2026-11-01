<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (!function_exists('promotion_apply_quote')) {
    throw new RuntimeException('Booking quotes do not apply recurring promotions yet.');
}

require __DIR__ . '/promotion-test-fixture.php';
$pdo = promotion_test_database();
$room = ['id' => 'r', 'status' => 'ACTIVE', 'discountPercent' => 0];
$package = [
    'id' => 'p', 'name' => '3 giờ', 'mode' => 'DURATION', 'durationMinutes' => 180,
    'price' => 300000, 'basePrice' => 300000, 'effectiveDiscountPercent' => 0,
];
$start = new DateTimeImmutable('2027-01-04 10:00:00', new DateTimeZone('Asia/Ho_Chi_Minh'));
promotion_save($pdo, [
    'name' => 'Giờ ngày thường', 'type' => 'SCHEDULE', 'percent' => 20, 'enabled' => true,
    'weekdays' => [1, 2, 3, 4, 5], 'startTime' => '06:00', 'endTime' => '15:00',
]);
$quote = [
    'room' => $room, 'package' => $package, 'start' => $start,
    'end' => $start->modify('+3 hours'), 'phone' => '', 'email' => '',
    'extraHours' => 1, 'extraHoursAmount' => 50000, 'total' => 350000,
];
$priced = promotion_apply_quote($pdo, $quote);
if ($priced['total'] !== 290000 || $priced['packagePrice'] !== 240000) {
    throw new RuntimeException('Only package price may be discounted; extra hours must retain their full price.');
}
if (!str_contains($priced['packageName'], '+ 1 giờ')) {
    throw new RuntimeException('Promotion calculation must retain the extra-hour description on the booking snapshot.');
}
$again = promotion_apply_quote($pdo, $priced);
if ($again['total'] !== 290000) throw new RuntimeException('Repeated quote calculation must not stack discounts.');
$custom = ['total' => 123000, 'packageId' => null];
if (promotion_apply_quote($pdo, $custom) !== $custom) {
    throw new RuntimeException('Custom-time agreed quotes must remain unchanged.');
}
$agreed = promotion_apply_quote($pdo, $quote, 199000);
if ($agreed['total'] !== 199000 || $agreed['packagePrice'] !== 240000) {
    throw new RuntimeException('Explicit agreed price must be preserved while retaining the package quote.');
}
$fresh = promotion_refresh_locked_quote($pdo, $quote + ['requestedDate' => '2027-01-04'], [
    'id' => 'r', 'status' => 'ACTIVE', 'discount_percent' => 30,
    'discount_start' => null, 'discount_end' => null,
], [
    'id' => 'p', 'name' => '3 giờ', 'timing_mode' => 'DURATION', 'duration_minutes' => 180,
    'check_in_time' => null, 'check_out_time' => null, 'price' => 300000,
    'discount_percent' => null, 'is_enabled' => 1,
]);
if ($fresh['packagePrice'] !== 210000 || $fresh['total'] !== 260000) {
    throw new RuntimeException('Final pricing must use the latest locked room discount instead of the pre-lock quote.');
}
echo "Promotion booking calculation and agreed prices: OK\n";
