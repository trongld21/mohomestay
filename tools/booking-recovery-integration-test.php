<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (config('db')['name'] !== 'mo_recovery_test' || !in_array(config('db')['host'], ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('This integration test requires the isolated local mo_recovery_test database.');
}

function integration_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$pdo = db();
$repo = new RoomRepository($pdo);
$day = (new DateTimeImmutable('+3 days', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
$room = $repo->create([
    'name'=>'Kiểm thử khôi phục', 'slug'=>'recovery-' . uuid(), 'status'=>'ACTIVE', 'maxGuests'=>2,
    'discountPercent'=>20, 'discountStart'=>$day, 'discountEnd'=>$day,
    'packages'=>[['name'=>'Qua đêm','mode'=>'FIXED_TIME','checkInTime'=>'21:00','checkOutTime'=>'09:00','price'=>300000]],
]);
try {
    integration_expect($room['discountStart'] === $day && $room['discountEnd'] === $day, 'Room discount schedule must persist.');
    $input = ['roomId'=>$room['id'],'packageId'=>$room['packages'][0]['id'],'date'=>$day,'time'=>'',
        'numberOfGuests'=>2,'guestName'=>'Khách kiểm thử','guestPhone'=>'0901234567','guestEmail'=>'test@example.test',
        'bookingSource'=>'PHONE','requestId'=>uuid()];
    $booking = create_manual_booking($pdo, $input);
    integration_expect((int)$booking['total_price'] === 240000, 'Arrival-day campaign must persist correct payable price.');
    $order = (string)(time() * 1000 + random_int(100, 999));
    $pdo->prepare("UPDATE bookings SET status='PENDING',booking_source='WEBSITE',payment_method='payos',order_code=?,hold_expires_at=? WHERE id=?")
        ->execute([$order, gmdate('Y-m-d H:i:s', time() - 60), $booking['id']]);
    $received = confirm_booking_receipt($pdo, $booking['id'], 'bank_transfer', 'admin');
    integration_expect($received['status'] === 'CONFIRMED' && $received['payment_status'] === 'PAID', 'MySQL expired receipt must recover inventory.');
    confirm_booking_receipt($pdo, $booking['id'], 'bank_transfer', 'admin');
    apply_payment_callback($pdo, ['orderCode'=>$order,'amount'=>240000,'reference'=>'TEST-' . $order]);
    $count = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE booking_id=?');
    $count->execute([$booking['id']]);
    integration_expect((int)$count->fetchColumn() === 1, 'Repeated receipt and callback must preserve a single payment.');
    $count = $pdo->prepare('SELECT COUNT(*) FROM booking_payment_receipts WHERE booking_id=?');
    $count->execute([$booking['id']]);
    integration_expect((int)$count->fetchColumn() === 1, 'MySQL manual receipt audit must be idempotent.');
    $outside = quote_manual_booking($room, array_replace($input, ['date'=>(new DateTimeImmutable($day))->modify('+2 days')->format('Y-m-d')]));
    integration_expect($outside['total'] === 300000, 'Out-of-campaign arrival must not inherit catalog discount.');
    $paidDay = (new DateTimeImmutable($day))->modify('+2 days')->format('Y-m-d');
    $late = create_manual_booking($pdo, array_replace($input, ['date'=>$paidDay,'requestId'=>uuid()]));
    $pdo->prepare("UPDATE bookings SET status='PENDING',payment_method='payos',order_code=?,hold_expires_at=? WHERE id=?")
        ->execute([$order . '1', gmdate('Y-m-d H:i:s', time() - 60), $late['id']]);
    $confirmed = apply_payment_callback($pdo, ['orderCode'=>$order . '1','amount'=>300000,'reference'=>'TEST-LATE-' . $order]);
    integration_expect($confirmed['status'] === 'CONFIRMED', 'Verified paid API callback must recover expired holds on MySQL.');
    echo "MySQL receipt recovery, audit, late callback and discount persistence: OK\n";
} finally {
    $ids = $pdo->prepare('SELECT id FROM bookings WHERE room_id=?');
    $ids->execute([$room['id']]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('DELETE FROM booking_payment_receipts WHERE booking_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM staff_email_notifications WHERE booking_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM payments WHERE booking_id=?')->execute([$id]);
    }
    $pdo->prepare('DELETE FROM bookings WHERE room_id=?')->execute([$room['id']]);
    $repo->delete($room['id']);
}
