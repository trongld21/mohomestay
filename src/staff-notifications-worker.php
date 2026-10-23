<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
try {
    echo 'Sent staff notifications: ' . dispatch_staff_notifications(db()) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Cannot process staff notifications. Check database and mail configuration.\n");
    exit(1);
}
