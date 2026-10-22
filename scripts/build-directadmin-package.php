<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/deploy-activate.php';

function deployment_build_package(string $root, string $output, string $sha, int $run, string $key): array
{
    if (!preg_match('/^[a-f0-9]{40}$/D', $sha) || $run < 1 || strlen($key) < 32) throw new RuntimeException('Invalid release identity or signing key.');
    if (!is_dir($output) && !mkdir($output, 0700, true)) throw new RuntimeException('Cannot create package directory.');
    $archive = $output . '/release-' . $sha . '-' . $run . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create deployment ZIP.');
    foreach (['src', 'database/migrations', 'public'] as $folder) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (agent_target_path($relative) === null) continue;
            if ($file->isLink() || !$file->isFile()) throw new RuntimeException('Runtime package contains a non-regular file.');
            if (!$zip->addFile($file->getPathname(), 'release/' . $relative)) throw new RuntimeException('Cannot package runtime file.');
        }
    }
    if (!$zip->close() || filesize($archive) > 50 * 1024 * 1024) throw new RuntimeException('Deployment ZIP failed or exceeds 50 MiB.');
    agent_read_archive($archive);
    $marker = deployment_marker($sha, $run, (string)hash_file('sha256', $archive), $key);
    $markerFile = $output . '/ready-' . $sha . '-' . $run . '.json';
    if (file_put_contents($markerFile, json_encode($marker, JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot create signed release marker.');
    return [$archive, $markerFile];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    try {
        deployment_build_package(dirname(__DIR__), $argv[1] ?? '', $argv[2] ?? '', (int)($argv[3] ?? 0), (string)getenv('DEPLOY_HOOK_KEY'));
        echo "Deployment ZIP and signed marker validated.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
