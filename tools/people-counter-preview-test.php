<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/people-counter.php';

function preview_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$now = time();
$jpeg = file_get_contents(APP_ROOT . '/tools/fixtures/counter-preview.jpg');
$payload = ['frameAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'jpeg' => base64_encode($jpeg)];
// A checked-in synthetic JPEG keeps CI independent of the optional GD extension.
$validated = people_counter_preview_validate($payload, $now);
preview_check($validated['jpeg'] === $payload['jpeg'], 'Valid JPEG changed.');
$overlay = ['atMs' => $now * 1000, 'line' => [[0.04, 0.52], [0.3, 0.98]],
    'boxes' => [['id' => 12, 'rect' => [0.1, 0.2, 0.3, 0.8], 'direction' => 'IN']]];
preview_check(people_counter_preview_validate([...$payload, 'overlay' => $overlay], $now)['overlay'] === $overlay, 'Detection metadata must survive ingest.');
foreach ([['atMs' => ($now - 10) * 1000], ['boxes' => array_fill(0, 21, $overlay['boxes'][0])],
    ['line' => [[-1, 0], [1, 1]]], ['boxes' => [['id' => 1, 'rect' => [0.4, 0.2, 0.1, 0.8], 'direction' => null]]]] as $invalid) {
    try {
        people_counter_preview_validate([...$payload, 'overlay' => array_replace($overlay, $invalid)], $now);
        throw new RuntimeException('Invalid overlay accepted.');
    } catch (BookingException) {
    }
}
$session = ['id' => str_repeat('a', 32), 'startedAt' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600), 'in' => 7, 'out' => 10, 'trial' => true];
$withSession = people_counter_preview_validate([...$payload, 'session' => $session], $now);
preview_check($withSession['session'] === $session, 'Live totals must remain separate from operational totals.');
foreach (['in' => -1, 'out' => true, 'trial' => false, 'id' => 123, 'startedAt' => 'invalid'] as $key => $value) {
    try {
        people_counter_preview_validate([...$payload, 'session' => array_replace($session, [$key => $value])], $now);
        throw new RuntimeException('Invalid live session accepted.');
    } catch (BookingException) {
    }
}
$directory = APP_ROOT . '/.deploy/preview-test-' . bin2hex(random_bytes(8));
try {
    preview_check(!people_counter_preview_read($directory, 'entrance', $now)['available'], 'Missing preview must be waiting.');
    people_counter_preview_save($directory, 'entrance', $validated);
    preview_check(people_counter_preview_read($directory, 'entrance', $now)['available'], 'Recent preview must be readable.');
    preview_check(!people_counter_preview_read($directory, 'entrance', $now + 31)['available'], 'Stale image must be hidden.');
    $sourceFile = $directory . '/entrance.source.json';
    file_put_contents($sourceFile, json_encode(['state' => 'MINIMIZED', 'at' => $payload['frameAt']]));
    $paused = people_counter_preview_read($directory, 'entrance', $now);
    preview_check(!$paused['available'] && $paused['state'] === 'MINIMIZED', 'Minimized producer must hide even a fresh image.');
    file_put_contents($sourceFile, json_encode(['state' => 'RUNNING', 'at' => $payload['frameAt']]));
    preview_check(people_counter_preview_read($directory, 'entrance', $now)['available'], 'Producer must resume with fresh image.');
    $offline = people_counter_preview_read($directory, 'entrance', $now + 16);
    preview_check(!$offline['available'] && $offline['state'] === 'BRIDGE_OFFLINE', 'Missing heartbeat must identify stopped PC bridge.');
    file_put_contents($sourceFile, json_encode(['state' => 'untrusted-message', 'at' => $payload['frameAt']]));
    preview_check(people_counter_preview_read($directory, 'entrance', $now)['available'], 'Invalid source labels must not enter the response.');
    people_counter_preview_save($directory, 'entrance', $validated);
    preview_check(!is_file($sourceFile), 'Authenticated worker must supersede the old trial heartbeat.');
    foreach ([array_replace($payload, ['jpeg' => base64_encode('<svg/>')]), array_replace($payload, ['jpeg' => str_repeat('a', 180001)]), array_replace($payload, ['frameAt' => gmdate('Y-m-d\TH:i:s\Z', $now - 31)])] as $invalid) {
        try {
            people_counter_preview_validate($invalid, $now);
            throw new RuntimeException('Invalid preview accepted.');
        } catch (BookingException $error) {
            preview_check($error->httpStatus === 422, 'Invalid preview must return 422.');
        }
    }
    try {
        people_counter_preview_read($directory, '../secret', $now);
        throw new RuntimeException('Traversal accepted.');
    } catch (BookingException) {
    }
} finally {
    if (is_file($directory . '/entrance.source.json')) unlink($directory . '/entrance.source.json');
    if (is_file($directory . '/entrance.json')) unlink($directory . '/entrance.json');
    if (is_dir($directory)) rmdir($directory);
}
echo "Counter preview: JPEG validation, private storage, expiry and traversal protection OK\n";
