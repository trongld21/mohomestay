<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/rooms.php';

$failures = [];
function room_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}
function room_throws(callable $callback, string $contains, string $message): void
{
    global $failures;
    try { $callback(); $failures[] = $message . ' (khong nem loi)'; }
    catch (BookingException $e) { if (!str_contains($e->getMessage(), $contains)) $failures[] = $message . ' (sai thong bao: '.$e->getMessage().')'; }
}

room_check(normalize_slug('Phòng Mây  2') === 'phong-may-2', 'Slug tieng Viet phai duoc chuan hoa');

$input = validate_room_input([
    'name'=>' Phòng Mây ','slug'=>'','subtitle'=>' Êm và riêng tư ','description'=>' Mô tả ','status'=>'ACTIVE',
    'maxGuests'=>4,'bedrooms'=>2,'bathrooms'=>1,'sortOrder'=>15,
    'tags'=>[' Netflix ','Máy chiếu','netflix'],
    'packages'=>[
        ['id'=>'','name'=>'3 giờ','mode'=>'DURATION','durationMinutes'=>180,'price'=>250000,'enabled'=>true,'sortOrder'=>10],
        ['id'=>'night','name'=>'Qua đêm','mode'=>'FIXED_TIME','checkInTime'=>'22:00','checkOutTime'=>'10:00','price'=>450000,'enabled'=>true,'sortOrder'=>20],
    ],
]);
room_check($input['name'] === 'Phòng Mây', 'Ten phong phai trim');
room_check($input['slug'] === 'phong-may', 'Slug trong phai sinh tu ten');
room_check($input['tags'] === ['Netflix','Máy chiếu'], 'Tag phai trim va loai trung khong phan biet hoa thuong');
room_check(count($input['packages']) === 2, 'Phai chap nhan danh sach goi linh hoat');

[$durationStart,$durationEnd] = package_window($input['packages'][0], '2027-01-15', '14:30');
room_check($durationEnd->getTimestamp() - $durationStart->getTimestamp() === 10800, 'Goi duration 180 phut phai dung 3 gio');
[$fixedStart,$fixedEnd] = package_window($input['packages'][1], '2027-01-15', '');
room_check($fixedStart->format('H:i') === '22:00' && $fixedEnd->format('Y-m-d H:i') === '2027-01-16 10:00', 'Goi fixed qua dem phai tra ngay ke tiep');

room_throws(fn()=>validate_room_input(['name'=>'Phòng A','status'=>'DRAFT','tags'=>[],'packages'=>[]]), 'Trạng thái', 'Phai tu choi trang thai la');
room_throws(fn()=>validate_packages([['name'=>'Ngắn','mode'=>'DURATION','durationMinutes'=>20,'price'=>1]]), '30 phút', 'Phai tu choi duration duoi 30 phut');
room_throws(fn()=>validate_packages([['name'=>'Sai','mode'=>'FIXED_TIME','checkInTime'=>'25:00','checkOutTime'=>'10:00','price'=>1]]), 'Giờ', 'Phai tu choi gio sai');
room_throws(fn()=>validate_packages([['name'=>'Đắt','mode'=>'DURATION','durationMinutes'=>60,'price'=>1000000001]]), 'Giá', 'Phai tu choi gia qua gioi han');

$migrationPath = APP_ROOT . '/database/migrations/002_room_management.sql';
$migration = is_file($migrationPath) ? (string)file_get_contents($migrationPath) : '';
foreach (['room_images','room_tags','room_packages','COMING_SOON','package_id','package_name_snapshot','package_price_snapshot','FIXED_TIME','DURATION'] as $contract) {
    room_check(str_contains($migration, $contract), 'Migration phai co contract '.$contract);
}
foreach (['pink-3h','pink-6h','pink-overnight','white-3h','white-6h','white-overnight','black-3h','black-6h','black-overnight'] as $seedId) {
    room_check(str_contains($migration, $seedId), 'Migration phai seed goi '.$seedId);
}

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL); exit(1); }
echo "Room domain tests: OK\n";
