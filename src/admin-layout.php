<?php

require_once __DIR__ . '/admin-icons.php';

function render_admin_navigation(string $path): void
{
    $dashboard = $path === '/admin';
    ?>
    <div class="admin-menu-backdrop" data-admin-menu-backdrop hidden aria-hidden="true"></div>
    <aside id="admin-sidebar" class="admin-sidebar" aria-label="Điều hướng quản trị">
        <div class="admin-sidebar-heading">
            <a class="admin-wordmark" href="/admin">Mơ<span>Home</span><small>KHÔNG GIAN QUẢN LÝ</small></a>
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
            <?php if ($dashboard): ?>
                <button type="button" data-admin-tab="bookings">
                    <?= admin_icon('layout-dashboard') ?>
                    <span class="admin-nav-label-long">Tổng quan & đặt phòng</span>
                    <span class="admin-nav-label-short">Đặt phòng</span>
                </button>
                <button type="button" data-admin-tab="rooms">
                    <?= admin_icon('bed') ?>
                    <span class="admin-nav-label-long">Quản lý phòng</span>
                    <span class="admin-nav-label-short">Phòng</span>
                </button>
            <?php else: ?>
                <a href="/admin#bookings">
                    <?= admin_icon('layout-dashboard') ?>
                    <span class="admin-nav-label-long">Tổng quan & đặt phòng</span>
                    <span class="admin-nav-label-short">Đặt phòng</span>
                </a>
                <a href="/admin#rooms">
                    <?= admin_icon('bed') ?>
                    <span class="admin-nav-label-long">Quản lý phòng</span>
                    <span class="admin-nav-label-short">Phòng</span>
                </a>
            <?php endif ?>
            <a href="/admin/door-codes" <?= !$dashboard ? 'aria-current="page"' : '' ?>>
                <?= admin_icon('key') ?>
                <span class="admin-nav-label-long">Mã mở cửa</span>
                <span class="admin-nav-label-short">Mã mở cửa</span>
            </a>
        </nav>
        <div class="admin-sidebar-bottom">
            <a class="admin-website-link" href="/" target="_blank" rel="noopener" aria-label="Xem website (tab mới)">
                <span>Xem website</span>
                <?= admin_icon('external-link') ?>
            </a>
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
    <script defer src="<?= e(asset_url('/assets/admin-navigation.js')) ?>"></script>
    <?php
}

function render_admin_topbar(): void
{
    ?>
    <header class="admin-topbar">
        <button class="icon-button admin-menu-toggle" type="button" data-admin-menu-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="Mở menu quản trị">
            <svg class="admin-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
        </button>
        <div>Mơ Home <span aria-hidden="true">/</span> <strong data-admin-crumb><?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin/door-codes') ? 'Mã mở cửa' : 'Không gian quản lý' ?></strong></div>
        <span class="admin-timezone">Giờ Việt Nam · UTC+7</span>
    </header>
    <?php
}
