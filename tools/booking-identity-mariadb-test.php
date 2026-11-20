<?php

declare(strict_types=1);

$dsn = (string)getenv('IDENTITY_TEST_DSN');
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=mo_identity_test;charset=utf8mb4$/D', $dsn)) {
    throw new RuntimeException('Use an isolated loopback mo_identity_test database.');
}
putenv('APP_KEY=' . str_repeat('a', 32));
putenv('BOOKING_IDENTITY_APP_KEY=');
putenv('BOOKING_IDENTITY_LEGACY_APP_KEY=');
require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = new PDO($dsn, (string)getenv('IDENTITY_TEST_USER'), (string)getenv('IDENTITY_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('CREATE TABLE booking_identity_images (
    booking_id CHAR(32), side VARCHAR(5), mime_type VARCHAR(32), encrypted_image MEDIUMBLOB,
    PRIMARY KEY(booking_id, side)
) ENGINE=InnoDB');
try {
    // Force the initial BLOB write to store different bytes so the text retry is exercised.
    $pdo->exec('CREATE TRIGGER corrupt_initial_identity BEFORE INSERT ON booking_identity_images
        FOR EACH ROW SET NEW.encrypted_image=0xDEADBEEF');
    $booking = str_repeat('b', 32);
    $image = ['mime' => 'image/jpeg', 'bytes' => random_bytes(3 * 1024 * 1024)];
    $pdo->beginTransaction();
    save_booking_identity($pdo, $booking, ['front' => $image], false);
    $pdo->commit();

    $stored = $pdo->query('SELECT encrypted_image FROM booking_identity_images')->fetchColumn();
    if (decrypt_booking_identity($stored, $booking . ':front') !== $image['bytes']) {
        throw new RuntimeException('Text write fallback did not preserve the image.');
    }
    if (decrypt_stored_booking_identity($pdo, $booking, 'front', 'incorrect read') !== $image['bytes']) {
        throw new RuntimeException('Text read fallback did not recover the image.');
    }
    $pdo->exec('UPDATE booking_identity_images SET encrypted_image=0xDEADBEEF');
    replace_unreadable_booking_identity($pdo, $booking, 'front', $image);
    $stored = $pdo->query('SELECT encrypted_image FROM booking_identity_images')->fetchColumn();
    if (decrypt_booking_identity($stored, $booking . ':front') !== $image['bytes']) {
        throw new RuntimeException('Image replacement failed.');
    }
    echo "MariaDB CCCD storage and binary fallbacks: OK\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('DROP TABLE booking_identity_images');
}
