<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/views.php';

function detail_render(array $room): string
{
    ob_start();
    render_room($room);
    return (string)ob_get_clean();
}

function detail_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$room = fallback_rooms()['pink'];
$room['description'] = 'Nội dung chi tiết riêng của phòng để kiểm tra vị trí hiển thị.';
$html = detail_render($room);
detail_expect(str_contains($html, 'room-amenities-plant.png'), 'Amenities must include the decorative plant image.');
foreach ([
    'Giường ngủ lớn' => 'bed',
    'Điều hòa không khí' => 'air-conditioner',
    'Tủ lạnh mini' => 'fridge',
    'Tủ quần áo rộng rãi' => 'wardrobe',
    'Gương soi toàn thân' => 'mirror',
    'Góc sofa và bàn trà' => 'sofa',
    'Rèm cửa' => 'curtains',
    'Đèn ngủ' => 'lamp',
    'Decor chụp ảnh' => 'camera',
    'Tiện nghi khác' => 'sparkles',
] as $label => $icon) {
    detail_expect(room_amenity_icon_name($label) === $icon, 'Amenities must use the matching icon: ' . $label);
}
detail_expect(substr_count($html, $room['description']) === 1, 'Detailed description must appear only once.');
detail_expect(
    strpos($html, $room['description']) > strpos($html, 'id="room-story-title"'),
    'Detailed description belongs in the room story, outside the booking panel.',
);
detail_expect(str_contains($html, 'action="/calendar"'), 'Stay selection must submit to the availability calendar.');
detail_expect(substr_count($html, 'href="/bookings?room=pink&amp;package=') === 3, 'Each enabled package must directly link to the booking form.');
detail_expect(str_contains($html, 'href="/bookings?room=pink&amp;package=pink-3h"'), 'Booking links must preserve the selected room and package without JavaScript.');
detail_expect(str_contains($html, 'name="room" value="pink"'), 'Calendar submission must preserve the room ID.');
detail_expect(str_contains($html, 'data-room-lightbox'), 'Gallery must support expanded images.');
detail_expect(!str_contains($html, 'data-gallery-next'), 'A single image must not show nonfunctional arrows.');
$room['images'][] = ['path' => '/uploads/rooms/pink/photo.webp', 'caption' => 'Ảnh tải lên'];
$uploaded = detail_render($room);
detail_expect(str_contains($uploaded, 'src="/uploads/rooms/pink/photo.webp"'), 'Admin uploads must work alongside static cover images.');
detail_expect(str_contains($uploaded, 'data-gallery-next'), 'Multiple images must enable gallery navigation.');
$room['packages'][0]['enabled'] = false;
detail_expect(!str_contains(detail_render($room), 'package=pink-3h'), 'Disabled packages must not be offered.');
$room['status'] = 'COMING_SOON';
$coming = detail_render($room);
detail_expect(!str_contains($coming, 'href="/bookings?'), 'Coming-soon packages must not link to booking.');
detail_expect(str_contains($coming, 'fieldset class="room-rates" disabled'), 'Coming-soon packages must not be bookable.');
detail_expect(!str_contains($coming, 'type="submit"'), 'Coming-soon rooms must show contact instead of booking.');
$room['status'] = 'HIDDEN';
$hidden = detail_render($room);
detail_expect(!str_contains($hidden, 'href="/bookings?'), 'Hidden room packages must not link to booking.');
detail_expect(!str_contains($hidden, 'type="submit"'), 'Admin preview of hidden rooms must not offer booking.');
detail_expect(str_contains($hidden, 'Phòng đang ẩn khỏi website'), 'Hidden preview status must be explicit.');
$room['status'] = 'ACTIVE';
$room['packages'] = [];
$room['images'] = [];
$room['tags'] = [];
$empty = detail_render($room);
detail_expect(str_contains($empty, 'Gói lưu trú đang được cập nhật'), 'Missing package data must have a usable contact fallback.');
detail_expect(!str_contains($empty, 'type="submit"'), 'A room with no packages cannot submit an empty booking selection.');
detail_expect(str_contains($empty, 'data-gallery-main'), 'Rooms without image rows must retain the cover image fallback.');
$room['name'] = '<script>bad()</script>';
$room['tags'] = ['<b>Test</b>'];
$escaped = detail_render($room);
detail_expect(!str_contains($escaped, '<script>bad()'), 'Room names must be escaped.');
detail_expect(str_contains($escaped, '&lt;b&gt;Test&lt;/b&gt;'), 'Amenities must be escaped.');
echo "Room detail render tests: OK\n";
