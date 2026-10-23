<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE door_codes (id TEXT PRIMARY KEY, code TEXT, status TEXT, starts_at TEXT, ends_at TEXT)');
$pdo->exec("INSERT INTO door_codes VALUES ('first', '001234#', 'ACTIVE', '2027-01-01 00:00:00', '2027-02-01 00:00:00'), ('other', '5678#', 'ACTIVE', '2027-01-01 00:00:00', '2027-02-01 00:00:00')");
$before = $pdo->query("SELECT * FROM door_codes WHERE id='first'")->fetch();
foreach (['DISABLED', 'DISABLED', 'ACTIVE'] as $status) {
    set_door_code_status($pdo, 'first', $status);
    $after = $pdo->query("SELECT * FROM door_codes WHERE id='first'")->fetch();
    if ($after !== array_replace($before, ['status' => $status])) {
        throw new RuntimeException('Toggle must only change the selected status, preserving code and dates.');
    }
}
foreach ([['missing', 'ACTIVE', 404], ["' OR 1=1 --", 'DISABLED', 404], ['first', 'EXPIRED', 422]] as [$id, $status, $httpStatus]) {
    try {
        set_door_code_status($pdo, $id, $status);
        throw new RuntimeException('Invalid toggle must fail.');
    } catch (BookingException $error) {
        if ($error->httpStatus !== $httpStatus) throw $error;
    }
}
if ($pdo->query("SELECT status FROM door_codes WHERE id='other'")->fetchColumn() !== 'ACTIVE') {
    throw new RuntimeException('Toggle must not change another code.');
}
echo "Door code toggle tests: OK\n";
