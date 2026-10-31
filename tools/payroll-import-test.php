<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/payroll.php';
require APP_ROOT . '/src/payroll-import.php';

function import_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function import_fixture(array $rows, bool $formula = false): string
{
    $path = tempnam(__DIR__, 'mo-timesheet-');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Chấm công" r:id="r1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="r1" Target="worksheets/sheet1.xml"/></Relationships>');
    $xml = '<worksheet><sheetData><row r="8"><c r="B8" t="inlineStr"><is><t>Ngày làm</t></is></c><c r="C8" t="inlineStr"><is><t>Check-in</t></is></c><c r="D8" t="inlineStr"><is><t>Check-out</t></is></c></row>';
    foreach ($rows as $index => $row) {
        $number = $index + 9;
        $xml .= '<row r="' . $number . '">';
        foreach (['B', 'C', 'D'] as $position => $column) {
            $value = htmlspecialchars((string)$row[$position], ENT_XML1);
            $xml .= '<c r="' . $column . $number . '" t="inlineStr">' . ($formula && $position === 1 ? '<f>NOW()</f>' : '') . '<is><t>' . $value . '</t></is></c>';
        }
        $xml .= '</row>';
    }
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml . '</sheetData></worksheet>');
    $zip->close();
    return $path;
}

$period = ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'];
$details = ['salary_type' => 'hourly', 'hourly_rate' => 25000, 'expected_days' => 26];
$read = static function (array $rows, bool $formula = false) use ($period, $details): array {
    $path = import_fixture($rows, $formula);
    try {
        return payroll_read_timesheet($path, $period, $details);
    } finally {
        unlink($path);
    }
};
$result = $read([['01/10/2026', '9:03', '17:00'], ['2026-10-02', '22:00', '06:00']]);
import_check($result['errors'] === [] && count($result['shifts']) === 2, 'Text dates, h:mm and overnight must import.');
import_check($result['calculated']['minutes'] === 957 && $result['calculated']['net_salary'] === 398750, 'Server must calculate salary from actual minutes.');
$serial = (new DateTimeImmutable('2026-10-03'))->diff(new DateTimeImmutable('1899-12-30'))->days;
$result = $read([[(string)$serial, (string)(9 / 24), '0']]);
import_check(!$result['errors'] && $result['shifts'][0]['out'] === '00:00', 'Numeric Excel date/time and midnight must work.');
foreach ([
    [['01/11/2026', '09:00', '17:00']],
    [['31/02/2026', '09:00', '17:00']],
    [['01/10/2026', '', '17:00']],
    [['01/10/2026', '9.03', '17:00']],
    [['01/10/2026', '09:00', '09:00']],
    [['01/10/2026', '09:00', '17:00'], ['01/10/2026', '16:00', '18:00']],
] as $invalid) {
    $result = $read($invalid);
    import_check(count($result['errors']) > 0 && $result['calculated'] === null, 'Invalid input must block import.');
}
import_check(count($read([['01/10/2026', '09:00', '17:00']], true)['errors']) === 1, 'Input formulas must not execute.');
$path = tempnam(__DIR__, 'mo-invalid-');
file_put_contents($path, 'not an Excel file');
try {
    payroll_read_timesheet($path, $period, $details);
    throw new RuntimeException('Non-Excel file accepted.');
} catch (BookingException $error) {
    import_check($error->httpStatus === 422, 'Invalid file status.');
} finally {
    unlink($path);
}
echo "Excel timesheet parsing, serial/text dates, times, overnight, overlap, formulas and invalid files: OK\n";

$readText = static function (string $text, string $source = '') use ($period, $details): array {
    $path = tempnam(__DIR__, 'mo-text-');
    file_put_contents($path, $text);
    try {
        return payroll_read_text_timesheet($path, $period, $details, ['employee_code' => 'NV-TEST', 'employee_name' => 'Nguyễn An'], $source);
    } finally {
        unlink($path);
    }
};
$text = "ID\tMachine\tEnNo\tName\tMode\tVerify\tDateTime\r\n"
    . "1\t1\t12\tNguyễn An\t0\t0\t2026-10-01 17:00:12\r\n"
    . "2\t1\t12\tNguyễn An\t0\t0\t2026-10-01 09:03:05\r\n"
    . "3\t1\t99\tBình\t0\t0\t2026-10-01 09:00:00\r\n";
$result = $readText("\xEF\xBB\xBF" . $text);
import_check(!$result['errors'] && count($result['sources']) === 2 && $result['calculated']['minutes'] === 477, 'TXT must group employees, match name, sort punches and use minute precision.');
$result = $readText("1;1;12;Nguyễn An;0;0;01/10/2026 22:00\n2;1;12;Nguyễn An;0;0;02/10/2026 05:00");
import_check(!$result['errors'] && $result['calculated']['minutes'] === 420, 'TXT overnight pairing must use the TNCompany 05:00 boundary.');
$result = $readText("1,1,12,Nguyễn An,0,0,2026-10-01 09:00\n2,1,12,Nguyễn An,0,0,2026-10-02 09:00");
import_check(count($result['errors']) === 2 && $result['calculated'] === null, 'Unpaired punches must block confirmation.');
$result = $readText($text, hash('sha256', "99\0Bình"));
import_check(count($result['errors']) === 1 && $result['selected_source'] === hash('sha256', "99\0Bình"), 'Explicit source selection must not mix employee punches.');
$result = $readText("1;1;12;Nguyễn An;0;0;31/02/2026 09:00");
import_check(count($result['errors']) === 1, 'Invalid calendar dates must be reported.');
$result = $readText("1;1;12;Nguyễn An;0;0;2026-10-01 09:00\n2;1;12;Nguyễn An;0;0;2026-10-01 09:00");
import_check($result['errors'] !== [], 'Duplicate punches must not silently change pay.');
$result = $readText("1;1;22;Không khớp;0;0;2026-10-01 09:00\n2;1;22;Không khớp;0;0;2026-10-01 17:00");
import_check($result['selected_source'] === '' && $result['calculated'] === null, 'Unmatched source must require explicit selection.');
$result = $readText("1;1;12;Nguyễn An;0;0;2026-09-30 09:00\n2;1;12;Nguyễn An;0;0;2026-10-31 22:00\n3;1;12;Nguyễn An;0;0;2026-11-01 05:00");
import_check(!$result['errors'] && $result['ignored'] === 1 && $result['calculated']['minutes'] === 420, 'Period filtering must preserve the final overnight checkout.');
echo "TXT grouping, employee selection, ordering, overnight boundary and invalid punches: OK\n";
$path = tempnam(__DIR__, 'mo-batch-');
file_put_contents($path, $text);
try {
    $batch = payroll_preview_timesheet_file($path, $period, [['id' => 'an', 'code' => 'NV-TEST', 'name' => 'Nguyễn An']]);
    import_check(count($batch['employees']) === 2, 'Standalone preview must include all source employees.');
    import_check($batch['employees'][0]['minutes'] === 477 && $batch['employees'][0]['employee_id'] === 'an', 'Standalone preview must calculate hours and match personnel by name.');
    import_check($batch['employees'][1]['match'] === 'unmatched' && count($batch['employees'][1]['errors']) === 1, 'Unmatched employee and unpaired punches must remain visible.');
    try {
        payroll_preview_timesheet_file($path, ['start_date' => '2026-10-01', 'end_date' => '2027-02-01'], []);
        throw new RuntimeException('Oversized range accepted.');
    } catch (BookingException $error) {
        import_check($error->httpStatus === 422, 'Standalone range must be limited.');
    }
} finally {
    unlink($path);
}
echo "Standalone timesheet preview, all employees, personnel matching and range validation: OK\n";
$path = import_fixture([['03/10/2026', '09:00', '17:00']]);
try {
    $preview = payroll_preview_employee_excel($path, $period, ['id' => 'an', 'code' => 'NV-TEST', 'name' => 'Nguyễn An']);
    import_check(count($preview['employees']) === 1 && $preview['employees'][0]['employee_id'] === 'an', 'Standalone Excel must be assigned to the explicitly selected employee.');
    import_check($preview['employees'][0]['minutes'] === 480 && $preview['employees'][0]['weekend_minutes'] === 480, 'Standalone Excel must calculate actual and weekend minutes.');
} finally {
    unlink($path);
}
echo "Standalone employee Excel selection, actual hours and weekend hours: OK\n";
$path = import_fixture([['03/10/2026', '09:00', '10:00'], ['04/10/2026', '09:00', '10:00']]);
try {
    $preview = payroll_read_timesheet($path, $period, array_replace($details, ['weekend_hours' => 1.5]));
    import_check($preview['errors'] === [] && $preview['calculated']['details']['weekend_hours'] === 1.5, 'Import must validate manual Bonus hours against all shifts, not each individual row.');
    $automatic = payroll_read_timesheet($path, $period, $details);
    import_check($automatic['calculated']['details']['weekend_minutes'] === 120 && $automatic['calculated']['details']['weekend_bonus_amount'] === 2000, 'Imported weekend shifts must calculate Bonus automatically.');
} finally {
    unlink($path);
}
