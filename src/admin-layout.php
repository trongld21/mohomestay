<?php

require_once __DIR__ . '/admin-icons.php';

function render_admin_navigation(string $path): void
{
    $dashboard = $path === '/admin';
    ?>
    <aside class="admin-sidebar" aria-label="Điều hướng quản trị">
        <a class="admin-wordmark" href="/admin">Mơ<span>Home</span><small>KHÔNG GIAN QUẢN LÝ</small></a>
        <div class="admin-property">
            <span class="admin-property-icon"><?= admin_icon('home') ?></span>
            <div><strong>Mơ Home</strong><small>Ninh Kiều, Cần Thơ</small></div>
        </div>
        <p class="admin-nav-label">QUẢN LÝ KHÔNG GIAN</p>
        <nav class="admin-navigation" aria-label="Khu vực quản lý">
            <?php if ($dashboard): ?>
                <button type="button" data-admin-tab="bookings"><?= admin_icon('layout-dashboard') ?>Tổng quan & đặt phòng</button>
                <button type="button" data-admin-tab="rooms"><?= admin_icon('bed') ?>Quản lý phòng</button>
            <?php else: ?>
                <a href="/admin#bookings"><?= admin_icon('layout-dashboard') ?>Tổng quan & đặt phòng</a>
                <a href="/admin#rooms"><?= admin_icon('bed') ?>Quản lý phòng</a>
            <?php endif ?>
            <a href="/admin/door-codes" <?= !$dashboard ? 'aria-current="page"' : '' ?>><?= admin_icon('key') ?>Mã mở cửa</a>
        </nav>
        <div class="admin-sidebar-bottom">
            <a class="admin-website-link" href="/" target="_blank" rel="noopener">Xem website <?= admin_icon('external-link') ?></a>
            <div class="admin-profile-row">
                <div class="admin-profile">
                    <span aria-hidden="true">M</span>
                    <div><strong>Chủ nhà Mơ</strong><small>Quản trị viên</small></div>
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
    ?>
    <header class="admin-topbar">
        <div>Mơ Home <span aria-hidden="true">/</span> <strong data-admin-crumb><?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin/door-codes') ? 'Mã mở cửa' : 'Không gian quản lý' ?></strong></div>
        <span class="admin-timezone">Giờ Việt Nam · UTC+7</span>
    </header>
    <?php
}
