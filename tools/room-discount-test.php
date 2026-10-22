<?php
require dirname(__DIR__) . '/src/bootstrap.php';
function discount_expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
discount_expect(function_exists('room_discount_price'), 'Missing room discount pricing');
discount_expect(room_discount_price(300000,10)===270000,'Ten percent reduces actual payable price');
discount_expect(room_discount_price(100001,15)===85001,'Round to nearest VND');
discount_expect(room_discount_price(300000,0)===300000,'Zero preserves base price');
$input=['id'=>'pkg','name'=>'3 giờ','mode'=>'DURATION','durationMinutes'=>180,'price'=>300000,'discountPercent'=>10];
discount_expect(validate_packages([$input])[0]['discountPercent']===10,'Package accepts discount');
foreach ([-1,100,101,10.5,'bad'] as $invalid) {
    try { validate_packages([array_replace($input,['discountPercent'=>$invalid])]); throw new LogicException('Invalid discount accepted'); }
    catch (BookingException) {}
}
$room=fallback_rooms()['pink'];
$room['packages'][0]['discountPercent']=10;
$booking=['packageId'=>'pink-3h','date'=>(new DateTimeImmutable('+2 days'))->format('Y-m-d'),'time'=>'14:00','numberOfGuests'=>2,'guestName'=>'Khách thử','guestPhone'=>'0901234567','guestEmail'=>'test@example.com','acceptTerms'=>true,'total'=>1];
$quote=quote_booking_for_room($room,$booking);
discount_expect($quote['total']===180000 && $quote['packagePrice']===180000,'Server ignores forged client total and snapshots discounted price');
$room['discountPercent']=20;
$room['packages'][0]['discountPercent']=null;
discount_expect(quote_booking_for_room($room,$booking)['total']===160000,'Blank package discount inherits room discount');
$room['packages'][0]['discountPercent']=0;
discount_expect(quote_booking_for_room($room,$booking)['total']===200000,'Explicit zero overrides room discount');
$room['packages'][0]['discountPercent']=10;
discount_expect(quote_booking_for_room($room,$booking)['total']===180000,'Package overrides room discount without stacking');
$resolved=package_with_discount($room['packages'][0],20);
discount_expect(package_with_discount($resolved,20)['price']===180000,'Hydrated public prices must not be discounted twice');
discount_expect(validate_packages([array_replace($input,['discountPercent'=>''])])[0]['discountPercent']===null,'Empty field persists inheritance');
discount_expect(validate_packages([array_replace($input,['discountPercent'=>0])])[0]['discountPercent']===0,'Zero must not become inheritance');
$manual=quote_booking_for_room($room,array_replace($booking,['guestEmail'=>'']),true);
discount_expect($manual['total']===180000,'Admin manual bookings use same effective price');
$hydrated=hydrate_room_rows([
    ['id'=>'r','name'=>'Test','slug'=>'test','max_guests'=>2,'discount_percent'=>20]
],[],[],[
    ['id'=>'p','room_id'=>'r','name'=>'3 giờ','timing_mode'=>'DURATION','duration_minutes'=>180,'check_in_time'=>null,'check_out_time'=>null,'price'=>300000,'discount_percent'=>null,'is_enabled'=>1,'sort_order'=>1],
    ['id'=>'q','room_id'=>'r','name'=>'Qua đêm','timing_mode'=>'DURATION','duration_minutes'=>360,'check_in_time'=>null,'check_out_time'=>null,'price'=>500000,'discount_percent'=>10,'is_enabled'=>1,'sort_order'=>2]
]);
discount_expect($hydrated['r']['packages'][0]['price']===240000 && $hydrated['r']['packages'][1]['price']===450000,'API catalog applies inherited and individual discounts independently');
discount_expect($hydrated['r']['packages'][1]['basePrice']===500000,'Admin editor retains original package price');
echo "Room discount tests: OK\n";
