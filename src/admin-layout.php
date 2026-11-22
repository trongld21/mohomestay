<?php

require_once __DIR__ . '/admin-icons.php';
require_once __DIR__ . '/branches.php';

function render_admin_branch_switcher(array $account): void
{
    $ids = $account['root'] ? [1, 2] : [(int)$account['branch_id']];
    ?>
    <details class="branch-switcher" data-branch-switcher>
        <summary class="branch-switcher-trigger" aria-label="Chọn chi nhánh · <?= e(branch_label()) ?>">
            <span class="branch-switcher-icon"><?= admin_icon('home') ?></span>
            <span class="min-w-0 flex-1"><strong class="block text-sm font-semibold">Mơ Home</strong><span class="mt-1 block break-words text-xs text-[#79543e]"><?= e(branch_label()) ?></span></span>
            <svg class="branch-switcher-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <nav class="branch-switcher-menu" aria-label="Chọn chi nhánh">
            <p class="px-3 pb-2 pt-1 text-xs font-semibold uppercase tracking-wider text-[#82705e]">Chọn không gian quản lý</p>
            <?php foreach ($ids as $id): ?>
                <a class="branch-switcher-option" data-branch-switch href="<?= e(branch_url('/admin', $id)) ?>" <?= branch_id() === $id ? 'aria-current="true"' : '' ?>>
                    <span class="branch-switcher-code">CN<?= str_pad((string)$id, 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="min-w-0 flex-1 break-words"><?= e(branch_label($id)) ?></span>
                    <?php if (branch_id() === $id): ?><?= admin_icon('check') ?><?php endif ?>
                </a>
            <?php endforeach ?>
            <?php if ($account['root']): ?>
                <a class="branch-switcher-manage" href="/admin/branches"><?= admin_icon('home') ?><span>Quản lý chi nhánh</span></a>
            <?php endif ?>
        </nav>
    </details>
    <?php
}

function render_admin_navigation(string $path): void
{
    $account = current_account();
    $link = 'flex min-h-11 items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium text-stone-700 hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800';
    ?>
    <div class="admin-menu-backdrop" data-admin-menu-backdrop hidden aria-hidden="true"></div>
    <aside id="admin-sidebar" class="admin-sidebar" aria-label="Điều hướng quản trị">
        <div class="admin-sidebar-heading">
            <a class="admin-wordmark" href="<?= e(account_home()) ?>">Mơ<span>Home</span><small>KHÔNG GIAN QUẢN LÝ</small></a>
            <button class="icon-button admin-menu-close" type="button" data-admin-menu-close aria-label="Đóng menu quản trị">
                <?= admin_icon('x') ?>
            </button>
        </div>
        <?php render_admin_branch_switcher($account); ?>
        <p class="admin-nav-label">QUẢN LÝ KHÔNG GIAN</p>
        <nav class="admin-navigation" aria-label="Khu vực quản lý">
            <?php if (can_use('bookings.overview')): ?>
                <a class="<?= $link ?>" href="/admin/overview" <?= $path === '/admin/overview' ? 'aria-current="page"' : '' ?>>
                    <?= admin_icon('layout-dashboard') ?><span>Tổng quan</span>
                </a>
            <?php endif ?>
            <?php foreach (account_permissions() as $key => $label): ?>
                <?php
                if (in_array($key, ['sales', 'products', 'inventory', 'expenses', 'people-counter'], true)
                    || !can_use($key) || ($key === 'bookings' && !can_use('bookings.view'))) {
                    continue;
                }
                $shortLabel = match ($key) {
                    'bookings' => 'Đặt phòng',
                    'rooms' => 'Phòng',
                    'availability' => 'Phòng trống',
                    default => 'Mã mở cửa',
                };
                ?>
                <?php if ($path === '/admin' && !in_array($key, ['door-codes', 'availability'], true)): ?>
                    <button class="<?= $link ?>" type="button" data-admin-tab="<?= e($key) ?>">
                        <?= admin_icon($key === 'rooms' ? 'bed' : 'layout-dashboard') ?>
                        <span class="admin-nav-label-long"><?= e($label) ?></span>
                        <span class="admin-nav-label-short"><?= e($shortLabel) ?></span>
                    </button>
                <?php else: ?>
                    <?php $href = in_array($key, ['door-codes', 'availability'], true) ? '/admin/' . $key : '/admin#' . $key; ?>
                    <a class="<?= $link ?>" href="<?= e($href) ?>" <?= $path === $href ? 'aria-current="page"' : '' ?>>
                        <?= admin_icon($key === 'rooms' ? 'bed' : ($key === 'bookings' ? 'layout-dashboard' : 'key')) ?>
                        <span class="admin-nav-label-long"><?= e($label) ?></span>
                        <span class="admin-nav-label-short"><?= e($shortLabel) ?></span>
                    </a>
                <?php endif ?>
            <?php endforeach ?>
            <?php if (can_use('rooms.view')): ?>
                <a class="<?= $link ?>" href="/admin/promotions" <?= $path === '/admin/promotions' ? 'aria-current="page"' : '' ?>>
                    <?= admin_icon('bed') ?><span>Chương trình ưu đãi</span>
                </a>
            <?php endif ?>
            <?php if (can_use('people-counter.view')): ?>
                <a class="<?= $link ?>" href="/admin/people-counter" <?= $path === '/admin/people-counter' ? 'aria-current="page"' : '' ?>>
                    <?= admin_icon('user') ?>
                    <span>Lượt vào / ra</span>
                </a>
            <?php endif ?>
            <?php if (can_use('sales.view') || can_use('sales.create') || can_use('products.view') || can_use('inventory.view') || can_use('expenses.view')): ?>
                <details class="admin-nav-group" <?= in_array($path, ['/admin/sales', '/admin/sales/history', '/admin/products', '/admin/inventory', '/admin/expenses'], true) ? 'open' : '' ?>>
                    <summary class="<?= $link ?> cursor-pointer">
                        <svg class="admin-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h2l2.4 12h11.2l2-8H6"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>
                        <span class="min-w-0 flex-1">Bán hàng</span>
                        <svg class="admin-nav-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
                    </summary>
                    <div class="grid gap-1 pb-1 pl-8">
                        <?php if (can_use('sales.create')): ?>
                            <a class="<?= $link ?>" href="/admin/sales" <?= $path === '/admin/sales' ? 'aria-current="page"' : '' ?>>Bán hàng</a>
                        <?php endif ?>
                        <?php if (can_use('sales.view')): ?>
                            <a class="<?= $link ?>" href="/admin/sales/history" <?= $path === '/admin/sales/history' ? 'aria-current="page"' : '' ?>>Lịch sử bán hàng</a>
                        <?php endif ?>
                        <?php if (can_use('expenses.view')): ?>
                            <a class="<?= $link ?>" href="/admin/expenses" <?= $path === '/admin/expenses' ? 'aria-current="page"' : '' ?>>Phiếu chi</a>
                        <?php endif ?>
                        <?php if (can_use('inventory.view')): ?>
                            <a class="<?= $link ?>" href="/admin/inventory" <?= $path === '/admin/inventory' ? 'aria-current="page"' : '' ?>>Nhập kho & tồn kho</a>
                        <?php endif ?>
                        <?php if (can_use('products.view')): ?>
                            <a class="<?= $link ?>" href="/admin/products" <?= $path === '/admin/products' ? 'aria-current="page"' : '' ?>>Quản lý sản phẩm</a>
                        <?php endif ?>
                    </div>
                </details>
            <?php endif ?>
            <?php if (can_use('payroll')): ?>
                <details class="admin-nav-group" <?= in_array($path, ['/admin/personnel', '/admin/payroll', '/admin/timesheet'], true) ? 'open' : '' ?>>
                    <summary class="<?= $link ?> cursor-pointer">
                        <?= admin_icon('user') ?>
                        <span class="min-w-0 flex-1">Nhân sự & tính lương</span>
                        <svg class="admin-nav-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
                    </summary>
                    <div class="grid gap-1 pb-1 pl-8">
                        <a class="<?= $link ?>" href="/admin/personnel" <?= $path === '/admin/personnel' ? 'aria-current="page"' : '' ?>>Quản lý nhân sự</a>
                        <a class="<?= $link ?>" href="/admin/payroll" <?= $path === '/admin/payroll' ? 'aria-current="page"' : '' ?>>Tính lương</a>
                        <a class="<?= $link ?>" href="/admin/timesheet" <?= $path === '/admin/timesheet' ? 'aria-current="page"' : '' ?>>Chấm công</a>
                    </div>
                </details>
            <?php endif ?>
            <?php if (can_use('accounts')): ?>
                <a class="<?= $link ?>" href="/admin/branches" <?= $path === '/admin/branches' ? 'aria-current="page"' : '' ?>>
                    <?= admin_icon('home') ?><span>Quản lý chi nhánh</span>
                </a>
                <details class="admin-nav-group" <?= in_array($path, ['/admin/accounts', '/admin/permissions'], true) ? 'open' : '' ?>>
                    <summary class="<?= $link ?> cursor-pointer">
                        <?= admin_icon('user') ?>
                        <span class="min-w-0 flex-1">Tài khoản & phân quyền</span>
                        <svg class="admin-nav-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
                    </summary>
                    <div class="grid gap-1 pb-1 pl-8">
                        <a class="<?= $link ?>" href="/admin/accounts" <?= $path === '/admin/accounts' ? 'aria-current="page"' : '' ?>>
                            <span>Quản lý tài khoản</span>
                        </a>
                        <a class="<?= $link ?>" href="/admin/permissions" <?= $path === '/admin/permissions' ? 'aria-current="page"' : '' ?>>
                            <span>Phân quyền tài khoản</span>
                        </a>
                    </div>
                </details>
            <?php endif ?>
        </nav>
        <div class="admin-sidebar-bottom">
            <a class="admin-website-link" href="/" target="_blank" rel="noopener" aria-label="Xem website (tab mới)">
                <span>Xem website</span>
                <?= admin_icon('external-link') ?>
            </a>
            <div class="admin-profile-row">
                <div class="admin-profile">
                    <span aria-hidden="true">M</span>
                    <div><strong><?= e($account['name']) ?></strong><small><?= $account['root'] ? 'Admin · Toàn quyền' : 'Nhân viên' ?></small></div>
                </div>
                <form action="/logout" method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <button type="submit" aria-label="Đăng xuất" title="Đăng xuất"><?= admin_icon('logout') ?></button>
                </form>
            </div>
        </div>
    </aside>
    <script defer src="<?= e(asset_url('/assets/admin-navigation.js')) ?>"></script>
    <?php
}

function render_admin_topbar(): void
{
    $path = branch_path();
    $title = match ($path) {
        '/admin/overview' => 'Tổng quan',
        '/admin/door-codes' => 'Mã mở cửa',
        '/admin/availability' => 'Tìm phòng trống',
        '/admin/sales' => 'Bán hàng',
        '/admin/sales/history' => 'Lịch sử bán hàng',
        '/admin/products' => 'Quản lý sản phẩm',
        '/admin/expenses' => 'Phiếu chi',
        '/admin/personnel' => 'Quản lý nhân sự',
        '/admin/payroll' => 'Tính lương',
        '/admin/timesheet' => 'Chấm công',
        '/admin/people-counter' => 'Lượt vào / ra',
        '/admin/inventory' => 'Nhập kho & tồn kho',
        '/admin/accounts' => 'Quản lý tài khoản',
        '/admin/permissions' => 'Phân quyền tài khoản',
        '/admin/branches' => 'Quản lý chi nhánh',
        default => 'Không gian quản lý',
    };
    ?>
    <header class="admin-topbar">
        <button class="icon-button admin-menu-toggle" type="button" data-admin-menu-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="Mở menu quản trị">
            <svg class="admin-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
        </button>
        <div>Mơ Home <span aria-hidden="true">/</span> <span class="min-w-0 break-words font-semibold text-[#79543e]"><?= e(branch_label()) ?></span> <span aria-hidden="true">/</span> <strong data-admin-crumb><?= e($title) ?></strong></div>
        <span class="admin-timezone">Giờ Việt Nam · UTC+7</span>
    </header>
    <?php
}
