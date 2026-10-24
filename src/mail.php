<?php

declare(strict_types=1);

function booking_mail_local_time(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))
        ->format('H:i, d/m/Y');
}

function build_booking_confirmation_email(array $booking): array
{
    $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $code = (string)$booking['booking_code'];
    $name = (string)$booking['guest_name'];
    $room = (string)$booking['room_name'];
    $checkIn = booking_mail_local_time((string)$booking['check_in']);
    $checkOut = booking_mail_local_time((string)$booking['check_out']);
    $guests = (int)$booking['number_of_guests'];
    $total = number_format((int)$booking['total_price'], 0, ',', '.') . 'đ';
    $doorHtml = '<p>Mơ chưa có mã mở cửa phù hợp cho thời gian nhận phòng của bạn. Vui lòng liên hệ Mơ để được hướng dẫn.</p>';
    if (!empty($booking['door_codes'])) {
        $doorHtml = !empty($booking['private_door_code'])
            ? '<h2 class="text-base font-semibold" style="margin:12px 0 8px;font-size:16px">Mã mở cổng của bạn</h2>'
            : '<h2 class="text-base font-semibold" style="margin:12px 0 8px;font-size:16px">Mã mở cửa</h2>';
        foreach ($booking['door_codes'] as $door) {
            $doorHtml .= '<div class="rounded-xl border-2 bg-stone-50 p-3 text-center" '
                . 'style="margin:8px 0;padding:12px;border:2px solid #d8c3b2;border-radius:12px;background:#faf5ee;text-align:center">'
                . '<strong class="block break-all text-4xl font-extrabold tracking-widest" '
                . 'style="display:block;font-family:Arial,sans-serif;font-size:36px;font-weight:800;line-height:1.3;letter-spacing:3px;color:#70432c;word-break:break-all">'
                . $escape($door['code']) . '</strong>'
                . '</div>';
        }

    }
    $subject = 'Mơ Home xác nhận đặt phòng ' . $code;
    $html = '<!doctype html><html lang="vi"><body style="margin:0;background:#f7f3ed;color:#3e322b;font-family:Arial,sans-serif">'
        .'<div style="max-width:620px;margin:0 auto;padding:12px"><div style="background:#fff;border:1px solid #e7e0d7;border-radius:14px;padding:16px">'
        .'<p style="margin:0 0 8px;color:#70432c;font-size:12px;letter-spacing:1.5px">MƠ HOME · ĐẶT PHÒNG THÀNH CÔNG</p>'
        .'<h1 class="whitespace-nowrap text-xl" style="margin:0 0 8px;font-size:20px;white-space:nowrap">Hẹn gặp bạn tại Mơ!</h1>'
        .'<p>Chào '.$escape($name).', Mơ đã xác nhận thanh toán.</p>'
        .'<table role="presentation" style="width:100%;border-collapse:collapse;margin:12px 0;font-size:14px">'
        .'<tr><td style="padding:6px 0;border-bottom:1px solid #eee">Mã đặt phòng</td><td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right"><strong>'.$escape($code).'</strong></td></tr>'
        .'<tr><td style="padding:6px 0;border-bottom:1px solid #eee">Phòng</td><td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right"><strong>'.$escape($room).'</strong></td></tr>'
        .'<tr><td style="padding:6px 0;border-bottom:1px solid #eee">Nhận phòng</td><td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right">'.$escape($checkIn).'</td></tr>'
        .'<tr><td style="padding:6px 0;border-bottom:1px solid #eee">Trả phòng</td><td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right">'.$escape($checkOut).'</td></tr>'
        .'<tr><td style="padding:6px 0;border-bottom:1px solid #eee">Số khách</td><td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right">'.$guests.'</td></tr>'
        .'<tr><td style="padding:6px 0">Đã thanh toán</td><td style="padding:6px 0;text-align:right"><strong>'.$escape($total).'</strong></td></tr>'
        .'</table>'.$doorHtml
        .'<h2 style="margin:10px 0 6px;font-size:16px">Wi-Fi tại Mơ</h2>'
        .'<p style="margin:6px 0">Tên Wi-Fi: <strong>Mơ Home</strong></p>'
        .'<p style="margin:6px 0 10px">Mật khẩu: <strong style="font-family:Arial,sans-serif;letter-spacing:1px">82828282</strong></p>'
        .(!empty($booking['generated_door_code']) ? '<p style="margin:8px 0;font-size:12px">Mã dùng được nhiều lần trong thời gian hiệu lực, bạn có thể ra ngoài rồi vào lại. Cần dùng lần đầu trong 24 giờ từ lúc mã bắt đầu hiệu lực để kích hoạt. Nhập mã rồi nhấn #.</p>' : '<p style="margin:8px 0;font-size:12px">Mã dùng được nhiều lần trong thời gian hiệu lực, bạn có thể ra ngoài rồi vào lại. Nhập mã rồi nhấn #. Giữ riêng mã cổng trong thời gian lưu trú.</p>')
        .'<p style="margin:10px 0 0;padding:10px;font-size:13px;line-height:1.5;background:#edf4e9;border-radius:8px">Mơ sẽ liên hệ riêng để hướng dẫn nhận phòng. Cần hỗ trợ, gọi <a href="tel:0357907153" style="color:#70432c">0357 907 153</a>.</p>'
        .'</div></div></body></html>';
    return ['subject'=>$subject,'html'=>$html];
}

function send_booking_confirmation_email(array $booking, ?callable $transport = null): bool
{
    $to = trim((string)($booking['guest_email'] ?? ''));
    $from = trim((string)config('mail.from', ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
    $replyTo = trim((string)config('mail.reply_to', $from));
    if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $replyTo = $from;
    $message = build_booking_confirmation_email($booking);
    $subject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($message['subject'], 'UTF-8') : $message['subject'];
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: Mơ Home <'.$from.'>',
        'Reply-To: '.$replyTo,
        'X-Mailer: PHP/'.PHP_VERSION,
    ];
    $transport ??= static fn(string $recipient,string $mailSubject,string $body,array $mailHeaders): bool => mail($recipient,$mailSubject,$body,implode("\r\n",$mailHeaders));
    return (bool)$transport($to,$subject,$message['html'],$headers);
}

function dispatch_confirmation_email(string $bookingId, ?callable $transport = null): bool
{
    $test = db()->prepare('SELECT * FROM bookings WHERE id=?');
    $test->execute([$bookingId]);
    if (!empty(($test->fetch() ?: [])['is_test'])) return false;
    // Keep the message pending until DirectAdmin has a valid sender configured.
    // This avoids consuming all retry attempts during deployment.
    if (!filter_var(trim((string)config('mail.from', '')), FILTER_VALIDATE_EMAIL)) return false;

    $access = null;
    if (config('ttlock.enabled')) {
        $access = sync_booking_access($bookingId);
        // Never fall back to shared/manual codes while automatic provisioning is enabled.
        if (!$access) return false;
    }
    $pdo = db();
    $claim = $pdo->prepare("UPDATE bookings SET confirmation_email_status='SENDING',confirmation_email_attempts=confirmation_email_attempts+1,confirmation_email_error=NULL WHERE id=? AND status IN ('CONFIRMED','CHECKED_IN') AND payment_status='PAID' AND guest_email<>'' AND confirmation_email_status IN ('PENDING','FAILED') AND confirmation_email_attempts<3");
    $claim->execute([$bookingId]);
    if ($claim->rowCount() !== 1) return false;
    $stmt = $pdo->prepare('SELECT b.*,r.name room_name FROM bookings b JOIN rooms r ON r.id=b.room_id WHERE b.id=? LIMIT 1');
    $stmt->execute([$bookingId]);$booking=$stmt->fetch();
    if (!$booking) return false;
    try {
        $booking['door_codes'] = $access ? [$access] : booking_door_codes((string)$booking['check_in']);
        $booking['private_door_code'] = $access !== null;
        $booking['generated_door_code'] = ($access['code_mode'] ?? '') === 'GENERATED';
        $sent = send_booking_confirmation_email($booking, $transport);
        $pdo->prepare("UPDATE bookings SET confirmation_email_status=?,confirmation_email_sent_at=IF(?='SENT',UTC_TIMESTAMP(),confirmation_email_sent_at),confirmation_email_error=? WHERE id=?")
            ->execute([$sent?'SENT':'FAILED',$sent?'SENT':'FAILED',$sent?null:'MAIL_FROM chưa cấu hình hoặc máy chủ mail từ chối thư.',$bookingId]);
        return $sent;
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE bookings SET confirmation_email_status='FAILED',confirmation_email_error=? WHERE id=?")->execute([mb_substr($e->getMessage(),0,500),$bookingId]);
        error_log($e->__toString());
        return false;
    }
}
