<?php

declare(strict_types=1);

function booking_arrival_details(array $booking, PDO $pdo): ?array
{
    if (($booking['payment_status'] ?? '') !== 'PAID'
        || !in_array($booking['status'] ?? '', ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT'], true)) {
        return null;
    }

    $httpsUrl = static function (string $name): string {
        $url = trim((string)env_value($name, ''));
        return filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : '';
    };
    $test = !empty($booking['is_test']);
    $localAdminTest = $test && dev_reload_enabled() && env_value('APP_ENV') !== 'production' && is_admin()
        && in_array($booking['status'], ['CONFIRMED', 'CHECKED_IN'], true);
    $codes = [];
    if ((booking_access_allowed($booking) || $localAdminTest) && strtotime($booking['check_out'] . ' UTC') > time()) {
        if (config('ttlock.enabled')) {
            // Only read an already provisioned code; lookup must not create a new PIN.
            $stmt = $pdo->prepare("SELECT code,starts_at,ends_at FROM booking_access_codes WHERE booking_id=? AND sync_status='READY' AND ends_at>UTC_TIMESTAMP()");
            $stmt->execute([$booking['id']]);
            $codes = $stmt->fetchAll();
        } else {
            $codes = booking_door_codes((string)$booking['check_in'], $pdo);
            $codes = array_values(array_filter($codes, static fn(array $code): bool => strtotime($code['ends_at'] . ' UTC') > time()));
        }
    }

    return [
        'isTest' => $test,
        'guideUrl' => $httpsUrl('CHECKIN_GUIDE_URL'),
        'rulesUrl' => $httpsUrl('HOUSE_RULES_URL'),
        'rules' => trim((string)config('booking_terms', '')),
        'wifiName' => trim((string)env_value('WIFI_NAME', '')),
        'wifiPassword' => (string)env_value('WIFI_PASSWORD', ''),
        'doorCodes' => array_map(static fn(array $code): array => [
            'code' => (string)$code['code'],
            'startsAt' => gmdate('c', strtotime($code['starts_at'] . ' UTC')),
            'endsAt' => gmdate('c', strtotime($code['ends_at'] . ' UTC')),
        ], $codes),
    ];
}
