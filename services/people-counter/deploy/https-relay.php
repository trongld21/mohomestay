<?php

declare(strict_types=1);

// Standalone HTTPS adapter. Never loads the POS application or its database.
ini_set('memory_limit', '32M');
set_time_limit(5);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function video_relay_error(int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo '{"error":"Video unavailable"}';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') video_relay_error(405);
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') video_relay_error(403);

$expires = $_SERVER['HTTP_X_VIDEO_EXPIRES'] ?? '';
$signature = $_SERVER['HTTP_X_VIDEO_SIGNATURE'] ?? '';
$file = $_GET['file'] ?? 'index.m3u8';
if (!preg_match('/^[0-9]{10}$/D', $expires)
    || !preg_match('/^[a-f0-9]{64}$/D', $signature)
    || (int)$expires < time() || (int)$expires > time() + 120) {
    video_relay_error(403);
}
if (!is_string($file) || !preg_match('/^(index\.m3u8|segment[0-9]{1,16}\.ts)$/D', $file)) {
    video_relay_error(404);
}

// Only the private gateway verifies the HMAC. No key is stored in the web root.
$curl = curl_init('http://127.0.0.1:19460/' . $file . '?expires=' . $expires . '&signature=' . $signature);
$data = '';
curl_setopt_array($curl, [
    CURLOPT_CONNECTTIMEOUT_MS => 500,
    CURLOPT_TIMEOUT_MS => 2000,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$data): int {
        if (strlen($data) + strlen($chunk) > 4 * 1024 * 1024) return 0;
        $data .= $chunk;
        return strlen($chunk);
    },
]);
$ok = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);
if ($ok === false || $status !== 200) {
    video_relay_error(in_array($status, [403, 404], true) ? $status : 503);
}

if ($file === 'index.m3u8') {
    // The receiving backend rewrites these names into its authenticated routes.
    // Do not pass the internal bearer ticket across the public relay.
    $data = preg_replace('/^(segment[0-9]{1,16}\.ts)\?[^\r\n]*/m', '$1', $data);
}
header('Content-Type: ' . ($file === 'index.m3u8' ? 'application/vnd.apple.mpegurl' : 'video/mp2t'));
header('Content-Length: ' . strlen($data));
echo $data;
