<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/rooms.php';
if (!is_file(APP_ROOT . '/src/promotions.php')) {
    fwrite(STDERR, "FAIL: promotion calculator is missing\n");
    exit(1);
}
require_once APP_ROOT . '/src/promotions.php';
require_once __DIR__ . '/promotion-test-fixture.php';

$pdo = promotion_test_database();
$checks = 0;
function promotion_check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$rule = ['name' => 'Weekday', 'type' => 'SCHEDULE', 'percent' => 20, 'enabled' => true, 'weekdays' => [1], 'startTime' => '06:00', 'endTime' => '15:00', 'startDate' => '2026-11-02', 'endDate' => '2026-11-02'];
$saved = promotion_save($pdo, $rule);
$room = ['id' => 'room'];
$package = ['id' => 'package', 'price' => 90000, 'basePrice' => 100000, 'effectiveDiscountPercent' => 10];
$quote = fn(string $date, string $phone = '', string $email = '') => promotion_price($pdo, $room, $package, new DateTimeImmutable($date, new DateTimeZone('Asia/Ho_Chi_Minh')), $phone, $email);
foreach (['2026-11-02 06:00', '2026-11-02 14:59:59'] as $date) {
    promotion_check($quote($date)['price'] === 80000, 'Inclusive start and exclusive end schedule');
}
foreach (['2026-11-02 05:59:59', '2026-11-02 15:00', '2026-11-03 08:00', '2026-11-09 08:00'] as $date) {
    promotion_check($quote($date)['price'] === 90000, 'Schedule outside bounds preserves inherited price');
}
promotion_check($quote('2026-11-01T23:00:00Z')['price'] === 80000, 'Arrival converted to Vietnam time');
$tie = promotion_save($pdo, array_merge($rule, ['name' => 'Tie']));
promotion_check($quote('2026-11-02 08:00')['promotionId'] === min($saved['id'], $tie['id']), 'Stable ID tie');
$returning = promotion_save($pdo, ['name' => 'Returning', 'type' => 'RETURNING', 'percent' => 30, 'enabled' => true, 'minStays' => 2]);
$insert = $pdo->prepare('INSERT INTO bookings VALUES (?,?,?,?,?,?)');
$insert->execute(['a', '+84 912-345-678', ' OLD@EXAMPLE.COM ', 'CHECKED_OUT', 0, '2020-01-01 10:00:00']);
$insert->execute(['b', '0912345678', 'different@example.com', 'CHECKED_OUT', 0, '2020-01-01 10:00:00']);
promotion_check($quote('2026-11-02 08:00', '0912345678', 'old@example.com')['price'] === 70000, 'Phone variants OR email count once and highest discount does not stack');
promotion_check($quote('2026-11-02 08:00', '0999999999', 'old@example.com')['price'] === 80000, 'Changed phone email matches only direct booking, not transitive identities');
foreach ([['future', 'CHECKED_OUT', 0, '2099-01-01'], ['test', 'CHECKED_OUT', 1, '2020-01-01'], ['cancel', 'CANCELLED', 0, '2020-01-01']] as [$id, $status, $test, $end]) {
    $insert->execute([$id, '0999999999', 'old@example.com', $status, $test, $end]);
}
promotion_check($quote('2026-11-02 08:00', '0999999999', 'old@example.com')['price'] === 80000, 'Future test cancelled stays excluded');
$pdo->exec("INSERT INTO booking_checkout_charges VALUES ('b','2099-01-01 00:00:00')");
promotion_check($quote('2026-11-02 08:00', '0912345678')['price'] === 80000, 'Actual checkout overrides scheduled checkout');
$insert->execute(['near-future', '0888888888', '', 'CHECKED_OUT', 0, gmdate('Y-m-d H:i:s', time() + 3600)]);
promotion_check(promotion_completed_stays($pdo, '0888888888', '') === 0, 'Future UTC departures excluded within next seven hours');
$insert->execute(['blank', '', '', 'CHECKED_OUT', 0, '2020-01-01']);
promotion_check(promotion_completed_stays($pdo, '', '') === 0, 'Blank identities never match');
$priced = $quote('2026-11-02 08:00');
promotion_snapshot($pdo, 'snapshot', $priced);
promotion_save($pdo, array_merge($saved, ['name' => 'Changed', 'percent' => 40]));
promotion_snapshot($pdo, 'snapshot', $quote('2026-11-02 08:00'));
promotion_check($pdo->query("SELECT price FROM booking_promotion_snapshots WHERE booking_id='snapshot'")->fetchColumn() == $priced['price'], 'Snapshot immutable');
promotion_check(promotion_validate(array_merge($rule, ['endTime' => '24:00']))['endTime'] === '24:00', 'Midnight ending accepted');
promotion_save($pdo, array_merge($saved, ['enabled' => false]));
promotion_save($pdo, array_merge($tie, ['enabled' => false]));
$scoped = promotion_save($pdo, array_merge($rule, [
    'percent' => 50,
    'startTime' => '23:00',
    'endTime' => '24:00',
    'roomIds' => ['room'],
    'packageIds' => ['package'],
]));
promotion_check($quote('2026-11-02 23:59:59')['price'] === 50000, '24:00 includes last second of matching day');
promotion_check($quote('2026-11-03 00:00')['price'] === 90000, '24:00 excludes following day');
promotion_save($pdo, array_merge($scoped, ['roomIds' => ['other']]));
promotion_check($quote('2026-11-02 23:00')['price'] === 90000, 'Room scope enforced');
promotion_save($pdo, array_merge($scoped, ['packageIds' => ['other']]));
promotion_check($quote('2026-11-02 23:00')['price'] === 90000, 'Package scope enforced');
$invalidRules = [
    ['percent' => 100],
    ['percent' => true],
    ['name' => []],
    ['endTime' => '05:00'],
    ['weekdays' => [0]],
    ['startDate' => '2026-02-30'],
    ['roomIds' => 'room'],
    ['roomIds' => array_fill(0, 501, 'room')],
];
foreach ($invalidRules as $invalid) {
    try {
        promotion_validate(array_merge($rule, $invalid));
        throw new RuntimeException('Invalid rule accepted');
    } catch (BookingException $error) {
        promotion_check($error->httpStatus === 422 || $error->getCode() === 422, 'Invalid rule rejected');
    }
}
echo "OK: {$checks} promotion checks\n";
