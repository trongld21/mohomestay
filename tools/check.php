<?php
$root = dirname(__DIR__); $errors = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR)) continue;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()), $output, $code);
    if ($code !== 0) $errors[] = $file->getPathname();
}
foreach (['pdo_mysql','curl','mbstring','openssl'] as $ext) if (!extension_loaded($ext)) $errors[] = 'Thieu extension '.$ext;
if ($errors) { fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL); exit(1); }
echo "PHP syntax va extension: OK\n";
