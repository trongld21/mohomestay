<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/promotions-view.php';

$editable = false;
function can_use(string $permission): bool
{
    global $editable;
    return $permission === 'rooms.edit' && $editable;
}
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function asset_url(string $value): string
{
    return $value;
}
function promotion_ui_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

foreach ([false, true] as $editable) {
    ob_start();
    render_promotions_page();
    $html = (string)ob_get_clean();
    promotion_ui_assert(str_contains($html, 'data-promotions'), 'Promotion page must render.');
    promotion_ui_assert(str_contains($html, 'data-promotion-form') === $editable, 'Only room editors may manage promotions.');
    promotion_ui_assert(str_contains($html, 'data-promotion-new') === $editable, 'Create action must follow edit permission.');
    promotion_ui_assert(!str_contains($html, 'style='), 'Use Tailwind rather than inline CSS.');
    if ($editable) {
        promotion_ui_assert(substr_count($html, 'name="weekdays"') === 7, 'All ISO weekdays must be selectable.');
        promotion_ui_assert(str_contains($html, '24:00'), 'End time must support midnight.');
        promotion_ui_assert(
            (bool)preg_match('/name="allScope"\s+checked/', $html),
            'Scope defaults to all.'
        );
    }
}

$index = file_get_contents(dirname(__DIR__) . '/public/index.php');
$accounts = file_get_contents(dirname(__DIR__) . '/src/accounts.php');
promotion_ui_assert((bool)preg_match("~/admin/promotions'\\) \\{.*?require_permission\\('rooms.view'\\)~s", $index), 'Page requires room view permission.');
promotion_ui_assert(str_contains($accounts, "'promotions' => \$method === 'GET' ? 'rooms.view' : 'rooms.edit'"), 'API reads and writes use existing room permissions.');
echo "Promotion UI checks passed.\n";
