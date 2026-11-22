<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/booking.php';
require dirname(__DIR__) . '/src/branches.php';
require dirname(__DIR__) . '/src/admin-icons.php';
require dirname(__DIR__) . '/src/branch-view.php';

$profileDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$profileDbCalls = [];
$profileRoot = true;
function db(int $branch = 1): PDO
{
    global $profileDb, $profileDbCalls;
    $profileDbCalls[] = $branch;
    return $profileDb;
}
function require_permission(string $permission): void
{
    global $profileRoot;
    if (!$profileRoot || $permission !== 'accounts') {
        throw new BookingException('Forbidden', 403);
    }
}
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function csrf_token(): string
{
    return 'fixture-csrf';
}
function profile_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function profile_reject(callable $action, int $status): void
{
    try {
        $action();
        throw new LogicException('Invalid profile operation succeeded');
    } catch (BookingException $error) {
        profile_expect($error->httpStatus === $status, 'Wrong profile failure status');
    }
}

profile_expect(branch_label(1) === 'Chi nhánh 1', 'Fallback labels must work before migration');
$profileDb->exec('CREATE TABLE branches(id INTEGER PRIMARY KEY,name TEXT,address TEXT,phone TEXT,email TEXT,map_url TEXT,description TEXT,version INTEGER NOT NULL DEFAULT 1)');
$insert = $profileDb->prepare('INSERT INTO branches(id,name,address,phone,email,map_url,description) VALUES(?,?,?,?,?,?,?)');
foreach (default_branch_profiles() as $row) {
    $insert->execute([$row['id'], $row['name'], $row['address'], $row['phone'], '', '', '']);
}
branch_profiles(true);
$originalOne = branch_profile(1);
$input = ['id' => '2', 'version' => '1', 'name' => 'Mơ Riverside', 'address' => '42 Đường mới', 'phone' => '+84 901 234 567', 'email' => 'cn2@example.test', 'map_url' => 'https://maps.google.com/?q=Can+Tho', 'description' => '<script>fixture</script>'];
save_branch_profile($input);
profile_expect(branch_label(2) === 'Mơ Riverside' && (int)branch_profile(2)['version'] === 2, 'Names must refresh after a successful save');
profile_expect(branch_profile(1) === $originalOne, 'Editing branch 2 must not alter branch 1');
profile_reject(fn() => update_branch_profile($profileDb, $input), 409);
foreach ([['name' => ''], ['name' => str_repeat('x', 101)], ['phone' => 'not a phone'], ['phone' => "0901\n234"], ['email' => 'invalid'], ['map_url' => 'javascript:alert(1)'], ['map_url' => 'https://user:pass@example.test/'], ['description' => ['invalid']]] as $invalid) {
    profile_reject(fn() => branch_profile_input(array_replace($input, $invalid)), 422);
}
profile_reject(fn() => update_branch_profile($profileDb, array_replace($input, ['id' => '3'])), 404);
$profileRoot = false;
$profileDbCalls = [];
profile_reject(fn() => save_branch_profile(array_replace($input, ['version' => 2])), 403);
profile_expect($profileDbCalls === [], 'Staff must be denied before any profile write');
$profileRoot = true;
$oldBranch = getenv('MO_BRANCH');
try {
    putenv('MO_BRANCH=2');
    $profileDbCalls = [];
    save_branch_profile(array_replace($input, ['version' => 2, 'name' => 'Mơ Two']));
    profile_expect(array_unique($profileDbCalls) === [1], 'Profile writes must use the shared connection from either branch');
    profile_expect(branch_label() === 'Mơ Two' && !isset(branch_tables()['branches']), 'Branch profile table must not be duplicated');
} finally {
    putenv($oldBranch === false ? 'MO_BRANCH' : 'MO_BRANCH=' . $oldBranch);
}
$profiles = load_branch_profiles($profileDb);
ob_start();
render_branch_management($profiles, 2, $profiles[2], '');
$html = ob_get_clean();
profile_expect(str_contains($html, '&lt;script&gt;fixture&lt;/script&gt;') && !str_contains($html, '<script>fixture</script>'), 'Profile content must be escaped');
profile_expect(str_contains($html, 'name="csrf"') && str_contains($html, 'name="version"'), 'Management form must retain CSRF and concurrency fields');
echo "Branch profiles: validation, shared storage, isolated edits, optimistic concurrency, root-only saves and escaped UI OK\n";
