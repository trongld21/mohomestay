<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/build-directadmin-package.php';

$root = dirname(__DIR__);
$output = $root . '/.deploy/counter-package-test-' . bin2hex(random_bytes(8));
$archive = null;
$marker = null;
try {
    [$archive, $marker] = deployment_build_package($root, $output, str_repeat('a', 40), 1, str_repeat('test', 16));
    $files = agent_read_archive($archive);
    $required = [
        'public_html/assets/people-counter.js',
        'public_html/assets/people-counter-offline.js',
        'public_html/assets/tailwind.css',
        'src/people-counter.php',
        'src/people-counter-preview.php',
        'src/people-counter-view.php',
        'src/people-counter-offline-view.php',
        'database/migrations/026_people_counter.sql',
        'database/migrations/027_people_counter_trials.sql',
    ];
    foreach ($required as $path) {
        if (!isset($files[$path]) || $files[$path] === '') {
            throw new RuntimeException('Counter runtime missing from deploy package: ' . $path);
        }
    }
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) throw new RuntimeException('Cannot inspect deployment ZIP.');
    try {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $path = substr((string)$zip->getNameIndex($index), strlen('release/'));
            if (str_contains($path, '..') || agent_target_path($path) === null
                || preg_match('#(?:^|/)(?:\.env|storage|uploads|services|node_modules|styles|tools)(?:/|$)#', $path)) {
                throw new RuntimeException('Private or unsupported path in deploy package: ' . $path);
            }
        }
    } finally {
        $zip->close();
    }
    echo "Counter deployment: runtime and both migrations packaged; secrets, SDK, recordings, uploads and Tailwind source excluded OK\n";
} finally {
    if ($archive !== null && is_file($archive)) unlink($archive);
    if ($marker !== null && is_file($marker)) unlink($marker);
    if (is_dir($output)) rmdir($output);
}
