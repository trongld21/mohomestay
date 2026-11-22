<?php

declare(strict_types=1);

$dsn = (string)getenv('ACCESS_TEST_DSN');
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=counter_migration_test(?:;charset=utf8mb4)?$/D', $dsn)) {
    throw new RuntimeException('Use an isolated loopback counter_migration_test database.');
}
$pdo = new PDO($dsn, (string)getenv('ACCESS_TEST_USER'), (string)getenv('ACCESS_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');
$migrationDir = dirname(__DIR__) . '/database/migrations/';
$schema = (string)file_get_contents($migrationDir . '009_accounts.sql');
$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $schema));

$insert = $pdo->prepare('INSERT INTO accounts(id,name,email,password_hash,permissions) VALUES (?,?,?,?,?)');
$original = ['bookings', 'bookings.overview', 'bookings.create', 'bookings.update', 'bookings.payment', 'bookings.identity.download', 'availability', 'availability.view', 'availability.checkout', 'sales.view'];
$insert->execute(['target', 'Fixture', 'nhansu@mohomestay.vn', 'fixture', json_encode($original, JSON_THROW_ON_ERROR)]);
$insert->execute(['other', 'Fixture', 'other@example.test', 'fixture', json_encode($original, JSON_THROW_ON_ERROR)]);
$migration = (string)file_get_contents($migrationDir . '031_staff_booking_view.sql');
$expected = ['availability.view', 'sales.view', 'availability.lock', 'availability.hold', 'bookings.view', 'bookings.identity'];

for ($attempt = 0; $attempt < 2; $attempt++) {
    $pdo->exec($migration);
    $result = $pdo->query('SELECT id,permissions FROM accounts')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (json_decode($result['target'], true, 512, JSON_THROW_ON_ERROR) !== $expected
        || json_decode($result['other'], true, 512, JSON_THROW_ON_ERROR) !== $original) {
        throw new RuntimeException('The migration must restrict the target account and preserve the other account.');
    }
}

echo "Staff booking access migration and PDO collation compatibility: OK\n";
