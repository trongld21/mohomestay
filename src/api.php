<?php

function handle_api(string $path, string $method): never
{
    try {
        if ($path === '/api/health' && $method === 'GET') json_response(['status'=>'ok','service'=>'lang-home-php']);
        if ($path === '/api/rooms' && $method === 'GET') json_response(['rooms'=>array_values(room_repository()->publicRooms())]);
        if ($method === 'GET' && preg_match('#^/api/rooms/([a-z0-9-]+)$#', $path, $matches)) {
            $room = room_repository()->findPublicBySlug($matches[1]);
            if (!$room) json_response(['error'=>'Không tìm thấy phòng.'], 404);
            json_response(['room'=>$room]);
        }
        if ($path === '/api/availability' && $method === 'GET') availability_api();
        if ($path === '/api/bookings' && $method === 'POST') create_booking_api();
        if ($path === '/api/bookings/lookup' && $method === 'POST') lookup_booking_api();
        if ($path === '/api/payments/webhook' && $method === 'POST') payment_webhook_api();
        if ($path === '/api/admin/rooms' && $method === 'GET') admin_rooms_api();
        if ($path === '/api/admin/rooms' && $method === 'POST') create_room_api();
        if (preg_match('#^/api/admin/rooms/([a-zA-Z0-9_-]+)/images$#',$path,$matches) && $method === 'POST') upload_room_image_api($matches[1]);
        if (preg_match('#^/api/admin/rooms/([a-zA-Z0-9_-]+)/images/([a-zA-Z0-9_-]+)$#',$path,$matches) && $method === 'PATCH') update_room_image_api($matches[1],$matches[2]);
        if (preg_match('#^/api/admin/rooms/([a-zA-Z0-9_-]+)/images/([a-zA-Z0-9_-]+)$#',$path,$matches) && $method === 'DELETE') delete_room_image_api($matches[1],$matches[2]);
        if (preg_match('#^/api/admin/rooms/([a-zA-Z0-9_-]+)$#',$path,$matches) && $method === 'PATCH') update_room_api($matches[1]);
        if (preg_match('#^/api/admin/rooms/([a-zA-Z0-9_-]+)$#',$path,$matches) && $method === 'DELETE') delete_room_api($matches[1]);
        if ($path === '/api/admin/bookings' && $method === 'GET') admin_bookings_api();
        if ($path === '/api/admin/bookings' && $method === 'PATCH') update_booking_api();
        json_response(['error'=>'Không tìm thấy API.'], 404);
    } catch (BookingException $e) { json_response(['error'=>$e->getMessage()], $e->httpStatus); }
    catch (Throwable $e) { error_log($e->__toString()); json_response(['error'=>'Hệ thống đang bận. Vui lòng thử lại hoặc liên hệ Lặng.'], 503); }
}

function availability_api(): never
{
    $date = (string)($_GET['date'] ?? '');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Ho_Chi_Minh'));
    if (!$d || $d->format('Y-m-d') !== $date) json_response(['error'=>'Ngày không hợp lệ.'], 400);
    $start = mysql_datetime($d); $end = mysql_datetime($d->modify('+2 days'));
    $stmt = db()->prepare("SELECT room_id,check_in,check_out,status FROM bookings WHERE check_in < ? AND check_out > ? AND (status IN ('CONFIRMED','CHECKED_IN') OR (status='PENDING' AND hold_expires_at>UTC_TIMESTAMP()))");
    $stmt->execute([$end,$start]); $bookings = $stmt->fetchAll();
    $blocks = db()->prepare('SELECT room_id,`date` FROM room_availability WHERE available=0 AND `date`>=? AND `date`<?');
    $blocks->execute([$date,$d->modify('+2 days')->format('Y-m-d')]); $blocked = $blocks->fetchAll();
    $inventory = db()->query('SELECT id,is_active FROM rooms')->fetchAll();
    $result = [];
    foreach (room_repository()->publicRooms() as $room) {
        $intervals = [];
        foreach ($bookings as $b) if ($b['room_id']===$room['id']) $intervals[]=['start'=>gmdate('c',strtotime($b['check_in'].' UTC')),'end'=>gmdate('c',strtotime($b['check_out'].' UTC')),'status'=>$b['status']];
        foreach ($blocked as $b) if ($b['room_id']===$room['id']) { $s=new DateTimeImmutable($b['date'].' 00:00',new DateTimeZone('Asia/Ho_Chi_Minh')); $intervals[]=['start'=>$s->format(DATE_ATOM),'end'=>$s->modify('+1 day')->format(DATE_ATOM),'status'=>'BLOCKED']; }
        $row = current(array_filter($inventory, fn($i)=>$i['id']===$room['id']));
        $result[]=['id'=>$room['id'],'active'=>(bool)($row['is_active'] ?? false),'intervals'=>$intervals];
    }
    json_response(['connected'=>true,'onlineBooking'=>payment_enabled(),'overnight'=>['checkIn'=>config('overnight_check_in'),'checkOut'=>config('overnight_check_out')],'terms'=>config('booking_terms'),'rooms'=>$result]);
}

function create_booking_api(): never
{
    if (!payment_enabled()) throw new BookingException('Đặt phòng trực tuyến chưa mở. Vui lòng gọi 0357 907 153 hoặc nhắn Zalo để đặt phòng.', 503);
    $q = quote_booking(json_body()); $pdo = db(); $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT id,status,(status='ACTIVE') is_active FROM rooms WHERE id=? FOR UPDATE"); $lock->execute([$q['room']['id']]); $room = $lock->fetch();
        $packageLock = $pdo->prepare('SELECT id,name,timing_mode,duration_minutes,check_in_time,check_out_time,price,is_enabled FROM room_packages WHERE id=? AND room_id=? FOR UPDATE');
        $packageLock->execute([$q['packageId'],$q['room']['id']]); $freshPackage = $packageLock->fetch();
        if (!$freshPackage || !(bool)$freshPackage['is_enabled']) throw new BookingException('Gói giá vừa được thay đổi. Vui lòng chọn lại.', 409);
        $freshPackageDto=['id'=>$freshPackage['id'],'name'=>$freshPackage['name'],'mode'=>$freshPackage['timing_mode'],'durationMinutes'=>$freshPackage['duration_minutes']===null?null:(int)$freshPackage['duration_minutes'],'checkInTime'=>$freshPackage['check_in_time']===null?null:substr((string)$freshPackage['check_in_time'],0,5),'checkOutTime'=>$freshPackage['check_out_time']===null?null:substr((string)$freshPackage['check_out_time'],0,5),'price'=>(int)$freshPackage['price'],'enabled'=>true];
        [$freshStart,$freshEnd]=package_window($freshPackageDto,$q['start']->format('Y-m-d'),$freshPackageDto['mode']==='DURATION'?$q['start']->format('H:i'):'');
        $q['package']=$freshPackageDto;$q['start']=$freshStart;$q['end']=$freshEnd;$q['total']=(int)$freshPackageDto['price'];
        $q=array_replace($q,booking_package_snapshot($freshPackageDto));
        if (!$room || !$room['is_active']) throw new BookingException('Phòng đang tạm ngưng nhận khách.', 409);
        $conflict = $pdo->prepare("SELECT id FROM bookings WHERE room_id=? AND check_in<? AND check_out>? AND (status IN ('CONFIRMED','CHECKED_IN') OR (status='PENDING' AND hold_expires_at>UTC_TIMESTAMP())) LIMIT 1");
        $conflict->execute([$q['room']['id'],mysql_datetime($q['end']),mysql_datetime($q['start'])]);
        if ($conflict->fetch()) throw new BookingException('Phòng vừa được đặt trong khung giờ này. Bạn chọn giờ khác nhé.', 409);
        $blocked = $pdo->prepare('SELECT id FROM room_availability WHERE room_id=? AND available=0 AND `date` BETWEEN ? AND ? LIMIT 1');
        $blocked->execute([$q['room']['id'],$q['start']->format('Y-m-d'),$q['end']->modify('-1 second')->format('Y-m-d')]);
        if ($blocked->fetch()) throw new BookingException('Phòng tạm khóa trong khoảng thời gian này.', 409);
        $holds=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE guest_phone=? AND status='PENDING' AND hold_expires_at>UTC_TIMESTAMP()");$holds->execute([$q['phone']]);
        if ((int)$holds->fetchColumn()>=2) throw new BookingException('Bạn đang có đơn chờ thanh toán. Vui lòng hoàn tất hoặc đợi hết thời gian giữ phòng.',429);
        $booking=['id'=>uuid(),'bookingCode'=>'LANG-'.strtoupper(bin2hex(random_bytes(5))),'accessToken'=>bin2hex(random_bytes(32)),'orderCode'=>(int)(floor(microtime(true)*1000)*1000+random_int(0,999)),'holdExpiresAt'=>gmdate('Y-m-d H:i:s',time()+900),'totalPrice'=>$q['total']];
        $sql='INSERT INTO bookings(id,booking_code,room_id,check_in,check_out,stay_package,package_id,package_name_snapshot,package_mode_snapshot,package_duration_snapshot,package_check_in_snapshot,package_check_out_snapshot,package_price_snapshot,hold_expires_at,access_token,order_code,total_price,number_of_guests,guest_name,guest_email,guest_phone,guest_note,payment_method) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'payos\')';
        $pdo->prepare($sql)->execute([$booking['id'],$booking['bookingCode'],$q['room']['id'],mysql_datetime($q['start']),mysql_datetime($q['end']),$q['packageId'],$q['packageId'],$q['packageName'],$q['packageMode'],$q['packageDuration'],$q['packageCheckIn'],$q['packageCheckOut'],$q['packagePrice'],$booking['holdExpiresAt'],$booking['accessToken'],$booking['orderCode'],$q['total'],$q['guests'],$q['name'],$q['email'],$q['phone'],$q['note']]);
        $pdo->commit();
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    try {
        $payment=create_payment($booking);
        // Webhook co the den truoc lenh nay; chi bo sung QR/link, khong ha trang thai PAID.
        $pdo->prepare("INSERT INTO payments(id,booking_id,amount,payment_method,status,qr_code,checkout_url) VALUES(?,?,?,'payos','PENDING',?,?) ON DUPLICATE KEY UPDATE qr_code=VALUES(qr_code),checkout_url=VALUES(checkout_url)")->execute([uuid(),$booking['id'],$q['total'],$payment['qrCode'],$payment['checkoutUrl']]);
    } catch (Throwable $e) { json_response(['error'=>'Chưa lấy được mã QR. Phòng được giữ tối đa 15 phút để đối soát; vui lòng liên hệ Lặng trước khi đặt lại.','bookingCode'=>$booking['bookingCode']],502); }
    json_response(['bookingCode'=>$booking['bookingCode'],'token'=>$booking['accessToken']],201);
}

function lookup_booking_api(): never
{
    $body=json_body();$code=strtoupper(trim((string)($body['code']??'')));$token=(string)($body['token']??'');$phone=preg_replace('/[\s.\-]/','',(string)($body['phone']??''));
    if(!preg_match('/^LANG-[A-F0-9]{10}$/',$code)||(!$token&&!$phone))json_response(['error'=>'Vui lòng nhập mã đặt phòng và số điện thoại hợp lệ.'],400);
    $sql='SELECT b.*,r.name room_name,p.qr_code,p.checkout_url FROM bookings b JOIN rooms r ON r.id=b.room_id LEFT JOIN payments p ON p.booking_id=b.id WHERE b.booking_code=? AND '.($token?'b.access_token=?':'b.guest_phone=?').' LIMIT 1';
    $stmt=db()->prepare($sql);$stmt->execute([$code,$token?:$phone]);$b=$stmt->fetch();if(!$b)json_response(['error'=>'Không tìm thấy đơn khớp với thông tin đã nhập.'],404);
    $expired=$b['status']==='PENDING'&&strtotime($b['hold_expires_at'].' UTC')<=time();
    json_response(['bookingCode'=>$b['booking_code'],'room'=>$b['room_name'],'checkIn'=>gmdate('c',strtotime($b['check_in'].' UTC')),'checkOut'=>gmdate('c',strtotime($b['check_out'].' UTC')),'total'=>(int)$b['total_price'],'status'=>$expired?'EXPIRED':$b['status'],'paymentStatus'=>$b['payment_status'],'expires'=>gmdate('c',strtotime($b['hold_expires_at'].' UTC')),'qrCode'=>$expired?null:$b['qr_code'],'checkoutUrl'=>$expired?null:$b['checkout_url']]);
}

function payment_webhook_api(): never
{
    $payload=json_body();$data=$payload['data']??null;$signature=(string)($payload['signature']??'');
    if(!is_array($data)||!payos_verify($data,$signature))json_response(['error'=>'Invalid signature'],401);
    if(($data['code']??'')!=='00'||($data['currency']??'')!=='VND')json_response(['ok'=>true]);
    if(!isset($data['orderCode'],$data['amount'],$data['reference'])||!is_numeric($data['orderCode'])||!is_numeric($data['amount'])||!is_string($data['reference'])||$data['reference']==='')json_response(['error'=>'Invalid payment data'],400);
    $pdo=db();$pdo->beginTransaction();
    try{$stmt=$pdo->prepare('SELECT * FROM bookings WHERE order_code=? FOR UPDATE');$stmt->execute([(string)$data['orderCode']]);$b=$stmt->fetch();if(!$b){$pdo->commit();json_response(['ok'=>true]);}if((int)$data['amount']!==(int)$b['total_price'])throw new BookingException('Amount mismatch',400);
        if($b['payment_status']!=='PAID'){$conf=$pdo->prepare("SELECT id FROM bookings WHERE id<>? AND room_id=? AND check_in<? AND check_out>? AND (status IN ('CONFIRMED','CHECKED_IN') OR (status='PENDING' AND hold_expires_at>UTC_TIMESTAMP())) LIMIT 1");$conf->execute([$b['id'],$b['room_id'],$b['check_out'],$b['check_in']]);$valid=$b['status']==='PENDING'&&strtotime($b['hold_expires_at'].' UTC')>time()&&!$conf->fetch();$status=$valid?'CONFIRMED':'CANCELLED';$reason=$valid?null:'Đã nhận tiền sau thời hạn hoặc đơn đã hủy. Cần đối soát/hoàn tiền.';$pdo->prepare('UPDATE bookings SET payment_status=\'PAID\',status=?,transaction_id=? WHERE id=?')->execute([$status,(string)$data['reference'],$b['id']]);$pdo->prepare("INSERT INTO payments(id,booking_id,amount,payment_method,status,transaction_id,paid_at,failure_reason) VALUES(?,?,?,'payos','PAID',?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE status='PAID',transaction_id=VALUES(transaction_id),paid_at=VALUES(paid_at),failure_reason=VALUES(failure_reason)")->execute([uuid(),$b['id'],(int)$data['amount'],(string)$data['reference'],$reason]);}
        $pdo->commit();json_response(['ok'=>true]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function admin_bookings_api(): never
{
    require_admin();
    $rows=db()->query('SELECT b.*,r.name room_name,p.failure_reason FROM bookings b JOIN rooms r ON r.id=b.room_id LEFT JOIN payments p ON p.booking_id=b.id ORDER BY b.created_at DESC LIMIT 100')->fetchAll();
    json_response(['bookings'=>$rows,'csrf'=>csrf_token()]);
}
function update_booking_api(): never
{
    require_admin();require_csrf();$body=json_body();$id=(string)($body['id']??'');$status=(string)($body['status']??'');$pdo=db();$pdo->beginTransaction();
    try{$s=$pdo->prepare('SELECT * FROM bookings WHERE id=? FOR UPDATE');$s->execute([$id]);$b=$s->fetch();if(!$b)json_response(['error'=>'Không tìm thấy đơn.'],404);$allowed=['PENDING'=>['CANCELLED'],'CONFIRMED'=>['CHECKED_IN','CANCELLED'],'CHECKED_IN'=>['CHECKED_OUT']];if(!in_array($status,$allowed[$b['status']]??[],true))throw new BookingException('Thao tác không hợp lệ.',409);$pdo->prepare('UPDATE bookings SET status=? WHERE id=?')->execute([$status,$id]);$pdo->commit();json_response(['ok'=>true]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function admin_rooms_api():never
{
    require_admin();json_response(['rooms'=>array_values(room_repository()->allRooms()),'csrf'=>csrf_token()]);
}

function create_room_api():never
{
    require_admin();require_csrf();$room=room_repository()->create(json_body());json_response(['room'=>$room],201);
}

function update_room_api(string $roomId):never
{
    require_admin();require_csrf();$room=room_repository()->update($roomId,json_body());json_response(['room'=>$room]);
}

function delete_room_api(string $roomId):never
{
    require_admin();require_csrf();$paths=room_repository()->delete($roomId);foreach($paths as $path)delete_managed_upload((string)$path,$roomId);json_response(['ok'=>true]);
}

function upload_room_image_api(string $roomId):never
{
    require_admin();require_csrf();
    if(!isset($_FILES['image'])||!is_array($_FILES['image']))throw new BookingException('Vui lòng chọn một ảnh.',422);
    assert_room_image_limit(room_repository()->imageCount($roomId));$stored=store_room_upload($_FILES['image'],$roomId);
    try{$image=room_repository()->addImage($roomId,$stored['path'],(string)($_POST['caption']??''));}
    catch(Throwable $e){delete_managed_upload($stored['path'],$roomId);throw $e;}
    json_response(['image'=>$image],201);
}

function update_room_image_api(string $roomId,string $imageId):never
{
    require_admin();require_csrf();$image=room_repository()->updateImage($roomId,$imageId,json_body());json_response(['image'=>$image]);
}

function delete_room_image_api(string $roomId,string $imageId):never
{
    require_admin();require_csrf();$path=room_repository()->deleteImage($roomId,$imageId);delete_managed_upload($path,$roomId);json_response(['ok'=>true]);
}
