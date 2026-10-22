<?php

declare(strict_types=1);

// Runs only on CI after tests and the production environment approval gate.
function da_api_success(string $body): bool
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) { parse_str($body, $decoded); }
    return is_array($decoded) && array_key_exists('error', $decoded)
        && in_array($decoded['error'], [0, '0', false], true);
}

function da_request(string $url, string $user, string $key, ?array $form = null): void
{
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_USERPWD => $user . ':' . $key,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Expect:'],
        ]);
        if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $form]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($body !== false && $status === 200 && da_api_success($body)) return;
        // Never retry authentication/permission errors or replay rejected uploads endlessly.
        if ($attempt === 0 && ($status === 0 || in_array($status, [429, 502, 503, 504], true))) { sleep(10); continue; }
        throw new RuntimeException('DirectAdmin operation failed (HTTP ' . $status . '). Check panel logs and Login Key permissions. Response omitted to protect account data.');
    }
}

function da_upload_release(string $base, string $user, string $key, string $directory, string $archive, string $marker, ?callable $request = null): void
{
    $parts = parse_url($base);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'], true)) throw new RuntimeException('DA_URL must be the verified HTTPS panel URL, optionally with its port.');
    if ($user === '' || $key === '' || preg_match('/[\r\n]/', $user . $key)) throw new RuntimeException('DirectAdmin account/Login Key is missing or invalid.');
    if (!preg_match('#^/domains/[A-Za-z0-9.-]+/\.deploy-incoming$#D', $directory)) throw new RuntimeException('Unsafe private staging path.');
    if (!is_file($archive) || !is_file($marker)) throw new RuntimeException('Release files missing.');
    if (!preg_match('/^release-([a-f0-9]{40})-([1-9][0-9]*)\.zip$/D', basename($archive), $match)
        || basename($marker) !== 'ready-' . $match[1] . '-' . $match[2] . '.json') throw new RuntimeException('Release filenames do not match.');
    $request ??= 'da_request';
    $api = rtrim($base, '/') . '/CMD_API_FILE_MANAGER';
    foreach ([$archive, $marker] as $file) {
        $request($api, $user, $key, [
            'action' => 'upload', 'path' => $directory, 'overwrite' => 'yes',
            'file1' => new CURLFile($file, str_ends_with($file, '.zip') ? 'application/zip' : 'application/json', basename($file)),
        ]);
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    try {
        da_upload_release((string)getenv('DA_URL'), (string)getenv('DA_USERNAME'), (string)getenv('DA_LOGIN_KEY'),
            '/domains/mohomestay.vn/.deploy-incoming', $argv[1] ?? '', $argv[2] ?? '');
        echo "Private release archive uploaded; signed ready marker uploaded last. Waiting for Cron activation.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
