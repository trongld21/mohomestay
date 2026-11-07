<?php

require __DIR__ . '/revenue-integration-test.php';
require __DIR__ . '/../src/overview-view.php';

function db(): PDO
{
    global $p;
    return $p;
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$_GET = ['from' => '2026-10-25', 'to' => '2026-10-25'];
ob_start();
render_overview_page();
$html = ob_get_clean();
check(str_contains($html, '1.300đ'), 'Rendered total matches receipts');
check(str_contains($html, '25/10/2026'), 'Rendered Vietnam day');
check(str_contains($html, 'name="from"') && str_contains($html, 'name="to"'), 'Date filters present');
check(str_contains($html, 'Tổng quan kinh doanh'), 'Business dashboard heading present');
check(str_contains($html, 'data-overview-export'), 'Dashboard export available');
check(str_contains($html, 'Giao dịch gần đây'), 'Recent transactions visible');
check(str_contains($html, 'role="img"'), 'Charts have an accessible representation');
$dashboard = overview_dashboard_data(mo_revenue_report($p, '2026-10-25', '2026-10-25'));
check($dashboard['receipt_count'] === 3, 'Count receipts without expenses');
check($dashboard['net'] === 1250.0, 'Net cash flow subtracts actual expenses');
check(count($dashboard['hours']) === 24 && $dashboard['hours'][0]['collected'] === 1000.0 && $dashboard['hours'][1]['collected'] === 100.0, 'Hourly chart uses Vietnam payment time');
check($dashboard['rooms'][0]['amount'] === 1100.0, 'Room ranking excludes retail and expenses');

$_GET = ['from' => '2026-10-26', 'to' => '2026-10-25'];
ob_start();
render_overview_page();
$html = ob_get_clean();
check(str_contains($html, 'role="alert"'), 'Invalid range shows error');
check(!str_contains($html, '<table'), 'Invalid range does not show stale totals');

$_GET = ['from' => ['invalid'], 'to' => '2026-10-25'];
ob_start();
render_overview_page();
$html = ob_get_clean();
check(str_contains($html, 'role="alert"'), 'Array input handled');

echo "Overview page: PASS\n";
