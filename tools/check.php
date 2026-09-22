<?php
$root = dirname(__DIR__); $errors = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR)) continue;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()), $output, $code);
    if ($code !== 0) $errors[] = $file->getPathname();
}
foreach (['pdo_mysql','curl','mbstring','openssl'] as $ext) if (!extension_loaded($ext)) $errors[] = 'Thieu extension '.$ext;
$required=['public/favicon.svg','public/apple-touch-icon.svg','public/assets/ui.js','public/assets/admin.js','public/assets/rooms.js','database/migrations/002_room_management.sql','public/uploads/.htaccess'];
foreach($required as $path)if(!is_file($root.'/'.$path))$errors[]='Thieu file '.$path;
$productionJs='';foreach(glob($root.'/public/assets/*.js')?:[] as $path)$productionJs.=(string)file_get_contents($path);
if(str_contains($productionJs,'alert(')||preg_match('/(?<!function )\bconfirm\(/',$productionJs))$errors[]='JavaScript production con native alert/confirm';
$workflow=(string)file_get_contents($root.'/.github/workflows/deploy-directadmin.yml');
if(!str_contains($workflow,"--exclude 'uploads/'")||!str_contains($workflow,'test ! -e .deploy/public_html/uploads'))$errors[]='Workflow phai bao toan uploads runtime';
$hook=(string)file_get_contents($root.'/public/deploy-hook.php');if(!str_contains($hook,'apple-touch-icon'))$errors[]='Deploy hook chua cho phep favicon moi';
if ($errors) { fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL); exit(1); }
echo "PHP syntax va extension: OK\n";
