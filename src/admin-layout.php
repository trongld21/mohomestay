<?php

require_once __DIR__ . '/admin-icons.php';

function render_admin_navigation(string $path): void
{
    $account = current_account();
    $link = 'flex min-h-11 items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium text-stone-700 hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800';
    ?>
    <aside class="admin-sidebar" aria-label="Điều hướng quản trị">
        <a class="admin-wordmark" href="<?= e(account_home()) ?>">Mơ<span>Home</span><small>KHÔNG GIAN QUẢN LÝ</small></a>
        <div class="admin-property">
            <span class="admin-property-icon"><?= admin_icon('home') ?></span>
            <div><strong>Mơ Home</strong><small>Ninh Kiều, Cần Thơ</small></div>
        </div>
        <p class="admin-nav-label">QUẢN LÝ KHÔNG GIAN</p>
        <nav class="admin-navigation" aria-label="Khu vực quản lý">
            <?php foreach (account_permissions() as $key => $label): ?>
                <?php if (!can_use($key)) continue; ?>
                <?php if ($path === '/admin' && $key !== 'door-codes'): ?>
                    <button class="<?= $link ?>" type="button" data-admin-tab="<?= e($key) ?>"><?= admin_icon($key === 'rooms' ? 'bed' : 'layout-dashboard') ?><?= e($label) ?></button>
                <?php else: ?>
                    <?php $href = $key === 'door-codes' ? '/admin/door-codes' : '/admin#' . $key; ?>
                    <a class="<?= $link ?>" href="<?= e($href) ?>" <?= $path === $href ? 'aria-current="page"' : '' ?>><?= admin_icon($key === 'rooms' ? 'bed' : ($key === 'bookings' ? 'layout-dashboard' : 'key')) ?><?= e($label) ?></a>
                <?php endif ?>
            <?php endforeach ?>
            <?php if (can_use('accounts')): ?>
                <a class="<?= $link ?>" href="/admin/accounts" <?= $path === '/admin/accounts' ? 'aria-current="page"' : '' ?>>Quản lý tài khoản</a>
                <a class="<?= $link ?>" href="/admin/permissions" <?= $path === '/admin/permissions' ? 'aria-current="page"' : '' ?>>Phân quyền tài khoản</a>
            <?php endif ?>
        </nav>
        <div class="admin-sidebar-bottom">
            <a class="admin-website-link" href="/" target="_blank" rel="noopener">Xem website <?= admin_icon('external-link') ?></a>
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
    <?php
}

function render_admin_topbar(): void
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $title = match ($path) {
        '/admin/door-codes' => 'Mã mở cửa',
        '/admin/accounts' => 'Quản lý tài khoản',
        '/admin/permissions' => 'Phân quyền tài khoản',
        default => 'Không gian quản lý',
    };
    ?>
    <header class="admin-topbar">
        <div>Mơ Home <span aria-hidden="true">/</span> <strong data-admin-crumb><?= e($title) ?></strong></div>
        <span class="admin-timezone">Giờ Việt Nam · UTC+7</span>
    </header>
    <?php
}
