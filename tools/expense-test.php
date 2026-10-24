<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/expenses.php';

function expense_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE expense_vouchers (id TEXT PRIMARY KEY,voucher_code TEXT UNIQUE,request_id TEXT UNIQUE,request_hash TEXT,spent_at TEXT,category TEXT,amount INTEGER,recipient TEXT,note TEXT,include_cashflow INTEGER,created_by TEXT,created_at TEXT)');
$input = ['spentAt' => '2026-10-24T00:00', 'category' => 'Nguyên liệu', 'amount' => 150000, 'recipient' => 'Nhà cung cấp', 'note' => '<script>test</script>', 'includeCashflow' => true, 'requestId' => str_repeat('a', 32)];
$first = create_expense($pdo, $input, 'admin');
expense_check($first['spent_at'] === '2026-10-23 17:00:00', 'Local midnight must be stored in UTC.');
expense_check(create_expense($pdo, $input, 'admin')['id'] === $first['id'], 'Retries must return the same voucher.');
foreach ([['amount' => 0], ['amount' => 1.5], ['amount' => true], ['category' => ''], ['spentAt' => '2026-02-30T12:00'], ['includeCashflow' => 'true']] as $invalid) {
    try {
        create_expense($pdo, array_replace($input, $invalid), 'admin');
        throw new RuntimeException('Invalid expense accepted.');
    } catch (BookingException $error) {
        expense_check($error->httpStatus === 422, 'Validation must return 422.');
    }
}
try {
    create_expense($pdo, array_replace($input, ['amount' => 200000]), 'admin');
    throw new RuntimeException('Changed retry accepted.');
} catch (BookingException $error) {
    expense_check($error->httpStatus === 409, 'Changed retry must conflict.');
}
create_expense($pdo, array_replace($input, ['requestId' => str_repeat('b', 32), 'spentAt' => '2026-10-24T23:59', 'amount' => 50000, 'includeCashflow' => false]), 'admin');
create_expense($pdo, array_replace($input, ['requestId' => str_repeat('c', 32), 'spentAt' => '2026-10-25T00:00']), 'admin');
$history = expense_history($pdo, ['from' => '2026-10-24', 'to' => '2026-10-24']);
expense_check($history['count'] === 2 && $history['total'] === 200000 && $history['cashflow'] === 150000, 'Date boundaries and accounted totals must match.');
expense_check($history['vouchers'][0]['id'] === $first['id'], 'History must sort by spent time ascending.');
expense_check(expense_history($pdo, ['from' => '2026-10-24', 'q' => $first['voucher_code']])['count'] === 1, 'Voucher search must filter totals and rows.');
expense_check(admin_api_permission('/api/admin/expenses', 'GET') === 'expenses.view', 'Read permission missing.');
expense_check(admin_api_permission('/api/admin/expenses', 'POST') === 'expenses.create', 'Create permission missing.');
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
expense_check(expense_history($pdo, [])['count'] === expense_history($pdo, ['from' => $today])['count'], 'Default range must be Vietnam today.');
echo "Expense validation, UTC dates, idempotency, filters, cashflow totals and permissions: OK\n";
