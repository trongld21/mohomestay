<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function manual_db_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function manual_db_conflict(callable $callback, string $message): void
{
    try {
        $callback();
        throw new RuntimeException($message);
    } catch (BookingException $error) {
        manual_db_expect($error->httpStatus === 409, $message . ' (wrong error status)');
    }
}

function manual_http(string $path, array $body, array $headers = [], string $method = 'POST'): array
{
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => implode("\r\n", ['Content-Type: application/json', ...$headers]),
        'content' => json_encode($body, JSON_THROW_ON_ERROR),
    ]]);
    $result = file_get_contents('http://localhost:8080' . $path, false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
    return [(int)($status[1] ?? 0), json_decode((string)$result, true, 512, JSON_THROW_ON_ERROR)];
}

// Child workers exercise simultaneous requests using separate database connections.
if (($argv[1] ?? '') === '--worker') {
    try {
        create_manual_booking(db(), [
            'roomId' => $argv[2], 'packageId' => $argv[3], 'date' => $argv[4],
            'time' => '14:00', 'numberOfGuests' => 1, 'guestName' => 'Khách thử đồng thời',
            'guestPhone' => '0901234567', 'bookingSource' => 'PHONE', 'requestId' => $argv[5],
        ]);
        echo '201';
    } catch (BookingException $error) {
        echo $error->httpStatus;
    }
    exit;
}

$pdo = db();
$repository = new RoomRepository($pdo);
$room = null;
$sessionId = null;
try {
    $room = $repository->create([
        'name' => 'Kiểm tra đơn thủ công', 'slug' => 'manual-booking-test-' . uuid(),
        'status' => 'ACTIVE', 'maxGuests' => 2,
        'packages' => [
            ['name' => '3 giờ thử nghiệm', 'mode' => 'DURATION', 'durationMinutes' => 180, 'price' => 123000],
            ['name' => 'Qua đêm thử nghiệm', 'mode' => 'FIXED_TIME', 'checkInTime' => '21:00', 'checkOutTime' => '09:00', 'price' => 234000],
        ],
    ]);
    $date = (new DateTimeImmutable('+10 days'))->format('Y-m-d');
    $base = [
        'roomId' => $room['id'], 'packageId' => $room['packages'][0]['id'], 'date' => $date,
        'time' => '10:00', 'numberOfGuests' => 2, 'guestName' => 'Khách thử nghiệm',
        'guestPhone' => '0901234567', 'bookingSource' => 'ZALO', 'requestId' => uuid(),
    ];
    $booking = create_manual_booking($pdo, $base + ['totalPrice' => 1]);
    manual_db_expect($booking['status'] === 'CONFIRMED' && $booking['payment_status'] === 'UNPAID', 'Unpaid manual bookings must reserve the room immediately.');
    manual_db_expect((int)$booking['total_price'] === 123000, 'Client-supplied prices must be ignored.');
    $repeat = create_manual_booking($pdo, $base);
    manual_db_expect($repeat['id'] === $booking['id'], 'Retries must return the same booking.');
    manual_db_conflict(fn() => create_manual_booking($pdo, array_replace($base, ['requestId' => uuid(), 'time' => '11:00'])), 'Overlapping bookings must be rejected.');
    $adjacent = create_manual_booking($pdo, array_replace($base, ['requestId' => uuid(), 'time' => '13:00']));
    manual_db_expect($adjacent['check_in'] === $booking['check_out'], 'Adjacent stays must be allowed.');

    $availability = json_decode((string)file_get_contents('http://localhost:8080/api/availability?date=' . $date), true, 512, JSON_THROW_ON_ERROR);
    manual_db_expect(($availability['connected'] ?? false) === true, 'Availability must use the actual database.');
    $inventory = array_values(array_filter($availability['rooms'], fn($item) => $item['id'] === $room['id']));
    manual_db_expect(count($inventory[0]['intervals'] ?? []) === 2, 'Manual bookings must immediately block public availability.');

    confirm_manual_payment($pdo, $booking['id'], 'bank_transfer');
    confirm_manual_payment($pdo, $booking['id'], 'bank_transfer');
    $payments = $pdo->prepare('SELECT * FROM payments WHERE booking_id=?');
    $payments->execute([$booking['id']]);
    $rows = $payments->fetchAll();
    manual_db_expect(count($rows) === 1 && (int)$rows[0]['amount'] === 123000 && $rows[0]['status'] === 'PAID', 'Repeated payment confirmation must record money once.');
    $pdo->prepare("UPDATE bookings SET status='CANCELLED' WHERE id=?")->execute([$adjacent['id']]);
    manual_db_conflict(fn() => confirm_manual_payment($pdo, $adjacent['id'], 'cash'), 'Cancelled bookings must reject payment confirmation.');
    $paid = create_manual_booking($pdo, array_replace($base, ['requestId' => uuid(), 'time' => '13:00', 'paymentStatus' => 'PAID', 'agreedPrice' => 99000]));
    manual_db_expect($paid['payment_status'] === 'PAID', 'Paid bookings must be supported at creation.');
    manual_db_expect((int)$paid['total_price'] === 99000 && (int)$paid['package_price_snapshot'] === 123000, 'Agreed totals must persist without changing the configured package price snapshot.');
    $payments->execute([$paid['id']]);
    manual_db_expect((int)$payments->fetch()['amount'] === 99000, 'Payment records must use the agreed price.');

    $overnight = create_manual_booking($pdo, array_replace($base, ['requestId' => uuid(), 'packageId' => $room['packages'][1]['id'], 'time' => '01:00']));
    manual_db_expect(strtotime($overnight['check_out']) - strtotime($overnight['check_in']) === 43200, 'Overnight packages must cross midnight using configured times.');
    $blockedDate = (new DateTimeImmutable('+12 days'))->format('Y-m-d');
    $pdo->prepare('INSERT INTO room_availability (id,room_id,`date`,available) VALUES (?,?,?,0)')->execute([uuid(), $room['id'], $blockedDate]);
    manual_db_conflict(fn() => create_manual_booking($pdo, array_replace($base, ['requestId' => uuid(), 'date' => $blockedDate])), 'Blocked dates must reject manual bookings.');

    $concurrentDate = (new DateTimeImmutable('+13 days'))->format('Y-m-d');
    $workers = [];
    for ($index = 0; $index < 2; $index++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $room['id'], $room['packages'][0]['id'], $concurrentDate, uuid()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        manual_db_expect(is_resource($process), 'Concurrency worker must start.');
        $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        manual_db_expect(proc_close($process) === 0, 'Concurrency worker failed: ' . $errors);
    }
    sort($results);
    manual_db_expect($results === ['201', '409'], 'Concurrent overlapping requests must create exactly one booking.');
    [$anonymous] = manual_http('/api/admin/bookings', $base);
    manual_db_expect($anonymous === 401, 'Anonymous users must not create manual bookings.');
    // Local test session only; no production credentials or customer data are used.
    session_name('langhome_session');
    $sessionId = uuid();
    session_id($sessionId);
    session_start();
    $csrf = bin2hex(random_bytes(32));
    $_SESSION = ['admin_until' => time() + 60, 'csrf' => $csrf];
    session_write_close();
    $cookie = 'Cookie: langhome_session=' . $sessionId;
    [$listStatus, $listBody] = manual_http('/api/admin/bookings', [], [$cookie], 'GET');
    manual_db_expect($listStatus === 200 && is_array($listBody['bookings'] ?? null), 'Booking list API must return a JSON array, never PHP error output.');
    manual_db_expect(count($listBody['bookings']) > 0 && isset($listBody['bookings'][0]['room_image']), 'Booking list must preserve room thumbnails.');
    [$missingCsrf] = manual_http('/api/admin/bookings', $base, [$cookie]);
    manual_db_expect($missingCsrf === 419, 'Authenticated writes must require CSRF.');
    $httpInput = array_replace($base, [
        'requestId' => uuid(), 'date' => (new DateTimeImmutable('+15 days'))->format('Y-m-d'),
    ]);
    [$createdStatus, $createdBody] = manual_http('/api/admin/bookings', $httpInput, [$cookie, 'X-CSRF-Token: ' . $csrf]);
    manual_db_expect($createdStatus === 201 && isset($createdBody['bookingCode']), 'Authenticated API requests must create bookings.');
    manual_db_expect(!isset($createdBody['access_token']), 'Create responses must not expose guest access tokens.');
    [$repeatedStatus, $repeatedBody] = manual_http('/api/admin/bookings', $httpInput, [$cookie, 'X-CSRF-Token: ' . $csrf]);
    manual_db_expect($repeatedStatus === 201 && $repeatedBody === $createdBody, 'API retries must return the original booking.');
    $lifecycleInput = array_replace($base, ['requestId' => uuid(), 'date' => (new DateTimeImmutable('+20 days'))->format('Y-m-d')]);
    $lifecycleBooking = create_manual_booking($pdo, $lifecycleInput);
    $pdo->prepare('UPDATE bookings SET check_in=?,check_out=? WHERE id=?')->execute([
        gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() + 3600), $lifecycleBooking['id'],
    ]);
    update_booking_lifecycle($pdo, $lifecycleBooking['id'], 'CHECKED_OUT');
    $findStatus = $pdo->prepare('SELECT * FROM bookings WHERE id=?');
    $findStatus->execute([$lifecycleBooking['id']]);
    $manualEnd = booking_lifecycle($findStatus->fetch());
    manual_db_expect($manualEnd['status'] === 'CHECKED_OUT' && $manualEnd['statusSource'] === 'MANUAL', 'An automatically started booking must allow manual early checkout.');
    manual_db_expect($manualEnd['payment_status'] === 'UNPAID', 'Manual checkout must not mark the booking paid.');
    manual_db_conflict(fn() => update_booking_lifecycle($pdo, $lifecycleBooking['id'], 'CHECKED_IN'), 'Ended bookings must not be checked in again.');

    $early = create_manual_booking($pdo, array_replace($base, ['requestId' => uuid(), 'date' => (new DateTimeImmutable('+21 days'))->format('Y-m-d')]));
    manual_db_conflict(fn() => update_booking_lifecycle($pdo, $early['id'], 'CHECKED_IN'), 'Early check-in must reject conflicts across the expanded stay window.');
} finally {
    if ($sessionId !== null) {
        session_id($sessionId);
        session_start();
        $_SESSION = [];
        session_destroy();
    }
    if ($room !== null) {
        // Only records owned by the temporary test room are removed.
        $pdo->prepare('DELETE FROM bookings WHERE room_id=?')->execute([$room['id']]);
        $repository->delete($room['id']);
    }
}

echo "Manual booking database, availability, payment, concurrency and authenticated API tests: OK\n";
