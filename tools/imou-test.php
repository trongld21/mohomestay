<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/imou.php';

function imou_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

imou_check(imou_sign(1706511734, 'f5a1ae2d-c09c-4d39-a744-83a5c2c653c2', 'test123456789test123456789')
    === 'xjhCQBoJ9hRDsCjyDcHjtDNzRZ3ZJezcawsfWeiaoxU=', 'Official signing vector mismatch.');
foreach (['http://openapi-sg.easy4ip.com', 'https://evil.test', 'https://openapi-sg.easy4ip.com@evil.test', 'https://openapi-sg.easy4ip.com/openapi'] as $host) {
    try {
        new ImouReadClient('app-test', 'secret-test', $host);
        throw new RuntimeException('Unsafe endpoint accepted.');
    } catch (InvalidArgumentException) {
    }
}
$methods = [];
$client = new ImouReadClient('app-test', 'secret-test', 'https://openapi-sg.easy4ip.com',
    function (string $method, array $request) use (&$methods): array {
        $methods[] = $method;
        if ($method === 'accessToken') {
            imou_check(is_object($request['params']) && (array)$request['params'] === [], 'Token params must be an empty map.');
            return ['result' => ['code' => '0', 'data' => ['accessToken' => 'private-token']]];
        }
        imou_check($request['params']->source === 'bindAndShare', 'Must include shared devices.');
        imou_check($request['params']->token === 'private-token', 'Token not passed server-side.');
        return ['result' => ['code' => '0', 'data' => ['deviceList' => [
            ['deviceId' => 'camera-1', 'deviceName' => 'Lễ tân', 'deviceStatus' => 'online', 'source' => 'share',
                'playToken' => 'must-not-leak', 'devicePassword' => 'must-not-leak', 'channelList' => [['channelId' => 0]]],
        ]]]];
    });
$devices = $client->devices();
imou_check(count($devices) === 1 && $devices[0]['name'] === 'Lễ tân', 'Camera list missing.');
imou_check(!str_contains(json_encode($devices), 'must-not-leak'), 'Playback credentials leaked into summary.');
imou_check($methods === ['accessToken', 'listDeviceDetailsByPage'], 'Unexpected API mutation.');
$empty = new ImouReadClient('app-test', 'secret-test', 'https://openapi-or.easy4ip.com',
    fn(string $method): array => ['result' => ['code' => '0', 'data' => $method === 'accessToken' ? ['accessToken' => 'token'] : ['deviceList' => []]]]);
imou_check($empty->devices() === [], 'Empty account must return empty list.');
$paged = new ImouReadClient('app-test', 'secret-test', 'https://openapi-sg.easy4ip.com',
    function (string $method, array $request): array {
        if ($method === 'accessToken') return ['result' => ['code' => '0', 'data' => ['accessToken' => 'token']]];
        $rows = $request['params']->page === 1 ? array_fill(0, 50, ['deviceId' => 'camera']) : [['deviceId' => 'last-camera']];
        return ['result' => ['code' => '0', 'data' => ['deviceList' => $rows]]];
    });
imou_check(count($paged->devices()) === 51, 'Device pagination must not stop after the first page.');
$malformed = new ImouReadClient('app-test', 'secret-test', 'https://openapi-sg.easy4ip.com',
    fn(string $method): array => ['result' => ['code' => '0', 'data' => $method === 'accessToken' ? ['accessToken' => 'token'] : ['deviceList' => 'invalid']]]);
try {
    $malformed->devices();
    throw new RuntimeException('Invalid camera list accepted.');
} catch (RuntimeException $error) {
    imou_check($error->getMessage() === 'Danh sách camera Imou không hợp lệ.', 'Malformed response was not rejected.');
}
$denied = new ImouReadClient('app-test', 'secret-test', 'https://openapi-fk.easy4ip.com',
    fn(): array => ['result' => ['code' => 'OP1001', 'msg' => 'secret-test must-not-leak']]);
try {
    $denied->devices();
    throw new RuntimeException('API rejection accepted.');
} catch (RuntimeException $error) {
    imou_check($error->getMessage() === 'Imou từ chối yêu cầu (OP1001).', 'Untrusted error response leaked.');
}
$liveDenied = new ImouReadClient('app-test', 'secret-test', 'https://openapi-sg.easy4ip.com',
    function (string $method, array $request): array {
        if ($method === 'accessToken') {
            return ['result' => ['code' => '0', 'data' => ['accessToken' => 'private-token']]];
        }
        imou_check($request['params']->deviceId === 'CAMERA123', 'Wrong camera checked.');
        if ($method === 'getKitToken') {
            imou_check($request['params']->type === '1', 'Only live-view permission may be requested.');
        }
        return ['result' => ['code' => 'OP1009', 'msg' => 'must-not-leak']];
    });
$access = $liveDenied->liveAccess('CAMERA123');
imou_check(count($access) === 3 && array_column($access, 'code') === ['OP1009', 'OP1009', 'OP1009'],
    'Successful authentication must not hide live permission denials.');
imou_check(!str_contains(json_encode($access), 'must-not-leak'), 'Live errors leaked response contents.');
$liveAccepted = new ImouReadClient('app-test', 'secret-test', 'https://openapi-sg.easy4ip.com',
    fn(string $method): array => ['result' => ['code' => '0', 'data' => $method === 'accessToken'
        ? ['accessToken' => 'private-token'] : ['kitToken' => 'private-token', 'hls' => 'https://private.example/video']]]);
imou_check(array_column($liveAccepted->liveAccess('CAMERA123'), 'code') === ['0', '0', '0'], 'Accepted checks missing.');
imou_check(!str_contains(json_encode($liveAccepted->liveAccess('CAMERA123')), 'private'), 'Live URL or token leaked.');
try {
    $liveAccepted->liveAccess('../invalid');
    throw new RuntimeException('Invalid device ID accepted.');
} catch (InvalidArgumentException) {
}
echo "Imou probe: signing, shared devices, live permission diagnosis and secret isolation OK\n";
