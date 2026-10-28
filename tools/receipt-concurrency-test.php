<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (config('db')['name'] !== 'mo_recovery_test' || !in_array(config('db')['host'], ['localhost','127.0.0.1'], true)) {
    throw new RuntimeException('Requires isolated local mo_recovery_test database.');
}
$pdo = db();
if (($argv[1] ?? '') === '--worker') {
    try {
        if ($argv[2] === 'manual') {
            echo confirm_booking_receipt($pdo, $argv[3], 'bank_transfer', 'admin')['status'];
        } else {
            echo apply_payment_callback($pdo, ['orderCode'=>$argv[3],'amount'=>200000,'reference'=>'TEST-RACE-' . $argv[3]])['status'];
        }
    } catch (BookingException $error) {
        echo $error->httpStatus;
    }
    exit;
}
$repo = new RoomRepository($pdo);
$room = $repo->create(['name'=>'Room race test','slug'=>'race-' . uuid(),'status'=>'ACTIVE','maxGuests'=>2,
    'packages'=>[['name'=>'3h','mode'=>'DURATION','durationMinutes'=>180,'price'=>200000]]]);
try {
    foreach (['manual','callback'] as $mode) {
        $input = ['roomId'=>$room['id'],'packageId'=>$room['packages'][0]['id'],
            'date'=>(new DateTimeImmutable($mode === 'manual' ? '+5 days' : '+6 days'))->format('Y-m-d'),
            'time'=>'14:00','numberOfGuests'=>1,'guestName'=>'Test race','guestPhone'=>'0901234567',
            'bookingSource'=>'PHONE','requestId'=>uuid()];
        $booking = create_manual_booking($pdo, $input);
        $order = (string)(time() * 1000 + random_int(100,999));
        $pdo->prepare("UPDATE bookings SET status='PENDING',payment_method='payos',order_code=?,hold_expires_at=? WHERE id=?")
            ->execute([$order,gmdate('Y-m-d H:i:s',time()-60),$booking['id']]);
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM rooms WHERE id=? FOR UPDATE');
        $lock->execute([$room['id']]);
        $process = proc_open([PHP_BINARY,__FILE__,'--worker',$mode,$mode==='manual' ? $booking['id'] : $order],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start race worker.');
        $waiting = false;
        $deadline = microtime(true)+5;
        do {
            $waiting = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND INFO LIKE 'SELECT id,status FROM rooms%'")->fetchColumn()>0;
            if (!$waiting) usleep(50000);
        } while (!$waiting && microtime(true)<$deadline);
        if (!$waiting) {
            $pdo->rollBack();
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
            throw new RuntimeException('Worker did not reach room lock: ' . $output . ' ' . $errors);
        }
        $pdo->prepare("INSERT INTO bookings(id,booking_code,room_id,check_in,check_out,stay_package,access_token,total_price,number_of_guests,guest_name,guest_phone,status,payment_status)
            SELECT ?,?,room_id,check_in,check_out,stay_package,?,total_price,number_of_guests,guest_name,guest_phone,'CONFIRMED','UNPAID' FROM bookings WHERE id=?")
            ->execute([uuid(),'MO-' . strtoupper(bin2hex(random_bytes(5))),bin2hex(random_bytes(32)),$booking['id']]);
        $pdo->commit();
        $output = trim(stream_get_contents($pipes[1]));
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit!==0 || $output!==($mode==='manual' ? '409' : 'CANCELLED')) {
            throw new RuntimeException('Recovery missed a reservation committed while waiting: ' . $output . ' ' . $errors);
        }
    }
    echo "MySQL lock-wait receipt and callback inventory races: OK\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach (['booking_payment_receipts','staff_email_notifications','payments'] as $table) {
        $pdo->prepare("DELETE FROM $table WHERE booking_id IN (SELECT id FROM bookings WHERE room_id=?)")->execute([$room['id']]);
    }
    $pdo->prepare('DELETE FROM bookings WHERE room_id=?')->execute([$room['id']]);
    $repo->delete($room['id']);
}
