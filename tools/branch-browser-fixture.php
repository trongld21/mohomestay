<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Disposable loopback-only fixture. Never use credentials or a database name
// from .env; browser tests are allowed to mutate every business module here.
$root = dirname(__DIR__);
$port = (int)(getenv('BRANCH_TEST_PORT') ?: '3317');
$schema = 'mo_branch_browser_test_' . getmypid();
foreach ([
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string)$port, 'DB_DATABASE' => $schema,
    'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'APP_ENV' => 'development',
    'APP_URL' => 'http://127.0.0.1:8097', 'ADMIN_EMAIL' => 'branch-test@example.test',
    'ADMIN_PASSWORD_HASH' => password_hash('branch-test-only', PASSWORD_DEFAULT),
    'TTLOCK_ENABLED' => 'false', 'BOOKINGS_ENABLED' => 'false', 'MAIL_FROM' => '',
    'PAYOS_CLIENT_ID' => '', 'PAYOS_API_KEY' => '', 'PAYOS_CHECKSUM_KEY' => '',
    'BRANCH2_BOOKINGS_ENABLED' => 'false', 'BRANCH2_TTLOCK_ENABLED' => 'false',
    'BRANCH2_PAYOS_CLIENT_ID' => '', 'BRANCH2_PAYOS_API_KEY' => '', 'BRANCH2_PAYOS_CHECKSUM_KEY' => '',
] as $key => $value) {
    putenv($key . '=' . $value);
}
$server = new PDO('mysql:host=127.0.0.1;port=' . $port, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    require $root . '/src/bootstrap.php';
    require $root . '/src/migrate.php';
    run_migrations();
    db(1)->exec("INSERT INTO bookings(id,booking_code,room_id,check_in,check_out,stay_package,access_token,total_price,number_of_guests,guest_name,guest_phone,status,payment_status)
        VALUES(REPEAT('a',32),'MO-AAAAAAAAAA','pink','2026-11-21 03:00:00','2026-11-21 06:00:00','3h',REPEAT('a',64),200000,2,'Legacy Branch 1','0900000000','CHECKED_OUT','PAID')");
    db(1)->exec("INSERT INTO payments(id,booking_id,amount,payment_method,status,paid_at) VALUES(REPEAT('a',32),REPEAT('a',32),200000,'manual_cash','PAID','2026-11-21 03:00:00')");
    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:8097', '-t', $root . '/public', $root . '/public/index.php'], [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the local browser fixture.');
    }
    $status = proc_close($process);
} finally {
    $server->exec('DROP DATABASE `' . $schema . '`');
}
exit($status);
