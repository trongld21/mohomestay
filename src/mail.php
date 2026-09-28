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
    $package = (string)($booking['package_name_snapshot'] ?: $booking['stay_package']);
    $checkIn = booking_mail_local_time((string)$booking['check_in']);
    $checkOut = booking_mail_local_time((string)$booking['check_out']);
    $guests = (int)$booking['number_of_guests'];
    $total = number_format((int)$booking['total_price'], 0, ',', '.') . 'đ';
    $subject = 'Mơ Home xác nhận đặt phòng ' . $code;
    $html = '<!doctype html><html lang="vi"><body style="margin:0;background:#f7f3ed;color:#3e322b;font-family:Arial,sans-serif">'
        .'<div style="max-width:620px;margin:0 auto;padding:32px 20px"><div style="background:#fff;border:1px solid #e7e0d7;border-radius:14px;padding:32px">'
        .'<p style="margin:0 0 8px;color:#70432c;font-size:12px;letter-spacing:1.5px">MƠ HOME · ĐẶT PHÒNG THÀNH CÔNG</p>'
        .'<h1 style="margin:0 0 16px;font-size:28px">Hẹn gặp bạn tại Mơ!</h1>'
        .'<p>Chào '.$escape($name).', thanh toán của bạn đã được xác nhận. Dưới đây là thông tin đặt phòng:</p>'
        .'<table role="presentation" style="width:100%;border-collapse:collapse;margin:22px 0">'
        .'<tr><td style="padding:10px 0;border-bottom:1px solid #eee">Mã đặt phòng</td><td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right"><strong>'.$escape($code).'</strong></td></tr>'
        .'<tr><td style="padding:10px 0;border-bottom:1px solid #eee">Phòng</td><td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right"><strong>'.$escape($room).'</strong></td></tr>'
        .'<tr><td style="padding:10px 0;border-bottom:1px solid #eee">Gói</td><td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right">'.$escape($package).'</td></tr>'
        .'<tr><td style="padding:10px 0;border-bottom:1px solid #eee">Nhận phòng</td><td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right">'.$escape($checkIn).'</td></tr>'
        .'<tr><td style="padding:10px 0;border-bottom:1px solid #eee">Trả phòng</td><td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right">'.$escape($checkOut).'</td></tr>'
        .'<tr><td style="padding:10px 0;border-bottom:1px solid #eee">Số khách</td><td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right">'.$guests.'</td></tr>'
        .'<tr><td style="padding:10px 0">Đã thanh toán</td><td style="padding:10px 0;text-align:right"><strong>'.$escape($total).'</strong></td></tr>'
        .'</table><p style="padding:14px;background:#edf4e9;border-radius:8px">Mơ sẽ liên hệ riêng để hướng dẫn nhận phòng. Cần hỗ trợ, gọi <a href="tel:0357907153" style="color:#70432c">0357 907 153</a>.</p>'
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

function dispatch_confirmation_email(string $bookingId): bool
{
    // Keep the message pending until DirectAdmin has a valid sender configured.
    // This avoids consuming all retry attempts during deployment.
    if (!filter_var(trim((string)config('mail.from', '')), FILTER_VALIDATE_EMAIL)) return false;

    $pdo = db();
    $claim = $pdo->prepare("UPDATE bookings SET confirmation_email_status='SENDING',confirmation_email_attempts=confirmation_email_attempts+1,confirmation_email_error=NULL WHERE id=? AND status='CONFIRMED' AND guest_email<>'' AND confirmation_email_status IN ('PENDING','FAILED') AND confirmation_email_attempts<3");
    $claim->execute([$bookingId]);
    if ($claim->rowCount() !== 1) return false;
    $stmt = $pdo->prepare('SELECT b.*,r.name room_name FROM bookings b JOIN rooms r ON r.id=b.room_id WHERE b.id=? LIMIT 1');
    $stmt->execute([$bookingId]);$booking=$stmt->fetch();
    if (!$booking) return false;
    try {
        $sent = send_booking_confirmation_email($booking);
        $pdo->prepare("UPDATE bookings SET confirmation_email_status=?,confirmation_email_sent_at=IF(?='SENT',UTC_TIMESTAMP(),confirmation_email_sent_at),confirmation_email_error=? WHERE id=?")
            ->execute([$sent?'SENT':'FAILED',$sent?'SENT':'FAILED',$sent?null:'MAIL_FROM chưa cấu hình hoặc máy chủ mail từ chối thư.',$bookingId]);
        return $sent;
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE bookings SET confirmation_email_status='FAILED',confirmation_email_error=? WHERE id=?")->execute([mb_substr($e->getMessage(),0,500),$bookingId]);
        error_log($e->__toString());
        return false;
    }
}
