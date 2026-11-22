<?php

function run_migrations_for(PDO $pdo): array
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $ran = [];
    foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $file) {
        $version = basename($file, '.sql');
        if (in_array($version, $applied, true)) continue;
        try {
            // MySQL commits DDL implicitly; every migration must therefore be idempotent.
            $sql = (string)file_get_contents($file);
            if ($pdo instanceof BranchPDO) {
                $pdo->execMigration($sql);
            } else {
                $pdo->exec($sql);
            }
            $stmt = $pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)');
            $stmt->execute([$version]);
            $ran[] = $version;
        } catch (Throwable $e) {
            throw $e;
        }
    }
    return $ran;
}

function initialize_second_branch(PDO $pdo): void
{
    $exists = $pdo->query("SHOW TABLES LIKE 'b2_schema_migrations'")->fetchColumn();
    if ($exists && (int)$pdo->query("SELECT COUNT(*) FROM b2_schema_migrations WHERE version='__branch_initialized'")->fetchColumn()) {
        return;
    }

    // Clone only the current schema. Do not replay historical INSERT/UPDATE
    // seeds: branch 2 must not inherit rooms, PINs, bills, stock or employees.
    $definitions = [];
    foreach (branch_tables() as $source => $target) {
        if ($source === 'schema_migrations') {
            continue;
        }
        $row = $pdo->query('SHOW CREATE TABLE `' . $source . '`')->fetch(PDO::FETCH_NUM);
        $definitions[$source] = (string)$row[1];
    }
    // SHOW CREATE includes foreign keys added by later ALTER migrations, so the
    // creation order must follow dependencies rather than file order.
    while ($definitions) {
        $progress = false;
        foreach ($definitions as $source => $definition) {
            preg_match_all('/REFERENCES\s+`([^`]+)`/', $definition, $references);
            if (array_intersect($references[1], array_keys($definitions))) {
                continue;
            }
            $sql = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $definition);
            $sql = preg_replace('/\bAUTO_INCREMENT=\d+\s*/', '', $sql);
            $pdo->exec(branch_sql($sql, true));
            unset($definitions[$source]);
            $progress = true;
        }
        if (!$progress) {
            throw new RuntimeException('Cannot initialize branch tables: cyclic foreign keys.');
        }
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS b2_schema_migrations LIKE schema_migrations');
    $pdo->beginTransaction();
    try {
        $pdo->exec('INSERT IGNORE INTO b2_schema_migrations(version) SELECT version FROM schema_migrations');
        $pdo->exec("INSERT IGNORE INTO b2_schema_migrations(version) VALUES('__branch_initialized')");
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function run_migrations(): array
{
    $pdo = db(1);
    $lock = $pdo->query("SELECT GET_LOCK(CONCAT(DATABASE(), ':mo-branch-migrations'), 30)")->fetchColumn();
    if ((int)$lock !== 1) {
        throw new RuntimeException('Another migration is running.');
    }
    try {
        $ran = run_migrations_for($pdo);
        initialize_second_branch($pdo);
        foreach (run_migrations_for(db(2)) as $version) {
            $ran[] = 'branch2:' . $version;
        }
        return $ran;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':mo-branch-migrations'))");
    }
}
