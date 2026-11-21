<?php

declare(strict_types=1);

final class BookingStayAccessException extends BookingException
{
    public function __construct(public readonly string $accessToken)
    {
        parent::__construct('Trong ứng dụng TTLock, tìm mã theo mã đơn và thu hồi mã cũ tại khóa. Nếu lần cấp mã đang đối soát, hãy kiểm tra cả các mã có thể đã được tạo cho đơn này.', 409);
    }
}

function correct_booking_stay(PDO $pdo, string $id, array $input): bool
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
    $query = $pdo->prepare('SELECT room_id,check_in,check_out FROM bookings WHERE id=?');
    $query->execute([$id]);
    $original = $query->fetch();
    if (!$original) throw new BookingException('Không tìm thấy đơn.', 404);
    $preserveTimes = ($input['preserveTimes'] ?? false) === true;
    $dates = [];
    foreach (['checkIn', 'checkOut'] as $key) {
        if ($preserveTimes) {
            $dates[] = (new DateTimeImmutable($original[$key === 'checkIn' ? 'check_in' : 'check_out'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
            continue;
        }
        $value = (string)($input[$key] ?? '');
        $format = strlen($value) === 19 ? 'Y-m-d\TH:i:s' : 'Y-m-d\TH:i';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('Asia/Ho_Chi_Minh'));
        if (!$date || $date->format($format) !== $value) {
            throw new BookingException('Nhập đầy đủ ngày và giờ nhận/trả phòng hợp lệ.', 422);
        }
        if ((int)$date->format('i') % 30 !== 0 || $date->format('s') !== '00') {
            throw new BookingException('Giờ nhận và trả phòng chỉ được chọn phút 00 hoặc 30, không có giây lẻ.', 422);
        }
        $dates[] = $date;
    }
    [$start, $end] = $dates;
    if ($end <= $start) throw new BookingException('Giờ trả phòng phải sau giờ nhận phòng.', 422);
    $a = mysql_datetime($start);
    $b = mysql_datetime($end);

    // Read the old room before beginning the transaction so inventory reads do not
    // reuse a MySQL snapshot created before waiting for the room locks.
    $oldRoomId = $original['room_id'];
    $changedTime = $original['check_in'] !== $a || $original['check_out'] !== $b;
    $accessLock = null;
    if ($changedTime && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute(['mo-access-' . $id]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new BookingException('Mã cổng đang đồng bộ. Vui lòng đợi rồi bấm lưu lại.', 409);
        }
        $accessLock = 'mo-access-' . $id;
    }
    try {
        $pdo->beginTransaction();
        $replacementQueued = false;
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
            || $booking['check_in'] !== $original['check_in'] || $booking['check_out'] !== $original['check_out']
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
        if (!empty($booking['continued_from_booking_id']) || !empty($booking['continued_to_booking_id']) || pending_booking_continuation($pdo, $id)) {
            throw new BookingException('Đơn đang nối gói ở tiếp. Hủy gói ở tiếp trước khi sửa lịch/phòng.', 409);
        }
        $query = $pdo->prepare('SELECT booking_id FROM booking_checkout_charges WHERE booking_id=?');
        $query->execute([$id]);
        if ($query->fetchColumn()) throw new BookingException('Đơn đã có xác nhận trả phòng/phụ thu, không thể sửa lịch.', 409);
        $query = $pdo->prepare('SELECT id FROM room_emergency_locks WHERE booking_id=? AND ended_at IS NULL');
        $query->execute([$id]);
        if ($query->fetchColumn()) throw new BookingException('Xử lý khóa khẩn cấp của đơn trước khi đổi phòng/lịch.', 409);

        assert_room_inventory($pdo, $roomId, $a, $b, $id);
        $query = $pdo->prepare('SELECT id FROM room_availability WHERE room_id=? AND available=0 AND `date` BETWEEN ? AND ? LIMIT 1');
        $query->execute([$roomId, $start->format('Y-m-d'), $end->modify('-1 second')->format('Y-m-d')]);
        if ($query->fetchColumn()) throw new BookingException('Phòng đích đang tạm khóa trong ngày đã chọn.', 409);

        if ($changedTime) {
            $updated = array_replace($booking, ['check_in' => $a, 'check_out' => $b]);
            $replacementQueued = reschedule_booking_stay_access($pdo, $updated, $input['revokedAccess'] ?? null);
        }
        $pdo->prepare('UPDATE bookings SET room_id=?,check_in=?,check_out=? WHERE id=?')->execute([$roomId, $a, $b, $id]);
        $pdo->prepare(
            'INSERT INTO booking_stay_changes (id,booking_id,actor_id,old_room_id,new_room_id,'
            . 'old_check_in,old_check_out,new_check_in,new_check_out,reason) VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            uuid(), $id, (string)$account['id'], $oldRoomId, $roomId,
            $booking['check_in'], $booking['check_out'], $a, $b, $reason,
        ]);
        $pdo->commit();
        return $replacementQueued;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        if ($accessLock !== null) {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$accessLock]);
        }
    }
}

function reschedule_booking_stay_access(PDO $pdo, array $booking, mixed $revokedAccess): bool
{
    $id = $booking['id'];
    $query = $pdo->prepare('SELECT * FROM booking_access_codes WHERE booking_id=?');
    $query->execute([$id]);
    $access = $query->fetch();
    if (!$access) {
        return false;
    }

    $generated = $access['code_mode'] === 'GENERATED';
    $window = $generated ? ttlock_generated_window($booking) : ttlock_window($booking);
    $revoked = $access['sync_status'] === 'REVOKED';
    if (!$revoked && $access['starts_at'] === $window['starts_at'] && $access['ends_at'] === $window['ends_at']) {
        return false;
    }
    if ($access['sync_status'] === 'PENDING' && $access['remote_id'] === null && (int)$access['attempts'] === 0) {
        $pdo->prepare('UPDATE booking_access_codes SET starts_at=?,ends_at=? WHERE booking_id=?')
            ->execute([$window['starts_at'], $window['ends_at'], $id]);
        return false;
    }

    $now = gmdate('Y-m-d H:i:s');
    if (($booking['confirmation_email_status'] ?? '') === 'SENDING') {
        throw new BookingException('Email xác nhận đang gửi. Vui lòng đợi rồi bấm lưu lại.', 409);
    }
    if (!$revoked && $access['ends_at'] > $now) {
        // Bind the confirmation to the version the administrator was asked to revoke.
        $token = hash('sha256', implode('|', [
            $id, $access['revision'], $access['remote_id'], $access['attempts'],
            $access['sync_status'], $access['starts_at'], $access['ends_at'],
        ]));
        if (!is_string($revokedAccess) || !hash_equals($token, $revokedAccess)) {
            throw new BookingStayAccessException($token);
        }
    }

    $active = $window['ends_at'] > $now;
    $pin = $active && !$generated ? (string)random_int(10000000, 99999999) : null;
    $pdo->prepare('UPDATE booking_access_codes SET starts_at=?,ends_at=?,sync_status=?,code=?,remote_id=NULL,'
        . 'attempts=0,last_attempt_at=NULL,sync_error=NULL,revision=revision+1 WHERE booking_id=?')
        ->execute([$window['starts_at'], $window['ends_at'], $active ? 'PENDING' : 'REVOKED', $pin, $id]);
    if ($active) {
        $pdo->prepare("UPDATE bookings SET confirmation_email_status='PENDING',confirmation_email_attempts=0,"
            . 'confirmation_email_sent_at=NULL,confirmation_email_error=NULL WHERE id=?')->execute([$id]);
    }
    return $active;
}
