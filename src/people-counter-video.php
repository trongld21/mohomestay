<?php

declare(strict_types=1);

function people_counter_video_api(string $method): never
{
    if ($method !== 'GET') json_response(['error' => 'Phương thức không được hỗ trợ.'], 405);
    $camera = $_GET['cameraId'] ?? 'entrance';
    $file = $_GET['file'] ?? 'index.m3u8';
    if ($camera !== 'entrance' || !is_string($file)
        || !preg_match('/^(index\.m3u8|segment[0-9]{1,16}\.ts)$/D', $file)) {
        json_response(['error' => 'Video không hợp lệ.'], 422);
    }
    $origin = rtrim((string)env_value('PEOPLE_COUNTER_VIDEO_ORIGIN', ''), '/');
    $parts = parse_url($origin);
    $local = ($parts['scheme'] ?? '') === 'http'
        && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true);
    if (!$parts || (!$local && ($parts['scheme'] ?? '') !== 'https')
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        json_response(['error' => 'Chưa cấu hình nguồn video trực tiếp.'], 503);
    }
    $query = db()->prepare('SELECT enabled FROM people_counter_cameras WHERE id = ?');
    $query->execute([$camera]);
    if (!(int)$query->fetchColumn()) json_response(['error' => 'Camera đã tắt.'], 404);
    $key = (string)env_value('PEOPLE_COUNTER_KEY', '');
    if (strlen($key) < 32) json_response(['error' => 'Chưa cấu hình nguồn video trực tiếp.'], 503);
    // Release the login lock before downloading segments in parallel.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $expires = (string)(time() + 60);
    $signature = hash_hmac('sha256', 'video:entrance:' . $expires, $key);
    $httpsRelay = !$local && str_ends_with($parts['path'] ?? '', '.php');
    $url = $httpsRelay
        ? $origin . '?file=' . $file
        : $origin . '/' . $file . '?expires=' . $expires . '&signature=' . $signature;
    $curl = curl_init($url);
    $data = '';
    curl_setopt_array($curl, [
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $httpsRelay
            ? ['X-Video-Expires: ' . $expires, 'X-Video-Signature: ' . $signature]
            : [],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$data): int {
            if (strlen($data) + strlen($chunk) > 8 * 1024 * 1024) return 0;
            $data .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($ok === false || $status !== 200) {
        json_response(['error' => 'Video đang kết nối lại.'], $status === 404 ? 404 : 503);
    }
    if ($file === 'index.m3u8') {
        if (!str_starts_with($data, '#EXTM3U')) json_response(['error' => 'Nguồn video không hợp lệ.'], 503);
        $lines = preg_split('/\r?\n/', $data);
        foreach ($lines as &$line) {
            if ($line === '' || str_starts_with($line, '#')) continue;
            $segment = explode('?', $line, 2)[0];
            if (!preg_match('/^segment[0-9]{1,16}\.ts$/D', $segment)) {
                json_response(['error' => 'Nguồn video không hợp lệ.'], 503);
            }
            $line = '/api/admin/people-counter/video?cameraId=entrance&file=' . $segment;
        }
        unset($line);
        $data = implode("\n", $lines);
    }
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . ($file === 'index.m3u8' ? 'application/vnd.apple.mpegurl' : 'video/mp2t'));
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}
