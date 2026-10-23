<?php

declare(strict_types=1);

function availability_snapshot(PDO $pdo, array $rooms, string $date, int $days = 31, ?int $now = null): array
{
    $zone = new DateTimeZone('Asia/Ho_Chi_Minh');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
    if (!$start || $start->format('Y-m-d') !== $date || $days < 1 || $days > 62) {
        throw new BookingException('Ngày hoặc khoảng thời gian không hợp lệ.', 400);
    }
    $now ??= time();
    $end = $start->modify('+' . $days . ' days');
    $query = $pdo->prepare(
        'SELECT b.room_id,b.check_in,b.check_out,b.status,c.actual_checkout_at FROM bookings b '
        . 'LEFT JOIN booking_checkout_charges c ON c.booking_id=b.id '
        . "WHERE b.check_in<? AND (b.status IN ('CONFIRMED','CHECKED_IN','CHECKED_OUT') "
        . "OR (b.status='PENDING' AND b.hold_expires_at>?))"
    );
    $query->execute([mysql_datetime($end), gmdate('Y-m-d H:i:s', $now)]);
    $bookings = $query->fetchAll();
    $query = $pdo->prepare('SELECT room_id,`date` FROM room_availability WHERE available=0 AND `date`>=? AND `date`<?');
    $query->execute([$date, $end->format('Y-m-d')]);
    $blocked = $query->fetchAll();
    $query = $pdo->prepare(
        'SELECT room_id,starts_at,ended_at FROM room_emergency_locks WHERE starts_at<? '
        . 'AND (ended_at IS NULL OR ended_at>?)'
    );
    $query->execute([mysql_datetime($end), mysql_datetime($start)]);
    $emergencyLocks = $query->fetchAll();
    $query = $pdo->prepare(
        'SELECT room_id,starts_at,ends_at FROM room_quote_holds WHERE starts_at<? AND ends_at>? '
        . 'AND released_at IS NULL AND expires_at>?'
    );
    $query->execute([mysql_datetime($end), mysql_datetime($start), gmdate('Y-m-d H:i:s', $now)]);
    $quoteHolds = $query->fetchAll();
    $inventory = $pdo->query('SELECT id,is_active FROM rooms')->fetchAll(PDO::FETCH_KEY_PAIR);
    $result = [];
    foreach ($rooms as $room) {
        $intervals = [];
        foreach ($bookings as $booking) {
            if ($booking['room_id'] !== $room['id']) continue;
            $occupiedEnd = room_occupied_until($booking);
            if ($occupiedEnd <= mysql_datetime($start)) continue;
            $stayEnd = $booking['status'] === 'CHECKED_OUT'
                ? ($booking['actual_checkout_at'] ?: $booking['check_out']) : $booking['check_out'];
            if ($stayEnd > mysql_datetime($start)) {
                $intervals[] = [
                    'start' => gmdate('c', strtotime($booking['check_in'] . ' UTC')),
                    'end' => gmdate('c', strtotime($stayEnd . ' UTC')),
                    'status' => $booking['status'] === 'CHECKED_OUT' ? 'CONFIRMED' : $booking['status'],
                ];
            }
            $intervals[] = [
                'start' => gmdate('c', strtotime($stayEnd . ' UTC')),
                'end' => gmdate('c', strtotime($occupiedEnd . ' UTC')),
                'status' => 'CLEANING',
            ];
        }
        foreach ($blocked as $block) {
            if ($block['room_id'] !== $room['id']) continue;
            $day = new DateTimeImmutable($block['date'] . ' 00:00', $zone);
            $intervals[] = ['start' => $day->format(DATE_ATOM), 'end' => $day->modify('+1 day')->format(DATE_ATOM), 'status' => 'BLOCKED'];
        }
        foreach ($emergencyLocks as $hold) {
            if ($hold['room_id'] !== $room['id']) continue;
            $intervals[] = [
                'start' => gmdate('c', strtotime($hold['starts_at'] . ' UTC')),
                'end' => $hold['ended_at'] ? gmdate('c', strtotime($hold['ended_at'] . ' UTC')) : $end->format(DATE_ATOM),
                'status' => 'BLOCKED',
            ];
        }
        foreach ($quoteHolds as $hold) {
            if ($hold['room_id'] !== $room['id']) continue;
            $intervals[] = [
                'start' => gmdate('c', strtotime($hold['starts_at'] . ' UTC')),
                'end' => gmdate('c', strtotime($hold['ends_at'] . ' UTC')),
                'status' => 'HELD',
            ];
        }
        $result[] = ['id' => $room['id'], 'active' => $room['status'] === 'ACTIVE' && (bool)($inventory[$room['id']] ?? false), 'intervals' => $intervals];
    }
    return ['connected' => true, 'serverTime' => gmdate('c', $now), 'rangeStart' => $start->format(DATE_ATOM), 'rangeEnd' => $end->format(DATE_ATOM), 'rooms' => $result];
}
