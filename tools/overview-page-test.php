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
