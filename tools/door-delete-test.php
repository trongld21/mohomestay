<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$config = config('db');
if (!in_array($config['host'], ['localhost', '127.0.0.1', '::1'], true) || $config['name'] !== 'mohomestay') {
    throw new RuntimeException('Deletion integration tests require the local mohomestay database.');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $id = uuid();
    $otherId = uuid();
    $insert = $pdo->prepare("INSERT INTO door_codes(id,code,period,status,starts_at,ends_at) VALUES(?,?,'DAY','DISABLED','2027-01-01','2027-01-02')");
    $insert->execute([$id, 'integration-test-' . $id]);
    $insert->execute([$otherId, 'integration-test-' . $otherId]);
    delete_door_code($pdo, $id);
    $lookup = $pdo->prepare('SELECT COUNT(*) FROM door_codes WHERE id=?');
    $lookup->execute([$id]);
    if ((int)$lookup->fetchColumn() !== 0) throw new RuntimeException('Deleted code remains in database.');
    $lookup->execute([$otherId]);
    if ((int)$lookup->fetchColumn() !== 1) throw new RuntimeException('Unrelated code was deleted.');
    foreach ([$id, "' OR 1=1 --"] as $missing) {
        try {
            delete_door_code($pdo, $missing);
            throw new RuntimeException('Missing code must return 404.');
        } catch (BookingException $error) {
            if ($error->httpStatus !== 404) throw $error;
        }
    }
    $lookup->execute([$otherId]);
    if ((int)$lookup->fetchColumn() !== 1) throw new RuntimeException('Parameterized deletion failed.');
    echo "Door code deletion integration tests: OK (rolled back)\n";
} finally {
    $pdo->rollBack();
}
