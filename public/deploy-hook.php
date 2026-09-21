<?php

declare(strict_types=1);

// Standalone bootstrap endpoint: this file can be uploaded before the rest of the app.
$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (is_file($envFile)) {
    $values = parse_ini_file($envFile, false, INI_SCANNER_RAW);
    if (is_array($values)) {
        foreach ($values as $name => $value) {
            if (getenv((string)$name) === false) putenv((string)$name . '=' . (string)$value);
        }
    }
}

$deployKey = (string)(getenv('DEPLOY_HOOK_KEY') ?: '');
if (strlen($deployKey) < 32) not_found();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    receive_file($root, $deployKey);
}

$provided = (string)($_SERVER['HTTP_X_DEPLOY_KEY'] ?? '');
if (!hash_equals($deployKey, $provided)) not_found();

require $root . '/src/bootstrap.php';
require APP_ROOT . '/src/migrate.php';
try {
    $ran = run_migrations();
    respond(['ok'=>true, 'migrations'=>$ran]);
} catch (Throwable $e) {
    error_log($e->__toString());
    respond(['error'=>'Migration failed'], 500);
}

function receive_file(string $root, string $deployKey): never
{
    $path = str_replace('\\', '/', (string)($_POST['path'] ?? ''));
    $allowed = '#^(?:\.env\.example|src/[A-Za-z0-9_.-]+\.php|database/migrations/[A-Za-z0-9_.-]+\.sql|public_html/(?:index\.php|\.htaccess|deploy-hook\.php|assets/[A-Za-z0-9_./-]+\.(?:css|js)|images/[A-Za-z0-9_./-]+\.(?:jpg|jpeg|png|webp|svg)))$#i';
    if (!preg_match($allowed, $path) || str_contains($path, '..')) respond(['error'=>'Unsupported deploy path'], 422);

    $upload = $_FILES['file'];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
        respond(['error'=>'Invalid upload'], 400);
    }
    if ((int)$upload['size'] <= 0 || (int)$upload['size'] > 10 * 1024 * 1024) respond(['error'=>'Invalid file size'], 413);

    $digest = hash_file('sha256', (string)$upload['tmp_name']);
    $expected = hash_hmac('sha256', $path . "\n" . $digest, $deployKey);
    $provided = strtolower((string)($_SERVER['HTTP_X_DEPLOY_SIGNATURE'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $provided) || !hash_equals($expected, $provided)) respond(['error'=>'Invalid deploy signature'], 401);

    $target = $root . '/' . $path;
    $directory = dirname($target);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) respond(['error'=>'Cannot create directory'], 500);
    $temporary = $target . '.uploading';
    if (!move_uploaded_file((string)$upload['tmp_name'], $temporary)) respond(['error'=>'Cannot store file'], 500);
    chmod($temporary, 0644);
    if (!rename($temporary, $target)) { @unlink($temporary); respond(['error'=>'Cannot publish file'], 500); }
    respond(['ok'=>true, 'path'=>$path]);
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function not_found(): never
{
    respond(['error'=>'Not found'], 404);
}
