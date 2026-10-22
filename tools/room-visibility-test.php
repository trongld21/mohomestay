<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$config = config('db');
if (!in_array($config['host'], ['localhost', '127.0.0.1', '::1'], true) || $config['name'] !== 'mohomestay') {
    throw new RuntimeException('Visibility integration tests require the local mohomestay database.');
}

$pdo = db();
$repository = new RoomRepository($pdo);
$pdo->beginTransaction();
try {
    $id = uuid();
    $slug = 'visibility-test-' . $id;
    $insert = $pdo->prepare("INSERT INTO rooms(id,name,slug,price,status,is_active) VALUES(?,?,?,123000,'ACTIVE',1)");
    $insert->execute([$id, 'Phòng kiểm thử', $slug]);
    $before = $repository->findById($id);
    $hidden = $repository->setVisibility($id, 'HIDDEN');
    if ($hidden['status'] !== 'HIDDEN' || $repository->findPublicBySlug($slug) !== null) {
        throw new RuntimeException('Hidden rooms must disappear from the public list.');
    }
    $active = $repository->setVisibility($id, 'ACTIVE');
    if ($active['status'] !== 'ACTIVE' || $repository->findPublicBySlug($slug) === null) {
        throw new RuntimeException('Active rooms must reappear in the public list.');
    }
    foreach (['name', 'slug', 'subtitle', 'description', 'images', 'tags', 'packages', 'maxGuests'] as $key) {
        if ($before[$key] !== $active[$key]) throw new RuntimeException('Unrelated room field changed: ' . $key);
    }
    $repository->setVisibility($id, 'ACTIVE');
    foreach ([[$id, 'INVALID', 422], [uuid(), 'ACTIVE', 404]] as [$roomId, $status, $expected]) {
        try {
            $repository->setVisibility($roomId, $status);
            throw new RuntimeException('Invalid visibility mutation was accepted.');
        } catch (BookingException $error) {
            if ($error->httpStatus !== $expected) throw $error;
        }
    }
    echo "Room visibility integration tests: OK (rolled back)\n";
} finally {
    $pdo->rollBack();
}
