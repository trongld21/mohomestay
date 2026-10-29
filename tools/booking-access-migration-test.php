<?php

declare(strict_types=1);

// Disposable loopback database only; never run against the application database.
$dsn = (string)getenv('ACCESS_TEST_DSN');
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=counter_migration_test(?:;charset=utf8mb4)?$/D', $dsn)) {
    throw new RuntimeException('Use an isolated loopback counter_migration_test database.');
}
$pdo = new PDO($dsn, (string)getenv('ACCESS_TEST_USER'), (string)getenv('ACCESS_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if ($pdo->query("SHOW TABLES LIKE 'booking_access_codes'")->fetchColumn() !== false) {
    throw new RuntimeException('Test database must not contain booking access records.');
}
$migrations = dirname(__DIR__) . '/database/migrations/';
try {
    $pdo->exec((string)file_get_contents($migrations . '005_booking_access_codes.sql'));
    $pdo->exec((string)file_get_contents($migrations . '006_generated_access_codes.sql'));
    $insert = $pdo->prepare("INSERT INTO booking_access_codes(booking_id,lock_id,code,starts_at,ends_at,remote_id,code_mode,sync_status) VALUES(?,7,?,'2099-01-01 14:00:00','2099-01-02 03:00:00',?,'GENERATED',?)");
    $insert->execute([str_repeat('a', 32), '0563456', 11, 'READY']);
    $insert->execute([str_repeat('b', 32), null, null, 'UNKNOWN']);
    $recover = $pdo->prepare("UPDATE booking_access_codes SET code='0563456',remote_id=12,sync_status='READY' WHERE booking_id=?");
    try {
        $recover->execute([str_repeat('b', 32)]);
        throw new RuntimeException('Old schema must reproduce duplicate PIN persistence failure.');
    } catch (PDOException $error) {
        if (($error->errorInfo[0] ?? '') !== '23000') throw $error;
    }
    $sql = (string)file_get_contents($migrations . '029_booking_access_reused_pins.sql');
    $pdo->exec($sql);
    $pdo->exec($sql);
    $recover->execute([str_repeat('b', 32)]);
    $before = $pdo->query('SELECT * FROM booking_access_codes ORDER BY booking_id')->fetchAll();
    $pdo->exec($sql);
    if ($before !== $pdo->query('SELECT * FROM booking_access_codes ORDER BY booking_id')->fetchAll()
        || count($before) !== 2 || array_column($before, 'sync_status') !== ['READY', 'READY']
        || array_column($before, 'code') !== ['0563456', '0563456']) {
        throw new RuntimeException('Repeated migration must preserve both recovered codes.');
    }
    $index = $pdo->query("SHOW INDEX FROM booking_access_codes WHERE Key_name='booking_access_pin_lookup'")->fetch();
    if (!$index || (int)$index['Non_unique'] !== 1) throw new RuntimeException('PIN lookup index must allow repeats.');
    try {
        $insert->execute([str_repeat('a', 32), '1234567', 13, 'READY']);
        throw new RuntimeException('A booking must still have at most one access record.');
    } catch (PDOException $error) {
        if (($error->errorInfo[0] ?? '') !== '23000') throw $error;
    }
    echo 'Booking access migration: reused PIN recovery, repeat DDL, data preservation and booking uniqueness OK (', $pdo->query('SELECT VERSION()')->fetchColumn(), ")\n";
} finally {
    $pdo->exec('DROP TABLE IF EXISTS booking_access_codes');
}
