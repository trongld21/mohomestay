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
        'SELECT b.*,c.actual_checkout_at FROM bookings b '
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
        if (($room['status'] ?? '') === 'HIDDEN') {
            continue;
        }
        $intervals = [];
        foreach ($bookings as $booking) {
            if ($booking['room_id'] !== $room['id']) continue;
            $booking['continuation_active'] = booking_has_live_continuation($booking, $bookings);
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
            if ($occupiedEnd > $stayEnd) {
                $intervals[] = [
                    'start' => gmdate('c', strtotime($stayEnd . ' UTC')),
                    'end' => gmdate('c', strtotime($occupiedEnd . ' UTC')),
                    'status' => 'CLEANING',
                ];
            }
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

/** Return only packages that can actually occupy the clicked hour and their full stay. */
function timeline_available_packages(PDO $pdo, array $room, string $date, string $time, ?DateTimeImmutable $now = null): array
{
    $zone = new DateTimeZone('Asia/Ho_Chi_Minh');
    $now ??= new DateTimeImmutable('now', $zone);
    $value = $date . ' ' . $time;
    $clicked = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $zone);
    if (!$clicked || $clicked->format('Y-m-d H:i') !== $value || substr($time, 3) !== '00') {
        throw new BookingException('Ô giờ không hợp lệ.', 422);
    }
    if ($clicked > $now->modify('+366 days')) {
        throw new BookingException('Chỉ nhận đặt phòng trong vòng 12 tháng.', 422);
    }
    $hourEnd = $clicked->modify('+1 hour');
    if ($hourEnd <= $now || ($room['status'] ?? '') !== 'ACTIVE') {
        return [];
    }
    $query = $pdo->prepare('SELECT is_active,status FROM rooms WHERE id=?');
    $query->execute([$room['id']]);
    $inventory = $query->fetch();
    if (!$inventory || !$inventory['is_active'] || $inventory['status'] !== 'ACTIVE') {
        return [];
    }
    $arrival = $clicked > $now ? $clicked : $now->setTimestamp((int)(floor($now->getTimestamp() / 60) * 60 + 60));
    $options = [];
    foreach ($room['packages'] ?? [] as $configured) {
        if (empty($configured['enabled'])) {
            continue;
        }
        try {
            $package = resolve_bookable_slot($room, (string)$configured['id']);
            [$start, $end] = bookable_package_window($package, $date, $arrival->format('H:i'), $now);
            if ($start < $clicked || $start >= $hourEnd) {
                continue;
            }
            assert_room_inventory($pdo, (string)$room['id'], mysql_datetime($start), mysql_datetime($end));
            $blocked = $pdo->prepare('SELECT id FROM room_availability WHERE room_id=? AND available=0 AND `date` BETWEEN ? AND ? LIMIT 1');
            $blocked->execute([$room['id'], $start->format('Y-m-d'), $end->modify('-1 second')->format('Y-m-d')]);
            if ($blocked->fetchColumn()) {
                continue;
            }
            $options[] = [
                'id' => $package['id'], 'name' => $package['name'], 'price' => (int)$package['price'],
                'start' => $start->format(DATE_ATOM), 'end' => $end->format(DATE_ATOM),
                'date' => $date, 'time' => $start->format('H:i'),
            ];
        } catch (BookingException $error) {
            // Invalid, expired, restricted and conflicting packages are not choices.
            continue;
        }
    }
    return $options;
}
