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
    $find = $pdo->prepare('SELECT room_id FROM bookings WHERE order_code=?');
    $find->execute([(string)$data['orderCode']]);
    $roomId = $find->fetchColumn();
    if (!$roomId) return null;
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id,status FROM rooms WHERE id=?' . room_operation_lock_suffix($pdo));
        $lock->execute([$roomId]);
        $room = $lock->fetch();
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE order_code=?' . room_operation_lock_suffix($pdo));
        $stmt->execute([(string)$data['orderCode']]);
        $booking = $stmt->fetch();
        if (!$booking) { $pdo->commit(); return null; }
        if ($booking['room_id'] !== $roomId) throw new BookingException('Đơn vừa đổi phòng. Cần thử đối soát lại.', 409);
        if ((bool)$booking['is_test'] !== $test) throw new BookingException('Callback không phù hợp với loại đơn.', 403);
        if ((int)$data['amount'] !== (int)$booking['total_price']) throw new BookingException('Số tiền không khớp đơn đặt phòng.', 422);
        if ($booking['payment_status'] === 'REFUNDED') {
            $pdo->commit();
            return $booking;
        }
        if ($booking['payment_status'] !== 'PAID') {
            // A verified payment may be discovered after its reservation hold expires.
            // Recover only a pending booking with inventory still available.
            $valid = $booking['status'] === 'PENDING' && ($room['status'] ?? '') === 'ACTIVE'
                && (!$test || strtotime($booking['hold_expires_at'] . ' UTC') > time());
            if ($valid) {
                try {
                    assert_payment_booking_inventory($pdo, $booking);
                } catch (BookingException $error) {
                    $valid = false;
                }
            }
            $status = $valid ? 'CONFIRMED' : 'CANCELLED';
            $reason = $valid ? null : 'Đã nhận tiền nhưng đơn đã hủy, quá giờ trả hoặc phòng đang bận/khóa. Cần đối soát thủ công.';
            $pdo->prepare("UPDATE bookings SET payment_status='PAID',status=?,transaction_id=? WHERE id=?")->execute([$status, $data['reference'], $booking['id']]);
            $payment = $pdo->prepare('SELECT id FROM payments WHERE booking_id=?');
            $payment->execute([$booking['id']]);
            if ($payment->fetchColumn()) {
                $pdo->prepare("UPDATE payments SET status='PAID',transaction_id=?,paid_at=?,failure_reason=? WHERE booking_id=?")
                    ->execute([$data['reference'], gmdate('Y-m-d H:i:s'), $reason, $booking['id']]);
            } else {
                $pdo->prepare("INSERT INTO payments(id,booking_id,amount,payment_method,status,transaction_id,paid_at,failure_reason) VALUES(?,?,?,?,'PAID',?,?,?)")
                    ->execute([uuid(), $booking['id'], (int)$data['amount'], $test ? 'mock' : 'payos', $data['reference'], gmdate('Y-m-d H:i:s'), $reason]);
            }
            $booking['status'] = $status;
            $booking['payment_status'] = 'PAID';
        }
        if (!$test && $booking['status'] === 'CONFIRMED') {
            queue_staff_notification($pdo, $booking, 'BOOKED');
        }
        $pdo->commit();
        return $booking;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
