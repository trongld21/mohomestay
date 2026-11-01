<?php

declare(strict_types=1);

function payroll_export_excel(array $detail): string
{
    if (!class_exists('ZipArchive')) {
        throw new BookingException('Hosting cần bật PHP zip để xuất Excel.', 503);
    }
    $headers = ['Mã nhân viên', 'Họ và tên', 'Vị trí', 'Loại lương', 'Giờ làm', 'Ngày công', 'Lương/h mặc định', 'Lương cơ bản', 'Phụ cấp / thưởng', 'Khấu trừ', 'Thực nhận', 'Bonus (giờ T7/CN)', 'Cộng T7/CN (đ/giờ)', 'Tiền Bonus (đ)'];
    $rows = [[$detail['period']['name']], [$detail['period']['start_date'] . ' – ' . $detail['period']['end_date']], $headers];
    $totals = [0, 0, 0, 0, 0, 0];
    $totalBonusHours = 0;
    $totalBonusAmount = 0;
    foreach ($detail['entries'] as $entry) {
        $settings = $entry['details'];
        $bonusHours = $settings['salary_type'] === 'hourly' ? (float)($settings['weekend_hours'] ?? (($settings['weekend_minutes'] ?? 0) / 60)) : 0;
        $bonusAmount = (int)($settings['weekend_bonus_amount'] ?? 0);
        $totalBonusHours += $bonusHours;
        $totalBonusAmount += $bonusAmount;
        $rows[] = [$entry['employee_code'], $entry['employee_name'], $entry['role'], $settings['salary_type'] === 'hourly' ? 'Theo giờ' : 'Lương tháng',
            (int)$entry['minutes'] / 60, (int)$entry['working_days'],
            (int)($settings['salary_type'] === 'hourly' ? $settings['hourly_rate'] : $settings['monthly_salary']),
            (int)$entry['base_salary'], (int)$entry['allowance_total'], (int)$entry['deduction_total'], (int)$entry['net_salary'],
            $bonusHours, (int)($settings['weekend_bonus_rate'] ?? 1000), $bonusAmount];
        foreach (['minutes', 'working_days', 'base_salary', 'allowance_total', 'deduction_total', 'net_salary'] as $index => $key) {
            $totals[$index] += (int)$entry[$key];
        }
    }
    $rows[] = ['Tổng cộng', '', '', '', $totals[0] / 60, $totals[1], '', $totals[2], $totals[3], $totals[4], $totals[5], $totalBonusHours, '', $totalBonusAmount];
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="3" topLeftCell="A4" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="22" customWidth="1"/><col min="2" max="3" width="25" customWidth="1"/><col min="4" max="14" width="20" customWidth="1"/></cols><sheetData>';
    foreach ($rows as $index => $values) {
        $number = $index + 1;
        $sheet .= '<row r="' . $number . '">';
        foreach ($values as $column => $value) {
            $address = chr(65 + $column) . $number;
            if (is_int($value) || is_float($value)) {
                $sheet .= '<c r="' . $address . '" s="' . (in_array($column, [4, 11], true) ? 2 : 3) . '"><v>' . $value . '</v></c>';
            } else {
                $sheet .= '<c r="' . $address . '" s="' . ($index === 2 ? 1 : 0) . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData><autoFilter ref="A3:N' . (count($rows) - 1) . '"/><mergeCells count="2"><mergeCell ref="A1:N1"/><mergeCell ref="A2:N2"/></mergeCells></worksheet>';
    $path = tempnam(sys_get_temp_dir(), 'mo-payroll-export-');
    if ($path === false) {
        throw new BookingException('Không tạo được file Excel. Vui lòng thử lại.', 503);
    }
    $zip = new ZipArchive();
    try {
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new BookingException('Không tạo được file Excel. Vui lòng thử lại.', 503);
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Bảng lương" sheetId="1" r:id="r1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="r2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF346044"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        if (!$zip->close()) {
            throw new BookingException('Không hoàn tất được file Excel.', 503);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new BookingException('Không đọc được file Excel vừa tạo.', 503);
        }
        return $content;
    } finally {
        unset($zip);
        unlink($path);
    }
}
