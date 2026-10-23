<?php

declare(strict_types=1);

const ROOM_CLEANING_SECONDS = 3600;

function room_occupied_until(array $booking, ?string $actualCheckout = null): string
{
    $scheduled = strtotime($booking['check_out'] . ' UTC');
    if ($booking['status'] === 'CHECKED_OUT') {
        $actual = $actualCheckout ?? ($booking['actual_checkout_at'] ?? null);
        $scheduled = $actual ? strtotime($actual . ' UTC') : $scheduled;
    }
    return gmdate('Y-m-d H:i:s', $scheduled + ROOM_CLEANING_SECONDS);
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
    foreach ($query->fetchAll() as $booking) {
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
        $query = $pdo->prepare('SELECT id FROM rooms WHERE id=?' . room_operation_lock_suffix($pdo));
        $query->execute([$roomId]);
        if (!$query->fetchColumn()) throw new BookingException('Không tìm thấy phòng.', 404);
        $room = (new RoomRepository($pdo))->findById($roomId);
        $package = resolve_bookable_slot($room, (string)($input['packageId'] ?? ''));
        [$start, $end] = bookable_package_window($package, (string)($input['date'] ?? ''), (string)($input['time'] ?? ''));
        if ($start > new DateTimeImmutable('+366 days')) throw new BookingException('Chỉ giữ phòng trong vòng 12 tháng.', 422);
        $a = mysql_datetime($start);
        $b = mysql_datetime($end);
        assert_room_inventory($pdo, $roomId, $a, $b);
        $query = $pdo->prepare('SELECT id FROM room_availability WHERE room_id=? AND available=0 AND date BETWEEN ? AND ? LIMIT 1');
        $query->execute([$roomId, $start->format('Y-m-d'), $end->modify('-1 second')->format('Y-m-d')]);
        if ($query->fetchColumn()) throw new BookingException('Phòng đang tạm khóa vào ngày đã chọn.', 409);
        $id = uuid();
        $expires = gmdate('Y-m-d H:i:s', time() + 3600);
        $query = $pdo->prepare('INSERT INTO room_quote_holds (id,room_id,package_id,starts_at,ends_at,expires_at,created_by) VALUES (?,?,?,?,?,?,?)');
        $query->execute([$id, $roomId, $package['id'], $a, $b, $expires, $actor]);
        $pdo->commit();
        return ['id' => $id, 'starts_at' => $a, 'ends_at' => $b, 'expires_at' => $expires];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
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
