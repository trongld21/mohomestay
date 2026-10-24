<?php

declare(strict_types=1);

function render_retail_page(bool $products): void
{
    ?>
    <section class="retail-page mx-auto grid w-full max-w-7xl gap-5 p-4 text-[#45382f] sm:p-6" data-retail-page data-mode="<?= $products ? 'products' : 'sales' ?>">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div><p class="text-xs font-semibold tracking-widest text-[#79543e]">MƠ HOME · BÁN HÀNG</p><h1 class="mt-2 font-sans text-2xl font-semibold"><?= $products ? 'Quản lý sản phẩm' : 'Bán hàng' ?></h1></div>
            <button type="button" class="timeline-button" data-retail-refresh>Làm mới</button>
        </header>
        <nav class="flex flex-wrap gap-2" aria-label="Các màn bán hàng">
            <?php if (can_use('sales.view') || can_use('sales.create')): ?><a class="timeline-button <?= !$products ? 'timeline-primary' : '' ?>" href="/admin/sales" <?= !$products ? 'aria-current="page"' : '' ?>>Bán hàng</a><?php endif ?>
            <?php if (can_use('products.view')): ?><a class="timeline-button <?= $products ? 'timeline-primary' : '' ?>" href="/admin/products" <?= $products ? 'aria-current="page"' : '' ?>>Quản lý sản phẩm</a><?php endif ?>
        </nav>
        <p class="text-sm text-stone-600" data-retail-status role="status"></p>
        <?php if ($products): ?>
            <?php if (can_use('products.create') || can_use('products.edit')): ?>
                <form class="retail-panel grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-product-form>
                    <input type="hidden" name="id">
                    <label class="grid gap-1 text-sm">Tên sản phẩm<input class="timeline-input" name="name" maxlength="150" required></label>
                    <label class="grid gap-1 text-sm">Giá bán (đ)<input class="timeline-input" name="price" type="number" min="1" max="100000000" step="1" inputmode="numeric" required></label>
                    <label class="flex min-h-11 items-center gap-2 self-end text-sm"><input class="size-4 accent-amber-900" type="checkbox" name="isActive" checked>Đang bán</label>
                    <div class="flex flex-wrap items-end gap-2"><button class="timeline-button timeline-primary" type="submit">Lưu sản phẩm</button><button class="timeline-button" type="button" data-product-reset>Làm mới form</button></div>
                </form>
            <?php endif ?>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-products></div>
        <?php else: ?>
            <?php if (can_use('sales.create')): ?>
                <div class="grid items-start gap-5 lg:grid-cols-[1.3fr_1fr]">
                    <section class="retail-panel"><h2 class="font-sans text-lg font-semibold">Chọn sản phẩm</h2><label class="mt-3 grid gap-1 text-sm">Tìm sản phẩm<input type="search" class="timeline-input" data-retail-search placeholder="Nhập tên sản phẩm…"></label><div class="mt-4 grid gap-3 sm:grid-cols-2" data-products></div></section>
                    <section class="retail-panel grid gap-4"><h2 class="font-sans text-lg font-semibold">Đơn bán hàng</h2>
                        <label class="grid gap-1 text-sm">Phòng liên quan (không bắt buộc)<select class="timeline-input" data-sale-room><option value="">Không gắn phòng</option></select></label>
                        <div class="grid gap-3" data-cart></div>
                        <p class="text-sm" data-cart-listed>Tổng theo bảng giá: 0đ</p>
                        <label class="grid gap-1 text-sm">Giá chốt (đ)<input class="timeline-input" data-cart-total type="number" min="1" max="100000000" step="1" <?= !can_use('sales.price') ? 'readonly' : '' ?>></label>
                        <p class="text-xs text-stone-600"><?= can_use('sales.price') ? 'Bạn có quyền điều chỉnh giá chốt của cả đơn.' : 'Giá chốt tự tính theo sản phẩm và số lượng.' ?></p>
                        <button type="button" class="timeline-button timeline-primary" data-sale-create disabled>Chốt đơn & tạo QR</button>
                        <p class="text-sm text-stone-600">Đơn chỉ được tính doanh thu khi payOS xác nhận thanh toán.</p>
                    </section>
                </div>
            <?php endif ?>
            <?php if (can_use('sales.view')): ?>
                <section class="retail-panel grid gap-4"><div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-sans text-lg font-semibold">Lịch sử bán hàng</h2><p class="font-semibold text-[#79543e]" data-sales-revenue></p></div><label class="grid gap-1 text-sm">Tra theo phòng hoặc mã đơn<input type="search" class="timeline-input" data-sales-filter maxlength="150" placeholder="Tên phòng hoặc mã BH…"></label><div class="overflow-x-auto" data-sales-history></div><div class="flex items-center gap-3"><button type="button" class="timeline-button" data-sales-prev>Trước</button><span class="text-sm" data-sales-page></span><button type="button" class="timeline-button" data-sales-next>Sau</button></div></section>
            <?php endif ?>
        <?php endif ?>
        <dialog class="timeline-dialog" data-sale-dialog aria-label="Đơn bán hàng và QR thanh toán"><div class="flex items-start justify-between gap-3 border-b border-[#e5dacb] p-4"><h2 class="font-sans text-lg font-semibold">Đơn bán hàng</h2><button type="button" class="timeline-button" data-sale-close>Đóng</button></div><div class="grid gap-3 p-4" data-sale-detail></div></dialog>
    </section>
    <script defer src="<?= e(asset_url('/assets/retail.js')) ?>"></script>
    <?php
}
