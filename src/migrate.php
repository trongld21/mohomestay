<?php

function run_migrations(): array
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $ran = [];
    foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $file) {
        $version = basename($file, '.sql');
        if (in_array($version, $applied, true)) continue;
        try {
            // MySQL commits DDL implicitly; every migration must therefore be idempotent.
            $pdo->exec((string)file_get_contents($file));
            $stmt = $pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)');
            $stmt->execute([$version]);
            $ran[] = $version;
        } catch (Throwable $e) {
            throw $e;
        }
    }
    return $ran;
}
