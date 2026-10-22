<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy-agent.php';

function deployment_marker(string $sha, int $run, string $digest, string $key): array
{
    return ['sha' => $sha, 'run_id' => $run, 'digest' => $digest,
        'signature' => hash_hmac('sha256', $sha . "\n" . $run . "\n" . $digest, $key)];
}

function deployment_marker_valid(array $marker, string $key): bool
{
    if (strlen($key) < 32 || !preg_match('/^[a-f0-9]{40}$/D', (string)($marker['sha'] ?? ''))
        || !is_int($marker['run_id'] ?? null) || $marker['run_id'] < 1
        || !preg_match('/^[a-f0-9]{64}$/D', (string)($marker['digest'] ?? ''))
        || !is_string($marker['signature'] ?? null)) return false;
    $expected = deployment_marker($marker['sha'], $marker['run_id'], $marker['digest'], $key);
    return hash_equals($expected['signature'], $marker['signature']);
}

function deployment_activate(string $root, string $key, callable $migrate): string
{
    if (is_link($root . '/public_html')) throw new RuntimeException('Public web root must not be a symlink.');
    $incoming = $root . '/.deploy-incoming';
    if (is_link($incoming) || !is_dir($incoming)) throw new RuntimeException('Private incoming directory is missing.');
    $lockPath = $incoming . '/activate.lock';
    if (is_link($lockPath)) throw new RuntimeException('Invalid activation lock.');
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) return 'busy';
    try {
        $statePath = $incoming . '/last-success.json';
        $previousState = is_file($statePath) ? (string)file_get_contents($statePath) : null;
        $state = $previousState !== null ? json_decode($previousState, true) : [];
        if (!is_array($state)) throw new RuntimeException('Invalid activation state.');
        $selected = null;
        foreach (glob($incoming . '/ready-*.json') ?: [] as $path) {
            if (is_link($path) || filesize($path) > 2048) continue;
            $marker = json_decode((string)file_get_contents($path), true);
            if (!is_array($marker) || !deployment_marker_valid($marker, $key)
                || basename($path) !== 'ready-' . $marker['sha'] . '-' . $marker['run_id'] . '.json') {
                rename($path, $path . '.invalid');
                continue;
            }
            if ($marker['run_id'] <= (int)($state['run_id'] ?? 0)) {
                rename($path, $path . '.stale');
                continue;
            }
            if ($selected === null || $marker['run_id'] > $selected['marker']['run_id']) $selected = ['marker' => $marker, 'path' => $path];
        }
        if ($selected === null) return 'unchanged';
        $marker = $selected['marker'];
        $path = $selected['path'];
        $archive = $incoming . '/release-' . $marker['sha'] . '-' . $marker['run_id'] . '.zip';
        $status = ['release' => $marker['sha'], 'run_id' => $marker['run_id']];
        $phase = 'verify-archive';
        try {
            if (is_link($archive) || !is_file($archive) || filesize($archive) > 50 * 1024 * 1024
                || !hash_equals($marker['digest'], (string)hash_file('sha256', $archive))) {
                throw new RuntimeException('Archive missing or checksum mismatch.');
            }
            $phase = 'validate-runtime';
            $files = agent_read_archive($archive);
            $backup = $incoming . '/backups/' . $marker['run_id'] . '-' . bin2hex(random_bytes(4));
            $phase = 'publish-and-migrate';
            $count = agent_publish($root, $files, $backup, $migrate);
            $phase = 'record-result';
            $status += ['status' => 'success', 'finishedAt' => gmdate('c'), 'changedFiles' => $count];
            agent_atomic_write($statePath, json_encode($status, JSON_THROW_ON_ERROR), 0600);
            agent_atomic_write($root . '/public_html/deploy-status.json', json_encode($status, JSON_THROW_ON_ERROR));
            rename($path, $path . '.done');
            unlink($archive);
            return 'success';
        } catch (Throwable $e) {
            agent_atomic_write($incoming . '/last-failure.json', json_encode(['phase' => $phase, 'type' => get_class($e), 'at' => gmdate('c')], JSON_THROW_ON_ERROR), 0600);
            // Detailed exception data could contain production SQL or configuration.
            if ($phase === 'record-result') {
                // A failed status publication must not permanently suppress a safe rerun.
                if ($previousState !== null) {
                    agent_atomic_write($statePath, $previousState, 0600);
                } elseif (is_file($statePath) && !is_link($statePath)) {
                    unlink($statePath);
                }
            }
            $status = array_replace($status, ['status' => 'failed', 'finishedAt' => gmdate('c')]);
            agent_atomic_write($root . '/public_html/deploy-status.json', json_encode($status, JSON_THROW_ON_ERROR));
            rename($path, $path . '.failed');
            return 'failed';
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    umask(0077);
    try {
        foreach (['zip', 'pdo_mysql', 'mbstring'] as $extension) {
            if (!extension_loaded($extension)) throw new RuntimeException('Missing PHP extension: ' . $extension);
        }
        $root = __DIR__;
        $mode = $argv[1] ?? '--check';
        if (!in_array($mode, ['--check', '--run'], true)) throw new RuntimeException('Use --check or --run.');
        $env = is_file($root . '/.env') ? parse_ini_file($root . '/.env', false, INI_SCANNER_RAW) : [];
        $key = (string)($env['DEPLOY_HOOK_KEY'] ?? '');
        if (strlen($key) < 32 || !is_dir($root . '/public_html')) throw new RuntimeException('Install beside production .env and public_html; DEPLOY_HOOK_KEY must be configured.');
        $incoming = $root . '/.deploy-incoming';
        if (is_link($incoming) || (!is_dir($incoming) && !mkdir($incoming, 0700))) throw new RuntimeException('Cannot create private incoming directory.');
        if ($mode === '--check') {
            echo 'Activation prerequisites: OK. PHP: ' . PHP_BINARY . '. No website or database changes.' . PHP_EOL;
            exit;
        }
        $settings = is_file($root . '/deploy-activate.ini') ? parse_ini_file($root . '/deploy-activate.ini', false, INI_SCANNER_RAW) : [];
        if (!filter_var($settings['ENABLED'] ?? 'false', FILTER_VALIDATE_BOOL)) throw new RuntimeException('Activation is disabled. Configure deploy-activate.ini after checking prerequisites.');
        $result = deployment_activate($root, $key, static function () use ($root): void {
            require_once $root . '/src/bootstrap.php';
            require_once $root . '/src/migrate.php';
            run_migrations();
        });
        if (in_array($result, ['success', 'failed'], true)) echo gmdate('c') . ' Deployment: ' . $result . PHP_EOL;
        if ($result === 'failed') exit(1);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Activation stopped: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
