<?php
require dirname(__DIR__) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$provided = $_SERVER['HTTP_X_DEPLOY_KEY'] ?? '';
if (!is_string(config('setup_key')) || strlen((string)config('setup_key')) < 24 || !hash_equals((string)config('setup_key'), $provided)) {
    http_response_code(404); echo '{"error":"Not found"}'; exit;
}
require APP_ROOT . '/src/migrate.php';
try {
    $ran = run_migrations();
    echo json_encode(['ok'=>true,'migrations'=>$ran], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['error'=>'Migration failed']);
}
