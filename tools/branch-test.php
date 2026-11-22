<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/branches.php';

function branch_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cases = [
    'SELECT * FROM bookings WHERE id=?' => 'SELECT * FROM b2_bookings WHERE id=?',
    'SELECT * FROM `rooms` r JOIN bookings b ON b.room_id=r.id' => 'SELECT * FROM `b2_rooms` r JOIN b2_bookings b ON b.room_id=r.id',
    "UPDATE bookings SET guest_note='FROM rooms; UPDATE bookings' WHERE id=?" => "UPDATE b2_bookings SET guest_note='FROM rooms; UPDATE bookings' WHERE id=?",
    'INSERT INTO retail_sales(id) VALUES(?)' => 'INSERT INTO b2_retail_sales(id) VALUES(?)',
    'DELETE FROM door_codes WHERE id=?' => 'DELETE FROM b2_door_codes WHERE id=?',
    'SELECT COUNT(*) bookings FROM bookings' => 'SELECT COUNT(*) bookings FROM b2_bookings',
    'SELECT * FROM accounts WHERE id=?' => 'SELECT * FROM accounts WHERE id=?',
    "SELECT 'bookings', 'rooms', 'payroll_entries' FROM bookings -- FROM rooms\n" => "SELECT 'bookings', 'rooms', 'payroll_entries' FROM b2_bookings -- FROM rooms\n",
    'SELECT * FROM (SELECT id FROM bookings) b LEFT JOIN payments p ON p.booking_id=b.id' => 'SELECT * FROM (SELECT id FROM b2_bookings) b LEFT JOIN b2_payments p ON p.booking_id=b.id',
];
foreach ($cases as $input => $expected) {
    branch_expect(branch_sql($input) === $expected, 'SQL routing failed: ' . $input);
}
branch_expect(branch_path('/chi-nhanh/2/admin') === '/admin', 'Branch route');
branch_expect(branch_url('/admin', 2) === '/chi-nhanh/2/admin', 'Branch URL');
branch_expect(branch_url('/chi-nhanh/2/admin', 2) === '/chi-nhanh/2/admin', 'No duplicate prefix');
branch_expect(branch_url('/assets/app.css', 2) === '/assets/app.css', 'Static assets');
branch_expect(branch_url('//example.com/', 2) === '//example.com/', 'External URL');
branch_expect(branch_url('/admin', 1) === '/admin', 'Legacy URL');
branch_expect(str_contains(branch_sql("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_NAME='rooms'", true), "TABLE_NAME='b2_rooms'"), 'Migration metadata');
branch_expect(str_contains(branch_sql("SET @sql = 'ALTER TABLE bookings ADD CONSTRAINT fk_bookings_package FOREIGN KEY (package_id) REFERENCES room_packages(id)'", true), 'b2_fk_bookings_package'), 'Dynamic DDL constraints');
branch_expect(branch_sql("INSERT INTO accounts(permissions) VALUES('[\"rooms.view\"]')", true) === "INSERT INTO accounts(permissions) VALUES('[\"rooms.view\"]')", 'Migration data must not be rewritten');
echo "Branch SQL and routing tests: OK\n";

if (function_exists('proc_open')) {
    $fixture = tempnam(sys_get_temp_dir(), 'mo-branch-worker-');
    try {
        file_put_contents($fixture, '<?php require ' . var_export(dirname(__DIR__) . '/src/branch-workers.php', true)
            . '; dispatch_branch_workers(__FILE__); echo "worker-" . getenv("MO_BRANCH") . PHP_EOL;');
        $environment = getenv();
        unset($environment['MO_BRANCH']);
        $process = proc_open([PHP_BINARY, $fixture], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment);
        branch_expect(is_resource($process), 'Worker fixture did not start');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        branch_expect(proc_close($process) === 0, 'Worker fixture failed: ' . $errors);
        branch_expect(str_contains($output, 'worker-1') && str_contains($output, 'worker-2'), 'Cron must dispatch both branches');
        echo "Branch cron process isolation: OK\n";
    } finally {
        unlink($fixture);
    }
}

if (!in_array('--mysql', $argv, true)) {
    exit;
}

// Explicitly opt in to a disposable local schema, never the configured app DB.
$port = (int)(getenv('BRANCH_TEST_PORT') ?: '3317');
$schema = 'mo_branch_test_' . getmypid();
foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => (string)$port, 'DB_DATABASE' => $schema,
    'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'TTLOCK_ENABLED' => 'false', 'BOOKINGS_ENABLED' => 'false'] as $key => $value) {
    putenv($key . '=' . $value);
}
$server = new PDO('mysql:host=127.0.0.1;port=' . $port, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    require dirname(__DIR__) . '/src/bootstrap.php';
    require APP_ROOT . '/src/migrate.php';
    require APP_ROOT . '/src/revenue-integration.php';
    require APP_ROOT . '/src/expenses.php';
    $one = db(1);
    run_migrations_for($one);
    // Exercise an upgrade from the pre-assignment schema with a real legacy
    // staff row. Only this disposable test database is modified.
    $one->exec('ALTER TABLE accounts DROP COLUMN branch_id');
    $one->exec("DELETE FROM schema_migrations WHERE version='031_account_branches'");
    $one->exec("INSERT INTO accounts(id,name,email,password_hash,permissions) VALUES(REPEAT('e',32),'Legacy Staff','legacy@example.test','fixture-hash','[]')");
    run_migrations_for($one);
    branch_expect((int)$one->query('SELECT branch_id FROM accounts')->fetchColumn() === 1, 'Legacy staff must migrate to branch 1');
    $id = str_repeat('a', 32);
    $insertBooking = "INSERT INTO bookings(id,booking_code,room_id,check_in,check_out,stay_package,access_token,total_price,number_of_guests,guest_name,guest_phone,status,payment_status)
        VALUES(?,? ,?,'2026-11-21 03:00:00','2026-11-21 06:00:00','3h',?, ?,2,?,'0900000000','CHECKED_OUT','PAID')";
    $one->prepare($insertBooking)->execute([$id, 'MO-AAAAAAAAAA', 'pink', str_repeat('a', 64), 200000, 'Chi nhánh 1']);
    $one->prepare("INSERT INTO payments(id,booking_id,amount,payment_method,status,paid_at) VALUES(?,?,?,'manual_cash','PAID','2026-11-21 03:00:00')")
        ->execute([$id, $id, 200000]);
    $one->exec("INSERT INTO door_codes(id,code,period,starts_at,ends_at) VALUES(REPEAT('a',32),'123456','DAY','2026-11-21','2026-11-22')");
    $one->exec("INSERT INTO retail_products(id,name,price) VALUES(REPEAT('a',32),'Sản phẩm CN1',20000)");
    create_expense($one, ['category' => 'Chi CN1', 'amount' => 50000, 'spentAt' => '2026-11-21T10:00', 'includeCashflow' => true, 'requestId' => $id], $id);
    $before = [];
    foreach (branch_tables() as $table => $target) {
        if ($table !== 'schema_migrations') {
            $before[$table] = (int)$one->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }
    }
    run_migrations();
    $two = db(2);
    foreach ($before as $table => $count) {
        branch_expect((int)$one->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === $count, 'Legacy data changed: ' . $table);
        branch_expect((int)$two->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0, 'Branch 2 inherited data: ' . $table);
    }
    branch_expect(run_migrations() === [], 'Migrations must be idempotent');
    $one->exec('UPDATE accounts SET branch_id=2');
    $one->exec("DELETE FROM schema_migrations WHERE version='031_account_branches'");
    $two->exec("DELETE FROM schema_migrations WHERE version='031_account_branches'");
    run_migrations();
    branch_expect((int)$two->query('SELECT branch_id FROM accounts')->fetchColumn() === 2, 'Repeated migration must preserve staff assignment and shared accounts');
    $two->exec("INSERT INTO rooms(id,name,slug,price) VALUES('only-two','Phòng CN2','only-two',300000)");
    $two->prepare($insertBooking)->execute([$id, 'MO-AAAAAAAAAA', 'only-two', str_repeat('a', 64), 300000, 'Chi nhánh 2']);
    $two->prepare("INSERT INTO payments(id,booking_id,amount,payment_method,status,paid_at) VALUES(?,?,?,'manual_transfer','PAID','2026-11-21 03:00:00')")
        ->execute([$id, $id, 300000]);
    create_expense($two, ['category' => 'Chi CN2', 'amount' => 20000, 'spentAt' => '2026-11-21T10:00', 'includeCashflow' => true, 'requestId' => $id], $id);
    $report1 = mo_revenue_report($one, '2026-11-21', '2026-11-21');
    $report2 = mo_revenue_report($two, '2026-11-21', '2026-11-21');
    branch_expect((int)$report1['totals']['room'] === 200000, 'Branch 1 revenue');
    branch_expect((int)$report2['totals']['room'] === 300000, 'Branch 2 revenue');
    branch_expect((int)$report1['totals']['expense'] === 50000, 'Branch 1 expenses');
    branch_expect((int)$report2['totals']['expense'] === 20000, 'Branch 2 expenses');
    $two->prepare('UPDATE bookings SET guest_name=? WHERE id=?')->execute(['Đã sửa CN2', $id]);
    branch_expect($one->query('SELECT guest_name FROM bookings')->fetchColumn() === 'Chi nhánh 1', 'Cross-branch update');
    try {
        $two->prepare($insertBooking)->execute([str_repeat('b', 32), 'MO-BBBBBBBBBB', 'pink', str_repeat('b', 64), 100, 'Invalid']);
        throw new RuntimeException('Branch 2 accepted branch 1 room foreign key');
    } catch (PDOException $error) {
        branch_expect(($error->errorInfo[1] ?? 0) === 1452, 'Wrong FK failure');
    }
    $two->execMigration("SET @branch_fk_exists=(SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND CONSTRAINT_NAME='fk_bookings_package')");
    branch_expect((int)$two->query('SELECT @branch_fk_exists')->fetchColumn() === 1, 'Branch 2 FK metadata');
    $two->exec('DELETE FROM bookings');
    branch_expect((int)$two->query('SELECT COUNT(*) FROM payments')->fetchColumn() === 0, 'Branch 2 cascading FK');
    branch_expect((int)$one->query('SELECT COUNT(*) FROM payments')->fetchColumn() === 1, 'Cross-branch delete');
    branch_expect(!isset(branch_tables()['accounts']), 'Accounts should be shared');
    $two->execMigration("SET @branch_schema_sql='ALTER TABLE rooms ADD COLUMN IF NOT EXISTS branch_future_test INT DEFAULT 0'; PREPARE branch_schema_stmt FROM @branch_schema_sql; EXECUTE branch_schema_stmt; DEALLOCATE PREPARE branch_schema_stmt;");
    branch_expect($one->query("SHOW COLUMNS FROM rooms LIKE 'branch_future_test'")->fetchColumn() === false, 'Future DDL touched branch 1');
    branch_expect($two->query("SHOW COLUMNS FROM rooms LIKE 'branch_future_test'")->fetchColumn() === 'branch_future_test', 'Future DDL did not update branch 2');
    $_SESSION = [];
    putenv('MO_BRANCH=1');
    remember_payment_return(['orderCode' => '1234567890123', 'bookingCode' => 'MO-AAAAAAAAAA', 'accessToken' => str_repeat('a', 64)]);
    putenv('MO_BRANCH=2');
    branch_expect(db() === $two && fallback_rooms() === [], 'Active branch must not fall back to branch 1');
    branch_expect(payment_return_credentials(['orderCode' => '1234567890123'])['token'] === '', 'Branch 2 inherited payment-return token');
    remember_payment_return(['orderCode' => '1234567890123', 'bookingCode' => 'MO-BBBBBBBBBB', 'accessToken' => str_repeat('b', 64)]);
    branch_expect(payment_return_credentials(['orderCode' => '1234567890123'])['token'] === str_repeat('b', 64), 'Branch 2 return token');
    $_ENV['TTLOCK_ENABLED'] = 'true';
    $_ENV['PAYOS_CLIENT_ID'] = 'branch1-test-key';
    $_ENV['BRANCH2_TTLOCK_ENABLED'] = 'false';
    $_ENV['BRANCH2_PAYOS_CLIENT_ID'] = '';
    $_ENV['BRANCH2_PAYOS_CHECKSUM_KEY'] = '';
    branch_expect(config('ttlock.enabled') === false && config('payos.client_id') === '', 'Branch 2 inherited integration credentials');
    branch_expect(!payos_verify([], hash_hmac('sha256', payos_normalize([]), '')), 'Unconfigured branch accepted a webhook signed with an empty key');
    putenv('MO_BRANCH=1');
    branch_expect(payment_return_credentials(['orderCode' => '1234567890123'])['token'] === str_repeat('a', 64), 'Branch 1 token was overwritten');
    echo "Branch MySQL migration, legacy preservation, empty initialization, foreign keys, revenue and write isolation: OK\n";
} finally {
    // The schema name is generated above and is never read from app config.
    $server->exec('DROP DATABASE `' . $schema . '`');
}
