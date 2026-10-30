<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$now = new DateTimeImmutable('2027-01-15 12:00:00', new DateTimeZone('Asia/Ho_Chi_Minh'));
$package = ['mode' => 'DURATION', 'durationMinutes' => 180];
foreach (['17:01', '17:15', '17:29', '17:45', '17:59'] as $time) {
    try {
        bookable_package_window($package, '2027-01-15', $time, $now);
        throw new RuntimeException('Odd-minute arrival accepted: ' . $time);
    } catch (BookingException $error) {
        if ($error->httpStatus !== 422) throw $error;
    }
}
foreach (['17:00', '17:30'] as $time) {
    [$start, $end] = bookable_package_window($package, '2027-01-15', $time, $now);
    if ($start->format('H:i') !== $time || $end->getTimestamp() - $start->getTimestamp() !== 10800) {
        throw new RuntimeException('Valid half-hour arrival changed.');
    }
}
$fixed = ['mode' => 'FIXED_TIME', 'checkInTime' => '10:00', 'checkOutTime' => '16:00'];
[$start] = bookable_package_window($fixed, '2027-01-15', '', $now->setTime(13, 23));
if ($start->format('H:i') !== '13:30') throw new RuntimeException('Late arrival must round up to the next half hour.');
echo "New booking arrival accepts only :00/:30 and keeps package duration: OK\n";
