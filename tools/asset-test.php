<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
foreach (['/assets/app.css', '/assets/tailwind.css', '/assets/contact.js', '/images/contact-chat.png'] as $path) {
    $expected = $path . '?v=' . substr(hash_file('sha256', APP_ROOT . '/public' . $path), 0, 16);
    if (asset_url($path) !== $expected) throw new RuntimeException('Incorrect asset version: ' . $path);
    if (asset_url($path) !== $expected) throw new RuntimeException('Asset URL is unstable');
}
if (asset_url('/images/missing-test.png') !== '/images/missing-test.png') throw new RuntimeException('Missing asset handling failed');
try {
    asset_url('/assets/../bootstrap.php');
    throw new RuntimeException('Traversal accepted');
} catch (InvalidArgumentException $expected) {}
echo "Asset version tests: OK\n";
