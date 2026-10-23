<?php

function payos_normalize(array $data): string
{
    ksort($data);
    $pairs = [];
    foreach ($data as $key => $value) {
        if ($value === null || $value === 'null' || $value === 'undefined') $value = '';
        if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pairs[] = $key . '=' . (string)$value;
    }
    return implode('&', $pairs);
}
function payos_signature(array $data): string { return hash_hmac('sha256', payos_normalize($data), (string)config('payos.checksum_key')); }
function payos_verify(array $data, string $signature): bool { return (bool)preg_match('/^[a-f0-9]{64}$/i', $signature) && hash_equals(payos_signature($data), strtolower($signature)); }

function remember_payment_return(array $booking): void
{
    if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') session_start();
    $_SESSION['payment_returns'] ??= [];
    $now = time();
    foreach ($_SESSION['payment_returns'] as $orderCode => $entry) {
        if (($entry['expires'] ?? 0) < $now) unset($_SESSION['payment_returns'][$orderCode]);
    }
    $_SESSION['payment_returns'][(string)$booking['orderCode']] = [
        'code'=>(string)$booking['bookingCode'],
        'token'=>(string)$booking['accessToken'],
        'expires'=>$now + 86400,
    ];
}

function payment_return_credentials(array $query): array
{
    $orderCode = preg_replace('/\D/', '', (string)($query['orderCode'] ?? ''));
    $entry = $_SESSION['payment_returns'][$orderCode] ?? null;
    if (!is_array($entry) || ($entry['expires'] ?? 0) < time()) return ['code'=>'','token'=>''];
    $code = strtoupper((string)($entry['code'] ?? ''));
    $token = (string)($entry['token'] ?? '');
    if (!preg_match('/^(?:MO|LANG)-[A-F0-9]{10}$/', $code) || !preg_match('/^[a-f0-9]{64}$/', $token)) return ['code'=>'','token'=>''];
    return ['code'=>$code,'token'=>$token];
}

function payos_payment_status(string $orderCode): array
{
    if (!preg_match('/^\d{10,20}$/', $orderCode)) {
        throw new RuntimeException('Mã thanh toán không hợp lệ.');
    }
    $ch = curl_init('https://api-merchant.payos.vn/v2/payment-requests/' . $orderCode);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'x-client-id: ' . config('payos.client_id'),
            'x-api-key: ' . config('payos.api_key'),
        ],
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $result = json_decode((string)$raw, true);
    if ($status !== 200 || ($result['code'] ?? '') !== '00' || !is_array($result['data'] ?? null)) {
        throw new RuntimeException('Chưa kiểm tra được trạng thái thanh toán.');
    }
    return $result['data'];
}

function refresh_payment_cancellation(PDO $pdo, array $booking, ?callable $fetch = null): void
{
    if ($booking['status'] !== 'PENDING' || $booking['payment_status'] === 'PAID'
        || !empty($booking['is_test']) || ($booking['payment_method'] ?? '') !== 'payos') {
        return;
    }
    $key = (string)$booking['id'];
    $now = time();
    $_SESSION['payment_status_checked'] ??= [];
    foreach ($_SESSION['payment_status_checked'] as $id => $checkedAt) {
        if ($checkedAt <= $now - 30) unset($_SESSION['payment_status_checked'][$id]);
    }
    if (isset($_SESSION['payment_status_checked'][$key])) return;
    $_SESSION['payment_status_checked'][$key] = $now;
    try {
        $remote = ($fetch ?? 'payos_payment_status')((string)$booking['order_code']);
    } catch (Throwable $error) {
        // A provider outage must not cancel a reservation or imply that it was paid.
        error_log('Payment status check unavailable for booking ' . $key);
        return;
    }
    if (($remote['status'] ?? '') !== 'CANCELLED'
        || (string)($remote['orderCode'] ?? '') !== (string)$booking['order_code']
        || !isset($remote['amount'], $remote['amountPaid'])
        || (float)$remote['amount'] !== (float)$booking['total_price']
        || (float)$remote['amountPaid'] !== 0.0) {
        return;
    }
    // Conditional update also protects a payment committed while payOS was queried.
    $stmt = $pdo->prepare("UPDATE bookings SET status='CANCELLED' WHERE id=? AND status='PENDING' AND payment_status IN ('UNPAID','PENDING','FAILED')");
    $stmt->execute([$key]);
}

function create_payment(array $booking): array
{
    $url = rtrim((string)config('app_url'), '/') . '/payment';
    $fields = ['amount'=>(int)$booking['totalPrice'],'cancelUrl'=>$url,'description'=>'MO'.substr($booking['bookingCode'], -5),'orderCode'=>(int)$booking['orderCode'],'returnUrl'=>$url];
    $payload = $fields + ['expiredAt'=>strtotime($booking['holdExpiresAt'] . ' UTC'),'signature'=>payos_signature($fields)];
    $ch = curl_init('https://api-merchant.payos.vn/v2/payment-requests');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-client-id: '.config('payos.client_id'),'x-api-key: '.config('payos.api_key')]]);
    $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $result = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300 || ($result['code'] ?? '') !== '00' || empty($result['data']['qrCode']) || !payos_verify($result['data'], (string)($result['signature'] ?? ''))) throw new RuntimeException('Không thể tạo liên kết thanh toán đã xác minh.');
    $data = $result['data'];
    if ((int)$data['amount'] !== (int)$booking['totalPrice'] || (int)$data['orderCode'] !== (int)$booking['orderCode']) throw new RuntimeException('Thông tin thanh toán không khớp.');
    $host = parse_url((string)$data['checkoutUrl'], PHP_URL_HOST);
    if ($host !== 'pay.payos.vn') throw new RuntimeException('Địa chỉ thanh toán không hợp lệ.');
    return ['qrCode'=>(string)$data['qrCode'],'checkoutUrl'=>(string)$data['checkoutUrl']];
}
