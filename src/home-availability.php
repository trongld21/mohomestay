<?php

declare(strict_types=1);

function home_available_room_ids(PDO $pdo, ?int $now = null): array
{
    $now ??= time();
    $utc = gmdate('Y-m-d H:i:s', $now);
    $localDate = (new DateTimeImmutable('@' . $now))
        ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d');
    $query = $pdo->prepare(
        "SELECT r.id FROM rooms r WHERE r.status='ACTIVE' AND r.is_active=1 "
        . 'AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.room_id=r.id '
        . 'AND b.check_in<=? AND b.check_out>? '
        . "AND (b.status IN ('CONFIRMED','CHECKED_IN') OR (b.status='PENDING' AND b.hold_expires_at>?))) "
        . 'AND NOT EXISTS (SELECT 1 FROM room_availability a WHERE a.room_id=r.id AND a.`date`=? AND a.available=0)'
    );
    $query->execute([$utc, $utc, $utc, $localDate]);
    return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
}
