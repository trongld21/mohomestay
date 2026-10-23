<?php

declare(strict_types=1);

function correct_booking_stay(PDO $pdo, string $id, array $input): void
{
    $account = current_account();
    if (!$account || ($account['root'] ?? false) !== true) {
        throw new BookingException('Chỉ superadmin được đổi phòng và sửa lịch lưu trú.', 403);
    }
    $roomId = (string)($input['roomId'] ?? '');
    $reason = trim((string)($input['reason'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $id) || $roomId === '' || strlen($roomId) > 64) {
        throw new BookingException('Đơn hoặc phòng không hợp lệ.', 422);
    }
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
        throw new BookingException('Nhập lý do điều chỉnh từ 5 đến 500 ký tự.', 422);
    }
    $dates = [];
    foreach (['checkIn', 'checkOut'] as $key) {
        $value = (string)($input[$key] ?? '');
        $format = strlen($value) === 19 ? 'Y-m-d\TH:i:s' : 'Y-m-d\TH:i';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('Asia/Ho_Chi_Minh'));
        if (!$date || $date->format($format) !== $value) {
            throw new BookingException('Nhập đầy đủ ngày và giờ nhận/trả phòng hợp lệ.', 422);
        }
        $dates[] = $date;
    }
    [$start, $end] = $dates;
    if ($end <= $start) throw new BookingException('Giờ trả phòng phải sau giờ nhận phòng.', 422);
    $a = mysql_datetime($start);
    $b = mysql_datetime($end);

    // Read the old room before beginning the transaction so inventory reads do not
    // reuse a MySQL snapshot created before waiting for the room locks.
    $query = $pdo->prepare('SELECT room_id FROM bookings WHERE id=?');
    $query->execute([$id]);
    $oldRoomId = $query->fetchColumn();
    if (!$oldRoomId) throw new BookingException('Không tìm thấy đơn.', 404);
    $pdo->beginTransaction();
    try {
        $suffix = room_operation_lock_suffix($pdo);
        $query = $pdo->prepare('SELECT id,status,is_active,max_guests FROM rooms WHERE id IN (?,?) ORDER BY id' . $suffix);
        $query->execute([$oldRoomId, $roomId]);
        $rooms = $query->fetchAll();
        $target = null;
        foreach ($rooms as $room) {
            if ($room['id'] === $roomId) $target = $room;
        }
        if (!$target) throw new BookingException('Không tìm thấy phòng đích.', 404);
        assert_room_bookable($target);
        if (!(bool)$target['is_active']) throw new BookingException('Phòng đích đang ngưng nhận khách.', 409);
        $query = $pdo->prepare('SELECT * FROM bookings WHERE id=?' . $suffix);
        $query->execute([$id]);
        $booking = $query->fetch();
        if (!$booking || $booking['room_id'] !== $oldRoomId
            || $booking['room_id'] !== ($input['expectedRoomId'] ?? null)
            || $booking['check_in'] !== ($input['expectedCheckIn'] ?? null)
            || $booking['check_out'] !== ($input['expectedCheckOut'] ?? null)) {
            throw new BookingException('Đơn vừa được thay đổi. Đóng biểu mẫu và tải lại đơn trước khi sửa.', 409);
        }
        if (!in_array($booking['status'], ['PENDING', 'CONFIRMED', 'CHECKED_IN'], true)
            || ($booking['status'] === 'PENDING' && (!$booking['hold_expires_at'] || $booking['hold_expires_at'] <= gmdate('Y-m-d H:i:s')))) {
            throw new BookingException('Chỉ sửa đơn còn hiệu lực, chưa hủy và chưa xác nhận trả phòng.', 409);
        }
        if ((int)$booking['number_of_guests'] > (int)$target['max_guests']) {
            throw new BookingException('Phòng đích không đủ sức chứa cho số khách trong đơn.', 409);
        }
        if ($oldRoomId === $roomId && $booking['check_in'] === $a && $booking['check_out'] === $b) {
            throw new BookingException('Phòng và lịch chưa thay đổi.', 422);
        }
        $query = $pdo->prepare('SELECT booking_id FROM booking_checkout_charges WHERE booking_id=?');
        $query->execute([$id]);
        if ($query->fetchColumn()) throw new BookingException('Đơn đã có xác nhận trả phòng/phụ thu, không thể sửa lịch.', 409);
        $query = $pdo->prepare('SELECT id FROM room_emergency_locks WHERE booking_id=? AND ended_at IS NULL');
        $query->execute([$id]);
        if ($query->fetchColumn()) throw new BookingException('Xử lý khóa khẩn cấp của đơn trước khi đổi phòng/lịch.', 409);

        $changedTime = $booking['check_in'] !== $a || $booking['check_out'] !== $b;
        $query = $pdo->prepare('SELECT * FROM booking_access_codes WHERE booking_id=?');
        $query->execute([$id]);
        $access = $query->fetch();
        if ($changedTime && $access && $access['sync_status'] !== 'REVOKED'
            && ($access['sync_status'] !== 'PENDING' || $access['remote_id'] !== null || (int)$access['attempts'] > 0)) {
            throw new BookingException('Đơn đã cấp hoặc đang đối soát mã TTLock. Cần xử lý mã cửa trước khi đổi thời gian.', 409);
        }
        assert_room_inventory($pdo, $roomId, $a, $b, $id);
        $query = $pdo->prepare('SELECT id FROM room_availability WHERE room_id=? AND available=0 AND `date` BETWEEN ? AND ? LIMIT 1');
        $query->execute([$roomId, $start->format('Y-m-d'), $end->modify('-1 second')->format('Y-m-d')]);
        if ($query->fetchColumn()) throw new BookingException('Phòng đích đang tạm khóa trong ngày đã chọn.', 409);

        $pdo->prepare('UPDATE bookings SET room_id=?,check_in=?,check_out=? WHERE id=?')->execute([$roomId, $a, $b, $id]);
        if ($changedTime && $access && $access['sync_status'] === 'PENDING') {
            $updated = array_replace($booking, ['check_in' => $a, 'check_out' => $b]);
            $window = $access['code_mode'] === 'GENERATED' ? ttlock_generated_window($updated) : ttlock_window($updated);
            $pdo->prepare('UPDATE booking_access_codes SET starts_at=?,ends_at=? WHERE booking_id=?')
                ->execute([$window['starts_at'], $window['ends_at'], $id]);
        }
        $pdo->prepare(
            'INSERT INTO booking_stay_changes (id,booking_id,actor_id,old_room_id,new_room_id,'
            . 'old_check_in,old_check_out,new_check_in,new_check_out,reason) VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            uuid(), $id, (string)$account['id'], $oldRoomId, $roomId,
            $booking['check_in'], $booking['check_out'], $a, $b, $reason,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
