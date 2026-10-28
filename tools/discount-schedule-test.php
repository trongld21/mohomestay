<?php

require dirname(__DIR__) . '/src/bootstrap.php';
function schedule_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$package = ['id'=>'night','mode'=>'FIXED_TIME','checkInTime'=>'21:00','checkOutTime'=>'09:00','price'=>300000,
    'discountPercent'=>20,'discountStart'=>'2027-01-28','discountEnd'=>'2027-01-28'];
schedule_expect(package_with_discount($package, 0, '2027-01-30')['price'] === 300000, 'Arrival outside campaign must pay base price.');
schedule_expect(package_with_discount($package, 0, '2027-01-28')['price'] === 240000, 'Arrival on inclusive campaign day must receive discount.');
schedule_expect(package_with_discount($package, 0, '2027-01-27')['price'] === 300000, 'Arrival before campaign must pay base price.');
schedule_expect(package_with_discount(package_with_discount($package, 0, '2027-01-28'), 0, '2027-01-30')['price'] === 300000, 'Catalog hydration must not freeze discounted price.');
$inherited = array_replace($package, ['discountPercent'=>null,'discountStart'=>null,'discountEnd'=>null]);
$roomSchedule = ['discountStart'=>'2027-01-28','discountEnd'=>'2027-01-29'];
schedule_expect(package_with_discount($inherited, 10, '2027-01-29', $roomSchedule)['price'] === 270000, 'Inherited room campaign includes its final day.');
schedule_expect(package_with_discount($inherited, 10, '2027-01-30', $roomSchedule)['price'] === 300000, 'Inherited room campaign must stop after its final day.');
$zero = array_replace($package, ['discountPercent'=>0]);
schedule_expect(package_with_discount($zero, 30, '2027-01-28', $roomSchedule)['price'] === 300000, 'Explicit package zero must suppress room promotion.');
try {
    validate_discount_schedule('2027-02-30', '2027-03-01');
    throw new RuntimeException('Invalid calendar date accepted.');
} catch (BookingException $error) {
    schedule_expect($error->httpStatus === 422, 'Invalid schedule must return 422.');
}
echo "Arrival-date discount schedules: OK\n";
