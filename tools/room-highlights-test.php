<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/rooms.php';
require_once APP_ROOT . '/src/room-repository.php';
require_once APP_ROOT . '/src/views.php';

function highlight_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$input = ['name' => 'Phòng thử', 'highlights' => [' Ánh sáng ấm ', '', 'Không gian riêng tư']];
$data = validate_room_input($input);
highlight_expect($data['highlights'] === ['Ánh sáng ấm', 'Không gian riêng tư'], 'Highlights must retain order and trim empty entries.');
foreach ([array_fill(0, 13, 'Quá nhiều ý'), [str_repeat('a', 301)], 'Sai định dạng', [['text' => 'Sai kiểu']]] as $invalid) {
    try {
        validate_room_input(['name' => 'Phòng thử', 'highlights' => $invalid]);
        throw new RuntimeException('Invalid highlight input was accepted.');
    } catch (BookingException $error) {
        highlight_expect($error->httpStatus === 422, 'Invalid highlights must return a validation error.');
    }
}
$room = fallback_rooms()['pink'];
$room['highlights'] = ['<b>Ánh sáng ấm</b>', 'Không gian riêng tư'];
$room['tags'] = ['Máy chiếu'];
ob_start();
render_room($room);
$html = (string)ob_get_clean();
highlight_expect(str_contains($html, 'Điểm đặc biệt của Pink'), 'Room highlights need their own heading.');
highlight_expect(str_contains($html, '&lt;b&gt;Ánh sáng ấm&lt;/b&gt;'), 'Highlights must be escaped.');
highlight_expect(strpos($html, 'Không gian riêng tư') < strpos($html, 'Tiện nghi nổi bật'), 'Highlights and amenities must be separate sections.');
highlight_expect(substr_count($html, 'Máy chiếu') === 1, 'Amenities must not be duplicated as highlights.');
$room['highlights'] = [];
ob_start();
render_room($room);
$empty = (string)ob_get_clean();
highlight_expect(!str_contains($empty, 'Điểm đặc biệt của Pink'), 'Empty highlights must hide their section.');
highlight_expect(str_contains($empty, 'Máy chiếu'), 'Hiding highlights must preserve amenities.');

if (in_array('--database', $argv, true)) {
    $repository = new RoomRepository(db());
    $created = null;
    try {
        $draft = [
            'name' => 'Kiểm tra điểm đặc biệt',
            'slug' => 'highlight-test-' . bin2hex(random_bytes(8)),
            'status' => 'HIDDEN',
            'tags' => ['Máy chiếu'],
            'highlights' => ['Ánh sáng ấm', 'Không gian riêng tư'],
        ];
        $created = $repository->create($draft);
        highlight_expect($created['highlights'] === $draft['highlights'], 'New highlights must survive a database round trip.');
        unset($draft['highlights']);
        $unchanged = $repository->update($created['id'], $draft);
        highlight_expect($unchanged['highlights'] === $created['highlights'], 'Older clients must preserve highlights when the field is omitted.');
        $draft['highlights'] = ['Ý đã chỉnh sửa'];
        $updated = $repository->update($created['id'], $draft);
        highlight_expect($updated['highlights'] === $draft['highlights'], 'Edited highlights must persist.');
        highlight_expect($updated['tags'] === ['Máy chiếu'], 'Highlights must not overwrite amenities.');
        $draft['highlights'] = [];
        $cleared = $repository->update($created['id'], $draft);
        highlight_expect($cleared['highlights'] === [], 'Highlights must be clearable.');
    } finally {
        if ($created !== null) {
            $repository->delete($created['id']);
        }
    }
    echo "Room highlight database round trip: OK\n";
}

echo "Room highlight tests: OK\n";
