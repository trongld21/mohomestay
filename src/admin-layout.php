<?php

require_once __DIR__ . '/admin-icons.php';

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
        <div class="admin-property">
            <span class="admin-property-icon"><?= admin_icon('home') ?></span>
            <div><strong>Mơ Home</strong><small>Ninh Kiều, Cần Thơ</small></div>
        </div>
        <p class="admin-nav-label">QUẢN LÝ KHÔNG GIAN</p>
        <nav class="admin-navigation" aria-label="Khu vực quản lý">
            <?php foreach (account_permissions() as $key => $label): ?>
                <?php
                if (!can_use($key)) {
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
            <?php if (can_use('accounts')): ?>
                <a class="<?= $link ?>" href="/admin/accounts" <?= $path === '/admin/accounts' ? 'aria-current="page"' : '' ?>>Quản lý tài khoản</a>
                <a class="<?= $link ?>" href="/admin/permissions" <?= $path === '/admin/permissions' ? 'aria-current="page"' : '' ?>>Phân quyền tài khoản</a>
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
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $title = match ($path) {
        '/admin/door-codes' => 'Mã mở cửa',
        '/admin/availability' => 'Tìm phòng trống',
        '/admin/accounts' => 'Quản lý tài khoản',
        '/admin/permissions' => 'Phân quyền tài khoản',
        default => 'Không gian quản lý',
    };
    ?>
    <header class="admin-topbar">
        <button class="icon-button admin-menu-toggle" type="button" data-admin-menu-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="Mở menu quản trị">
            <svg class="admin-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
        </button>
        <div>Mơ Home <span aria-hidden="true">/</span> <strong data-admin-crumb><?= e($title) ?></strong></div>
        <?php if (!empty(current_account()['root'])): ?>
            <button class="ml-auto inline-flex min-h-11 shrink-0 items-center rounded-lg border border-[#d2bda7] bg-white px-3 py-2 text-sm text-[#70432c] enabled:hover:bg-amber-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800" type="button" data-staff-email-open>Gửi email thử</button>
        <?php endif ?>
        <span class="admin-timezone">Giờ Việt Nam · UTC+7</span>
    </header>
    <?php if (!empty(current_account()['root'])): ?>
        <dialog class="timeline-dialog" data-staff-email-dialog aria-labelledby="staff-email-title">
            <form class="p-5" data-staff-email-form>
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-lg font-semibold" id="staff-email-title">Gửi email thử</h2>
                    <button class="timeline-button" type="button" data-staff-email-close>Đóng</button>
                </div>
                <p class="mt-3 text-sm text-stone-600">Gửi đến mohomestay01@gmail.com bằng dữ liệu minh họa. Không tạo đơn hoặc thay đổi thanh toán.</p>
                <label class="mt-4 grid gap-2 text-sm">Loại thông báo
                    <select class="timeline-input" name="event">
                        <option value="BOOKED">Đặt phòng mới</option>
                        <option value="CHECKED_OUT">Khách trả phòng</option>
                        <option value="SURCHARGE">Phụ thu cần thu</option>
                    </select>
                </label>
                <p class="mt-3 text-sm" data-staff-email-status role="status"></p>
                <button class="timeline-button timeline-primary mt-4" type="submit">Gửi email thử</button>
            </form>
        </dialog>
        <script defer src="<?= e(asset_url('/assets/staff-email-test.js')) ?>"></script>
    <?php endif ?>
    <?php
}
