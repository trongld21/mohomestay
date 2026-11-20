<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function check_saved_booking_identity(PDO $pdo, string $bookingCode): array
{
    $booking = $pdo->prepare('SELECT id FROM bookings WHERE booking_code=?');
    $booking->execute([$bookingCode]);
    $bookingId = $booking->fetchColumn();
    if ($bookingId === false) return ['booking' => 'not_found'];

    $results = ['front' => 'not_uploaded', 'back' => 'not_uploaded'];
    $images = $pdo->prepare('SELECT side, encrypted_image FROM booking_identity_images WHERE booking_id=?');
    $images->execute([$bookingId]);
    foreach ($images as $row) {
        $side = $row['side'];
        try {
            decrypt_booking_identity($row['encrypted_image'], $bookingId . ':' . $side);
            $results[$side] = 'readable';
        } catch (BookingIdentityReadException $error) {
            $results[$side] = $error->getMessage();
        }
    }

    return $results;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $bookingCode = strtoupper(trim($argv[1] ?? ''));
    if (!preg_match('/^(?:MO|LANG)-[A-F0-9]{10}$/D', $bookingCode)) {
        fwrite(STDERR, 'Cách dùng: php src/booking-identity-check.php MO-1234567890' . PHP_EOL);
        exit(2);
    }

    try {
        require_once __DIR__ . '/bootstrap.php';
        $result = check_saved_booking_identity(db(), $bookingCode);
        echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
        exit($result === ['front' => 'readable', 'back' => 'readable'] ? 0 : 1);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Không chạy được kiểm tra CCCD (' . get_class($error) . ').' . PHP_EOL);
        exit(1);
    }
}
