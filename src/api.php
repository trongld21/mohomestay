<?php

function handle_api(string $path, string $method): never
{
    try {
        if (str_starts_with($path, '/api/admin/')) {
            require_admin();
            $body = $method === 'PATCH' && (str_contains($path, '/door-codes/') || $path === '/api/admin/bookings') ? json_body() : [];
            $permission = admin_api_permission($path, $method, $body);
            require_permission($permission);
        }
        if ($path === '/api/admin/staff-email/test' && $method === 'POST') {
            require_csrf();
            if (empty(current_account()['root'])) throw new BookingException('Chỉ Super Admin được gửi email thử.', 403);
            $body = json_body();
            $event = (string)($body['event'] ?? 'BOOKED');
            if (!in_array($event, ['BOOKED', 'CHECKED_OUT', 'SURCHARGE'], true)) {
                throw new BookingException('Loại email thử không hợp lệ.', 422);
            }
            if ((int)($_SESSION['staff_mail_test_at'] ?? 0) > time() - 60) {
                throw new BookingException('Vui lòng chờ 60 giây giữa các lần gửi email thử.', 429);
            }
            $_SESSION['staff_mail_test_at'] = time();
            if (!send_staff_test_email($event)) {
                throw new BookingException('Mail server từ chối gửi. Kiểm tra MAIL_FROM và log mail hosting.', 503);
            }
            json_response(['message' => 'Mail server đã nhận email thử gửi tới mohomestay01@gmail.com. Kiểm tra Hộp thư đến và Spam.']);
        }
        if ($path === '/api/health' && $method === 'GET') json_response(['status'=>'ok','service'=>'mo-home-php']);
        if ($path === '/api/rooms/available-now' && $method === 'GET') {
            require_once APP_ROOT . '/src/home-availability.php';
            json_response(['roomIds' => home_available_room_ids(db())]);
        }
        if ($path === '/api/rooms' && $method === 'GET') json_response(['rooms'=>array_values(room_repository()->publicRooms())]);
        if ($method === 'GET' && preg_match('#^/api/rooms/([a-z0-9-]+)$#', $path, $matches)) {
            $room = room_repository()->findPublicBySlug($matches[1]);
            if (!$room) json_response(['error'=>'Không tìm thấy phòng.'], 404);
            json_response(['room'=>$room]);
        }
        if (str_starts_with($path, '/api/admin/room-operations')) {
            require_once APP_ROOT . '/src/room-operations-api.php';
            room_operations_api($path, $method);
        }
        if ($path === '/api/availability/packages' && $method === 'GET') {
            require_once APP_ROOT . '/src/availability.php';
            header('Cache-Control: no-store');
            $room = room_repository()->findById((string)($_GET['roomId'] ?? ''));
            if (!$room || $room['status'] !== 'ACTIVE') {
                throw new BookingException('Phòng không khả dụng.', 404);
            }
            $options = timeline_available_packages(db(), $room, (string)($_GET['date'] ?? ''), (string)($_GET['time'] ?? ''));
            json_response(['packages' => $options]);
        }
        if ($path === '/api/availability' && $method === 'GET') availability_api();
        if ($path === '/api/bookings' && $method === 'POST') create_booking_api();
        if ($path === '/api/bookings/lookup' && $method === 'POST') lookup_booking_api();
        if ($path === '/api/payments/webhook' && $method === 'POST') payment_webhook_api();
        if ($path === '/api/admin/manual-access' && $method === 'POST') admin_manual_access_api();
        if (preg_match('#^/api/admin/manual-access/([a-f0-9]{32})/retry$#', $path, $matches) && $method === 'POST') admin_manual_access_api($matches[1]);
        if ($method === 'POST' && $path === '/api/dev/payments/create') mock_payment_api('create');
        if ($method === 'POST' && $path === '/api/dev/payments/callback') mock_payment_api('callback');
        if ($path === '/api/admin/door-codes' && in_array($method, ['GET', 'POST'], true)) admin_door_codes_api($method);
        if (
            preg_match('#^/api/admin/door-codes/([a-f0-9]{32})$#', $path, $matches)
            && in_array($method, ['PATCH', 'DELETE'], true)
        ) {
            admin_door_codes_api($method, $matches[1]);
        }
        if (preg_match('#^/api/admin/booking-access/([a-f0-9]{32})/retry$#', $path, $matches) && $method === 'POST') admin_booking_access_retry($matches[1]);
        if ($path === '/api/admin/booking-rooms' && $method === 'GET') admin_rooms_api();
        if ($path === '/api/admin/rooms' && $method === 'GET') admin_rooms_api();
        if ($path === '/api/admin/rooms' && $method === 'POST') create_room_api();
        if (preg_match('#^/api/admin/rooms/([a-zA-Z0-9_-]+)/visibility$#', $path, $matches) && $method === 'PATCH') {
            require_admin();
            require_csrf();
            $body = json_body();
            $room = room_repository()->setVisibility($matches[1], (string)($body['status'] ?? ''));
            json_response(['room' => $room]);
        }
        if (preg_match('#^/api/admin/rooms/([a-zA-Z0-9_-]+)/images$#',$path,$matches) && $method === 'POST') upload_room_image_api($matches[1]);
        if (preg_match('#^/api/admin/rooms/([a-zA-Z0-9_-]+)/images/([a-zA-Z0-9_-]+)$#',$path,$matches) && $method === 'PATCH') update_room_image_api($matches[1],$matches[2]);
        if (preg_match('#^/api/admin/rooms/([a-zA-Z0-9_-]+)/images/([a-zA-Z0-9_-]+)$#',$path,$matches) && $method === 'DELETE') delete_room_image_api($matches[1],$matches[2]);
        if (preg_match('#^/api/admin/rooms/([a-zA-Z0-9_-]+)$#',$path,$matches) && $method === 'PATCH') update_room_api($matches[1]);
        if (preg_match('#^/api/admin/rooms/([a-zA-Z0-9_-]+)$#',$path,$matches) && $method === 'DELETE') delete_room_api($matches[1]);
        if ($path === '/api/admin/booking-overview' && $method === 'GET') {
            $rows = db()->query('SELECT status,payment_status,total_price,is_test,check_in,check_out FROM bookings')->fetchAll();
            json_response(['overview' => booking_overview($rows)]);
        }
        if ($method === 'GET' && preg_match('#^/api/admin/bookings/([a-f0-9]{32})/identity/(front|back)$#', $path, $matches)) admin_booking_identity_image($matches[1], $matches[2]);
        if ($path === '/api/admin/bookings' && $method === 'GET') admin_bookings_api();
        if ($method === 'POST' && preg_match('#^/api/admin/bookings/([a-f0-9]{32})/stay$#', $path, $matches)) {
            require_csrf();
            correct_booking_stay(db(), $matches[1], json_body());
            json_response(['ok' => true]);
        }
        if ($path === '/api/admin/bookings' && $method === 'POST') {
            require_admin();
            require_csrf();
            $body = json_body();
            if (($body['paymentStatus'] ?? 'UNPAID') === 'PAID') {
                require_permission('bookings.payment');
            }
            $booking = create_manual_booking(db(), $body);
            try_dispatch_staff_notifications(db(), $booking['id']);
            json_response(['bookingCode' => $booking['booking_code']], 201);
        }
        if ($method === 'POST' && preg_match('#^/api/admin/bookings/([a-f0-9]{32})/payment$#', $path, $matches)) {
            require_admin();
            require_csrf();
            $body = json_body();
            confirm_manual_payment(db(), $matches[1], (string)($body['paymentMethod'] ?? ''));
            json_response(['ok' => true]);
        }
        if ($path === '/api/admin/bookings' && $method === 'PATCH') update_booking_api();
        json_response(['error'=>'Không tìm thấy API.'], 404);
    } catch (BookingException $e) { json_response(['error'=>$e->getMessage()], $e->httpStatus); }
    catch (Throwable $e) { error_log($e->__toString()); json_response(['error'=>'Hệ thống đang bận. Vui lòng thử lại hoặc liên hệ Mơ.'], 503); }
}

function availability_api(): never
{
    require_once APP_ROOT . '/src/availability.php';
    $days = filter_var($_GET['days'] ?? 31, FILTER_VALIDATE_INT);
    if ($days === false) throw new BookingException('Khoảng thời gian không hợp lệ.', 400);
    $snapshot = availability_snapshot(db(), room_repository()->publicRooms(), (string)($_GET['date'] ?? ''), $days);
    json_response($snapshot + [
        'onlineBooking' => payment_enabled(),
        'overnight' => ['checkIn' => config('overnight_check_in'), 'checkOut' => config('overnight_check_out')],
        'terms' => config('booking_terms'),
    ]);
}

function create_booking_api(): never
{
    if (!payment_enabled()) throw new BookingException('Đặt phòng trực tuyến chưa mở. Vui lòng gọi 0357 907 153 hoặc nhắn Zalo để đặt phòng.', 503);
    $q = quote_booking(booking_form_body());
    $identity = read_booking_identity_uploads($_FILES);
    $pdo = db(); $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT id,status,max_guests,discount_percent,(status='ACTIVE') is_active FROM rooms WHERE id=? FOR UPDATE");
        $lock->execute([$q['room']['id']]);
        $room = $lock->fetch();
        if ($q['guests'] > (int)($room['max_guests'] ?? 0)) throw new BookingException('Sức chứa phòng vừa được cập nhật. Vui lòng chọn lại số khách.', 409);
        $packageLock = $pdo->prepare('SELECT id,name,timing_mode,duration_minutes,check_in_time,check_out_time,price,discount_percent,is_enabled FROM room_packages WHERE id=? AND room_id=? FOR UPDATE');
        $packageLock->execute([$q['packageId'], $q['room']['id']]);
        $freshPackage = $packageLock->fetch();
        if (!$freshPackage || !(bool)$freshPackage['is_enabled']) throw new BookingException('Gói giá vừa được thay đổi. Vui lòng chọn lại.', 409);
        $freshPackageDto = locked_booking_package($room, $freshPackage);
        [$freshStart,$freshEnd]=bookable_package_window($freshPackageDto,$q['requestedDate'],$freshPackageDto['mode']==='DURATION'?$q['start']->format('H:i'):'');
        $q['package'] = $freshPackageDto;
        $q['start'] = $freshStart;
        $q['end'] = $freshEnd;
        $q = array_replace($q, booking_package_snapshot($freshPackageDto),
            booking_extra_hours_details($freshPackageDto, $freshEnd, $q['extraHours']));
        if (!$room || !$room['is_active']) throw new BookingException('Phòng đang tạm ngưng nhận khách.', 409);
        assert_room_inventory($pdo, $q['room']['id'], mysql_datetime($q['start']), mysql_datetime($q['end']));
        $blocked = $pdo->prepare('SELECT id FROM room_availability WHERE room_id=? AND available=0 AND `date` BETWEEN ? AND ? LIMIT 1');
        $blocked->execute([$q['room']['id'],$q['start']->format('Y-m-d'),$q['end']->modify('-1 second')->format('Y-m-d')]);
        if ($blocked->fetch()) throw new BookingException('Phòng tạm khóa trong khoảng thời gian này.', 409);
        $holds=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE guest_phone=? AND status='PENDING' AND hold_expires_at>UTC_TIMESTAMP()");$holds->execute([$q['phone']]);
        if ((int)$holds->fetchColumn()>=2) throw new BookingException('Bạn đang có đơn chờ thanh toán. Vui lòng hoàn tất hoặc đợi hết thời gian giữ phòng.',429);
        $booking=['id'=>uuid(),'bookingCode'=>'MO-'.strtoupper(bin2hex(random_bytes(5))),'accessToken'=>bin2hex(random_bytes(32)),'orderCode'=>(int)(floor(microtime(true)*1000)*1000+random_int(0,999)),'holdExpiresAt'=>gmdate('Y-m-d H:i:s',time()+900),'totalPrice'=>$q['total']];
        $sql='INSERT INTO bookings(id,booking_code,room_id,check_in,check_out,stay_package,package_id,package_name_snapshot,package_mode_snapshot,package_duration_snapshot,package_check_in_snapshot,package_check_out_snapshot,package_price_snapshot,hold_expires_at,access_token,order_code,total_price,number_of_guests,guest_name,guest_email,guest_phone,guest_note,payment_method) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'payos\')';
        $pdo->prepare($sql)->execute([$booking['id'],$booking['bookingCode'],$q['room']['id'],mysql_datetime($q['start']),mysql_datetime($q['end']),$q['packageId'],$q['packageId'],$q['packageName'],$q['packageMode'],$q['packageDuration'],$q['packageCheckIn'],$q['packageCheckOut'],$q['packagePrice'],$booking['holdExpiresAt'],$booking['accessToken'],$booking['orderCode'],$q['total'],$q['guests'],$q['name'],$q['email'],$q['phone'],$q['note']]);
        if ($q['extraHours'] > 0) {
            $pdo->prepare('UPDATE bookings SET extra_hours=?,extra_hours_amount=? WHERE id=?')
                ->execute([$q['extraHours'], $q['extraHoursAmount'], $booking['id']]);
        }
        save_booking_identity($pdo, $booking['id'], $identity);
        $pdo->commit();
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    try {
        $payment=create_payment($booking);
        // Webhook co the den truoc lenh nay; chi bo sung QR/link, khong ha trang thai PAID.
        $pdo->prepare("INSERT INTO payments(id,booking_id,amount,payment_method,status,qr_code,checkout_url) VALUES(?,?,?,'payos','PENDING',?,?) ON DUPLICATE KEY UPDATE qr_code=VALUES(qr_code),checkout_url=VALUES(checkout_url)")->execute([uuid(),$booking['id'],$q['total'],$payment['qrCode'],$payment['checkoutUrl']]);
    } catch (Throwable $e) { json_response(['error'=>'Chưa lấy được mã QR. Phòng được giữ tối đa 15 phút để đối soát; vui lòng liên hệ Mơ trước khi đặt lại.','bookingCode'=>$booking['bookingCode']],502); }
    remember_payment_return($booking);
    json_response(['bookingCode'=>$booking['bookingCode'],'token'=>$booking['accessToken'],'orderCode'=>(string)$booking['orderCode']],201);
}

function lookup_booking_api(): never
{
    $body=json_body();$code=strtoupper(trim((string)($body['code']??'')));$token=(string)($body['token']??'');$phone=preg_replace('/[\s.\-]/','',(string)($body['phone']??''));$orderCode=preg_replace('/\D/','',(string)($body['orderCode']??''));
    $validCode=(bool)preg_match('/^(?:MO|LANG)-[A-F0-9]{10}$/',$code);$returnRecovery=!$validCode&&preg_match('/^[a-f0-9]{64}$/',$token)&&preg_match('/^\d{10,20}$/',$orderCode);
    if(!$returnRecovery&&(!$validCode||(!$token&&!$phone)))json_response(['error'=>'Vui lòng nhập mã đặt phòng và số điện thoại hợp lệ.'],400);
    $where=$returnRecovery?'b.access_token=? AND b.order_code=?':'b.booking_code=? AND '.($token?'b.access_token=?':'b.guest_phone=?');
    $sql='SELECT b.*,r.name room_name,p.qr_code,p.checkout_url FROM bookings b JOIN rooms r ON r.id=b.room_id LEFT JOIN payments p ON p.booking_id=b.id WHERE '.$where.' LIMIT 1';
    $stmt=db()->prepare($sql);$stmt->execute($returnRecovery?[$token,$orderCode]:[$code,$token?:$phone]);$b=$stmt->fetch();if(!$b)json_response(['error'=>'Không tìm thấy đơn khớp với thông tin đã nhập.'],404);
    if ($b['status'] === 'PENDING') {
        refresh_payment_cancellation(db(), $b);
        $stmt->execute($returnRecovery ? [$token, $orderCode] : [$code, $token ?: $phone]);
        $b = $stmt->fetch();
    }
    if(empty($b['is_test'])&&$b['status']==='CONFIRMED'&&in_array($b['confirmation_email_status']??'PENDING',['PENDING','FAILED'],true)){
        dispatch_confirmation_email((string)$b['id']);
        $email=db()->prepare('SELECT confirmation_email_status FROM bookings WHERE id=?');$email->execute([$b['id']]);$b['confirmation_email_status']=$email->fetchColumn()?:'PENDING';
    }
    $b = booking_lifecycle($b);
    $expired=$b['status']==='PENDING'&&strtotime($b['hold_expires_at'].' UTC')<=time();
    if ($b['status'] !== 'PENDING' || $b['payment_status'] === 'PAID') {
        $b['qr_code'] = null;
        $b['checkout_url'] = null;
    }
    $response = [
        'bookingCode' => $b['booking_code'],
        'room' => $b['room_name'],
        'roomImage' => room_repository()->allRooms()[$b['room_id']]['image'] ?? '',
        'packageName' => $b['package_name_snapshot'] ?: $b['stay_package'],
        'guests' => (int)$b['number_of_guests'],
        'arrival' => $expired ? null : booking_arrival_details($b, db()),
        'checkIn' => gmdate('c', strtotime($b['check_in'] . ' UTC')),
        'checkOut' => gmdate('c', strtotime($b['check_out'] . ' UTC')),
        'total' => (int)$b['total_price'],
        'extraHours' => (int)($b['extra_hours'] ?? 0),
        'extraHoursAmount' => (int)($b['extra_hours_amount'] ?? 0),
        'status' => $expired ? 'EXPIRED' : $b['status'],
        'paymentStatus' => $b['payment_status'],
        'emailStatus' => $b['confirmation_email_status'] ?? 'PENDING',
        'expires' => gmdate('c', strtotime($b['hold_expires_at'] . ' UTC')),
        'qrCode' => $expired ? null : $b['qr_code'],
        'checkoutUrl' => $expired ? null : $b['checkout_url'],
    ];
    if (!empty($b['is_test']) && dev_reload_enabled() && env_value('APP_ENV') !== 'production' && is_admin()) {
        $response['mockPayment'] = ['orderCode' => (string)$b['order_code'], 'amount' => (int)$b['total_price']];
    }
    json_response($response);
}

function payment_webhook_api(): never
{
    $payload=json_body();$data=$payload['data']??null;$signature=(string)($payload['signature']??'');
    if(!is_array($data)||!payos_verify($data,$signature))json_response(['error'=>'Invalid signature'],401);
    if(($data['code']??'')!=='00'||($data['currency']??'')!=='VND')json_response(['ok'=>true]);
    if(!isset($data['orderCode'],$data['amount'],$data['reference'])||!is_numeric($data['orderCode'])||!is_numeric($data['amount'])||!is_string($data['reference'])||$data['reference']==='')json_response(['error'=>'Invalid payment data'],400);
    if (apply_checkout_payment_callback(db(), $data)) json_response(['ok' => true]);
    $booking = apply_payment_callback(db(), $data);
    if ($booking) {
        try_dispatch_staff_notifications(db(), (string)$booking['id']);
        dispatch_confirmation_email((string)$booking['id']);
    }
    json_response(['ok'=>true]);
}

function admin_bookings_api(): never
{
    require_admin();
    $rows = db()->query(
        'SELECT b.*,r.name room_name,p.failure_reason,(SELECT COUNT(*) FROM booking_identity_images i WHERE i.booking_id=b.id) identity_image_count FROM bookings b '
        . 'JOIN rooms r ON r.id=b.room_id LEFT JOIN payments p ON p.booking_id=b.id '
        . 'ORDER BY b.created_at DESC,b.id'
    )->fetchAll();
    $now = time();
    $rooms = room_repository()->allRooms();
    $rows = array_map(function ($booking) use ($now, $rooms) {
        $booking['room_image'] = $rooms[$booking['room_id']]['image'] ?? '/images/white-illustration.jpg';
        return booking_lifecycle($booking, $now);
    }, $rows);
    json_response(['bookings'=>$rows,'csrf'=>csrf_token()]);
}
function update_booking_api(): never
{
    require_admin();
    require_csrf();
    $body = json_body();
    $id = (string)($body['id'] ?? '');
    $status = (string)($body['status'] ?? '');
    if ($status === 'CHECKED_OUT') {
        require_permission('availability.checkout');
        $charge = confirm_room_checkout(db(), $id, (string)current_account()['id'], null, ($body['collectSurcharge'] ?? false) === true);
        try_dispatch_staff_notifications(db(), $id);
        json_response(['ok' => true, 'charge' => checkout_charge_dto($charge)]);
    }
    update_booking_lifecycle(db(), $id, $status);
    if ($status === 'CANCELLED') sync_booking_access($id);
    json_response(['ok'=>true]);
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
    require_admin();require_csrf();$room=room_repository()->update($roomId, json_body(), can_use('rooms.visibility'));json_response(['room'=>$room]);
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
