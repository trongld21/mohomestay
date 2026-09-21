<?php
require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/migrate.php';
try {
    $ran = run_migrations();
    echo $ran ? 'Da chay migration: ' . implode(', ', $ran) . PHP_EOL : "Database da cap nhat.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration loi: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
