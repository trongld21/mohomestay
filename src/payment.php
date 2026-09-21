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
function create_payment(array $booking): array
{
    $url = rtrim((string)config('app_url'), '/') . '/payment?code=' . rawurlencode($booking['bookingCode']) . '#' . $booking['accessToken'];
    $fields = ['amount'=>(int)$booking['totalPrice'],'cancelUrl'=>$url,'description'=>'LANG'.substr($booking['bookingCode'], -5),'orderCode'=>(int)$booking['orderCode'],'returnUrl'=>$url];
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
