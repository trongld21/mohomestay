<?php

declare(strict_types=1);

function assert_payment_booking_inventory(PDO $pdo, array $booking, bool $manualReceipt = false): void
{
    if (!$manualReceipt && $booking['check_out'] <= gmdate('Y-m-d H:i:s')) {
        throw new BookingException('Đơn đã qua giờ trả phòng. Không thể khôi phục giữ lịch.', 409);
    }
    assert_room_inventory($pdo, (string)$booking['room_id'], $booking['check_in'], $booking['check_out'], (string)$booking['id'], $manualReceipt ? time() : null);
    $zone = new DateTimeZone('Asia/Ho_Chi_Minh');
    $start = (new DateTimeImmutable($booking['check_in'], new DateTimeZone('UTC')))->setTimezone($zone);
    $end = (new DateTimeImmutable($booking['check_out'], new DateTimeZone('UTC')))->setTimezone($zone);
    $blocked = $pdo->prepare('SELECT id FROM room_availability WHERE room_id=? AND available=0 AND date BETWEEN ? AND ? LIMIT 1');
    $blocked->execute([$booking['room_id'], $start->format('Y-m-d'), $end->modify('-1 second')->format('Y-m-d')]);
    if ($blocked->fetchColumn()) throw new BookingException('Phòng đang tạm khóa trong ngày lưu trú. Mở khóa rồi thử lại.', 409);
}

function confirm_booking_receipt(PDO $pdo, string $bookingId, string $method, string $actor): array
{
    if (!in_array($method, ['cash', 'bank_transfer', 'payos'], true) || trim($actor) === '') {
        throw new BookingException('Thông tin xác nhận nhận tiền không hợp lệ.', 422);
    }
    // Read before beginning: a snapshot taken before waiting on the room lock
    // could hide a conflicting booking committed by the previous lock holder.
    $query = $pdo->prepare('SELECT room_id FROM bookings WHERE id=?');
    $query->execute([$bookingId]);
    $roomId = $query->fetchColumn();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id,status FROM rooms WHERE id=?' . room_operation_lock_suffix($pdo));
        $lock->execute([$roomId]);
        $room = $lock->fetch();
        $query = $pdo->prepare('SELECT * FROM bookings WHERE id=?' . room_operation_lock_suffix($pdo));
        $query->execute([$bookingId]);
        $booking = $query->fetch();
        if (!$booking || !$room || !empty($booking['is_test'])) throw new BookingException('Không tìm thấy đơn thật.', 404);
        if ($booking['room_id'] !== $roomId) throw new BookingException('Đơn vừa được đổi phòng. Tải lại và thử lại.', 409);
        if ($booking['payment_status'] === 'REFUNDED') throw new BookingException('Đơn đã hoàn tiền, không thể xác nhận lại.', 409);
        $alreadyConfirmed = $booking['payment_status'] === 'PAID' && in_array($booking['status'], ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT'], true);
        if (!$alreadyConfirmed) {
            $completedManual = $booking['status'] === 'CHECKED_OUT' && in_array($booking['booking_source'] ?? '', ['PHONE', 'ZALO'], true);
            if (!$completedManual) {
                if ($room['status'] !== 'ACTIVE') throw new BookingException('Phòng hiện không hoạt động.', 409);
                assert_payment_booking_inventory($pdo, $booking, true);
            }
            $method = ($booking['payment_method'] ?? '') === 'payos' ? 'payos' : ($method === 'payos' ? 'bank_transfer' : $method);
            $payment = $pdo->prepare('SELECT id,amount,status FROM payments WHERE booking_id=?' . room_operation_lock_suffix($pdo));
            $payment->execute([$bookingId]);
            $existing = $payment->fetch();
            if ($existing && $existing['status'] === 'REFUNDED') throw new BookingException('Giao dịch đã hoàn tiền. Cần đối soát trước.', 409);
            if ($existing && (int)$existing['amount'] !== (int)$booking['total_price']) {
                throw new BookingException('Số tiền thanh toán khác giá đơn. Cần đối soát trước.', 409);
            }
            if ($existing) {
                $pdo->prepare("UPDATE payments SET status='PAID',paid_at=COALESCE(paid_at,?),failure_reason=NULL WHERE booking_id=?")
                    ->execute([gmdate('Y-m-d H:i:s'), $bookingId]);
            } else {
                $pdo->prepare("INSERT INTO payments(id,booking_id,amount,payment_method,status,paid_at) VALUES(?,?,?,?,'PAID',?)")
                    ->execute([uuid(), $bookingId, $booking['total_price'], $method, gmdate('Y-m-d H:i:s')]);
            }
            $status = in_array($booking['status'], ['CHECKED_IN', 'CHECKED_OUT'], true) ? $booking['status'] : 'CONFIRMED';
            if ($booking['check_out'] <= gmdate('Y-m-d H:i:s')) $status = 'CHECKED_OUT';
            $pdo->prepare("UPDATE bookings SET status=?,payment_status='PAID',payment_method=? WHERE id=?")
                ->execute([$status, $method, $bookingId]);
            $booking = array_replace($booking, ['status' => $status, 'payment_status' => 'PAID', 'payment_method' => $method]);
            $audit = $pdo->prepare('SELECT id FROM booking_payment_receipts WHERE booking_id=?');
            $audit->execute([$bookingId]);
            if (!$audit->fetchColumn()) {
                $pdo->prepare('INSERT INTO booking_payment_receipts(id,booking_id,confirmed_by,amount,payment_method,confirmed_at) VALUES(?,?,?,?,?,?)')
                    ->execute([uuid(), $bookingId, $actor, $booking['total_price'], $method, gmdate('Y-m-d H:i:s')]);
            }
        }
        $pdo->commit();
        return $booking;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function provision_paid_booking(string $bookingId): array
{
    $result = ['accessReady' => false, 'emailSent' => false];
    try {
        $result['accessReady'] = sync_booking_access($bookingId) !== null;
        $result['emailSent'] = dispatch_confirmation_email($bookingId);
    } catch (Throwable $error) {
        error_log('Paid booking provisioning pending for ' . $bookingId);
    }
    return $result;
}

function retry_paid_booking_provisioning(PDO $pdo, ?callable $provision = null, ?bool $automaticAccess = null): void
{
    $automaticAccess ??= config('ttlock.enabled') && ttlock_configured();
    $query = $pdo->prepare("SELECT b.id FROM bookings b LEFT JOIN booking_access_codes a ON a.booking_id=b.id
        WHERE b.payment_status='PAID' AND b.status IN ('CONFIRMED','CHECKED_IN')
        AND b.is_test=0 AND b.check_out>?
        AND (b.payment_provision_checked_at IS NULL OR b.payment_provision_checked_at<?)
        AND ((b.confirmation_email_status IN ('PENDING','FAILED') AND b.confirmation_email_attempts<3)
            OR (?=1 AND (a.booking_id IS NULL OR a.sync_status IN ('PENDING','FAILED','UNKNOWN'))))
        ORDER BY COALESCE(b.payment_provision_checked_at,'1970-01-01'),b.created_at LIMIT 30");
    $now = gmdate('Y-m-d H:i:s');
    $before = gmdate('Y-m-d H:i:s', time() - 60);
    $query->execute([$now, $before, $automaticAccess ? 1 : 0]);
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $claim = $pdo->prepare('UPDATE bookings SET payment_provision_checked_at=? WHERE id=? AND (payment_provision_checked_at IS NULL OR payment_provision_checked_at<?)');
        $claim->execute([$now, $id, $before]);
        if ($claim->rowCount() !== 1) continue;
        try {
            ($provision ?? 'provision_paid_booking')((string)$id);
        } catch (Throwable $error) {
            error_log('Paid booking retry pending for ' . (string)$id);
        }
    }
}
