<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (config('db')['name'] !== 'mo_recovery_test' || !in_array(config('db')['host'], ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Requires isolated local mo_recovery_test database and localhost:8080 test server.');
}
$pdo = db();
$repo = new RoomRepository($pdo);
$room = $repo->create(['name'=>'Receipt API test','slug'=>'receipt-api-' . uuid(),'status'=>'ACTIVE','maxGuests'=>2,
    'packages'=>[['name'=>'3h','mode'=>'DURATION','durationMinutes'=>180,'price'=>223000]]]);
$accountId = uuid();
$sessionId = uuid();
try {
    $booking = create_manual_booking($pdo, ['roomId'=>$room['id'],'packageId'=>$room['packages'][0]['id'],
        'date'=>(new DateTimeImmutable('+7 days'))->format('Y-m-d'),'time'=>'14:00','numberOfGuests'=>1,
        'guestName'=>'API test','guestPhone'=>'0901234567','bookingSource'=>'PHONE','requestId'=>uuid()]);
    $pdo->prepare("UPDATE bookings SET status='PENDING',payment_method='payos',booking_source='WEBSITE',hold_expires_at=? WHERE id=?")
        ->execute([gmdate('Y-m-d H:i:s',time()-60),$booking['id']]);
    $pdo->prepare('INSERT INTO accounts(id,name,email,password_hash,permissions) VALUES(?,?,?,?,?)')
        ->execute([$accountId,'Test viewer','viewer-' . $accountId . '@example.test',password_hash('test-only',PASSWORD_DEFAULT),'["bookings.view"]']);
    session_save_path(APP_ROOT . '/storage/sessions');
    session_name('langhome_session'); session_id($sessionId); session_start();
    $_SESSION=['admin_until'=>time()+300,'account_id'=>$accountId,'account_version'=>1,'csrf'=>'test-csrf'];
    session_write_close();
    $post = function (array $data, bool $csrf = true) use ($sessionId,$booking): array {
        $headers=['Content-Type: application/json','Cookie: langhome_session=' . $sessionId];
        if ($csrf) $headers[]='X-CSRF-Token: test-csrf';
        $context=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'timeout'=>10,
            'header'=>implode("\r\n",$headers),'content'=>json_encode($data)]]);
        $body=file_get_contents('http://localhost:8080/api/admin/bookings/' . $booking['id'] . '/payment',false,$context);
        preg_match('/\s(\d{3})\s/',$http_response_header[0] ?? '',$status);
        return [(int)($status[1] ?? 0),json_decode((string)$body,true)];
    };
    if ($post(['received'=>true,'paymentMethod'=>'payos'])[0]!==403) throw new RuntimeException('Viewer bypassed receipt permission.');
    $pdo->prepare('UPDATE accounts SET permissions=? WHERE id=?')->execute(['["bookings.view","bookings.payment"]',$accountId]);
    if ($post(['received'=>true,'paymentMethod'=>'payos'],false)[0]!==419) throw new RuntimeException('Receipt allowed missing CSRF.');
    if ($post(['paymentMethod'=>'payos'])[0]!==422) throw new RuntimeException('Receipt allowed missing explicit confirmation.');
    [$status,$result]=$post(['received'=>true,'paymentMethod'=>'payos']);
    if ($status!==200 || ($result['status'] ?? '')!=='CONFIRMED') throw new RuntimeException('Authorized receipt API failed.');
    if ($post(['received'=>true,'paymentMethod'=>'payos'])[0]!==200) throw new RuntimeException('Receipt API is not retry-safe.');
    echo "Receipt HTTP permission, CSRF, explicit confirmation and retry: OK\n";
} finally {
    $pdo->prepare('DELETE FROM booking_payment_receipts WHERE booking_id=?')->execute([$booking['id'] ?? '']);
    $pdo->prepare('DELETE FROM staff_email_notifications WHERE booking_id=?')->execute([$booking['id'] ?? '']);
    $pdo->prepare('DELETE FROM bookings WHERE room_id=?')->execute([$room['id']]);
    $repo->delete($room['id']);
    $pdo->prepare('DELETE FROM accounts WHERE id=?')->execute([$accountId]);
    $sessionFile=APP_ROOT . '/storage/sessions/sess_' . $sessionId;
    if (is_file($sessionFile)) unlink($sessionFile);
}
