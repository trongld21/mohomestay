<?php

declare(strict_types=1);

function render_login_form(string $error): void
{
    ?>
    <section class="mo-login" aria-labelledby="login-title">
        <img class="mo-login-background" src="<?= e(asset_url('/images/story-interior.webp')) ?>" alt="" width="1920" height="711" fetchpriority="high">
        <div class="mo-login-card">
            <header class="mo-login-heading">
                <a class="mo-login-mark" href="/" aria-label="Về trang chủ Mơ Home">
                    <span aria-hidden="true"></span>
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 22 16-14 16 14M10 18v23h23V18M18 41V29h8v12M32 30c5-8 7-14 8-24M37 18c-6 0-7-5-7-5s6-1 7 5ZM39 12c0-6 5-8 5-8s1 6-5 8ZM35 24c6 0 9-5 9-5s-7-1-9 5Z"/></svg>
                    <span aria-hidden="true"></span>
                </a>
                <h1 id="login-title">Đăng nhập quản lý</h1>
                <p>Khách đặt phòng không cần tạo tài khoản.</p>
            </header>
            <form method="post" action="/auth/login" class="mo-login-form" data-login-form>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <label class="mo-login-label" for="login-email">Email</label>
                <div class="mo-login-input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18v14H3zM3 5l9 7 9-7"/></svg>
                    <input id="login-email" name="email" type="email" autocomplete="username" placeholder="Email quản lý của bạn" required value="<?= e(is_string($_POST['email'] ?? null) ? $_POST['email'] : '') ?>">
                </div>
                <label class="mo-login-label" for="login-password">Mật khẩu</label>
                <div class="mo-login-input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 10h14v11H5zM8 10V6a4 4 0 0 1 8 0v4M12 15v2"/></svg>
                    <input id="login-password" name="password" type="password" autocomplete="current-password" placeholder="Nhập mật khẩu" required <?= $error !== '' ? 'aria-describedby="login-error" aria-invalid="true"' : '' ?>>
                    <button class="mo-login-password-toggle" type="button" aria-label="Hiện mật khẩu" aria-pressed="false" data-login-password-toggle hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path d="m3 3 18 18" data-eye-slash hidden/></svg>
                    </button>
                </div>
                <?php if ($error !== ''): ?>
                    <p class="mo-login-error" id="login-error" role="alert"><?= e($error) ?></p>
                <?php endif ?>
                <div class="mo-login-options">
                    <label class="mo-login-remember" data-login-remember-label hidden><input type="checkbox" data-login-remember>Ghi nhớ email</label>
                </div>
                <button class="mo-login-submit" type="submit"><span>Đăng nhập</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></button>
            </form>
            <a class="mo-login-back" href="/">Về trang chủ Mơ Home</a>
        </div>
    </section>
    <script defer src="<?= e(asset_url('/assets/login.js')) ?>"></script>
    <?php
}
