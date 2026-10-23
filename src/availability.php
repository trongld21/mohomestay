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
        'SELECT room_id,check_in,check_out,status FROM bookings WHERE check_in<? AND check_out>? '
        . "AND (status IN ('CONFIRMED','CHECKED_IN') OR (status='PENDING' AND hold_expires_at>?))"
    );
    $query->execute([mysql_datetime($end), mysql_datetime($start), gmdate('Y-m-d H:i:s', $now)]);
    $bookings = $query->fetchAll();
    $query = $pdo->prepare('SELECT room_id,`date` FROM room_availability WHERE available=0 AND `date`>=? AND `date`<?');
    $query->execute([$date, $end->format('Y-m-d')]);
    $blocked = $query->fetchAll();
    $inventory = $pdo->query('SELECT id,is_active FROM rooms')->fetchAll(PDO::FETCH_KEY_PAIR);
    $result = [];
    foreach ($rooms as $room) {
        $intervals = [];
        foreach ($bookings as $booking) {
            if ($booking['room_id'] !== $room['id']) continue;
            $intervals[] = [
                'start' => gmdate('c', strtotime($booking['check_in'] . ' UTC')),
                'end' => gmdate('c', strtotime($booking['check_out'] . ' UTC')),
                'status' => $booking['status'],
            ];
        }
        foreach ($blocked as $block) {
            if ($block['room_id'] !== $room['id']) continue;
            $day = new DateTimeImmutable($block['date'] . ' 00:00', $zone);
            $intervals[] = ['start' => $day->format(DATE_ATOM), 'end' => $day->modify('+1 day')->format(DATE_ATOM), 'status' => 'BLOCKED'];
        }
        $result[] = ['id' => $room['id'], 'active' => $room['status'] === 'ACTIVE' && (bool)($inventory[$room['id']] ?? false), 'intervals' => $intervals];
    }
    return ['connected' => true, 'serverTime' => gmdate('c', $now), 'rangeStart' => $start->format(DATE_ATOM), 'rangeEnd' => $end->format(DATE_ATOM), 'rooms' => $result];
}
