<?php

declare(strict_types=1);

function render_expenses_page(): void
{
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
    ?>
    <section class="mx-auto grid w-full max-w-7xl gap-5 p-4 text-[#45382f] sm:p-6" data-expenses>
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div><p class="text-xs font-semibold tracking-widest text-[#79543e]">MƠ HOME · BÁN HÀNG</p><h1 class="mt-2 font-sans text-2xl font-semibold">Phiếu chi</h1><p class="mt-2 text-sm text-stone-600">Ghi lại khoản tiền đã chi theo ngày, giờ Việt Nam.</p></div>
            <?php if (can_use('expenses.create')): ?><button class="timeline-button timeline-primary" type="button" data-expense-new>Lập phiếu chi</button><?php endif ?>
        </header>
        <nav class="flex flex-wrap gap-2" aria-label="Các màn bán hàng">
            <?php foreach (['sales' => ['sales.create', 'Bán hàng'], 'sales/history' => ['sales.view', 'Lịch sử bán hàng'], 'inventory' => ['inventory.view', 'Nhập kho & tồn kho'], 'products' => ['products.view', 'Quản lý sản phẩm']] as $path => [$permission, $label]): ?>
                <?php if (can_use($permission)): ?><a class="timeline-button" href="/admin/<?= e($path) ?>"><?= e($label) ?></a><?php endif ?>
            <?php endforeach ?>
            <a class="timeline-button timeline-primary" href="/admin/expenses" aria-current="page">Phiếu chi</a>
        </nav>
        <p class="text-sm text-stone-600" role="status" data-expense-status></p>
        <section class="retail-panel grid gap-4">
            <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-expense-filter>
                <label class="grid gap-1 text-sm">Từ ngày<input class="timeline-input" type="date" name="from" value="<?= e($today) ?>" required></label>
                <label class="grid gap-1 text-sm">Đến ngày<input class="timeline-input" type="date" name="to" value="<?= e($today) ?>" required></label>
                <label class="grid gap-1 text-sm">Tìm phiếu chi<input class="timeline-input" type="search" name="q" maxlength="150" placeholder="Mã phiếu, loại chi, người nhận…"></label>
                <button class="timeline-button self-end" type="submit">Lọc / làm mới</button>
            </form>
            <div class="flex flex-wrap gap-4 rounded-xl bg-[#f5efe6] p-4 font-semibold" data-expense-totals></div>
            <p class="text-sm text-stone-600">Tổng theo thời gian chi của phiếu. Chỉ phiếu được tích hạch toán mới cộng vào tổng chi dòng tiền; phiếu chi không thay đổi doanh thu bán hàng.</p>
            <div class="overflow-x-auto" data-expense-list></div>
            <div class="flex items-center gap-3"><button class="timeline-button" type="button" data-expense-prev>Trước</button><span class="text-sm" data-expense-page></span><button class="timeline-button" type="button" data-expense-next>Sau</button></div>
        </section>
        <?php if (can_use('expenses.create')): ?>
            <dialog class="m-auto w-[calc(100%-2rem)] max-w-2xl rounded-2xl border border-[#e5dacb] bg-[#fffdf9] p-0 text-[#45382f] shadow-xl backdrop:bg-black/40" data-expense-dialog aria-labelledby="expense-dialog-title">
                <form class="flex max-h-[85dvh] flex-col" data-expense-form>
                    <header class="flex items-center justify-between gap-3 border-b border-[#e5dacb] p-5"><div><h2 class="font-sans text-xl font-semibold" id="expense-dialog-title">Lập phiếu chi</h2><p class="mt-1 text-sm text-stone-600">Mã phiếu tự tạo sau khi lưu</p></div><button type="button" class="timeline-button" data-expense-close>Đóng</button></header>
                    <div class="grid gap-4 overflow-y-auto p-5 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm">Thời gian chi (Việt Nam) *<input class="timeline-input" type="datetime-local" name="spentAt" required></label>
                        <label class="grid gap-1 text-sm">Loại chi *<input class="timeline-input" name="category" maxlength="150" required placeholder="Ví dụ: Chi mua nguyên liệu"></label>
                        <label class="grid gap-1 text-sm">Giá trị (đ) *<input class="timeline-input" type="number" name="amount" min="1" max="1000000000" step="1" inputmode="numeric" required placeholder="Nhập số tiền"></label>
                        <label class="grid gap-1 text-sm sm:col-span-2">Tên người nhận<input class="timeline-input" name="recipient" maxlength="150" placeholder="Ví dụ: Nhà cung cấp A"></label>
                        <label class="grid gap-1 text-sm sm:col-span-2">Ghi chú<textarea class="timeline-input" name="note" rows="3" maxlength="2000" placeholder="Ghi chú thêm nếu cần"></textarea></label>
                        <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm sm:col-span-2"><input class="size-4 accent-amber-900 focus-visible:outline-2 focus-visible:outline-offset-2" type="checkbox" name="includeCashflow" checked>Hạch toán vào dòng tiền trong ngày</label>
                        <p class="text-sm text-red-700 sm:col-span-2" role="alert" data-expense-error></p>
                    </div>
                    <footer class="flex justify-end gap-2 border-t border-[#e5dacb] p-5"><button class="timeline-button" type="button" data-expense-close>Bỏ qua</button><button class="timeline-button timeline-primary" type="submit">Lưu phiếu</button></footer>
                </form>
            </dialog>
        <?php endif ?>
    </section>
    <script defer src="<?= e(asset_url('/assets/expenses.js')) ?>"></script>
    <?php
}
