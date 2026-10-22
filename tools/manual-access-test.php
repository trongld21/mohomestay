<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
function manual_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$input = ['id' => str_repeat('a', 32), 'name' => 'Khách xem phòng', 'startsAt' => '2027-01-15T11:30', 'endsAt' => '2027-01-15T12:15'];
$row = validate_manual_access($input);
manual_expect($row['starts_at'] === '2027-01-15 04:00:00' && $row['ends_at'] === '2027-01-15 06:00:00', 'Manual hours round outward without booking buffer');
try {
    validate_manual_access(array_merge($input, ['endsAt' => $input['startsAt']]));
    throw new RuntimeException('Invalid period accepted');
} catch (BookingException $expected) {
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('GET_LOCK', fn() => 1);
$pdo->sqliteCreateFunction('RELEASE_LOCK', fn() => 1);
$pdo->exec('CREATE TABLE manual_access_codes(id TEXT PRIMARY KEY,name TEXT,lock_id INTEGER,code TEXT,remote_id INTEGER,starts_at TEXT,ends_at TEXT,sync_status TEXT,sync_error TEXT)');
$pdo->prepare("INSERT INTO manual_access_codes(id,name,lock_id,starts_at,ends_at,sync_status) VALUES(?,?,7,?,?,'PENDING')")
    ->execute([$row['id'], $row['name'], $row['starts_at'], $row['ends_at']]);
$options = ['base_url' => 'https://api.sciener.com', 'client_id' => 'test', 'client_secret' => 'test', 'username' => 'test', 'password_md5' => str_repeat('a', 32)];
$creates = 0;
$remote = null;
$client = new TTLockClient($options, function ($url, $params) use (&$creates, &$remote) {
    if (str_ends_with($url, '/token')) return ['access_token' => 'test'];
    if (str_ends_with($url, '/listKeyboardPwd')) return ['list' => $remote ? [$remote] : []];
    $creates++;
    $remote = $params + ['keyboardPwd' => '01234567', 'keyboardPwdId' => 123, 'status' => 1];
    throw new TTLockException('Connection lost', true);
});
$first = sync_manual_access($pdo, $row['id'], $client);
manual_expect($first['sync_status'] === 'UNKNOWN', 'Uncertain creation must persist for reconciliation');
$second = sync_manual_access($pdo, $row['id'], $client);
manual_expect($second['sync_status'] === 'READY' && $second['code'] === '01234567' && $creates === 1, 'Retry recovers PIN without duplicate creation');
sync_manual_access($pdo, $row['id'], $client);
manual_expect($creates === 1, 'Ready request remains idempotent');
echo "Manual access tests passed.\n";
