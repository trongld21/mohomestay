<?php

declare(strict_types=1);

function payroll_text(array $input, string $key, int $limit, bool $required = false): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || mb_strlen(trim($value)) > $limit || ($required && trim($value) === '')) {
        throw new BookingException('Thông tin ' . $key . ' không hợp lệ.', 422);
    }
    return trim($value);
}

function payroll_integer(array $input, string $key, int $max, int $default = 0): int
{
    $value = $input[$key] ?? $default;
    if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false
        || (int)$value < 0 || (int)$value > $max) {
        throw new BookingException('Giá trị ' . $key . ' không hợp lệ.', 422);
    }
    return (int)$value;
}

function payroll_date(mixed $value): DateTimeImmutable
{
    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Ho_Chi_Minh')) : false;
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new BookingException('Ngày không hợp lệ.', 422);
    }
    return $date;
}

function payroll_salary_settings(array $input): array
{
    $type = payroll_text($input, 'salary_type', 20, true);
    if (!in_array($type, ['hourly', 'monthly'], true)) {
        throw new BookingException('Chọn lương giờ hoặc lương tháng.', 422);
    }
    $expected = payroll_integer($input, 'expected_days', 31, 26);
    if ($expected < 1) {
        throw new BookingException('Ngày công chuẩn phải từ 1 đến 31.', 422);
    }
    return [
        'salary_type' => $type,
        'hourly_rate' => payroll_integer($input, 'hourly_rate', 1000000000),
        'monthly_salary' => payroll_integer($input, 'monthly_salary', 1000000000),
        'expected_days' => $expected,
    ];
}

function payroll_calculate(array $input, array $period): array
{
    $details = payroll_salary_settings($input);
    $details['paid_leave_days'] = payroll_integer($input, 'paid_leave_days', 31);
    if (!is_bool($input['prorate'] ?? false)) {
        throw new BookingException('Cấu hình tính theo ngày công không hợp lệ.', 422);
    }
    $details['prorate'] = $input['prorate'] ?? false;
    $details['note'] = payroll_text($input, 'note', 2000);
    $start = payroll_date($period['start_date']);
    $end = payroll_date($period['end_date']);
    $shifts = $input['shifts'] ?? [];
    if (!is_array($shifts) || !array_is_list($shifts) || count($shifts) > 200) {
        throw new BookingException('Tối đa 200 ca trong mỗi dòng lương.', 422);
    }
    $minutes = 0;
    $hourlyPayMinutes = 0;
    $weekendMinutes = 0;
    $days = [];
    $intervals = [];
    $details['shifts'] = [];
    foreach ($shifts as $shift) {
        if (!is_array($shift)) {
            throw new BookingException('Ca làm không hợp lệ.', 422);
        }
        $day = payroll_date($shift['date'] ?? null);
        $in = payroll_text($shift, 'in', 5, true);
        $out = payroll_text($shift, 'out', 5, true);
        if ($day < $start || $day > $end || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $in)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $out) || $in === $out) {
            throw new BookingException('Ca làm phải có giờ hợp lệ và ngày bắt đầu nằm trong kỳ.', 422);
        }
        $from = $day->setTime((int)substr($in, 0, 2), (int)substr($in, 3));
        $to = $day->setTime((int)substr($out, 0, 2), (int)substr($out, 3));
        if ($to < $from) {
            $to = $to->modify('+1 day');
        }
        $break = payroll_integer($shift, 'break_minutes', 1439);
        $duration = (int)(($to->getTimestamp() - $from->getTimestamp()) / 60) - $break;
        if ($duration <= 0) {
            throw new BookingException('Thời gian nghỉ phải nhỏ hơn thời lượng ca.', 422);
        }
        foreach ($intervals as [$previousFrom, $previousTo]) {
            if ($from < $previousTo && $to > $previousFrom) {
                throw new BookingException('Ca làm bị trùng thời gian.', 422);
            }
        }
        $intervals[] = [$from, $to];
        $minutes += $duration;
        if ((int)$day->format('N') >= 6) {
            $weekendMinutes += $duration;
        }
        $days[$shift['date']] = true;
        $details['shifts'][] = ['date' => $shift['date'], 'in' => $in, 'out' => $out, 'break_minutes' => $break];
        $rate = $details['hourly_rate'];
        if ($details['salary_type'] === 'hourly' && isset($shift['hourly_rate'])) {
            $rate = payroll_integer($shift, 'hourly_rate', 1000000000);
            $details['shifts'][array_key_last($details['shifts'])]['hourly_rate'] = $rate;
        }
        $hourlyPayMinutes += $duration * $rate;
    }
    $totals = [];
    foreach (['allowances', 'deductions'] as $key) {
        $items = $input[$key] ?? [];
        if (!is_array($items) || !array_is_list($items) || count($items) > 50) {
            throw new BookingException('Tối đa 50 khoản cộng hoặc trừ.', 422);
        }
        $details[$key] = [];
        $totals[$key] = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new BookingException('Khoản cộng/trừ không hợp lệ.', 422);
            }
            $name = payroll_text($item, 'name', 150, true);
            $amount = payroll_integer($item, 'amount', 1000000000);
            $details[$key][] = ['name' => $name, 'amount' => $amount];
            $totals[$key] += $amount;
        }
    }
    if ($details['salary_type'] === 'hourly') {
        $base = (int)round($hourlyPayMinutes / 60);
    } elseif ($details['prorate']) {
        $paidDays = min(count($days) + $details['paid_leave_days'], $details['expected_days']);
        $base = (int)round($details['monthly_salary'] * $paidDays / $details['expected_days']);
    } else {
        $base = $details['monthly_salary'];
    }
    $details['weekend_bonus_rate'] = payroll_integer($input, 'weekend_bonus_rate', 1000000000, 1000);
    $details['weekend_minutes'] = $weekendMinutes;
    $bonus = $weekendMinutes * $details['weekend_bonus_rate'] / 60;
    if ($details['salary_type'] === 'hourly' && isset($input['weekend_hours'])) {
        $value = $input['weekend_hours'];
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || !preg_match('/^\d{1,4}(?:\.\d{1,2})?$/D', (string)$value)
            || (float)$value > round($minutes / 60, 2)) {
            throw new BookingException('Giờ Bonus phải từ 0 đến tổng giờ làm, tối đa 2 chữ số thập phân.', 422);
        }
        $hundredths = (int)round((float)$value * 100);
        $details['weekend_hours'] = $hundredths / 100;
        $bonus = $hundredths * $details['weekend_bonus_rate'] / 100;
    }
    $details['weekend_bonus_amount'] = $details['salary_type'] === 'hourly' ? (int)round($bonus) : 0;
    $totals['allowances'] += $details['weekend_bonus_amount'];
    return [
        'details' => $details,
        'minutes' => $minutes,
        'working_days' => count($days),
        'base_salary' => $base,
        'allowance_total' => $totals['allowances'],
        'deduction_total' => $totals['deductions'],
        'net_salary' => max(0, $base + $totals['allowances'] - $totals['deductions']),
    ];
}

function payroll_find(PDO $pdo, string $table, string $id, bool $lock = false): array
{
    if (!in_array($table, ['payroll_employees', 'payroll_periods'], true)) {
        throw new LogicException('Unknown payroll table.');
    }
    $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $query = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE id=?' . $suffix);
    $query->execute([$id]);
    $row = $query->fetch();
    if (!$row) {
        throw new BookingException('Không tìm thấy hồ sơ hoặc kỳ lương.', 404);
    }
    return $row;
}

function payroll_save_employee(PDO $pdo, array $input): array
{
    $id = payroll_text($input, 'id', 36);
    $values = payroll_salary_settings($input);
    foreach (['name' => 150, 'phone' => 30, 'email' => 190, 'role' => 100, 'note' => 2000] as $key => $limit) {
        $values[$key] = payroll_text($input, $key, $limit, $key === 'name');
    }
    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        throw new BookingException('Email không hợp lệ.', 422);
    }
    $values['start_date'] = payroll_date($input['start_date'] ?? null)->format('Y-m-d');
    $values['end_date'] = ($input['end_date'] ?? '') !== '' ? payroll_date($input['end_date'])->format('Y-m-d') : null;
    $values['status'] = payroll_text($input, 'status', 20, true);
    if (!in_array($values['status'], ['active', 'inactive'], true)
        || ($values['end_date'] !== null && $values['end_date'] < $values['start_date'])
        || ($values['status'] === 'active' && $values['end_date'] !== null)) {
        throw new BookingException('Trạng thái hoặc ngày nghỉ việc không hợp lệ.', 422);
    }
    $values['updated_at'] = gmdate('Y-m-d H:i:s');
    try {
        if ($id !== '') {
            $existing = payroll_find($pdo, 'payroll_employees', $id);
            if ($existing['status'] === 'deleted') {
                throw new BookingException('Hồ sơ nhân viên đã được xóa.', 404);
            }
            $version = payroll_integer($input, 'version', PHP_INT_MAX);
            $sql = implode(',', array_map(static fn($key) => $key . '=?', array_keys($values)));
            $query = $pdo->prepare('UPDATE payroll_employees SET ' . $sql . ',version=version+1 WHERE id=? AND version=?');
            $query->execute([...array_values($values), $id, $version]);
            if ($query->rowCount() !== 1) {
                throw new BookingException('Hồ sơ đã thay đổi ở nơi khác. Tải lại trước khi sửa.', 409);
            }
        } else {
            $id = uuid();
            $values += ['id' => $id, 'created_at' => $values['updated_at']];
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $values['code'] = 'NV-' . strtoupper(bin2hex(random_bytes(6)));
                try {
                    $columns = implode(',', array_keys($values));
                    $placeholders = implode(',', array_fill(0, count($values), '?'));
                    $pdo->prepare('INSERT INTO payroll_employees (' . $columns . ') VALUES (' . $placeholders . ')')
                        ->execute(array_values($values));
                    break;
                } catch (PDOException $error) {
                    if (!in_array((string)$error->getCode(), ['23000', '23505'], true) || $attempt === 2) {
                        throw $error;
                    }
                }
            }
        }
    } catch (PDOException $error) {
        if (in_array((string)$error->getCode(), ['23000', '23505'], true)) {
            throw new BookingException('Mã nhân viên đã được sử dụng.', 409);
        }
        throw $error;
    }
    return payroll_find($pdo, 'payroll_employees', $id);
}

function payroll_delete_employee(PDO $pdo, array $input): void
{
    $id = payroll_text($input, 'id', 36, true);
    $version = payroll_integer($input, 'version', PHP_INT_MAX);
    $pdo->beginTransaction();
    try {
        $employee = payroll_find($pdo, 'payroll_employees', $id, true);
        if ($employee['status'] === 'deleted') {
            throw new BookingException('Hồ sơ nhân viên đã được xóa.', 404);
        }
        if ((int)$employee['version'] !== $version) {
            throw new BookingException('Hồ sơ đã thay đổi. Tải lại trước khi xóa.', 409);
        }
        // Retain the referenced identity so historical payroll rows remain intact.
        $pdo->prepare("UPDATE payroll_employees SET status='deleted',version=version+1,updated_at=? WHERE id=?")
            ->execute([gmdate('Y-m-d H:i:s'), $id]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function payroll_create_period(PDO $pdo, array $input, string $actor): array
{
    $name = payroll_text($input, 'name', 150, true);
    $start = payroll_date($input['start_date'] ?? null);
    $end = payroll_date($input['end_date'] ?? null);
    if ($end < $start || $end > $start->modify('+92 days')) {
        throw new BookingException('Kỳ lương tối đa 93 ngày, ngày cuối không trước ngày đầu.', 422);
    }
    $id = uuid();
    if (!is_bool($input['copy_employees'] ?? false)) {
        throw new BookingException('Tùy chọn sao chép nhân viên không hợp lệ.', 422);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO payroll_periods (id,name,start_date,end_date,created_by,created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $name, $start->format('Y-m-d'), $end->format('Y-m-d'), $actor, gmdate('Y-m-d H:i:s')]);
        if ($input['copy_employees'] ?? false) {
            $query = $pdo->prepare("SELECT id FROM payroll_employees WHERE status='active' AND start_date<=? AND (end_date IS NULL OR end_date>=?) ORDER BY id");
            $query->execute([$end->format('Y-m-d'), $start->format('Y-m-d')]);
            $employeeIds = $query->fetchAll(PDO::FETCH_COLUMN);
            foreach ($employeeIds as $employeeId) {
                $employee = payroll_find($pdo, 'payroll_employees', (string)$employeeId, true);
                if ($employee['status'] !== 'active' || $employee['start_date'] > $end->format('Y-m-d')
                    || ($employee['end_date'] !== null && $employee['end_date'] < $start->format('Y-m-d'))) {
                    continue;
                }
                $values = payroll_calculate($employee, ['start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d')]);
                $values['details'] = json_encode($values['details'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $values += [
                    'id' => uuid(),
                    'period_id' => $id,
                    'employee_id' => $employeeId,
                    'employee_code' => $employee['code'],
                    'employee_name' => $employee['name'],
                    'role' => $employee['role'],
                ];
                $columns = implode(',', array_keys($values));
                $placeholders = implode(',', array_fill(0, count($values), '?'));
                $pdo->prepare('INSERT INTO payroll_entries (' . $columns . ') VALUES (' . $placeholders . ')')
                    ->execute(array_values($values));
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return payroll_find($pdo, 'payroll_periods', $id);
}

function payroll_period_detail(PDO $pdo, string $id): array
{
    // A read transaction keeps the version and rows from the same database snapshot.
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $period = payroll_find($pdo, 'payroll_periods', $id);
        $query = $pdo->prepare('SELECT * FROM payroll_entries WHERE period_id=? ORDER BY employee_name,employee_code');
        $query->execute([$id]);
        $entries = $query->fetchAll();
        foreach ($entries as &$entry) {
            $entry['details'] = json_decode($entry['details'], true, 512, JSON_THROW_ON_ERROR);
        }
        unset($entry);
        if ($ownTransaction) {
            $pdo->commit();
        }
        return ['period' => $period, 'entries' => $entries];
    } catch (Throwable $error) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function payroll_mutate_period(PDO $pdo, array $input, string $actor): array
{
    $action = payroll_text($input, 'action', 30, true);
    $periodId = payroll_text($input, 'period_id', 36, true);
    $version = payroll_integer($input, 'version', PHP_INT_MAX);
    $pdo->beginTransaction();
    try {
        $period = payroll_find($pdo, 'payroll_periods', $periodId, true);
        if ((int)$period['version'] !== $version) {
            throw new BookingException('Kỳ vừa thay đổi. Tải lại để xem dữ liệu mới.', 409);
        }
        if ($action === 'period/delete') {
            $pdo->prepare('DELETE FROM payroll_entries WHERE period_id=?')->execute([$periodId]);
            $pdo->prepare('DELETE FROM payroll_periods WHERE id=?')->execute([$periodId]);
            $pdo->commit();
            return ['deleted' => true];
        }
        if ($action === 'period/rename') {
            $name = payroll_text($input, 'name', 150, true);
            $pdo->prepare('UPDATE payroll_periods SET name=? WHERE id=?')->execute([$name, $periodId]);
        } elseif (in_array($action, ['entry/save', 'entry/remove'], true)) {
            $employeeId = payroll_text($input, 'employee_id', 36, true);
            $query = $pdo->prepare('SELECT * FROM payroll_entries WHERE period_id=? AND employee_id=?');
            $query->execute([$periodId, $employeeId]);
            $existing = $query->fetch();
            if ($action === 'entry/remove') {
                if (!$existing) {
                    throw new BookingException('Không tìm thấy dòng lương.', 404);
                }
                $pdo->prepare('DELETE FROM payroll_entries WHERE period_id=? AND employee_id=?')->execute([$periodId, $employeeId]);
            } else {
                $employee = payroll_find($pdo, 'payroll_employees', $employeeId, true);
                if (!$existing && $employee['status'] === 'deleted') {
                    throw new BookingException('Hồ sơ nhân viên đã được xóa.', 404);
                }
                if (!$existing && ($employee['start_date'] > $period['end_date']
                    || ($employee['end_date'] !== null && $employee['end_date'] < $period['start_date']))) {
                    throw new BookingException('Nhân viên không làm việc trong kỳ này.', 422);
                }
                // An existing row always keeps its own salary and identity snapshot.
                $data = $input['details'] ?? ($existing ? json_decode($existing['details'], true, 512, JSON_THROW_ON_ERROR) : $employee);
                if (!is_array($data)) {
                    throw new BookingException('Chi tiết lương không hợp lệ.', 422);
                }
                $values = payroll_calculate($data, $period);
                $values['details'] = json_encode($values['details'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if ($existing) {
                    $sql = implode(',', array_map(static fn($key) => $key . '=?', array_keys($values)));
                    $pdo->prepare('UPDATE payroll_entries SET ' . $sql . ' WHERE id=?')
                        ->execute([...array_values($values), $existing['id']]);
                } else {
                    $values += [
                        'id' => uuid(),
                        'period_id' => $periodId,
                        'employee_id' => $employeeId,
                        'employee_code' => $employee['code'],
                        'employee_name' => $employee['name'],
                        'role' => $employee['role'],
                    ];
                    $columns = implode(',', array_keys($values));
                    $placeholders = implode(',', array_fill(0, count($values), '?'));
                    $pdo->prepare('INSERT INTO payroll_entries (' . $columns . ') VALUES (' . $placeholders . ')')
                        ->execute(array_values($values));
                }
            }
        } else {
            throw new BookingException('Thao tác không hợp lệ.', 422);
        }
        $pdo->prepare('UPDATE payroll_periods SET version=version+1 WHERE id=?')->execute([$periodId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return payroll_period_detail($pdo, $periodId);
}

function payroll_api(string $method): never
{
    require_permission('payroll');
    header('Cache-Control: no-store');
    if ($method === 'GET') {
        $resource = payroll_text($_GET, 'resource', 20, true);
        if ($resource === 'employees') {
            json_response(['employees' => db()->query("SELECT * FROM payroll_employees WHERE status<>'deleted' ORDER BY name,code")->fetchAll()]);
        }
        if ($resource === 'periods') {
            json_response(['periods' => db()->query('SELECT p.*,COALESCE(e.employee_count,0) employee_count,COALESCE(e.net_salary,0) net_salary FROM payroll_periods p LEFT JOIN (SELECT period_id,COUNT(*) employee_count,SUM(net_salary) net_salary FROM payroll_entries GROUP BY period_id) e ON e.period_id=p.id ORDER BY p.start_date DESC,p.created_at DESC')->fetchAll()]);
        }
        if ($resource === 'period') {
            json_response(payroll_period_detail(db(), payroll_text($_GET, 'id', 36, true)));
        }
        if ($resource === 'export') {
            $detail = payroll_period_detail(db(), payroll_text($_GET, 'id', 36, true));
            require_once __DIR__ . '/payroll-export.php';
            $content = payroll_export_excel($detail);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="Bang-luong-' . $detail['period']['id'] . '.xlsx"');
            header('Content-Length: ' . strlen($content));
            echo $content;
            exit;
        }
        throw new BookingException('Không tìm thấy dữ liệu yêu cầu.', 404);
    }
    if ($method !== 'POST') {
        json_response(['error' => 'Phương thức không được hỗ trợ.'], 405);
    }
    require_csrf();
    if (str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
        require_once __DIR__ . '/payroll-import.php';
        if (($_POST['action'] ?? '') === 'timesheet/preview') {
            json_response(payroll_preview_timesheet_upload(db()));
        }
        json_response(payroll_preview_upload(db()));
    }
    $input = json_body();
    $action = payroll_text($input, 'action', 30, true);
    if ($action === 'employee/save') {
        json_response(['employee' => payroll_save_employee(db(), $input)]);
    }
    if ($action === 'employee/delete') {
        payroll_delete_employee(db(), $input);
        json_response(['deleted' => true]);
    }
    $actor = (string)current_account()['id'];
    if ($action === 'period/create') {
        json_response(['period' => payroll_create_period(db(), $input, $actor)], 201);
    }
    json_response(payroll_mutate_period(db(), $input, $actor));
}
