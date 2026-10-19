<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
function expect_door(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
expect_door(function_exists('select_door_codes'), 'Missing door code selection');
$base = ['code'=>'001234#','period'=>'MONTH','status'=>'ACTIVE','starts_at'=>'2027-01-01 00:00:00','ends_at'=>'2027-02-01 00:00:00'];
$week = array_replace($base, ['code'=>'2222','period'=>'WEEK']);
$day = array_replace($base, ['code'=>'3333','period'=>'DAY']);
$disabled = array_replace($day, ['code'=>'4444','status'=>'DISABLED']);
$at = '2027-01-15 07:00:00';
expect_door(array_column(select_door_codes([$base,$week,$day,$disabled], $at), 'code') === ['3333'], 'Day beats week/month; disabled excluded');
expect_door(array_column(select_door_codes([$base,$week], $at), 'code') === ['2222'], 'Week beats month');
expect_door(array_column(select_door_codes([$base], $at), 'code') === ['001234#'], 'Month fallback preserves code');
expect_door(select_door_codes([$disabled], $at) === [], 'Disabled codes never sent');
expect_door(count(select_door_codes([$day,array_replace($day,['code'=>'5555'])], $at)) === 2, 'Include equally preferred codes');
expect_door(select_door_codes([$base], '2027-02-01 00:00:00') === [], 'End boundary exclusive');
expect_door(count(select_door_codes([$base], '2027-01-01 00:00:00')) === 1, 'Start boundary inclusive');
expect_door(door_code_status($base, '2026-12-31 23:59:59') === 'SCHEDULED', 'Future code scheduled');
expect_door(door_code_status($base, '2027-02-01 00:00:00') === 'EXPIRED', 'Expires automatically');
expect_door(door_code_status($disabled, $at) === 'DISABLED', 'Manual disabling honored');
$input = ['code'=>'001234#','period'=>'DAY','status'=>'ACTIVE','startsAt'=>'2027-01-15T14:00','endsAt'=>'2027-01-16T14:00'];
$valid = validate_door_code($input);
expect_door($valid['starts_at'] === '2027-01-15 07:00:00' && $valid['code'] === '001234#', 'Vietnam time converted; leading zeros preserved');
foreach ([['endsAt'=>'2027-01-15T14:00'],['startsAt'=>'2027-02-30T14:00'],['period'=>'YEAR'],['status'=>'EXPIRED'],['code'=>'']] as $invalid) {
    try {
        validate_door_code(array_replace($input, $invalid));
        expect_door(false, 'Reject invalid input');
    } catch (BookingException) {
    }
}
$booking = ['booking_code'=>'MO-TEST','guest_name'=>'An','room_name'=>'Pink','package_name_snapshot'=>'3 giờ','check_in'=>$at,'check_out'=>'2027-01-15 10:00:00','number_of_guests'=>2,'total_price'=>200000];
$mail = build_booking_confirmation_email($booking + ['door_codes'=>[array_replace($base,['code'=>'<0012>'])]]);
expect_door(str_contains($mail['html'],'&lt;0012&gt;') && str_contains($mail['html'],'Mã mở cửa'), 'Email includes escaped code');
expect_door(str_contains(build_booking_confirmation_email($booking)['html'],'chưa có mã mở cửa'), 'Missing code gives contact instructions');
echo "Door code tests: OK\n";
