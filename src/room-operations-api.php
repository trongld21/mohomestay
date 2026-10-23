<?php

declare(strict_types=1);

function room_operations_api(string $path, string $method): never
{
    if ($path === '/api/admin/room-operations' && $method === 'GET') {
        require_once APP_ROOT . '/src/availability.php';
        $date = (string)($_GET['date'] ?? '');
        $days = (int)($_GET['days'] ?? 7);
        if ($days < 1 || $days > 14) throw new BookingException('Chọn từ 1 đến 14 ngày.', 422);
        $pdo = db();
        $rooms = array_values(room_repository()->allRooms());
        $snapshot = availability_snapshot($pdo, $rooms, $date, $days);
        foreach ($snapshot['rooms'] as &$item) {
            $room = current(array_filter($rooms, static fn($r) => $r['id'] === $item['id']));
            $item['name'] = $room['name'];
            $item['slug'] = $room['slug'];
        }
        unset($item);
        $query = $pdo->prepare(
            'SELECT b.id,b.booking_code,b.room_id,b.check_in,b.check_out,b.status,'
            . 'r.name room_name,l.id emergency_lock_id,l.ended_at,'
            . 'c.actual_checkout_at,c.amount surcharge_amount,c.payment_status surcharge_status '
            . 'FROM bookings b JOIN rooms r ON r.id=b.room_id '
            . 'LEFT JOIN room_emergency_locks l ON l.booking_id=b.id '
            . 'LEFT JOIN booking_checkout_charges c ON c.booking_id=b.id '
            . "WHERE (b.check_in<? AND b.check_out>?) OR "
            . "(b.check_in<? AND b.status IN ('CONFIRMED','CHECKED_IN')) OR "
            . "(c.amount>0 AND c.payment_status<>'PAID') ORDER BY b.check_in DESC LIMIT 500"
        );
        $start = mysql_datetime(new DateTimeImmutable($snapshot['rangeStart']));
        $end = mysql_datetime(new DateTimeImmutable($snapshot['rangeEnd']));
        $query->execute([$end, $start, gmdate('Y-m-d H:i:s')]);
        $bookings = array_map(static fn($b) => booking_lifecycle($b), $query->fetchAll());
        $holds = $pdo->query("SELECT id,room_id,starts_at,ends_at,expires_at FROM room_quote_holds WHERE released_at IS NULL AND expires_at>UTC_TIMESTAMP() ORDER BY starts_at")->fetchAll();
        json_response($snapshot + ['bookings' => $bookings, 'quoteHolds' => $holds, 'csrf' => csrf_token()]);
    }
    if ($path === '/api/admin/room-operations/charge' && $method === 'GET') {
        // Read-only charge status is visible only to checkout operators as it includes payment links.
        require_permission('availability.checkout');
        $query = db()->prepare('SELECT * FROM booking_checkout_charges WHERE booking_id=?');
        $query->execute([(string)($_GET['bookingId'] ?? '')]);
        $charge = $query->fetch();
        if (!$charge) throw new BookingException('Chưa có khoản phụ thu cho đơn này.', 404);
        json_response(['charge' => checkout_charge_dto($charge)]);
    }
    if ($method !== 'POST' || !in_array($path, [
        '/api/admin/room-operations/lock',
        '/api/admin/room-operations/hold',
        '/api/admin/room-operations/release',
        '/api/admin/room-operations/checkout',
        '/api/admin/room-operations/payment',
    ], true)) {
        json_response(['error' => 'Không tìm thấy API.'], 404);
    }
    require_csrf();
    $body = json_body();
    $id = (string)($body['bookingId'] ?? '');
    $actor = (string)current_account()['id'];
    if ($path === '/api/admin/room-operations/hold') {
        json_response(['hold' => create_room_quote_hold(db(), $body, $actor)]);
    }
    if ($path === '/api/admin/room-operations/release') {
        release_room_quote_hold(db(), (string)($body['holdId'] ?? ''), $actor);
        json_response(['ok' => true]);
    }
    if ($path === '/api/admin/room-operations/lock') {
        json_response(['ok' => true] + lock_overstaying_booking(db(), $id, $actor));
    }
    if ($path === '/api/admin/room-operations/checkout') {
        $charge = confirm_room_checkout(db(), $id, $actor);
        // Provider errors never roll back departure or lose the amount owed.
        if ((int)$charge['amount'] > 0) {
            try {
                $charge = ensure_checkout_payment(db(), $id);
            } catch (Throwable $error) {
                error_log('Checkout QR unavailable: ' . $error->getMessage());
                json_response([
                    'charge' => checkout_charge_dto($charge),
                    'paymentError' => 'Đã xác nhận trả phòng và lưu phụ thu. Chưa tạo được QR, hãy bấm Tạo lại QR.',
                ]);
            }
        }
        json_response(['charge' => checkout_charge_dto($charge)]);
    }
    json_response(['charge' => checkout_charge_dto(ensure_checkout_payment(db(), $id))]);
}
