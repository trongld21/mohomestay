<?php

declare(strict_types=1);

function home_available_room_ids(PDO $pdo, ?int $now = null): array
{
    require_once __DIR__ . '/availability.php';
    $now ??= time();
    $localDate = (new DateTimeImmutable('@' . $now))
        ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d');
    $rows = $pdo->query('SELECT id,status,is_active FROM rooms')->fetchAll();
    $snapshot = availability_snapshot($pdo, $rows, $localDate, 1, $now);
    $result = [];
    foreach ($snapshot['rooms'] as $room) {
        if (!$room['active']) continue;
        $busy = false;
        foreach ($room['intervals'] as $interval) {
            if (strtotime($interval['start']) <= $now && strtotime($interval['end']) > $now) {
                $busy = true;
                break;
            }
        }
        if (!$busy) $result[] = (string)$room['id'];
    }
    return $result;
}
