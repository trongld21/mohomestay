<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/people-counter.php';

function counter_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function counter_reject(callable $action, int $status): void
{
    try {
        $action();
    } catch (BookingException $error) {
        counter_check($error->httpStatus === $status, 'Unexpected rejection status.');
        return;
    }
    throw new RuntimeException('Invalid input was accepted.');
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE people_counter_cameras (id TEXT PRIMARY KEY, name TEXT, enabled INTEGER, last_received_at TEXT, last_frame_at TEXT, status TEXT)');
$pdo->exec('CREATE TABLE people_counter_events (camera_id TEXT, event_id TEXT, direction TEXT, occurred_at TEXT, received_at TEXT, PRIMARY KEY(camera_id,event_id))');
$pdo->exec('CREATE TABLE people_counter_trial_events (camera_id TEXT, event_id TEXT, direction TEXT, occurred_at TEXT, received_at TEXT, PRIMARY KEY(camera_id,event_id))');
$pdo->exec("INSERT INTO people_counter_cameras(id,name,enabled,status) VALUES('entrance','Lễ tân',1,'WAITING')");
$now = strtotime('2026-10-27T18:00:00Z');
$body = '{"events":[]}';
$key = str_repeat('s', 32);
$signature = hash_hmac('sha256', $now . '.' . $body, $key);
counter_check(people_counter_authorized($key, (string)$now, $signature, $body, $now), 'Valid signature rejected.');
foreach ([['', (string)$now, $signature, $body], [$key, (string)($now - 301), $signature, $body], [$key, (string)$now, $signature, $body . ' '], [$key, (string)$now, str_repeat('0', 64), $body]] as $args) {
    counter_check(!people_counter_authorized(...[...$args, $now]), 'Invalid signature accepted.');
}
$event = ['id' => str_repeat('a', 32), 'direction' => 'IN', 'occurredAt' => '2026-10-26T17:00:00Z'];
$input = ['cameraId' => 'entrance', 'status' => 'RUNNING', 'frameAt' => '2026-10-27T17:59:59Z', 'events' => [$event]];
counter_check(people_counter_ingest($pdo, $input, $now)['inserted'] === 1, 'First event must be inserted.');
counter_check(people_counter_ingest($pdo, $input, $now)['inserted'] === 0, 'Retry must not double count.');
$trialInput = [...$input, 'trial' => true];
counter_check(people_counter_ingest($pdo, $trialInput, $now)['inserted'] === 1, 'Trial event must be persisted separately.');
counter_check(people_counter_ingest($pdo, $trialInput, $now)['inserted'] === 0, 'Trial retry must not double count.');
$trialReport = people_counter_report($pdo, ['mode' => 'trial', 'from' => '2026-10-27', 'to' => '2026-10-27'], $now);
counter_check($trialReport['count'] === 1 && $trialReport['hours'][0]['in'] === 1, 'Trial history must use Vietnam time.');
counter_check($trialReport['mode'] === 'trial' && count($trialReport['events']) === 1, 'Trial history is missing.');
counter_reject(fn() => people_counter_ingest($pdo, [...$input, 'trial' => 'true'], $now), 422);
counter_reject(fn() => people_counter_report($pdo, ['mode' => 'bad'], $now), 422);
counter_reject(fn() => people_counter_ingest($pdo, array_replace($input, ['events' => [array_replace($event, ['direction' => 'OUT'])]]), $now), 409);
foreach ([['direction' => 'SIDE'], ['id' => 'bad'], ['occurredAt' => '2026-02-30T00:00:00Z'], ['occurredAt' => '2026-10-28T00:00:00Z'], ['occurredAt' => '2026-10-27T01:00:00'], ['direction' => []]] as $change) {
    counter_reject(fn() => people_counter_ingest($pdo, array_replace($input, ['events' => [array_replace($event, $change)]]), $now), 422);
}
counter_reject(fn() => people_counter_ingest($pdo, array_replace($input, ['cameraId' => 'unknown']), $now), 422);
counter_reject(fn() => people_counter_ingest($pdo, array_replace($input, ['status' => 'RUNNING', 'frameAt' => null]), $now), 422);
counter_reject(fn() => people_counter_ingest($pdo, array_replace($input, ['events' => array_fill(0, 101, $event)]), $now), 422);
$other = array_replace($event, ['id' => str_repeat('b', 32), 'direction' => 'OUT', 'occurredAt' => '2026-10-27T16:59:59Z']);
people_counter_ingest($pdo, array_replace($input, ['events' => [$other]]), $now);
$nextDay = array_replace($event, ['id' => str_repeat('c', 32), 'occurredAt' => '2026-10-27T17:00:00Z']);
people_counter_ingest($pdo, array_replace($input, ['events' => [$nextDay]]), $now);
$report = people_counter_report($pdo, ['from' => '2026-10-27', 'to' => '2026-10-27'], $now);
counter_check($report['totals'] === ['in' => 1, 'out' => 1], 'Vietnam midnight boundaries are wrong.');
counter_check(count($report['hours']) === 24 && $report['hours'][0]['in'] === 1 && $report['hours'][23]['out'] === 1, 'Hourly grouping is wrong.');
counter_check($report['events'][0]['id'] === $other['id'], 'Newest events must appear first.');
counter_check($report['camera']['state'] === 'RUNNING', 'Fresh camera must be running.');
counter_check(people_counter_report($pdo, [], $now + 121)['camera']['state'] === 'STALE', 'Stale heartbeat must be visible.');
$pending = array_replace($event, ['id' => str_repeat('d', 32)]);
counter_reject(fn() => people_counter_ingest($pdo, array_replace($input, ['events' => [$pending, array_replace($other, ['direction' => 'IN'])]]), $now), 409);
counter_check((int)$pdo->query('SELECT COUNT(*) FROM people_counter_events')->fetchColumn() === 3, 'Conflicting batch must roll back entirely.');
$pdo->exec("UPDATE people_counter_cameras SET enabled = 0 WHERE id = 'entrance'");
counter_reject(fn() => people_counter_ingest($pdo, $input, $now), 422);
counter_check(people_counter_report($pdo, [], $now)['camera']['state'] === 'DISABLED', 'Disabled camera state missing.');
$pdo->exec("UPDATE people_counter_cameras SET enabled = 1 WHERE id = 'entrance'");
people_counter_ingest($pdo, array_replace($input, ['status' => 'OFFLINE', 'frameAt' => null, 'events' => []]), $now);
counter_check(people_counter_report($pdo, [], $now)['camera']['state'] === 'OFFLINE', 'Offline heartbeat must not look healthy.');
$batch = [];
for ($index = 1; $index <= 55; $index++) {
    $batch[] = array_replace($event, ['id' => str_pad(dechex($index), 32, '0', STR_PAD_LEFT)]);
}
people_counter_ingest($pdo, array_replace($input, ['events' => $batch]), $now);
$firstPage = people_counter_report($pdo, ['from' => '2026-10-27', 'page' => 1], $now);
$secondPage = people_counter_report($pdo, ['from' => '2026-10-27', 'page' => 2], $now);
counter_check($firstPage['count'] === 57 && count($firstPage['events']) === 50 && count($secondPage['events']) === 7, 'Pagination must retain full filtered totals.');
counter_check(array_intersect(array_column($firstPage['events'], 'id'), array_column($secondPage['events'], 'id')) === [], 'Pages must not overlap.');
foreach ([['from' => '2026-02-30'], ['from' => "2026-10-27\0"], ['from' => '2026-01-01', 'to' => '2026-03-01'], ['from' => []], ['page' => '-1']] as $filter) {
    counter_reject(fn() => people_counter_report($pdo, $filter, $now), 422);
}
counter_check(admin_api_permission('/api/admin/people-counter', 'GET') === 'people-counter.view', 'Counter read permission missing.');
counter_check(admin_api_permission('/api/admin/people-counter', 'POST') === 'accounts', 'Viewer must not be granted a write permission.');
echo "People counter: signatures, validation, retries, rollback, Vietnam dates and health OK\n";
