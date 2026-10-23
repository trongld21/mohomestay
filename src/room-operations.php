<?php

declare(strict_types=1);

function late_checkout_fee(string $scheduledCheckout, ?int $actual = null): array
{
    $scheduled = strtotime($scheduledCheckout . ' UTC');
    if ($scheduled === false) throw new BookingException('Giờ trả phòng không hợp lệ.', 422);
    $late = max(0, ($actual ?? time()) - $scheduled);
    $hours = (int)ceil(max(0, $late - 900) / 3600);
    return ['lateSeconds' => $late, 'billableHours' => $hours, 'amount' => $hours * 50000];
}

function room_operation_lock_suffix(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

function room_has_emergency_lock(PDO $pdo, string $roomId, string $start, string $end): bool
{
    $query = $pdo->prepare(
        'SELECT id FROM room_emergency_locks WHERE room_id=? AND starts_at<? '
        . 'AND (ended_at IS NULL OR ended_at>?) LIMIT 1'
    );
    $query->execute([$roomId, $end, $start]);
    return (bool)$query->fetchColumn();
}

function assert_no_emergency_lock(PDO $pdo, string $roomId, string $start, string $end): void
{
    if (room_has_emergency_lock($pdo, $roomId, $start, $end)) {
        throw new BookingException('Phòng đang khóa khẩn cấp vì khách chưa rời phòng. Vui lòng chọn phòng khác.', 409);
    }
}

function room_operation_booking(PDO $pdo, string $bookingId): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $bookingId)) {
        throw new BookingException('Mã đơn không hợp lệ.', 422);
    }
    $query = $pdo->prepare('SELECT room_id FROM bookings WHERE id=?');
    $query->execute([$bookingId]);
    $roomId = $query->fetchColumn();
    if (!$roomId) throw new BookingException('Không tìm thấy đơn.', 404);
    // All inventory mutations acquire the room row before any booking row.
    $query = $pdo->prepare('SELECT id FROM rooms WHERE id=?' . room_operation_lock_suffix($pdo));
    $query->execute([$roomId]);
    $query = $pdo->prepare('SELECT * FROM bookings WHERE id=?' . room_operation_lock_suffix($pdo));
    $query->execute([$bookingId]);
    $booking = $query->fetch();
    if (!$booking) throw new BookingException('Không tìm thấy đơn.', 404);
    return $booking;
}

function lock_overstaying_booking(PDO $pdo, string $bookingId, string $actor, ?int $now = null): array
{
    $now ??= time();
    $pdo->beginTransaction();
    try {
        $booking = room_operation_booking($pdo, $bookingId);
        if (!in_array($booking['status'], ['CONFIRMED', 'CHECKED_IN'], true)
            || strtotime($booking['check_out'] . ' UTC') > $now) {
            throw new BookingException('Chỉ khóa khẩn cấp đơn đã đến giờ trả phòng và chưa xác nhận trả.', 409);
        }
        $query = $pdo->prepare('SELECT * FROM room_emergency_locks WHERE booking_id=?');
        $query->execute([$bookingId]);
        $hold = $query->fetch();
        if (!$hold) {
            $query = $pdo->prepare('SELECT id FROM room_emergency_locks WHERE room_id=? AND ended_at IS NULL');
            $query->execute([$booking['room_id']]);
            if ($query->fetch()) throw new BookingException('Phòng đã bị khóa bởi một đơn khác. Kiểm tra khách đang ở trước khi khóa.', 409);
            $query = $pdo->prepare(
                'INSERT INTO room_emergency_locks (id,booking_id,room_id,starts_at,locked_by) VALUES (?,?,?,?,?)'
            );
            $query->execute([uuid(), $bookingId, $booking['room_id'], $booking['check_out'], $actor]);
        }
        $query = $pdo->prepare(
            "SELECT booking_code,check_in,check_out FROM bookings WHERE room_id=? AND id<>? "
            . "AND check_out>? AND (status IN ('CONFIRMED','CHECKED_IN') OR (status='PENDING' AND hold_expires_at>?)) "
            . 'ORDER BY check_in LIMIT 10'
        );
        $query->execute([$booking['room_id'], $bookingId, $booking['check_out'], gmdate('Y-m-d H:i:s', $now)]);
        $affected = $query->fetchAll();
        $pdo->commit();
        return ['affectedBookings' => $affected];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function confirm_room_checkout(PDO $pdo, string $bookingId, string $actor, ?int $now = null): array
{
    $now ??= time();
    $pdo->beginTransaction();
    try {
        $booking = room_operation_booking($pdo, $bookingId);
        $query = $pdo->prepare('SELECT * FROM booking_checkout_charges WHERE booking_id=?');
        $query->execute([$bookingId]);
        $charge = $query->fetch();
        if ($charge) {
            $pdo->commit();
            return $charge;
        }
        $effective = booking_lifecycle($booking, $now);
        if ($effective['status'] !== 'CHECKED_IN') {
            throw new BookingException('Chỉ xác nhận trả phòng cho đơn đang lưu trú.', 409);
        }
        $fee = late_checkout_fee($booking['check_out'], $now);
        // A separate numeric namespace cannot collide with booking millisecond order codes.
        $orderCode = $fee['amount'] > 0 ? random_int(800000000000000, 899999999999999) : null;
        $query = $pdo->prepare(
            'INSERT INTO booking_checkout_charges '
            . '(booking_id,actual_checkout_at,confirmed_by,late_seconds,billable_hours,amount,order_code,payment_status) '
            . 'VALUES (?,?,?,?,?,?,?,?)'
        );
        $query->execute([
            $bookingId, gmdate('Y-m-d H:i:s', $now), $actor, $fee['lateSeconds'],
            $fee['billableHours'], $fee['amount'], $orderCode, $fee['amount'] > 0 ? 'DUE' : 'NONE',
        ]);
        $pdo->prepare("UPDATE bookings SET status='CHECKED_OUT' WHERE id=?")->execute([$bookingId]);
        $pdo->prepare('UPDATE room_emergency_locks SET ended_at=?,ended_by=? WHERE booking_id=? AND ended_at IS NULL')
            ->execute([gmdate('Y-m-d H:i:s', $now), $actor, $bookingId]);
        $query = $pdo->prepare('SELECT * FROM booking_checkout_charges WHERE booking_id=?');
        $query->execute([$bookingId]);
        $charge = $query->fetch();
        $pdo->commit();
        return $charge;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function checkout_charge_dto(array $charge): array
{
    return [
        'bookingId' => $charge['booking_id'],
        'actualCheckoutAt' => $charge['actual_checkout_at'],
        'lateSeconds' => (int)$charge['late_seconds'],
        'billableHours' => (int)$charge['billable_hours'],
        'amount' => (int)$charge['amount'],
        'paymentStatus' => $charge['payment_status'],
        'checkoutUrl' => $charge['checkout_url'],
    ];
}

function create_checkout_payment(array $charge, array $booking): array
{
    if (!empty($booking['is_test'])) throw new BookingException('Đơn thử không tạo QR thu tiền thật.', 422);
    if (!config('payos.client_id') || !config('payos.api_key') || !config('payos.checksum_key') || !config('app_url')) {
        throw new BookingException('Chưa cấu hình payOS. Đã lưu phụ thu, hãy cấu hình rồi thử tạo QR lại.', 503);
    }
    // The hosted page displays the actual QR; no sensitive QR payload is sent to image services.
    $url = rtrim((string)config('app_url'), '/') . '/checkout-payment';
    $fields = [
        'amount' => (int)$charge['amount'],
        'cancelUrl' => $url,
        'description' => 'PT' . substr($booking['booking_code'], -8),
        'orderCode' => (int)$charge['order_code'],
        'returnUrl' => $url,
    ];
    $ch = curl_init('https://api-merchant.payos.vn/v2/payment-requests');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => json_encode($fields + ['signature' => payos_signature($fields)]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-client-id: ' . config('payos.client_id'),
            'x-api-key: ' . config('payos.api_key'),
        ],
    ]);
    $raw = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $response = json_decode((string)$raw, true);
    if ($httpStatus >= 200 && $httpStatus < 300 && ($response['code'] ?? '') === '00'
        && is_array($response['data'] ?? null)
        && payos_verify($response['data'], (string)($response['signature'] ?? ''))) {
        $data = $response['data'];
        if ((string)($data['orderCode'] ?? '') === (string)$charge['order_code']
            && (int)($data['amount'] ?? -1) === (int)$charge['amount']
            && parse_url((string)($data['checkoutUrl'] ?? ''), PHP_URL_HOST) === 'pay.payos.vn'
            && str_starts_with((string)$data['checkoutUrl'], 'https://')
            && !empty($data['qrCode'])) {
            return $data;
        }
    }
    // A timed-out POST may already have created this payment. Reuse its stable identity.
    $data = payos_payment_status((string)$charge['order_code']);
    if ((string)($data['orderCode'] ?? '') !== (string)$charge['order_code']
        || (int)($data['amount'] ?? -1) !== (int)$charge['amount']
        || !preg_match('/^[a-f0-9]{32}$/', (string)($data['id'] ?? ''))
        || !in_array($data['status'] ?? '', ['PENDING', 'PROCESSING', 'PAID'], true)) {
        throw new BookingException('Chưa tạo được QR phụ thu. Khoản phải thu đã lưu, vui lòng thử lại.', 503);
    }
    return $data + ['checkoutUrl' => 'https://pay.payos.vn/web/' . $data['id'], 'qrCode' => null];
}

function ensure_checkout_payment(PDO $pdo, string $bookingId, ?callable $create = null): array
{
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM booking_checkout_charges WHERE booking_id=?' . room_operation_lock_suffix($pdo));
        $query->execute([$bookingId]);
        $charge = $query->fetch();
        if (!$charge) throw new BookingException('Chưa xác nhận trả phòng cho đơn này.', 409);
        if ((int)$charge['amount'] === 0 || $charge['payment_status'] === 'PAID' || !empty($charge['checkout_url'])) {
            $pdo->commit();
            return $charge;
        }
        $bookingQuery = $pdo->prepare('SELECT booking_code,is_test FROM bookings WHERE id=?');
        $bookingQuery->execute([$bookingId]);
        $booking = $bookingQuery->fetch();
        if (!$booking) throw new BookingException('Không tìm thấy đơn.', 404);
        $payment = ($create ?? 'create_checkout_payment')($charge, $booking);
        if (($payment['status'] ?? '') === 'PAID'
            && (int)($payment['amountPaid'] ?? 0) < (int)$charge['amount']) {
            throw new BookingException('Thông tin phụ thu đã thanh toán không khớp.', 422);
        }
        $status = ($payment['status'] ?? '') === 'PAID' ? 'PAID' : 'PENDING';
        $query = $pdo->prepare('UPDATE booking_checkout_charges SET qr_code=?,checkout_url=?,payment_link_id=?,'
            . 'payment_status=?,paid_at=? WHERE booking_id=?');
        $query->execute([
            $payment['qrCode'] ?? null, $payment['checkoutUrl'], $payment['paymentLinkId'] ?? $payment['id'] ?? null,
            $status, $status === 'PAID' ? gmdate('Y-m-d H:i:s') : null, $bookingId,
        ]);
        $query = $pdo->prepare('SELECT * FROM booking_checkout_charges WHERE booking_id=?');
        $query->execute([$bookingId]);
        $charge = $query->fetch();
        $pdo->commit();
        return $charge;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

// Called only after signature/currency/success validation in the existing webhook.
function apply_checkout_payment_callback(PDO $pdo, array $data): bool
{
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM booking_checkout_charges WHERE order_code=?' . room_operation_lock_suffix($pdo));
        $query->execute([(string)$data['orderCode']]);
        $charge = $query->fetch();
        if (!$charge) {
            $pdo->commit();
            return false;
        }
        $bookingQuery = $pdo->prepare('SELECT is_test FROM bookings WHERE id=?');
        $bookingQuery->execute([$charge['booking_id']]);
        $isTest = $bookingQuery->fetchColumn();
        if ($isTest === false || (bool)$isTest || (float)$data['amount'] !== (float)$charge['amount']
            || (!empty($charge['payment_link_id']) && $charge['payment_link_id'] !== ($data['paymentLinkId'] ?? ''))) {
            throw new BookingException('Giao dịch không khớp khoản phụ thu.', 422);
        }
        if ($charge['payment_status'] !== 'PAID') {
            $pdo->prepare("UPDATE booking_checkout_charges SET payment_status='PAID',transaction_reference=?,paid_at=? WHERE booking_id=?")
                ->execute([$data['reference'], gmdate('Y-m-d H:i:s'), $charge['booking_id']]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
