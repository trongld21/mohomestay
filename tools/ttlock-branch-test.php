<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function lock_branch_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Fake credentials and a mandatory in-memory transport: never contact TTLock,
// create a physical code, or print any configured production credential.
foreach ([
    'TTLOCK_BASE_URL' => 'https://api.sciener.com', 'TTLOCK_CLIENT_ID' => 'shared-app',
    'TTLOCK_CLIENT_SECRET' => 'fixture-secret', 'TTLOCK_USERNAME' => 'shared-owner',
    'TTLOCK_PASSWORD_MD5' => str_repeat('a', 32), 'TTLOCK_LOCK_ID' => '1001',
    'TTLOCK_ENABLED' => 'true', 'TTLOCK_CODE_MODE' => 'CUSTOM',
    'BRANCH2_TTLOCK_SHARED_ACCOUNT' => 'true', 'BRANCH2_TTLOCK_ENABLED' => 'false',
    'BRANCH2_TTLOCK_LOCK_ID' => '', 'BRANCH2_TTLOCK_CODE_MODE' => 'GENERATED',
    'BRANCH2_TTLOCK_CLIENT_ID' => 'stale-separate-app',
] as $key => $value) {
    $_ENV[$key] = $value;
}
$previousBranch = getenv('MO_BRANCH');
try {
    putenv('MO_BRANCH=1');
    $one = ttlock_options();
    lock_branch_expect(ttlock_configured() && config('ttlock.enabled'), 'Branch 1 setup must remain unchanged');
    putenv('MO_BRANCH=2');
    lock_branch_expect(!config('ttlock.enabled'), 'Branch 2 must not inherit enablement');
    lock_branch_expect(!ttlock_configured() && ttlock_options()['lock_id'] === '', 'Branch 2 must not inherit branch 1 lock');
    $_ENV['BRANCH2_TTLOCK_LOCK_ID'] = '2002';
    $_ENV['BRANCH2_TTLOCK_ENABLED'] = 'true';
    $two = ttlock_options();
    foreach (['base_url', 'client_id', 'client_secret', 'username', 'password_md5'] as $key) {
        lock_branch_expect($two[$key] === $one[$key], 'Shared identity mismatch: ' . $key);
    }
    lock_branch_expect(ttlock_configured() && config('ttlock.code_mode') === 'GENERATED', 'Mode and lock must stay branch-local');

    $remote = [];
    $calls = [];
    $creates = [];
    $window = ['starts_at' => '2099-01-15 07:00:00', 'ends_at' => '2099-01-15 11:00:00'];
    foreach ([1 => $one, 2 => $two] as $branch => $options) {
        putenv('MO_BRANCH=' . $branch);
        $transport = static function (string $url, array $params) use (&$remote, &$calls, &$creates, $branch, $options): array {
            $calls[$branch][] = [$url, $params];
            if (str_ends_with($url, '/oauth2/token')) {
                lock_branch_expect($params['client_id'] === 'shared-app' && $params['username'] === 'shared-owner', 'Wrong TTLock account');
                return ['access_token' => 'fixture-token'];
            }
            $lock = (int)$params['lockId'];
            lock_branch_expect($lock === (int)$options['lock_id'], 'TTLock request crossed branches');
            if (str_ends_with($url, '/listKeyboardPwd')) {
                return ['list' => $remote[$lock] ?? [], 'pages' => 1];
            }
            if (str_ends_with($url, '/get')) {
                $creates[$branch] = ($creates[$branch] ?? 0) + 1;
                $code = $params + ['keyboardPwdId' => $lock * 10, 'keyboardPwd' => '12345678', 'status' => 1];
                $remote[$lock][] = $code;
                return $code;
            }
            if (str_ends_with($url, '/delete')) {
                $remote[$lock] = [];
                return ['errcode' => 0];
            }
            throw new RuntimeException('Unexpected TTLock fixture endpoint');
        };
        $row = ['booking_id' => str_repeat('b', 32), 'booking_code' => 'MO-SAME-CODE', 'lock_id' => $options['lock_id']] + $window;
        $client = new TTLockClient($options, $transport);
        $code = $client->ensureGeneratedPasscode($row);
        lock_branch_expect($client->ensureGeneratedPasscode($row) === $code && $creates[$branch] === 1, 'Retry must reuse the correct lock code');
        $client->deletePasscode($row + ['code' => $code['code']]);
        lock_branch_expect($remote[(int)$options['lock_id']] === [], 'Delete must target the correct lock');
    }

    putenv('MO_BRANCH=2');
    $_ENV['BRANCH2_TTLOCK_LOCK_ID'] = '01001';
    lock_branch_expect(!ttlock_configured() && str_contains(ttlock_configuration_error(), 'trùng'), 'Same physical lock must be rejected');
    $_ENV['BRANCH2_TTLOCK_LOCK_ID'] = '2002';
    $networkCalls = 0;
    $client = new TTLockClient(ttlock_options(), static function () use (&$networkCalls): array {
        $networkCalls++;
        throw new LogicException('Wrong-lock request reached transport');
    });
    $wrongRow = ['booking_id' => str_repeat('c', 32), 'lock_id' => 1001, 'code' => '12345678'] + $window;
    foreach (['findPasscode', 'ensurePasscode', 'ensureGeneratedPasscode', 'deletePasscode'] as $method) {
        try {
            $client->$method($wrongRow);
            throw new LogicException('Branch 2 accepted a stored branch-1 lock: ' . $method);
        } catch (TTLockException $error) {
            lock_branch_expect(str_contains($error->getMessage(), 'chi nhánh 1'), 'Wrong denial reason');
        }
    }
    lock_branch_expect($networkCalls === 0, 'Wrong-lock denial must occur before any network request');
    $_ENV['BRANCH2_TTLOCK_SHARED_ACCOUNT'] = 'false';
    lock_branch_expect(ttlock_options()['client_id'] === 'stale-separate-app', 'Separate branch account mode must remain supported');
    putenv('MO_BRANCH=1');
    lock_branch_expect(ttlock_options() === $one && config('ttlock.enabled'), 'Branch 2 settings modified branch 1');
    echo "TTLock branches: shared account opt-in, separate lock/mode/enablement, create/retry/delete routing and wrong-lock denial OK (offline)\n";
} finally {
    putenv($previousBranch === false ? 'MO_BRANCH' : 'MO_BRANCH=' . $previousBranch);
}
