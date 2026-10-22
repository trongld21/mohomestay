<?php

declare(strict_types=1);

function quote_manual_booking(array $room, array $input): array
{
    $source = (string)($input['bookingSource'] ?? 'PHONE');
    $paymentStatus = (string)($input['paymentStatus'] ?? 'UNPAID');
    $paymentMethod = (string)($input['paymentMethod'] ?? 'cash');
    $requestId = (string)($input['requestId'] ?? '');
    if (!in_array($source, ['PHONE', 'ZALO'], true)) {
        throw new BookingException('Chọn nguồn đặt phòng qua Điện thoại hoặc Zalo.', 422);
    }
    if (!in_array($paymentStatus, ['UNPAID', 'PAID'], true)
        || !in_array($paymentMethod, ['cash', 'bank_transfer'], true)) {
        throw new BookingException('Thông tin thanh toán không hợp lệ.', 422);
    }
    if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) {
        throw new BookingException('Mã yêu cầu tạo đơn không hợp lệ. Vui lòng mở lại form.', 422);
    }
    $quote = quote_booking_for_room($room, $input, true);
    if (array_key_exists('agreedPrice', $input)) {
        $price = filter_var($input['agreedPrice'], FILTER_VALIDATE_INT);
        if ((!is_int($input['agreedPrice']) && !is_string($input['agreedPrice']))
            || $price === false || $price < 0 || $price > 1000000000) {
            throw new BookingException('Giá chốt phải là số nguyên từ 0 đến 1.000.000.000đ.', 422);
        }
        $quote['total'] = (int)$price;
    }
    return $quote + compact('source', 'paymentStatus', 'paymentMethod', 'requestId');
}

function create_manual_booking(PDO $pdo, array $input): array
{
    $requestId = (string)($input['requestId'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) {
        throw new BookingException('Mã yêu cầu tạo đơn không hợp lệ.', 422);
    }
    $pdo->beginTransaction();
    try {
        // All booking writers lock the room before checking overlaps.
        $lock = $pdo->prepare('SELECT id FROM rooms WHERE id=? FOR UPDATE');
        $lock->execute([(string)($input['roomId'] ?? '')]);
        if (!$lock->fetchColumn()) {
            throw new BookingException('Không tìm thấy phòng.', 404);
        }
        $existing = $pdo->prepare('SELECT * FROM bookings WHERE manual_request_id=?');
        $existing->execute([$requestId]);
        if ($booking = $existing->fetch()) {
            $pdo->commit();
            return $booking;
        }
        $room = (new RoomRepository($pdo))->findById((string)$input['roomId']);
        $quote = quote_manual_booking($room, $input);
        $start = mysql_datetime($quote['start']);
        $end = mysql_datetime($quote['end']);
        $conflict = $pdo->prepare(
            "SELECT id FROM bookings WHERE room_id=? AND check_in<? AND check_out>? "
            . "AND (status IN ('CONFIRMED','CHECKED_IN') OR (status='PENDING' AND hold_expires_at>UTC_TIMESTAMP())) LIMIT 1"
        );
        $conflict->execute([$room['id'], $end, $start]);
        if ($conflict->fetch()) {
            throw new BookingException('Phòng đã có đơn trong khung giờ này. Vui lòng chọn giờ khác.', 409);
        }
        $blocked = $pdo->prepare('SELECT id FROM room_availability WHERE room_id=? AND available=0 AND `date` BETWEEN ? AND ? LIMIT 1');
        $blocked->execute([$room['id'], $quote['start']->format('Y-m-d'), $quote['end']->modify('-1 second')->format('Y-m-d')]);
        if ($blocked->fetch()) {
            throw new BookingException('Phòng tạm khóa trong khoảng thời gian này.', 409);
        }
        $values = [
            'id' => uuid(),
            'booking_code' => 'MO-' . strtoupper(bin2hex(random_bytes(5))),
            'room_id' => $room['id'],
            'check_in' => $start,
            'check_out' => $end,
            'stay_package' => $quote['packageId'],
            'package_id' => $quote['packageId'],
            'package_name_snapshot' => $quote['packageName'],
            'package_mode_snapshot' => $quote['packageMode'],
            'package_duration_snapshot' => $quote['packageDuration'],
            'package_check_in_snapshot' => $quote['packageCheckIn'],
            'package_check_out_snapshot' => $quote['packageCheckOut'],
            'package_price_snapshot' => $quote['packagePrice'],
            'access_token' => bin2hex(random_bytes(32)),
            'total_price' => $quote['total'],
            'number_of_guests' => $quote['guests'],
            'guest_name' => $quote['name'],
            'guest_email' => $quote['email'],
            'guest_phone' => $quote['phone'],
            'guest_note' => $quote['note'],
            'booking_source' => $quote['source'],
            'manual_request_id' => $quote['requestId'],
            'status' => 'CONFIRMED',
            'payment_status' => $quote['paymentStatus'],
            'payment_method' => $quote['paymentMethod'],
        ];
        $columns = implode(',', array_keys($values));
        $marks = implode(',', array_fill(0, count($values), '?'));
        $pdo->prepare('INSERT INTO bookings (' . $columns . ') VALUES (' . $marks . ')')->execute(array_values($values));
        if ($quote['paymentStatus'] === 'PAID') {
            record_manual_payment($pdo, $values['id'], $quote['total'], $quote['paymentMethod']);
        }
        $pdo->commit();
        return $values;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // A request repeated after a network error must never create a second order.
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            $existing = $pdo->prepare('SELECT * FROM bookings WHERE manual_request_id=?');
            $existing->execute([$requestId]);
            if ($booking = $existing->fetch()) {
                return $booking;
            }
        }
        throw $error;
    }
}

function record_manual_payment(PDO $pdo, string $bookingId, int $amount, string $method): void
{
    $pdo->prepare("INSERT INTO payments (id,booking_id,amount,payment_method,status,paid_at) VALUES (?,?,?,?,'PAID',UTC_TIMESTAMP())")
        ->execute([uuid(), $bookingId, $amount, $method]);
}

function confirm_manual_payment(PDO $pdo, string $bookingId, string $method): void
{
    if (!in_array($method, ['cash', 'bank_transfer'], true)) {
        throw new BookingException('Phương thức thanh toán không hợp lệ.', 422);
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id=? FOR UPDATE');
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();
        if (!$booking || !in_array($booking['booking_source'], ['PHONE', 'ZALO'], true)) {
            throw new BookingException('Không tìm thấy đơn thủ công.', 404);
        }
        if (!in_array($booking['status'], ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT'], true)) {
            throw new BookingException('Không thể thu tiền cho đơn đã hủy.', 409);
        }
        if ($booking['payment_status'] !== 'PAID') {
            if ($booking['payment_status'] !== 'UNPAID') {
                throw new BookingException('Trạng thái thanh toán cần được đối soát.', 409);
            }
            record_manual_payment($pdo, $bookingId, (int)$booking['total_price'], $method);
            $pdo->prepare("UPDATE bookings SET payment_status='PAID',payment_method=? WHERE id=?")->execute([$method, $bookingId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
