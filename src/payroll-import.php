<?php

declare(strict_types=1);

function payroll_xlsx_xml(ZipArchive $zip, string $path): SimpleXMLElement
{
    $stat = $zip->statName($path);
    if (!$stat || $stat['size'] > 3000000) {
        throw new BookingException('Thành phần Excel bị thiếu hoặc quá lớn.', 422);
    }
    $text = $zip->getFromName($path);
    if ($text === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $text)) {
        throw new BookingException('Nội dung XML của Excel không được hỗ trợ.', 422);
    }
    $previous = libxml_use_internal_errors(true);
    try {
        $xml = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET);
        if (!$xml) {
            throw new BookingException('File Excel bị lỗi cấu trúc XML.', 422);
        }
        return $xml;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function payroll_xlsx_label(string $value): string
{
    return preg_replace('/[\s\-_–]+/u', '', mb_strtolower(trim($value)));
}

function payroll_xlsx_date(string $value, bool $date1904): string
{
    if (is_numeric($value)) {
        $serial = (float)$value;
        if ($serial !== floor($serial) || $serial < 1 || $serial > 73050) {
            throw new BookingException('Ngày Excel không hợp lệ.', 422);
        }
        return (new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30'))->modify('+' . (int)$serial . ' days')->format('Y-m-d');
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $parts)) {
        $value = sprintf('%04d-%02d-%02d', $parts[3], $parts[2], $parts[1]);
    }
    return payroll_date($value)->format('Y-m-d');
}

function payroll_xlsx_time(string $value): string
{
    if (is_numeric($value)) {
        $fraction = (float)$value;
        $minute = (int)round($fraction * 1440);
        if ($fraction < 0 || $fraction >= 1 || $minute >= 1440 || abs($fraction * 1440 - $minute) > 0.001) {
            throw new BookingException('Giờ Excel phải chính xác đến phút, trong một ngày.', 422);
        }
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $parts)) {
        throw new BookingException('Nhập giờ dạng 9:03 hoặc 17:30.', 422);
    }
    return sprintf('%02d:%02d', $parts[1], $parts[2]);
}

function payroll_read_timesheet(string $path, array $period, array $details): array
{
    if (!class_exists(ZipArchive::class) || !function_exists('simplexml_load_string')) {
        throw new BookingException('Hosting cần bật PHP zip và SimpleXML để đọc Excel.', 503);
    }
    if (!is_file($path) || filesize($path) > 5 * 1024 * 1024) {
        throw new BookingException('Chọn file .xlsx tối đa 5 MB.', 422);
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new BookingException('File không phải Excel .xlsx hợp lệ.', 422);
    }
    try {
        $size = 0;
        if ($zip->numFiles > 2000) {
            throw new BookingException('File Excel có quá nhiều thành phần.', 422);
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $size += $stat['size'];
            if ($size > 20 * 1024 * 1024 || preg_match('#vbaProject|externalLinks/#i', $stat['name'])) {
                throw new BookingException('File Excel quá lớn hoặc chứa macro/liên kết ngoài.', 422);
            }
        }
        $strings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $xml = payroll_xlsx_xml($zip, 'xl/sharedStrings.xml');
            foreach ($xml->xpath('//*[local-name()="si"]') as $item) {
                $strings[] = implode('', array_map('strval', $item->xpath('.//*[local-name()="t"]')));
            }
        }
        $workbook = payroll_xlsx_xml($zip, 'xl/workbook.xml');
        $properties = $workbook->xpath('//*[local-name()="workbookPr"]');
        $date1904 = isset($properties[0]) && in_array((string)$properties[0]['date1904'], ['1', 'true'], true);
        $relationships = payroll_xlsx_xml($zip, 'xl/_rels/workbook.xml.rels');
        $targets = [];
        foreach ($relationships->xpath('//*[local-name()="Relationship"]') as $relation) {
            if ((string)$relation['TargetMode'] === 'External') {
                continue;
            }
            $target = (string)$relation['Target'];
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            if (preg_match('#^xl/worksheets/[A-Za-z0-9_.-]+\.xml$#', $target)) {
                $targets[(string)$relation['Id']] = $target;
            }
        }
        $candidates = [];
        $sheets = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
        if (count($sheets) > 10) {
            throw new BookingException('File tối đa 10 sheet. Tách riêng sheet của nhân viên cần nhập.', 422);
        }
        foreach ($sheets as $sheet) {
            $id = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (!isset($targets[$id])) {
                continue;
            }
            $xml = payroll_xlsx_xml($zip, $targets[$id]);
            $rows = [];
            foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                $cells = [];
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    if (!preg_match('/^([A-Z]{1,3})\d+$/', (string)$cell['r'], $match)) {
                        continue;
                    }
                    $values = $cell->xpath('./*[local-name()="v"]');
                    $value = isset($values[0]) ? (string)$values[0] : '';
                    if ((string)$cell['t'] === 's') {
                        $value = $strings[(int)$value] ?? '';
                    } elseif ((string)$cell['t'] === 'inlineStr') {
                        $value = implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]')));
                    }
                    $cells[$match[1]] = ['value' => trim($value), 'formula' => count($cell->xpath('./*[local-name()="f"]')) > 0];
                }
                $rows[(int)$row['r']] = $cells;
            }
            foreach ($rows as $number => $cells) {
                $columns = [];
                foreach ($cells as $column => $cell) {
                    $key = payroll_xlsx_label($cell['value']);
                    $field = match ($key) {
                        'ngàylàm', 'ngày' => 'date',
                        'checkin' => 'in',
                        'checkout' => 'out',
                        default => null,
                    };
                    if ($field !== null) {
                        $columns[$field] = $column;
                    }
                }
                if (count($columns) === 3) {
                    $candidates[] = ['name' => (string)$sheet['name'], 'rows' => $rows, 'header' => $number, 'columns' => $columns];
                    break;
                }
            }
        }
        if (count($candidates) !== 1) {
            throw new BookingException('File cần đúng một sheet có cột Ngày làm, Check-in, Check-out. Tách riêng sheet cần nhập.', 422);
        }
        $source = $candidates[0];
        $shifts = [];
        $errors = [];
        foreach ($source['rows'] as $number => $cells) {
            if ($number <= $source['header']) {
                continue;
            }
            $fields = [];
            $formula = false;
            foreach ($source['columns'] as $field => $column) {
                $fields[$field] = $cells[$column]['value'] ?? '';
                $formula = $formula || ($cells[$column]['formula'] ?? false);
            }
            if (implode('', $fields) === '' && !$formula) {
                continue;
            }
            if (count($shifts) + count($errors) >= 200) {
                throw new BookingException('Tối đa 200 dòng chấm công mỗi lần nhập.', 422);
            }
            try {
                if ($formula || in_array('', $fields, true)) {
                    throw new BookingException('Thiếu ngày/giờ hoặc ô nhập chứa công thức. Nhập giá trị trực tiếp.', 422);
                }
                $shift = [
                    'date' => payroll_xlsx_date($fields['date'], $date1904),
                    'in' => payroll_xlsx_time($fields['in']), 'out' => payroll_xlsx_time($fields['out']),
                    'break_minutes' => 0,
                ];
                payroll_calculate(array_replace($details, ['shifts' => [$shift], 'weekend_hours' => null]), $period);
                $shifts[] = $shift;
            } catch (BookingException $error) {
                $errors[] = ['row' => $number, 'message' => $error->getMessage()];
            }
        }
        if (!$shifts && !$errors) {
            throw new BookingException('File chưa có ngày và giờ chấm công để nhập.', 422);
        }
        $calculated = null;
        if (!$errors) {
            try {
                $calculated = payroll_calculate(array_replace($details, ['shifts' => $shifts]), $period);
            } catch (BookingException $error) {
                $errors[] = ['row' => null, 'message' => $error->getMessage()];
            }
        }
        return ['sheet' => $source['name'], 'shifts' => $shifts, 'errors' => $errors, 'calculated' => $calculated];
    } finally {
        $zip->close();
    }
}

function payroll_text_timestamp(string $value): DateTimeImmutable
{
    if (!preg_match('/^(\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{4})[ T](\d{1,2}:\d{2})(?::(\d{2}))?$/D', $value, $match)) {
        throw new BookingException('Thời điểm quẹt phải có ngày và giờ (ví dụ 2026-10-01 09:03:00).', 422);
    }
    $date = str_replace(['/', '.'], '-', $match[1]);
    $format = strlen(explode('-', $date)[0]) === 4 ? 'Y-n-j' : 'j-n-Y';
    $timestamp = DateTimeImmutable::createFromFormat('!' . $format . ' G:i:s', $date . ' ' . $match[2] . ':' . ($match[3] ?? '00'), new DateTimeZone('Asia/Ho_Chi_Minh'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$timestamp || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
        throw new BookingException('Ngày hoặc giờ quẹt không hợp lệ.', 422);
    }
    return $timestamp;
}

function payroll_text_groups(string $path): array
{
    if (!is_file($path) || filesize($path) > 5 * 1024 * 1024) {
        throw new BookingException('Chọn file .txt tối đa 5 MB.', 422);
    }
    $text = file_get_contents($path);
    if ($text === false || str_contains($text, "\0") || !preg_match('//u', $text)) {
        throw new BookingException('File TXT phải được lưu ở định dạng UTF-8.', 422);
    }
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $lines = preg_split('/\r\n|\n|\r/', $text);
    if (count($lines) > 20000) {
        throw new BookingException('Tối đa 20.000 dòng trong file TXT.', 422);
    }
    $groups = [];
    $fileErrors = [];
    foreach ($lines as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        $delimiter = str_contains($line, "\t") ? "\t" : (str_contains($line, ';') ? ';' : ',');
        $fields = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
        if (isset($fields[6]) && in_array(strtolower($fields[6]), ['datetime', 'date time'], true)) {
            continue;
        }
        if (count($fields) < 7 || ($fields[2] === '' && $fields[3] === '')) {
            $fileErrors[] = ['row' => $index + 1, 'message' => 'Cần ít nhất 7 cột: mã NV ở cột 3, tên ở cột 4, thời điểm quẹt ở cột 7.'];
            continue;
        }
        $key = hash('sha256', $fields[2] . "\0" . $fields[3]);
        if (!isset($groups[$key])) {
            if (count($groups) >= 500) {
                throw new BookingException('Tối đa 500 nhân viên trong file TXT.', 422);
            }
            $groups[$key] = ['key' => $key, 'code' => $fields[2], 'name' => $fields[3], 'rows' => []];
        }
        $groups[$key]['rows'][] = ['row' => $index + 1, 'value' => $fields[6]];
    }
    if (!$groups) {
        throw new BookingException('Không tìm thấy bản ghi chấm công TXT hợp lệ với 7 cột như tncompany.', 422);
    }
    return ['groups' => $groups, 'errors' => $fileErrors];
}

function payroll_read_text_timesheet(string $path, array $period, array $details, array $employee, string $selected = '', ?array $parsed = null): array
{
    $parsed ??= payroll_text_groups($path);
    $groups = $parsed['groups'];
    $fileErrors = $parsed['errors'];
    if ($selected === '') {
        $matches = array_filter($groups, static fn (array $group): bool =>
            $group['code'] === ($employee['employee_code'] ?? '')
            || payroll_xlsx_label($group['name']) === payroll_xlsx_label($employee['employee_name'] ?? ''));
        if (count($matches) === 1) {
            $selected = array_key_first($matches);
        }
    }
    $sources = array_values(array_map(static fn (array $group): array => [
        'key' => $group['key'], 'code' => $group['code'], 'name' => $group['name'], 'count' => count($group['rows']),
    ], $groups));
    $result = ['sheet' => 'TXT / quẹt chấm công', 'sources' => $sources, 'selected_source' => $selected,
        'shifts' => [], 'errors' => $fileErrors, 'calculated' => null, 'ignored' => 0];
    if (!isset($groups[$selected])) {
        $result['errors'][] = ['row' => null, 'message' => 'Chọn nhân viên trong file TXT để ghép với hồ sơ đang mở.'];
        return $result;
    }
    $punches = [];
    $start = payroll_date($period['start_date']);
    $end = payroll_date($period['end_date'])->modify('+1 day')->setTime(5, 0);
    foreach ($groups[$selected]['rows'] as $row) {
        try {
            $time = payroll_text_timestamp($row['value']);
            // Compare local calendar values: payroll_date() uses the application's default timezone.
            if ($time->format('Y-m-d H:i') < $start->format('Y-m-d 00:00') || $time->format('Y-m-d H:i') > $end->format('Y-m-d H:i')) {
                $result['ignored']++;
                continue;
            }
            $punches[] = ['time' => $time, 'row' => $row['row']];
        } catch (BookingException $error) {
            $result['errors'][] = ['row' => $row['row'], 'message' => $error->getMessage()];
        }
    }
    usort($punches, static fn (array $left, array $right): int => $left['time'] <=> $right['time']);
    for ($index = 0; $index < count($punches); $index++) {
        $current = $punches[$index];
        $in = $current['time'];
        $out = $punches[$index + 1]['time'] ?? null;
        $limit = ((int)$in->format('G') >= 5 ? $in->modify('+1 day') : $in)->setTime(5, 0);
        if (!$out || $out <= $in || $out > $limit) {
            $result['errors'][] = ['row' => $current['row'], 'message' => 'Thiếu giờ ra hoặc hai lần quẹt không ghép được trước mốc 05:00.'];
            continue;
        }
        $shift = ['date' => $in->format('Y-m-d'), 'in' => $in->format('H:i'), 'out' => $out->format('H:i'), 'break_minutes' => 0];
        try {
            payroll_calculate(array_replace($details, ['shifts' => [$shift], 'weekend_hours' => null]), $period);
            $result['shifts'][] = $shift;
        } catch (BookingException $error) {
            $result['errors'][] = ['row' => $current['row'], 'message' => $error->getMessage()];
        }
        $index++;
        if (count($result['shifts']) > 200) {
            throw new BookingException('Tối đa 200 ca làm mỗi lần nhập.', 422);
        }
    }
    if (!$result['shifts'] && !$result['errors']) {
        $result['errors'][] = ['row' => null, 'message' => 'Không có ca làm trong khoảng ngày của kỳ lương.'];
    }
    if (!$result['errors']) {
        try {
            $result['calculated'] = payroll_calculate(array_replace($details, ['shifts' => $result['shifts']]), $period);
        } catch (BookingException $error) {
            $result['errors'][] = ['row' => null, 'message' => $error->getMessage()];
        }
    }
    return $result;
}

function payroll_preview_timesheet_file(string $path, array $period, array $employees): array
{
    $start = payroll_date($period['start_date'] ?? null);
    $end = payroll_date($period['end_date'] ?? null);
    if ($end < $start || $end > $start->modify('+92 days')) {
        throw new BookingException('Chọn khoảng ngày hợp lệ, tối đa 93 ngày.', 422);
    }
    $parsed = payroll_text_groups($path);
    $rows = [];
    $details = ['salary_type' => 'hourly', 'hourly_rate' => 0, 'expected_days' => 26];
    foreach ($parsed['groups'] as $group) {
        // File-level errors are shown once, independently of each employee's punches.
        $preview = payroll_read_text_timesheet($path, $period, $details, [], $group['key'], ['groups' => [$group['key'] => $group], 'errors' => []]);
        $matches = array_values(array_filter($employees, static fn (array $employee): bool =>
            $employee['code'] === $group['code'] || payroll_xlsx_label($employee['name']) === payroll_xlsx_label($group['name'])));
        $minutes = 0;
        $weekendMinutes = 0;
        foreach ($preview['shifts'] as $shift) {
            $calculated = payroll_calculate(array_replace($details, ['shifts' => [$shift]]), $period);
            $minutes += $calculated['minutes'];
            if ((int)payroll_date($shift['date'])->format('N') >= 6) {
                $weekendMinutes += $calculated['minutes'];
            }
        }
        $rows[] = [
            'key' => $group['key'], 'code' => $group['code'], 'name' => $group['name'],
            'employee_id' => count($matches) === 1 ? $matches[0]['id'] : null,
            'match' => count($matches) === 1 ? 'matched' : (count($matches) > 1 ? 'ambiguous' : 'unmatched'),
            'shifts' => $preview['shifts'], 'errors' => $preview['errors'], 'ignored' => $preview['ignored'],
            'minutes' => $minutes, 'weekend_minutes' => $weekendMinutes,
        ];
    }
    return ['start_date' => $period['start_date'], 'end_date' => $period['end_date'], 'employees' => $rows, 'errors' => $parsed['errors']];
}

function payroll_preview_timesheet_upload(PDO $pdo): array
{
    $format = payroll_text($_POST, 'source_format', 10) ?: 'machine';
    if (!in_array($format, ['machine', 'excel'], true)) {
        throw new BookingException('Chọn loại file chấm công hợp lệ.', 422);
    }
    $file = $_FILES['timesheet'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['name'] ?? null)
        || !in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), $format === 'excel' ? ['xlsx'] : ['txt', 'csv'], true)
        || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new BookingException($format === 'excel' ? 'Chọn file Excel .xlsx hợp lệ, tối đa 5 MB.' : 'Chọn file TXT hoặc CSV hợp lệ, tối đa 5 MB.', 422);
    }
    $period = [
        'start_date' => payroll_text($_POST, 'start_date', 10, true),
        'end_date' => payroll_text($_POST, 'end_date', 10, true),
    ];
    if ($format === 'excel') {
        $employee = payroll_find($pdo, 'payroll_employees', payroll_text($_POST, 'employee_id', 36, true));
        if ($employee['status'] === 'deleted') {
            throw new BookingException('Hồ sơ nhân viên đã được xóa.', 404);
        }
        return payroll_preview_employee_excel($file['tmp_name'], $period, $employee);
    }
    return payroll_preview_timesheet_file($file['tmp_name'], $period, $pdo->query("SELECT id,code,name FROM payroll_employees WHERE status<>'deleted'")->fetchAll());
}

function payroll_preview_employee_excel(string $path, array $period, array $employee): array
{
    $start = payroll_date($period['start_date']);
    $end = payroll_date($period['end_date']);
    if ($end < $start || $end > $start->modify('+92 days')) {
        throw new BookingException('Chọn khoảng ngày hợp lệ, tối đa 93 ngày.', 422);
    }
    $details = ['salary_type' => 'hourly', 'hourly_rate' => 0, 'expected_days' => 26];
    $preview = payroll_read_timesheet($path, $period, $details);
    $minutes = 0;
    $weekendMinutes = 0;
    foreach ($preview['shifts'] as $shift) {
        $calculated = payroll_calculate(array_replace($details, ['shifts' => [$shift]]), $period);
        $minutes += $calculated['minutes'];
        if ((int)payroll_date($shift['date'])->format('N') >= 6) {
            $weekendMinutes += $calculated['minutes'];
        }
    }
    return ['start_date' => $period['start_date'], 'end_date' => $period['end_date'], 'errors' => [], 'employees' => [[
        'key' => $employee['id'], 'code' => $employee['code'], 'name' => $employee['name'],
        'employee_id' => $employee['id'], 'match' => 'matched', 'shifts' => $preview['shifts'],
        'errors' => $preview['errors'], 'ignored' => 0, 'minutes' => $minutes, 'weekend_minutes' => $weekendMinutes,
    ]]];
}

function payroll_preview_upload(PDO $pdo): array
{
    $period = payroll_find($pdo, 'payroll_periods', payroll_text($_POST, 'period_id', 36, true));
    if ((int)$period['version'] !== payroll_integer($_POST, 'version', PHP_INT_MAX)) {
        throw new BookingException('Kỳ vừa thay đổi. Tải lại trước khi nhập.', 409);
    }
    $employeeId = payroll_text($_POST, 'employee_id', 36, true);
    $query = $pdo->prepare('SELECT details,employee_code,employee_name FROM payroll_entries WHERE period_id=? AND employee_id=?');
    $query->execute([$period['id'], $employeeId]);
    $entry = $query->fetch();
    if ($entry === false) {
        throw new BookingException('Thêm nhân viên vào kỳ lương trước khi nhập chấm công.', 422);
    }
    $file = $_FILES['timesheet'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['name'] ?? null)
        || !in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['xlsx', 'txt'], true)
        || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new BookingException('Chọn file .xlsx hoặc .txt hợp lệ, tối đa 5 MB.', 422);
    }
    $details = json_decode($entry['details'], true, 512, JSON_THROW_ON_ERROR);
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'txt') {
        return payroll_read_text_timesheet($file['tmp_name'], $period, $details, $entry, payroll_text($_POST, 'source_employee', 64));
    }
    return payroll_read_timesheet($file['tmp_name'], $period, $details);
}
