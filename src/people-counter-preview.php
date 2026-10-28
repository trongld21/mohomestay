<?php

declare(strict_types=1);

function people_counter_preview_validate(mixed $preview, int $now): ?array
{
    if ($preview === null) return null;
    if (!is_array($preview) || !is_string($preview['jpeg'] ?? null) || strlen($preview['jpeg']) > 180000) {
        throw new BookingException('Khung hình không hợp lệ.', 422);
    }
    $at = people_counter_time($preview['frameAt'] ?? null, $now);
    if (strtotime($at . ' UTC') < $now - 30) {
        throw new BookingException('Khung hình đã quá cũ.', 422);
    }
    $jpeg = base64_decode($preview['jpeg'], true);
    $size = $jpeg === false ? false : @getimagesizefromstring($jpeg);
    if (!$size || $size[2] !== IMAGETYPE_JPEG || $size[0] > 1280 || $size[1] > 1280) {
        throw new BookingException('Khung hình phải là JPEG tối đa 1280 px mỗi cạnh.', 422);
    }
    $result = ['jpeg' => base64_encode($jpeg), 'frameAt' => $preview['frameAt']];
    if (isset($preview['session'])) {
        $session = $preview['session'];
        $started = is_array($session) && is_string($session['startedAt'] ?? null)
            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $session['startedAt'], new DateTimeZone('UTC')) : false;
        if (!is_array($session) || !is_string($session['id'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $session['id']) || !$started
            || $started->format('Y-m-d\TH:i:s\Z') !== $session['startedAt'] || $started->getTimestamp() > $now + 60
            || ($session['trial'] ?? null) !== true) {
            throw new BookingException('Phiên đếm không hợp lệ.', 422);
        }
        foreach (['in', 'out'] as $key) {
            if (!is_int($session[$key] ?? null) || $session[$key] < 0 || $session[$key] > 10000000) {
                throw new BookingException('Số lượt của phiên không hợp lệ.', 422);
            }
        }
        $result['session'] = array_intersect_key($session, array_flip(['id', 'startedAt', 'in', 'out', 'trial']));
    }
    return $result;
}

function people_counter_preview_file(string $directory, string $cameraId): string
{
    if (!preg_match('/^[a-z0-9_-]{1,64}$/D', $cameraId)) {
        throw new BookingException('Camera không hợp lệ.', 422);
    }
    return $directory . '/' . $cameraId . '.json';
}

function people_counter_preview_save(string $directory, string $cameraId, array $preview): void
{
    $file = people_counter_preview_file($directory, $cameraId);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create private preview directory');
    }
    if (file_put_contents($file, json_encode($preview, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Cannot save preview');
    }
    @chmod($file, 0600);
    // A fresh frame from the authenticated worker supersedes a previous local trial bridge.
    $sourceFile = $directory . '/' . $cameraId . '.source.json';
    if (is_file($sourceFile)) @unlink($sourceFile);
}

function people_counter_source_read(string $directory, string $cameraId, int $now): ?string
{
    // The optional PC bridge heartbeat distinguishes producer pauses from camera failure.
    people_counter_preview_file($directory, $cameraId);
    $file = $directory . '/' . $cameraId . '.source.json';
    if (!is_file($file)) return null;
    $record = json_decode((string)@file_get_contents($file, false, null, 0, 1024), true);
    $states = ['STARTING', 'RUNNING', 'MINIMIZED', 'LAYOUT_CHANGED', 'CAPTURE_STALLED', 'WINDOW_CLOSED', 'STOPPED'];
    if (!is_array($record) || !in_array($record['state'] ?? null, $states, true)) return null;
    try {
        $at = people_counter_time($record['at'] ?? null, $now);
    } catch (BookingException) {
        return 'BRIDGE_OFFLINE';
    }
    if (strtotime($at . ' UTC') < $now - 15) return 'BRIDGE_OFFLINE';
    return $record['state'];
}

function people_counter_preview_read(string $directory, string $cameraId, int $now): array
{
    $file = people_counter_preview_file($directory, $cameraId);
    $sourceState = people_counter_source_read($directory, $cameraId, $now);
    if ($sourceState !== null && $sourceState !== 'RUNNING') {
        return ['available' => false, 'state' => $sourceState];
    }
    if (!is_file($file)) return ['available' => false, 'state' => 'WAITING'];
    $handle = fopen($file, 'rb');
    if ($handle === false) throw new RuntimeException('Cannot read preview');
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Cannot lock preview');
        $record = json_decode((string)stream_get_contents($handle, 190001), true);
    } finally {
        fclose($handle);
    }
    try {
        $preview = people_counter_preview_validate($record, $now);
    } catch (BookingException) {
        return ['available' => false, 'state' => 'STALE'];
    }
    if ($preview === null) return ['available' => false, 'state' => 'WAITING'];
    return ['available' => true, 'state' => 'RUNNING', ...$preview];
}

function people_counter_preview_api(string $method): never
{
    if ($method !== 'GET') json_response(['error' => 'Phương thức không được hỗ trợ.'], 405);
    $cameraId = $_GET['cameraId'] ?? 'entrance';
    if (!is_string($cameraId)) json_response(['error' => 'Camera không hợp lệ.'], 422);
    try {
        people_counter_preview_file('', $cameraId);
        $query = db()->prepare('SELECT enabled FROM people_counter_cameras WHERE id = ?');
        $query->execute([$cameraId]);
        if (!(int)$query->fetchColumn()) json_response(['available' => false, 'state' => 'DISABLED']);
        $preview = people_counter_preview_read(APP_ROOT . '/storage/people-counter', $cameraId, time());
        if (($_GET['metadataOnly'] ?? '') === '1') unset($preview['jpeg']);
        json_response($preview);
    } catch (BookingException $error) {
        json_response(['error' => $error->getMessage()], $error->httpStatus);
    } catch (Throwable) {
        json_response(['error' => 'Chưa tải được hình ảnh camera.'], 503);
    }
}
