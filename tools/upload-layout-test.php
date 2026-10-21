<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/uploads.php';

define('APP_ROOT', sys_get_temp_dir() . '/mo-upload-layout-' . bin2hex(random_bytes(8)));
mkdir(APP_ROOT);
$failures = [];
$path = '/uploads/rooms/pink/' . str_repeat('a', 32) . '.jpg';

try {
    foreach (['public', 'public_html'] as $directory) {
        mkdir(APP_ROOT . '/' . $directory);
        $expected = APP_ROOT . '/' . $directory . $path;
        $actual = str_replace('\\', '/', room_upload_disk_path($path));
        if ($actual !== str_replace('\\', '/', $expected)) {
            $failures[] = $directory . ': image path must point inside the web root';
        }
    }
} finally {
    rmdir(APP_ROOT . '/public_html');
    rmdir(APP_ROOT . '/public');
    rmdir(APP_ROOT);
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Upload layout tests: OK\n";
