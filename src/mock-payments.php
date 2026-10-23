<?php

declare(strict_types=1);

function require_mock_payment_access(): void
{
    if (!dev_reload_enabled() || env_value('APP_ENV') === 'production') throw new BookingException('Không tìm thấy API.', 404);
    require_admin();
    require_csrf();
}

function mock_payment_api(string $action): never
{
    require_mock_payment_access();
    require_permission($action === 'create' ? 'bookings.create' : 'bookings.payment');
    $input = json_body();
    $pdo = db();
    if ($action === 'create') {
        $input['paymentStatus'] = 'UNPAID';
        $booking = create_manual_booking($pdo, $input, true);
        if (empty($booking['is_test'])) throw new BookingException('Mã yêu cầu thuộc đơn thật. Hãy tạo mã yêu cầu thử mới.', 409);
        json_response([
            'test' => true,
            'bookingCode' => $booking['booking_code'],
            'orderCode' => (string)$booking['order_code'],
            'amount' => (int)$booking['total_price'],
            'paymentUrl' => '/payment?code=' . rawurlencode($booking['booking_code']) . '#' . $booking['access_token'],
        ], 201);
    }
    $orderCode = (string)($input['orderCode'] ?? '');
    $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_INT);
    if (!preg_match('/^\d{10,20}$/', $orderCode) || $amount === false || $amount < 0) {
        throw new BookingException('orderCode và amount không hợp lệ.', 422);
    }
    $booking = apply_payment_callback($pdo, [
        'orderCode' => $orderCode, 'amount' => $amount,
        'reference' => 'MOCK-' . $orderCode,
    ], true);
    if (!$booking) throw new BookingException('Không tìm thấy đơn thử.', 404);
    json_response(['test' => true, 'bookingCode' => $booking['booking_code'], 'status' => $booking['status'], 'paymentStatus' => $booking['payment_status']]);
}

function apply_payment_callback(PDO $pdo, array $data, bool $test = false): ?array
{
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT room_id FROM bookings WHERE order_code=?');
        $find->execute([(string)$data['orderCode']]);
        $roomId = $find->fetchColumn();
        if (!$roomId) { $pdo->commit(); return null; }
        $lock = $pdo->prepare('SELECT id FROM rooms WHERE id=? FOR UPDATE');
        $lock->execute([$roomId]);
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE order_code=? FOR UPDATE');
        $stmt->execute([(string)$data['orderCode']]);
        $booking = $stmt->fetch();
        if (!$booking) { $pdo->commit(); return null; }
        if ((bool)$booking['is_test'] !== $test) throw new BookingException('Callback không phù hợp với loại đơn.', 403);
        if ((int)$data['amount'] !== (int)$booking['total_price']) throw new BookingException('Số tiền không khớp đơn đặt phòng.', 422);
        if ($booking['payment_status'] !== 'PAID') {
            $conflict = $pdo->prepare("SELECT id FROM bookings WHERE id<>? AND room_id=? AND check_in<? AND check_out>? AND (status IN ('CONFIRMED','CHECKED_IN') OR (status='PENDING' AND hold_expires_at>UTC_TIMESTAMP())) LIMIT 1");
            $conflict->execute([$booking['id'], $roomId, $booking['check_out'], $booking['check_in']]);
            $valid = $booking['status'] === 'PENDING' && strtotime($booking['hold_expires_at'] . ' UTC') > time() && !$conflict->fetch();
            $status = $valid ? 'CONFIRMED' : 'CANCELLED';
            $reason = $valid ? null : 'Đã nhận tiền sau thời hạn hoặc đơn đã hủy. Cần đối soát/hoàn tiền.';
            $pdo->prepare("UPDATE bookings SET payment_status='PAID',status=?,transaction_id=? WHERE id=?")->execute([$status, $data['reference'], $booking['id']]);
            $pdo->prepare("INSERT INTO payments(id,booking_id,amount,payment_method,status,transaction_id,paid_at,failure_reason) VALUES(?,?,?,?,'PAID',?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE status='PAID',transaction_id=VALUES(transaction_id),paid_at=VALUES(paid_at),failure_reason=VALUES(failure_reason)")
                ->execute([uuid(), $booking['id'], (int)$data['amount'], $test ? 'mock' : 'payos', $data['reference'], $reason]);
            $booking['status'] = $status;
            $booking['payment_status'] = 'PAID';
        }
        $pdo->commit();
        return $booking;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
