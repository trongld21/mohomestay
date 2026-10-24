<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function session_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$_ENV['ADMIN_EMAIL'] = 'session@example.test';
$_ENV['ADMIN_PASSWORD_HASH'] = password_hash('session-test-password', PASSWORD_DEFAULT);
start_app_session();
$lifetime = 30 * 24 * 3600;
$params = session_get_cookie_params();
session_check($params['lifetime'] === $lifetime, 'Cookie must last 30 days');
session_check($params['httponly'] && $params['samesite'] === 'Lax', 'Cookie protections must remain');
session_check((int)ini_get('session.gc_maxlifetime') === $lifetime, 'Stored sessions must last 30 days');
session_check(realpath(session_save_path()) === realpath(APP_ROOT . '/storage/sessions'), 'Use private session storage');
session_check(!account_login('session@example.test', 'incorrect'), 'Wrong password must fail');
$before = time();
session_check(account_login('session@example.test', 'session-test-password'), 'Valid login must succeed');
session_check($_SESSION['admin_until'] >= $before + $lifetime, 'Login must last 30 days');
$sessionId = session_id();
session_write_close();
session_id($sessionId);
start_app_session();
session_check(current_account() !== null, 'Login must survive reopening');
$_SESSION['admin_until'] = time() - 1;
session_check(current_account() === null, 'Expired login must fail');
$_SESSION = [];
session_destroy();
echo "Session tests passed.\n";
