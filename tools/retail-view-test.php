<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/retail-view.php';

$grants = [];
function can_use(string $permission): bool
{
    global $grants;
    return in_array($permission, $grants, true);
}
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function asset_url(string $path): string
{
    return $path;
}
function view_check(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}
$grants = ['sales.view'];
ob_start();
render_retail_page(false, true);
$html = ob_get_clean();
view_check(str_contains($html, 'data-sales-history'), 'Viewer must see history.');
view_check(str_contains($html, 'data-mode="history"'), 'History must be its own screen.');
view_check(!str_contains($html, 'data-sale-create'), 'Viewer must not see creation controls.');
$grants = ['sales.view', 'sales.create', 'sales.price', 'products.view'];
ob_start();
render_retail_page(false);
$html = ob_get_clean();
view_check(str_contains($html, 'data-sale-create') && str_contains($html, 'data-cart-total'), 'Seller must see checkout.');
view_check(!str_contains($html, 'data-sales-history'), 'Creation-only grants do not expose history in the view.');
view_check(str_contains($html, '/admin/products'), 'Product tab must link to the second screen.');
$grants = ['products.view'];
ob_start();
render_retail_page(true);
$html = ob_get_clean();
view_check(str_contains($html, 'data-products') && !str_contains($html, 'data-product-form'), 'Product viewer must not see edit form.');
$grants = ['products.view', 'products.create'];
ob_start();
render_retail_page(true);
$html = ob_get_clean();
view_check(str_contains($html, 'data-product-form'), 'Product creator must see form.');
$grants = ['inventory.view'];
ob_start();
render_retail_page(false, false, true);
$html = ob_get_clean();
view_check(str_contains($html, 'data-stock-history') && str_contains($html, 'data-stock-products'), 'Inventory viewer sees stock and audit.');
view_check(!str_contains($html, 'data-stock-form'), 'Inventory viewer cannot receive stock.');
$grants = ['inventory.view', 'inventory.receive'];
ob_start();
render_retail_page(false, false, true);
$html = ob_get_clean();
view_check(str_contains($html, 'data-stock-form'), 'Warehouse operator sees receipt form.');
echo "Retail view permission and tab contracts: OK\n";
