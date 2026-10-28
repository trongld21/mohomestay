<?php

declare(strict_types=1);

// This test is deliberately separate from SQLite domain tests: production uses
// MariaDB 10.3, whose INSERT/SELECT compatibility differs from newer MySQL.
$dsn = (string)getenv('COUNTER_TEST_DSN');
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=counter_migration_test(?:;charset=utf8mb4)?$/D', $dsn)) {
    throw new RuntimeException('Use an isolated loopback counter_migration_test database.');
}
$pdo = new PDO($dsn, (string)getenv('COUNTER_TEST_USER'), (string)getenv('COUNTER_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$tables = ['people_counter_trial_events', 'people_counter_events', 'people_counter_cameras'];
foreach ($tables as $table) {
    if ($pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn() !== false) {
        throw new RuntimeException('Test database must not contain counter tables.');
    }
}
$migration = dirname(__DIR__) . '/database/migrations/';
try {
    $sql = (string)file_get_contents($migration . '026_people_counter.sql');
    $pdo->exec($sql);
    if ((int)$pdo->query("SELECT COUNT(*) FROM people_counter_cameras WHERE id = 'entrance'")->fetchColumn() !== 1) {
        throw new RuntimeException('Initial camera was not seeded.');
    }
    $pdo->exec("UPDATE people_counter_cameras SET name='Existing camera', enabled=0 WHERE id='entrance'");
    $pdo->exec("INSERT INTO people_counter_events VALUES ('entrance', REPEAT('a',32), 'IN', '2026-10-28 00:00:00', '2026-10-28 00:00:00')");
    // Re-running after partially committed DDL must preserve configured cameras
    // and previously saved events instead of resetting either table.
    $pdo->exec($sql);
    $camera = $pdo->query("SELECT name,enabled FROM people_counter_cameras WHERE id='entrance'")->fetch();
    if ($camera['name'] !== 'Existing camera' || (int)$camera['enabled'] !== 0
        || (int)$pdo->query('SELECT COUNT(*) FROM people_counter_events')->fetchColumn() !== 1) {
        throw new RuntimeException('Repeated migration changed existing camera or events.');
    }
    $sql = (string)file_get_contents($migration . '027_people_counter_trials.sql');
    $pdo->exec($sql);
    $pdo->exec($sql);
    if ((int)$pdo->query('SELECT COUNT(*) FROM people_counter_trial_events')->fetchColumn() !== 0) {
        throw new RuntimeException('Trial migration must not copy operational events.');
    }
    echo 'Counter SQL migrations: seed, repeat, partial-DDL recovery and data preservation OK (', $pdo->query('SELECT VERSION()')->fetchColumn(), ")\n";
} finally {
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
}
