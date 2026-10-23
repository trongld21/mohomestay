<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

try {
    $checkOnly = in_array('--check', $argv, true);
    $fromValid = (bool)filter_var(trim((string)config('mail.from', '')), FILTER_VALIDATE_EMAIL);
    $mailEnabled = function_exists('mail');
    echo 'MAIL_FROM configured: ' . ($fromValid ? 'YES' : 'NO') . PHP_EOL;
    echo 'PHP mail available: ' . ($mailEnabled ? 'YES' : 'NO') . PHP_EOL;
    $pdo = db();
    echo 'Database connection: OK' . PHP_EOL;
    $counts = $pdo->query('SELECT status,COUNT(*) AS total FROM staff_email_notifications GROUP BY status')->fetchAll();
    echo 'Notification queue: ' . json_encode($counts) . PHP_EOL;
    if ($checkOnly) {
        $recent = $pdo->query(
            'SELECT event_type,status,attempts,last_attempt_at,sent_at FROM staff_email_notifications ORDER BY created_at DESC,id DESC LIMIT 5'
        )->fetchAll();
        echo 'Recent notification states: ' . json_encode($recent) . PHP_EOL;
        echo 'Check only: no email was sent.' . PHP_EOL;
        exit;
    }
    if (!$fromValid || !$mailEnabled) {
        fwrite(STDERR, "Email blocked: configure MAIL_FROM and enable PHP mail on hosting.
");
        exit(1);
    }
    echo 'Sent staff notifications: ' . dispatch_staff_notifications($pdo) . PHP_EOL;
} catch (Throwable $error) {
    $dbError = $error instanceof PDOException ? (string)($error->errorInfo[1] ?? $error->getCode()) : '';
    fwrite(STDERR, 'Cannot process staff notifications. Error type: ' . get_class($error)
        . ($dbError !== '' ? '; database error: ' . $dbError : '') . ". Check migration 014 and hosting logs.
");
    exit(1);
}
