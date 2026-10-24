<?php

declare(strict_types=1);

require_once __DIR__ . '/booking-continuation.php';

const ROOM_CLEANING_SECONDS = 3600;

function room_occupied_until(array $booking, ?string $actualCheckout = null): string
{
    $scheduled = strtotime($booking['check_out'] . ' UTC');
    if ($booking['status'] === 'CHECKED_OUT') {
        $actual = $actualCheckout ?? ($booking['actual_checkout_at'] ?? null);
        $scheduled = $actual ? strtotime($actual . ' UTC') : $scheduled;
    }
    $cleaning = !empty($booking['continuation_active']) && $booking['status'] !== 'CHECKED_OUT' ? 0 : ROOM_CLEANING_SECONDS;
    return gmdate('Y-m-d H:i:s', $scheduled + $cleaning);
}

/**
 * Exact half-open interval check shared by all booking writers under the room row lock.
 * Both stays need their cleaning window; completed stays retain their actual cleaning time.
 */
function room_booking_conflict(PDO $pdo, string $roomId, string $start, string $end, ?string $exceptId = null): bool
{
    $query = $pdo->prepare(
        'SELECT b.*,c.actual_checkout_at FROM bookings b '
        . 'LEFT JOIN booking_checkout_charges c ON c.booking_id=b.id '
        . "WHERE b.room_id=? AND b.check_in<? AND (b.status IN ('CONFIRMED','CHECKED_IN','CHECKED_OUT') "
        . "OR (b.status='PENDING' AND b.hold_expires_at>?))"
    );
    $query->execute([$roomId, gmdate('Y-m-d H:i:s', strtotime($end . ' UTC') + ROOM_CLEANING_SECONDS), gmdate('Y-m-d H:i:s')]);
    $bookings = $query->fetchAll();
    foreach ($bookings as $booking) {
        $booking['continuation_active'] = booking_has_live_continuation($booking, $bookings);
        if ($exceptId !== null && $booking['id'] === $exceptId) continue;
        if ($start < room_occupied_until($booking)) return true;
    }
    return false;
}

function room_quote_hold_conflict(PDO $pdo, string $roomId, string $start, string $end, ?int $now = null): bool
{
    $query = $pdo->prepare(
        'SELECT id FROM room_quote_holds WHERE room_id=? AND starts_at<? AND ends_at>? '
        . 'AND released_at IS NULL AND expires_at>? LIMIT 1'
    );
    $query->execute([$roomId, $end, $start, gmdate('Y-m-d H:i:s', $now ?? time())]);
    return (bool)$query->fetchColumn();
}

function assert_room_inventory(PDO $pdo, string $roomId, string $start, string $end, ?string $exceptId = null): void
{
    assert_no_emergency_lock($pdo, $roomId, $start, $end);
    if (room_quote_hold_conflict($pdo, $roomId, $start, $end)) {
        throw new BookingException('Khung giờ này đang được nhân viên giữ chỗ. Vui lòng chọn giờ hoặc phòng khác.', 409);
    }
    if (room_booking_conflict($pdo, $roomId, $start, $end, $exceptId)) {
        throw new BookingException('Khung giờ trùng lịch đặt hoặc 1 giờ dọn phòng. Vui lòng chọn giờ khác.', 409);
    }
}

function create_room_quote_hold(PDO $pdo, array $input, string $actor): array
{
    $pdo->beginTransaction();
    try {
        $roomId = (string)($input['roomId'] ?? '');
        $query = $pdo->prepare('SELECT id,is_active,status FROM rooms WHERE id=?' . room_operation_lock_suffix($pdo));
        $query->execute([$roomId]);
        $room = $query->fetch();
        if (!$room) {
            throw new BookingException('Không tìm thấy phòng.', 404);
        }
        if (!$room['is_active'] || $room['status'] !== 'ACTIVE') {
            throw new BookingException('Phòng hiện không hoạt động.', 409);
        }
        $zone = new DateTimeZone('Asia/Ho_Chi_Minh');
        $value = (string)($input['date'] ?? '') . ' ' . (string)($input['time'] ?? '');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $zone);
        $now = new DateTimeImmutable('now', $zone);
        if (!$start || $start->format('Y-m-d H:i') !== $value) {
            throw new BookingException('Chọn ngày và giờ bắt đầu hợp lệ.', 422);
        }
        if ($start < $now->setTime((int)$now->format('H'), (int)$now->format('i'))) {
            throw new BookingException('Giờ bắt đầu khóa đã qua. Vui lòng chọn lại.', 422);
        }
        if ($start > $now->modify('+366 days')) {
            throw new BookingException('Chỉ khóa phòng trong vòng 12 tháng.', 422);
        }
        $end = $start->modify('+1 hour');
        $a = mysql_datetime($start);
        $b = mysql_datetime($end);
        assert_room_inventory($pdo, $roomId, $a, $b);
        $query = $pdo->prepare('SELECT id FROM room_availability WHERE room_id=? AND available=0 AND date BETWEEN ? AND ? LIMIT 1');
        $query->execute([$roomId, $start->format('Y-m-d'), $end->modify('-1 second')->format('Y-m-d')]);
        if ($query->fetchColumn()) throw new BookingException('Phòng đang tạm khóa trong khoảng thời gian này.', 409);
        $id = uuid();
        $expires = $b;
        $query = $pdo->prepare('INSERT INTO room_quote_holds (id,room_id,package_id,starts_at,ends_at,expires_at,created_by) VALUES (?,?,?,?,?,?,?)');
        $query->execute([$id, $roomId, '', $a, $b, $expires, $actor]);
        $pdo->commit();
        return ['id' => $id, 'starts_at' => $a, 'ends_at' => $b, 'expires_at' => $expires];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function active_room_quote_holds(PDO $pdo, string $roomId = ''): array
{
    $sql = 'SELECT id,room_id,starts_at,ends_at,expires_at FROM room_quote_holds '
        . 'WHERE released_at IS NULL AND expires_at>?';
    $params = [gmdate('Y-m-d H:i:s')];
    if ($roomId !== '') {
        $sql .= ' AND room_id=?';
        $params[] = $roomId;
    }
    $query = $pdo->prepare($sql . ' ORDER BY starts_at');
    $query->execute($params);
    return $query->fetchAll();
}

function release_room_quote_hold(PDO $pdo, string $id, string $actor): void
{
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT room_id FROM room_quote_holds WHERE id=?');
        $find->execute([$id]);
        $roomId = $find->fetchColumn();
        if (!$roomId) throw new BookingException('Không tìm thấy lượt giữ phòng.', 404);
        $query = $pdo->prepare('SELECT id FROM rooms WHERE id=?' . room_operation_lock_suffix($pdo));
        $query->execute([$roomId]);
        $pdo->prepare('UPDATE room_quote_holds SET released_at=?,released_by=? WHERE id=? AND released_at IS NULL')
            ->execute([gmdate('Y-m-d H:i:s'), $actor, $id]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
