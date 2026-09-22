<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/rooms.php';
require_once APP_ROOT . '/src/room-repository.php';

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
room_check((bool)preg_match('/MODIFY COLUMN stay_package VARCHAR\(64\)/i',$migration), 'Migration phai mo rong stay_package cho package UUID');

room_check(room_deletion_allowed(0) === true, 'Phong chua co don phai xoa duoc');
room_check(room_deletion_allowed(1) === false, 'Phong co don khong duoc xoa vat ly');
room_check(room_is_public('ACTIVE') && room_is_public('COMING_SOON') && !room_is_public('HIDDEN'), 'Chi active va coming soon duoc cong khai');
$hydrated = hydrate_room_rows(
    [['id'=>'r1','name'=>'Mây','slug'=>'may','subtitle'=>'êm','description'=>'dài','tag'=>'','status'=>'ACTIVE','max_guests'=>2,'bedrooms'=>1,'bathrooms'=>1,'sort_order'=>10,'created_at'=>'x','updated_at'=>'x']],
    [['id'=>'i1','room_id'=>'r1','path'=>'/a.jpg','caption'=>'A','is_cover'=>1,'sort_order'=>2]],
    [['id'=>'t1','room_id'=>'r1','label'=>'Netflix','sort_order'=>1]],
    [['id'=>'p1','room_id'=>'r1','name'=>'2 giờ','timing_mode'=>'DURATION','duration_minutes'=>120,'check_in_time'=>null,'check_out_time'=>null,'price'=>200000,'is_enabled'=>1,'sort_order'=>1]]
);
room_check($hydrated['r1']['image'] === '/a.jpg', 'DTO phai chon cover image');
room_check($hydrated['r1']['tags'] === ['Netflix'], 'DTO phai gom tags theo phong');
room_check($hydrated['r1']['packages'][0]['durationMinutes'] === 120, 'DTO phai map package linh hoat');
room_check(select_package_id('owned-id', ['owned-id']) === 'owned-id', 'Cap nhat phai giu package id cua phong');
room_check(select_package_id('foreign-id', ['owned-id']) !== 'foreign-id', 'Khong duoc chiem package id cua phong khac');

$bookableRoom = $hydrated['r1'];
$selectedPackage = resolve_bookable_package($bookableRoom, 'p1');
room_check($selectedPackage['price'] === 200000, 'Phai tim dung goi gia cua phong');
room_throws(fn()=>resolve_bookable_package($bookableRoom, 'missing'), 'G', 'Phai tu choi package khong thuoc phong');
$disabledRoom = $bookableRoom;
$disabledRoom['packages'][0]['enabled'] = false;
room_throws(fn()=>resolve_bookable_package($disabledRoom, 'p1'), 'G', 'Phai tu choi package da tat');
$comingSoonRoom = $bookableRoom;
$comingSoonRoom['status'] = 'COMING_SOON';
room_throws(fn()=>assert_room_bookable($comingSoonRoom), 'ra', 'Phong coming soon khong duoc dat');
$snapshot = booking_package_snapshot($selectedPackage);
$selectedPackage['price'] = 999;
room_check($snapshot['packagePrice'] === 200000 && $snapshot['packageId'] === 'p1', 'Snapshot goi phai bat bien sau khi dat');
$apiSource=(string)file_get_contents(APP_ROOT.'/src/api.php');
room_check(str_contains($apiSource,'status,max_guests')&&str_contains($apiSource,'$q[\'guests\'] > (int)($room[\'max_guests\'] ?? 0)'),'Booking phai revalidate suc chua trong room lock');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL); exit(1); }
echo "Room domain tests: OK\n";
