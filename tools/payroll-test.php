<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/payroll.php';

function payroll_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function payroll_reject(callable $action, int $status = 422): void
{
    try {
        $action();
    } catch (BookingException $error) {
        payroll_check($error->httpStatus === $status, $error->getMessage());
        return;
    }
    throw new RuntimeException('Invalid payroll operation accepted.');
}

$period = ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'];
$input = [
    'salary_type' => 'hourly', 'hourly_rate' => 25000, 'monthly_salary' => 6000000,
    'expected_days' => 26, 'paid_leave_days' => 0, 'prorate' => false,
    'shifts' => [
        ['date' => '2026-10-01', 'in' => '22:00', 'out' => '06:00', 'break_minutes' => 30],
        ['date' => '2026-10-02', 'in' => '08:00', 'out' => '12:00', 'break_minutes' => 0],
    ],
    'allowances' => [['name' => 'Ăn ca', 'amount' => 30000]],
    'deductions' => [['name' => 'Tạm ứng', 'amount' => 50000]], 'note' => '',
];
$result = payroll_calculate($input, $period);
payroll_check($result['minutes'] === 690 && $result['working_days'] === 2, 'Overnight shifts and breaks.');
payroll_check($result['base_salary'] === 287500 && $result['net_salary'] === 267500, 'Hourly salary and adjustments.');
$weekendInput = array_replace($input, ['shifts' => [['date' => '2026-10-03', 'in' => '09:00', 'out' => '17:00', 'break_minutes' => 0]], 'allowances' => [], 'deductions' => []]);
$weekend = payroll_calculate($weekendInput, $period);
payroll_check($weekend['details']['weekend_minutes'] === 480 && $weekend['details']['weekend_bonus_amount'] === 8000 && $weekend['net_salary'] === 208000, 'Weekend Bonus must count Saturday/Sunday hours and add the configured extra hourly pay.');
$override = payroll_calculate(array_replace($weekendInput, ['weekend_hours' => 2.5, 'weekend_bonus_rate' => 2000]), $period);
payroll_check($override['details']['weekend_hours'] === 2.5 && $override['net_salary'] === 205000, 'Manual decimal Bonus hours must persist and calculate the extra amount.');
payroll_check(payroll_calculate(array_replace($weekendInput, ['weekend_hours' => 0]), $period)['net_salary'] === 200000, 'Zero Bonus hours must be respected.');
payroll_reject(fn() => payroll_calculate(array_replace($weekendInput, ['weekend_hours' => 9]), $period));
payroll_reject(fn() => payroll_calculate(array_replace($weekendInput, ['weekend_hours' => 1.234]), $period));
$rated = $input;
$rated['shifts'][0]['hourly_rate'] = 30000;
$ratedResult = payroll_calculate($rated, $period);
payroll_check($ratedResult['base_salary'] === 325000 && $ratedResult['net_salary'] === 305000, 'Each shift may override the default hourly rate; other shifts inherit it.');
payroll_check($ratedResult['details']['shifts'][0]['hourly_rate'] === 30000 && !isset($ratedResult['details']['shifts'][1]['hourly_rate']), 'Persist explicit shift rates without freezing inherited defaults.');
$reversed = payroll_calculate(array_replace($input, ['shifts' => array_reverse($input['shifts'])]), $period);
payroll_check($reversed['base_salary'] === $result['base_salary'] && $reversed['minutes'] === $result['minutes'], 'Changing shift order must not change hours or pay.');
payroll_check($reversed['details']['shifts'][0]['date'] === '2026-10-02', 'Saved shift order must follow the requested display order.');
$rated['shifts'][0]['hourly_rate'] = 0;
payroll_check(payroll_calculate($rated, $period)['base_salary'] === 100000, 'An explicit zero shift rate must not fall back to the employee rate.');
$rated['shifts'][0]['hourly_rate'] = -1;
payroll_reject(fn() => payroll_calculate($rated, $period));
$monthly = array_replace($input, ['salary_type' => 'monthly', 'prorate' => true, 'paid_leave_days' => 1]);
payroll_check(payroll_calculate($monthly, $period)['base_salary'] === 692308, 'Monthly prorating rounds once.');
payroll_check(payroll_calculate(array_replace($monthly, ['prorate' => false]), $period)['base_salary'] === 6000000, 'Full monthly salary.');
foreach ([
    ['hourly_rate' => true], ['hourly_rate' => -1], ['prorate' => 'false'],
    ['expected_days' => 0], ['paid_leave_days' => 32],
    ['shifts' => [['date' => '2026-02-30', 'in' => '08:00', 'out' => '09:00']]],
    ['shifts' => [['date' => '2026-11-01', 'in' => '08:00', 'out' => '09:00']]],
    ['shifts' => [['date' => '2026-10-01', 'in' => '08:00', 'out' => '08:00']]],
    ['shifts' => [['date' => '2026-10-01', 'in' => '08:00', 'out' => '09:00', 'break_minutes' => 60]]],
    ['shifts' => array_merge($input['shifts'], [['date' => '2026-10-02', 'in' => '05:00', 'out' => '07:00']])],
    ['allowances' => [['name' => '', 'amount' => 100]]],
    ['deductions' => [['name' => 'Sai', 'amount' => 1.5]]],
] as $invalid) {
    payroll_reject(fn() => payroll_calculate(array_replace($input, $invalid), $period));
}
echo "Payroll salary, attendance, overnight shifts, overlap and validation: OK\n";

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys=ON');
$schema = file_get_contents(APP_ROOT . '/database/migrations/030_personnel_payroll.sql');
$schema = preg_replace('/ ENGINE=InnoDB[^;]+/', '', $schema);
$schema = preg_replace('/UNIQUE KEY [a-z_]+ /', 'UNIQUE ', $schema);
$pdo->exec($schema);
$pdo->exec($schema);
$employeeInput = [
    'code' => 'nv001', 'name' => 'Nhân viên thử', 'role' => 'Lễ tân',
    'start_date' => '2026-09-01', 'status' => 'active', 'salary_type' => 'hourly',
    'hourly_rate' => 25000, 'expected_days' => 26,
];
$employee = payroll_save_employee($pdo, $employeeInput);
payroll_check((bool)preg_match('/^NV-[A-F0-9]{12}$/', $employee['code']), 'Employee codes must be generated by the server.');
$originalCode = $employee['code'];
$secondEmployee = payroll_save_employee($pdo, $employeeInput);
payroll_check($secondEmployee['code'] !== $originalCode, 'New employee codes must be unique even with the same supplied code.');
payroll_reject(fn() => payroll_save_employee($pdo, array_replace($employeeInput, ['code' => 'NV002', 'email' => 'bad'])));
$period = payroll_create_period($pdo, ['name' => 'Tháng 10', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'], 'admin');
$mutation = ['action' => 'entry/save', 'period_id' => $period['id'], 'version' => 1, 'employee_id' => $employee['id'], 'details' => $input];
$detail = payroll_mutate_period($pdo, $mutation, 'admin');
payroll_check(count($detail['entries']) === 1 && (int)$detail['period']['version'] === 2, 'Entry saved and version incremented.');
payroll_reject(fn() => payroll_mutate_period($pdo, $mutation, 'admin'), 409);
$employee = payroll_save_employee($pdo, array_replace($employeeInput, ['id' => $employee['id'], 'version' => 1, 'hourly_rate' => 99999, 'status' => 'inactive', 'end_date' => '2026-10-15']));
payroll_check($employee['code'] === $originalCode, 'Editing must preserve the original code regardless of supplied code.');
payroll_reject(fn() => payroll_save_employee($pdo, array_replace($employeeInput, ['id' => $employee['id'], 'version' => 1])), 409);
$detail = payroll_period_detail($pdo, $period['id']);
payroll_check($detail['entries'][0]['details']['hourly_rate'] === 25000, 'Profile changes must preserve payroll snapshot.');
$detail = payroll_mutate_period($pdo, ['action' => 'entry/save', 'period_id' => $period['id'], 'version' => 2, 'employee_id' => $employee['id']], 'admin');
payroll_check($detail['entries'][0]['details']['hourly_rate'] === 25000, 'Retry without details must preserve saved salary.');
$pdo->prepare("UPDATE payroll_periods SET status='locked',locked_by='legacy',locked_at='2026-10-01 00:00:00' WHERE id=?")->execute([$period['id']]);
$detail = payroll_mutate_period($pdo, ['action' => 'entry/save', 'period_id' => $period['id'], 'version' => 3, 'employee_id' => $employee['id']], 'admin');
payroll_check((int)$detail['period']['version'] === 4, 'Previously locked periods must remain editable.');
payroll_reject(fn() => payroll_mutate_period($pdo, ['action' => 'entry/save', 'period_id' => $period['id'], 'version' => 3, 'employee_id' => $employee['id']], 'admin'), 409);
payroll_reject(fn() => payroll_mutate_period($pdo, ['action' => 'period/lock', 'period_id' => $period['id'], 'version' => 4], 'admin'), 422);
$emptyPeriod = payroll_create_period($pdo, ['name' => 'Rỗng', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30'], 'admin');
payroll_reject(fn() => payroll_mutate_period($pdo, ['action' => 'period/lock', 'period_id' => $emptyPeriod['id'], 'version' => 1], 'admin'));
payroll_check((int)payroll_find($pdo, 'payroll_periods', $emptyPeriod['id'])['version'] === 1, 'Failed operation must roll back version.');
payroll_check(admin_api_permission('/api/admin/payroll', 'GET') === 'payroll', 'Payroll permission mapping.');
$_SESSION = ['admin_until' => time() + 60];
payroll_check(can_use('payroll'), 'Root access required.');
$_SESSION = [];
payroll_check(!can_use('payroll'), 'Anonymous users must be rejected.');
echo "Payroll employee uniqueness, optimistic concurrency, snapshots, transactions and legacy-period editing: OK\n";

$copyPeriod = payroll_create_period($pdo, ['name' => 'Sao chép', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'copy_employees' => true], 'admin');
$copy = payroll_period_detail($pdo, $copyPeriod['id']);
payroll_check(count($copy['entries']) === 1 && $copy['entries'][0]['employee_id'] === $secondEmployee['id'], 'Copy only eligible active employees.');
payroll_check($copy['entries'][0]['details']['hourly_rate'] === 25000, 'New period must keep salary snapshot.');
$renamed = payroll_mutate_period($pdo, ['action' => 'period/rename', 'period_id' => $copyPeriod['id'], 'version' => 1, 'name' => 'Kỳ đã đổi tên'], 'admin');
payroll_check($renamed['period']['name'] === 'Kỳ đã đổi tên' && (int)$renamed['period']['version'] === 2, 'Rename must increment the period version.');
payroll_reject(fn() => payroll_mutate_period($pdo, ['action' => 'period/delete', 'period_id' => $copyPeriod['id'], 'version' => 1], 'admin'), 409);
$deleted = payroll_mutate_period($pdo, ['action' => 'period/delete', 'period_id' => $copyPeriod['id'], 'version' => 2], 'admin');
payroll_check($deleted['deleted'] === true, 'Draft period deletion.');
payroll_reject(fn() => payroll_find($pdo, 'payroll_periods', $copyPeriod['id']), 404);
$query = $pdo->prepare('SELECT COUNT(*) FROM payroll_entries WHERE period_id=?');
$query->execute([$copyPeriod['id']]);
payroll_check((int)$query->fetchColumn() === 0 && payroll_find($pdo, 'payroll_employees', $secondEmployee['id'])['id'] === $secondEmployee['id'], 'Deleting a draft removes entries but preserves personnel.');
payroll_reject(fn() => payroll_create_period($pdo, ['name' => 'Sai', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'copy_employees' => 'true'], 'admin'));
$before = (int)$pdo->query('SELECT COUNT(*) FROM payroll_periods')->fetchColumn();
$pdo->prepare('UPDATE payroll_employees SET expected_days=0 WHERE id=?')->execute([$secondEmployee['id']]);
payroll_reject(fn() => payroll_create_period($pdo, ['name' => 'Rollback', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'copy_employees' => true], 'admin'));
payroll_check((int)$pdo->query('SELECT COUNT(*) FROM payroll_periods')->fetchColumn() === $before, 'Failed employee copying must roll back the whole period.');
echo "Payroll active-employee copying, snapshot, rename, period deletion and rollback: OK\n";
payroll_reject(fn() => payroll_delete_employee($pdo, ['id' => $employee['id'], 'version' => 1]), 409);
payroll_delete_employee($pdo, ['id' => $employee['id'], 'version' => 2]);
$removedEmployee = payroll_find($pdo, 'payroll_employees', $employee['id']);
payroll_check($removedEmployee['status'] === 'deleted' && (int)$removedEmployee['version'] === 3, 'Deleting personnel must hide the record and advance its version.');
payroll_check(count(payroll_period_detail($pdo, $period['id'])['entries']) === 1, 'Deleting personnel must preserve historical payroll snapshots.');
payroll_reject(fn() => payroll_save_employee($pdo, array_replace($employeeInput, ['id' => $employee['id'], 'version' => 3])), 404);
payroll_reject(fn() => payroll_delete_employee($pdo, ['id' => $employee['id'], 'version' => 3]), 404);
payroll_reject(fn() => payroll_mutate_period($pdo, ['action' => 'entry/save', 'period_id' => $emptyPeriod['id'], 'version' => 1, 'employee_id' => $employee['id']], 'admin'), 404);
echo "Personnel deletion, concurrency, hidden records and preserved payroll history: OK\n";
require_once APP_ROOT . '/src/payroll-export.php';
$exportDetail = payroll_period_detail($pdo, $period['id']);
$exportDetail['entries'][0]['employee_name'] = '=HYPERLINK("test") <Tên>';
$exportPath = tempnam(__DIR__, 'mo-export-');
try {
    file_put_contents($exportPath, payroll_export_excel($exportDetail));
    $archive = new ZipArchive();
    payroll_check($archive->open($exportPath) === true, 'Export must be a valid XLSX archive.');
    $xml = $archive->getFromName('xl/worksheets/sheet1.xml');
    payroll_check(simplexml_load_string($xml) !== false && !str_contains($xml, '<f>'), 'Export must contain valid XML and never execute employee text as formulas.');
    payroll_check(str_contains($xml, '&lt;Tên&gt;') && str_contains($xml, 't="inlineStr"'), 'Export must escape employee names as text.');
    $archive->close();
} finally {
    unlink($exportPath);
}
echo "Payroll XLSX export structure and formula injection protection: OK\n";
