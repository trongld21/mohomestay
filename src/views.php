<?php

function render_contact_widget(): void
{
    // Only use explicitly configured Facebook destinations, never guessed accounts.
    $socialUrl = static function (string $key): string {
        $url = trim((string)env_value($key, ''));
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        return ($parts['scheme'] ?? '') === 'https' && in_array($host, ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'm.me', 'messenger.com', 'www.messenger.com'], true) ? $url : '';
    };
    $contacts = [
        ['Messenger', 'Trò chuyện cùng Mơ', $socialUrl('CONTACT_MESSENGER_URL'), 'messenger'],
        ['Zalo', 'Nhắn Mơ qua Zalo', 'https://zalo.me/0357907153', 'zalo'],
        ['Fanpage', 'Ghé thăm Mơ Home', $socialUrl('CONTACT_FACEBOOK_URL'), 'facebook'],
        ['Hotline', '0357 907 153', 'tel:0357907153', 'phone'],
    ];
?><details class="contact-widget" data-contact-widget>
        <summary class="contact-toggle" aria-controls="mo-contact-links">
            <span class="contact-toggle-label"><img class="contact-chat" src="<?= e(asset_url('/images/contact-chat.png')) ?>" alt="" width="56" height="56"><span class="sr-only">Mở danh sách liên hệ Mơ</span></span>
            <span class="contact-close" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                    <path d="m6 6 12 12M18 6 6 18" />
                </svg></span>
            <span class="contact-close-label">Đóng danh sách liên hệ</span>
        </summary>
        <div class="contact-backdrop" data-contact-backdrop aria-hidden="true"></div>
        <ul id="mo-contact-links" class="contact-list" aria-label="Liên hệ Mơ Home">
            <?php foreach ($contacts as [$label, $description, $url, $icon]): ?>
                <li><?php if ($url): ?><a class="contact-link" href="<?= e($url) ?>" <?= str_starts_with($url, 'https://') ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?php else: ?><span class="contact-link contact-unavailable" aria-disabled="true"><?php endif ?>
                            <span class="contact-copy"><strong><?= e($label) ?></strong><small><?= e($url ? $description : 'Sắp kết nối') ?></small></span>
                            <span class="contact-icon contact-icon-<?= e($icon) ?>" aria-hidden="true">
                                <?php if ($icon === 'zalo'): ?><span class="contact-zalo">Zalo</span>
                                <?php elseif ($icon === 'facebook'): ?><svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M14 22v-9h3l.5-4H14V7c0-1.2.4-2 2-2h2V1.5A24 24 0 0 0 15 1c-3 0-5 1.8-5 5v3H7v4h3v9z" />
                                    </svg>
                                <?php elseif ($icon === 'messenger'): ?><svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M12 2C6.5 2 2 6.1 2 11.2c0 2.9 1.4 5.5 3.6 7.2V22l3.5-1.9c.9.2 1.9.3 2.9.3 5.5 0 10-4.1 10-9.2S17.5 2 12 2Zm-6 12 5-5 3 2 4-2-5 5-3-2z" />
                                    </svg>
                                <?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m5 3 4 1 1 5-3 2a15 15 0 0 0 6 6l2-3 5 1 1 4c0 1-1 2-3 2C10 21 3 14 3 6c0-2 1-3 2-3ZM15 3a7 7 0 0 1 6 6M15 7a3 3 0 0 1 2 2" />
                                    </svg><?php endif ?>
                            </span>
                            <?php if ($url): ?></a><?php else: ?></span><?php endif ?></li>
            <?php endforeach ?>
        </ul>
    </details><?php
            }

function render_google_analytics(string $path, string $host): void
{
    // Track public pages only. Payment URLs can contain booking credentials.
    if (!in_array(strtolower($host), ['mohomestay.vn', 'www.mohomestay.vn'], true)
        || !preg_match('#^/(?:rooms(?:/[a-z0-9-]+)?|calendar|bookings)?/?$#', $path)) {
        return;
    }
    ?>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-4QZJV3WY0J"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag('js', new Date());
        let analyticsReferrer = '';
        try {
            const referrer = new URL(document.referrer);
            analyticsReferrer = referrer.origin + referrer.pathname;
        } catch {}
        gtag('config', 'G-4QZJV3WY0J', {
            page_location: window.location.origin + window.location.pathname,
            page_referrer: analyticsReferrer
        });
    </script>
    <?php
}

            function render_page(string $title, callable $content, array $options = []): never
            {
                $fullTitle = e($title) . ' | Mơ Home';
                $adminPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
                $adminLayout = in_array($adminPath, ['/admin', '/admin/availability', '/admin/door-codes', '/admin/accounts', '/admin/permissions', '/admin/no-access'], true);
                if ($adminLayout) require_once APP_ROOT . '/src/admin-layout.php';
                $robots = ($options['robots'] ?? true) ? 'index,follow' : 'noindex,nofollow';
                ?>
    <!doctype html>
    <html lang="vi">

    <head>
        <meta charset="utf-8">
        <?php render_google_analytics($adminPath, $_SERVER['HTTP_HOST'] ?? ''); ?>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= $fullTitle ?></title>
        <meta name="description" content="Mơ Home — homestay riêng tư tại Ninh Kiều, Cần Thơ.">
        <meta name="robots" content="<?= $robots ?>">
        <link rel="icon" href="<?= e(asset_url('/images/mo-home-favicon-m.png')) ?>" type="image/png">
        <link rel="apple-touch-icon" href="<?= e(asset_url('/images/mo-home-favicon-m.png')) ?>">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&amp;family=Noto+Sans:wght@400;500;600;700&amp;family=Noto+Serif:wght@400;500;600&amp;display=swap" rel="stylesheet">
        <link rel="stylesheet" href="<?= e(asset_url('/assets/app.css')) ?>">
        <link rel="stylesheet" href="<?= e(asset_url('/assets/tailwind.css')) ?>">
        <script src="<?= e(asset_url('/assets/contact.js')) ?>" defer></script>
        <script src="<?= e(asset_url('/assets/pagination.js')) ?>" defer></script>
        <script src="<?= e(asset_url('/assets/date-picker.js')) ?>" defer></script>
        <script src="<?= e(asset_url('/assets/select.js')) ?>" defer></script>
        <script src="<?= e(asset_url('/assets/hero-carousel.js')) ?>" defer></script>
        <script src="<?= e(asset_url('/assets/room-favorites.js')) ?>" defer></script>
        <script src="<?= e(asset_url('/assets/phone-ring.js')) ?>" defer></script>
        <script src="<?= e(asset_url('/assets/story-scene.js')) ?>" defer></script>
        <?php if (dev_reload_enabled()): ?><script src="<?= e(asset_url('/assets/dev-reload.js')) ?>" defer></script><?php endif ?>
    </head>

    <body<?= $adminLayout ? ' class="admin-page"' : '' ?>>
        <a class="skip-link" href="#main">Đi đến nội dung</a>
        <?php if ($adminLayout): ?>
            <?php render_admin_icons(); render_admin_navigation($adminPath); ?>
            <div class="admin-workspace"><?php render_admin_topbar(); ?><main id="main"><?php $content(); ?></main></div>
        <?php else: ?>
            <?php render_header(); ?><main id="main"><?php $content(); ?></main><?php render_footer(); ?>
        <?php endif ?>
        <div class="ui-toast-region" data-toast-region aria-live="polite" aria-atomic="false"></div>
        <div class="ui-dialog-root" data-dialog-root></div>
        <?php $pagePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (!preg_match('#^/(admin|auth)(/|$)#', $pagePath)) render_contact_widget(); ?>
        <script>
            window.LANG_HOME = {
                isSuperAdmin: <?= json_encode((bool)(current_account()['root'] ?? false)) ?>,
                manualDateOverride: <?= json_encode((bool)(current_account()['root'] ?? false)) ?>,
                csrf: <?= json_encode(csrf_token()) ?>,
                abilities: <?= json_encode(array_values(array_filter(array_keys(account_permission_labels()), fn($key) => can_use($key)))) ?>,
                permissions: <?= json_encode(array_values(array_filter(array_keys(account_permissions()), fn($key) => can_use($key)))) ?>,
                today: <?= json_encode(date('Y-m-d')) ?>,
                rooms: <?= json_encode(array_values(rooms()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
            };
        </script>
        <script defer src="<?= e(asset_url('/assets/ui.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/app.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/booking-slots.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/time-picker.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/calendar.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/booking-identity.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/rooms.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/admin-slug.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/room-image-draft.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/admin-permissions.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/admin-booking-filters.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/admin.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/booking-stay.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/admin-inputs.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/admin-date-picker.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/booking-date-picker.js')) ?>"></script>
        <script defer src="<?= e(asset_url('/assets/manual-booking.js')) ?>"></script>
    </body>

    </html><?php exit;
            }
function logo(): void
{
    static $instance = 0;
    $filterId = 'mo-logo-transparency-' . ++$instance;
    ?>
    <span class="brand">
        <svg class="brand-art" role="img" aria-label="Mơ Home" viewBox="400 1650 4000 1400" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <filter id="<?= e($filterId) ?>" color-interpolation-filters="sRGB" x="0" y="0" width="100%" height="100%">
                    <feColorMatrix type="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  -1 -1 -1 0 2.7" />
                </filter>
            </defs>
            <image href="<?= e(asset_url('/images/mo-home-wordmark.png')) ?>" x="0" y="0" width="4699" height="4698" filter="url(#<?= e($filterId) ?>)" />
        </svg>
    </span>
    <?php
}

function render_header(): void
                                                                                                                                                                                        { ?><header class="site-header">
        <div class="container nav-inner"><a href="/" aria-label="Mơ Home — Trang chủ"><?php logo(); ?></a>
            <nav class="nav-links" id="nav"><a class="header-link" href="/">Trang chủ</a><a class="header-link" href="/#ve-mo">Về Mơ</a><a class="header-link" href="/rooms">Phòng & giá</a><a class="header-link" href="/calendar">Lịch phòng</a><a class="header-link" href="/#lien-he">Liên hệ</a></nav>
            <div class="nav-actions"><a class="nav-phone" href="tel:0357907153"><svg class="nav-phone-icon" aria-hidden="true" viewBox="0 0 24 24">
                        <path class="phone-body" fill="currentColor" d="M7 10h10l3 8v3H4v-3l3-8Zm5 3a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z" fill-rule="evenodd" />
                        <g class="phone-handset" fill="currentColor">
                            <path d="M2 7c0-4 4-6 10-6s10 2 10 6v2h-6V6c-2.5-1.2-5.5-1.2-8 0v3H2V7Z" />
                        </g>
                    </svg><span>0357 907 153</span></a><a class="button small" href="/bookings">Đặt phòng ↗</a><button class="menu-toggle" data-menu aria-label="Mở menu">☰</button></div>
        </div>
    </header><?php }
                                                                                                                                                                                        function render_footer(): void
                                                                                                                                                                                        { ?><footer id="lien-he" class="site-footer">
        <div class="container footer-top">
            <div><?php logo(); ?><p>Một chốn riêng. Một nhịp chậm.<br>Một chút bình yên dành cho bạn.</p>
            </div>
            <div><span class="eyebrow">KHÁM PHÁ MƠ</span><a href="/rooms">Phòng & bảng giá</a><a href="/calendar">Xem lịch phòng</a><a href="/bookings#tra-cuu">Tra cứu đặt phòng</a></div>
            <div><span class="eyebrow">MÌNH KẾT NỐI NHÉ</span><a class="footer-phone" href="tel:0357907153">☎ 0357 907 153</a>
                <p>82 đường B18, KDC 91B<br>Ninh Kiều, Cần Thơ</p><a href="https://zalo.me/0357907153" target="_blank" rel="noreferrer">Trò chuyện qua Zalo ↗</a>
            </div>
        </div>
        <div class="container footer-bottom"><span>© <?= date('Y') ?> Mơ Home.</span><span>Được chăm chút, từ những điều nhỏ nhất.</span></div>
    </footer><?php }

                                                                                                                                                                                        function package_timing_label(array $package): string
                                                                                                                                                                                        {
                                                                                                                                                                                            return $package['mode'] === 'FIXED_TIME' ? substr((string)$package['checkInTime'], 0, 5) . ' – ' . substr((string)$package['checkOutTime'], 0, 5) : ((int)$package['durationMinutes'] >= 60 && ((int)$package['durationMinutes'] % 60 === 0) ? ((int)$package['durationMinutes'] / 60) . ' giờ' : (int)$package['durationMinutes'] . ' phút');
                                                                                                                                                                                        }
function render_package_sale_price(array $package, bool $showBadge = true): void
{
    $base = (int)($package['basePrice'] ?? $package['price']);
    $price = (int)$package['price'];
    $percent = (int)($package['effectiveDiscountPercent'] ?? 0);
    $sale = $percent > 0 && $base > $price;
    ?>
    <span class="inline-flex flex-col items-start gap-1">
        <?php if ($sale && $showBadge): ?>
            <span class="rounded-md bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900">Giảm <?= $percent ?>%</span>
        <?php endif ?>
        <span class="inline-flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <?php if ($sale): ?><del class="text-sm font-normal text-stone-500"><span class="sr-only">Giá gốc </span><?= money($base) ?></del><?php endif ?>
            <strong><span class="sr-only">Giá hiện tại </span><?= money($price) ?></strong>
        </span>
    </span>
    <?php
}

function render_room_cards(bool $liveAvailability = false): void
{
    $catalog = array_values(rooms());
    ?>
    <div class="room-grid" data-public-room-grid <?= $liveAvailability ? 'aria-busy="true"' : '' ?>>
        <?php foreach ($catalog as $index => $room):
            $packages = array_values(array_filter($room['packages'] ?? [], fn($package) => $package['enabled']));
            $minimum = $packages ? min(array_column($packages, 'price')) : null;
            $minimumPackage = null;
            foreach ($packages as $candidate) {
                if ($candidate['price'] === $minimum) {
                    $minimumPackage = $candidate;
                    break;
                }
            }
            $discountPercent = (int)($minimumPackage['effectiveDiscountPercent'] ?? 0);
            $hasDiscount = $minimumPackage !== null && $discountPercent > 0
                && (int)($minimumPackage['basePrice'] ?? $minimumPackage['price']) > (int)$minimumPackage['price'];
            $coming = $room['status'] === 'COMING_SOON';
            ?>
            <article class="room-card room-product-card relative <?= $coming ? 'coming-soon' : '' ?>" data-room-card-id="<?= e($room['id']) ?>">
                <?php if ($liveAvailability && !$coming): ?>
                    <span class="home-room-availability" data-room-availability data-state="unknown">Đang kiểm tra…</span>
                <?php endif ?>
                <div class="room-image">
                    <a href="/rooms/<?= e($room['slug']) ?>"><img src="<?= e($room['image']) ?>" alt="Phòng <?= e($room['name']) ?>" loading="lazy"></a>
                    <?php if ($coming): ?>
                        <span class="room-status-badge">Sắp ra mắt</span>
                    <?php elseif ($hasDiscount): ?>
                        <span class="room-discount-corner">Giảm <?= $discountPercent ?>%</span>
                    <?php endif ?>
                    <span class="room-photo-count">▧ 1/<?= max(1, count($room['images'] ?? [])) ?></span>
                </div>
                <div class="room-body">
                    <div class="room-title">
                        <h3><a href="/rooms/<?= e($room['slug']) ?>"><?= e($room['name']) ?></a></h3><span><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    </div>
                    <p><?= e($room['subtitle']) ?></p>
                    <div class="room-tag-chips"><?php foreach ($room['tags'] as $tag): ?><span><?php if ($tag === 'Máy nước nóng'): ?><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                                        <path d="M6 3c-6 5 6 8 0 13l-1 2M12 3c-6 5 6 8 0 13l-1 2M18 3c-6 5 6 8 0 13l-1 2" />
                                    </svg><?php elseif ($tag === 'Máy chiếu'): ?><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                                        <circle cx="12" cy="12" r="5" />
                                        <path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M19 5l-1.5 1.5m-11 11L5 19" />
                                    </svg><?php elseif ($tag === 'Netflix'): ?><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                                        <rect x="3" y="4" width="18" height="13" rx="1" />
                                        <path d="M12 17v4m-4 0h8" />
                                    </svg><?php endif ?><?= e($tag) ?></span><?php endforeach ?></div>
                    <div class="room-amenities"><span><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <circle cx="12" cy="7" r="3" />
                                <path d="M5 21v-3a7 7 0 0 1 14 0v3Z" />
                            </svg><?= e($room['maxGuests']) ?> khách</span><span><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path d="M3 21V7h18v14M3 17h18M3 13h18M6 13V9h4v4m4 0V9h4v4" />
                            </svg><?= e($room['bedrooms']) ?> phòng ngủ</span><span><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path d="M3 12h18v3a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5ZM6 12V5a2 2 0 0 1 4 0M6 20v2m12-2v2" />
                            </svg><?= e($room['bathrooms']) ?> phòng tắm</span></div>
                    <div class="room-price">
                        <div><?php if ($minimum !== null): ?><small>Từ</small><?php render_package_sale_price($minimumPackage, false); ?><?php else: ?><span>Liên hệ để biết giá</span><?php endif ?></div><?php if ($coming): ?><span class="disabled-action" aria-disabled="true">Sắp mở</span><?php else: ?><a href="/bookings?room=<?= e($room['id']) ?>" aria-label="Đặt <?= e($room['name']) ?>">→</a><?php endif ?>
                    </div>
                </div>
            </article><?php endforeach ?><?php if (!$catalog): ?><div class="empty-state">
                <h3>Không gian đang được chuẩn bị</h3>
                <p>Hẹn bạn quay lại thật sớm nhé.</p>
            </div><?php endif ?></div><?php }

                                                                                                                                                                                        function render_home(): void
                                                                                                                                                                                        { ?><script type="application/ld+json">
        <?= json_encode(['@context' => 'https://schema.org', '@type' => 'LodgingBusiness', 'name' => 'Mơ Home', 'telephone' => '+84357907153', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => '82 đường B18, KDC 91B', 'addressLocality' => 'Ninh Kiều', 'addressRegion' => 'Cần Thơ', 'addressCountry' => 'VN']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    </script>
    <section class="hero" data-hero-carousel aria-label="Không gian Mơ Home" aria-roledescription="carousel">
        <?php foreach (['pink', 'white', 'black'] as $index => $image): ?><img class="hero-photo hero-slide <?= $index === 0 ? 'is-active' : '' ?>" src="<?= e(asset_url('/images/' . $image . '-illustration.jpg')) ?>" alt="Không gian phòng <?= e(ucfirst($image)) ?>" data-hero-slide aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>" <?= $index === 0 ? ' fetchpriority="high"' : ' loading="lazy"' ?>><?php endforeach ?>
        <div class="hero-shade"></div>
        <div class="container hero-content" data-home-reveal><span class="eyebrow"><span class="tiny-line"></span> CHÀO BẠN, MƠ ĐÂY</span>
            <h1>Chạm vào<br>một chốn <em>mơ.</em></h1>
            <p>Một căn phòng ấm. Một khoảng trời riêng.<br>Để bạn nghỉ ngơi, kết nối và tận hưởng những điều nhỏ bé.</p><a href="/rooms" class="button cream">Khám phá không gian ↗</a>
        </div>
        <div class="hero-carousel-controls" data-hero-controls hidden>
            <button type="button" data-hero-prev aria-label="Ảnh trước">‹</button>
            <div class="hero-carousel-dots"><?php for ($i = 0; $i < 3; $i++): ?><button type="button" data-hero-dot="<?= $i ?>" aria-label="Xem ảnh <?= $i + 1 ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"></button><?php endfor ?></div>
            <button type="button" data-hero-next aria-label="Ảnh tiếp theo">›</button>
            <button type="button" data-hero-pause aria-label="Tạm dừng chuyển ảnh">Ⅱ</button>
            <span class="sr-only" data-hero-status aria-live="polite"></span>
        </div>
        <div class="hero-bottom container"><span>STAY SLOW. FEEL AT HOME.</span><a href="#khong-gian">Kéo xuống để khám phá ↓</a></div>
    </section>
    <div class="container search-wrap" data-home-reveal>
        <form class="search-bar" action="/calendar"><label><span>NGÀY GHÉ MƠ</span><input type="date" name="date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label><label><span>KHÔNG GIAN</span><select name="room">
                    <option value="all">Tất cả phòng</option><?php foreach (rooms() as $r): ?><option value="<?= e($r['id']) ?>"><?= e($r['name']) ?></option><?php endforeach ?>
                </select></label><label><span>GÓI LƯU TRÚ</span><select name="package"><?php foreach (packages() as $id => $label): ?><option value="<?= e($id) ?>"><?= e($label) ?></option><?php endforeach ?></select></label><button class="button" type="submit">Kiểm tra phòng →</button></form>
    </div>
    <section class="container intro-strip" data-home-reveal>
        <p>♡ Một nơi để đến. Một cảm giác để nhớ.</p>
        <div><span>✓ Riêng tư & an tâm</span><span>⌁ Self check-in</span><span>◷ Linh hoạt theo giờ</span></div>
    </section>
    <section id="khong-gian" class="container section" data-home-availability data-home-reveal>
        <div class="section-heading">
            <div><span class="eyebrow">KHÔNG GIAN CỦA BẠN</span>
                <h2>Mỗi căn phòng, một cảm xúc.</h2>
                <p>Chọn một góc nhỏ phù hợp với tâm trạng hôm nay.</p>
            </div><a class="text-link" href="/rooms">Xem tất cả phòng ↗</a>
        </div>
        <p class="mb-5 text-sm text-[#736b61]" data-home-availability-status role="status">Đang kiểm tra phòng trống lúc này…</p>
        <?php render_room_cards(true); ?>
        <a class="mt-5 inline-flex min-h-11 items-center rounded-sm text-sm text-[#79543e] underline underline-offset-4 hover:text-[#493020] focus-visible:outline-2 focus-visible:outline-offset-4" href="/calendar">Xem lịch phòng</a>
        <noscript><p>Vui lòng bật JavaScript hoặc xem lịch phòng để kiểm tra phòng trống.</p></noscript>
        <script defer src="<?= e(asset_url('/assets/home-availability.js')) ?>"></script>
    </section>
    <?php
    require_once APP_ROOT . '/src/home-location-view.php';
    render_home_location();
    ?>
    <section id="ve-mo" class="story-section story-editorial" data-home-reveal>
        <img class="story-editorial-backdrop" src="<?= e(asset_url('/images/story-interior.webp')) ?>" alt="" width="1920" height="720" loading="lazy" aria-hidden="true">
        <div class="container story-grid">
            <div class="story-main story-brand" data-story-scene><img src="<?= e(asset_url('/images/mo-home-illustration.png')) ?>" alt="Mơ Home — Homestay for a slower life, EST 2026"><canvas class="story-scene-canvas" aria-hidden="true"></canvas></div>
            <div class="story-copy"><span class="eyebrow">CÂU CHUYỆN CỦA MƠ</span>
                <h2>Không cần đi xa,<br>chỉ cần <em>thấy bình yên.</em></h2>
                <p>Có những ngày, điều mình cần chỉ là một nơi đủ yên để tạm gác lại mọi bộn bề. Một chiếc giường êm, ánh đèn ấm, và thời gian dành trọn cho bản thân hay người thương.</p>
                <p>Mơ muốn là nơi nhỏ bé ấy. Không cầu kỳ, không vội vã — chỉ có sự riêng tư và cảm giác thoải mái như ở nhà.</p><a href="https://zalo.me/0357907153" class="text-link story-contact-link" target="_blank" rel="noopener noreferrer">Chuyện trò với Mơ <span class="story-contact-arrow" aria-hidden="true">↗</span></a>
            </div>
        </div>
    </section>
    <div class="perks-background" style="--perks-background:url('<?= e(asset_url('/images/perks-botanical.png')) ?>')">
        <section class="section perks-section" data-home-reveal>
            <div class="container">
                <div class="center-heading"><span class="eyebrow">NHỮNG ĐIỀU NHỎ, ĐƯỢC CHĂM CHÚT</span>
                    <h2>Đến Mơ, cứ thoải mái là mình.</h2>
                </div>
                <div class="perks-grid">
                    <div><span class="perk-icon"><svg aria-hidden="true" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="15" y="4" width="15" height="40" rx="4" />
                                <path d="M24 29h15a2 2 0 0 0 0-4H24a3 3 0 1 0 0 4Z" />
                                <path d="M20 10h1m4 0h1m-6 5h1m4 0h1m-6 5h1m4 0h1M22 36v3" />
                            </svg></span>
                        <h3>Nhận phòng riêng tư</h3>
                        <p>Chủ động vào phòng với khóa thông minh. Mã cửa được gửi riêng khi đơn được xác nhận.</p>
                    </div>
                    <div><span class="perk-icon"><svg aria-hidden="true" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 24v-4a8 8 0 0 1 16 0v4M7 37h22M9 37v4m18-4v4M34 41h10M39 41V5H29l-3 7h7l-3-7" />
                                <rect x="4" y="23" width="7" height="14" rx="3" />
                                <rect x="25" y="23" width="7" height="14" rx="3" />
                                <path d="M11 31h14" />
                            </svg></span>
                        <h3>Không gian thảnh thơi</h3>
                        <p>Nghỉ ngơi, chuyện trò hay đơn giản là dành cho bản thân một khoảng riêng.</p>
                    </div>
                    <div><span class="perk-icon"><svg aria-hidden="true" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <circle cx="24" cy="24" r="18" />
                                <path d="M24 13v12l9 7" />
                            </svg></span>
                        <h3>Ở lại theo cách bạn muốn</h3>
                        <p>Một cuộc hẹn 3 giờ, một chiều 6 giờ hay một đêm thật chậm.</p>
                    </div>
                    <div><span class="perk-icon"><svg aria-hidden="true" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M24 40 7 24C-5 12 12 0 24 14 36 0 53 12 41 24Z" />
                            </svg></span>
                        <h3>Có Mơ ở đây</h3>
                        <p>Cần tư vấn hay hỗ trợ nhận phòng? Chỉ một cuộc gọi hoặc tin nhắn đến Mơ.</p>
                    </div>
                </div>
            </div>
        </section>
        <section class="container final-cta" data-home-reveal><span class="eyebrow">MỘT CUỘC HẸN VỚI BÌNH YÊN</span>
            <h2>Chừa một khoảng nhỏ cho mình nhé?</h2>
            <p>Căn phòng ấm áp đang chờ. Còn lại, để Mơ lo.</p><a href="/calendar" class="button">Tìm ngày ghé Mơ →</a>
        </section>
    </div>
    <script defer src="<?= e(asset_url('/assets/home-reveal.js')) ?>"></script>
    <?php }

                                                                                                                                                                                        function render_rooms(): void
                                                                                                                                                                                        { ?><div class="container section">
        <div class="center-heading"><span class="eyebrow">CHỌN MỘT GÓC NHỎ CHO RIÊNG MÌNH</span>
            <h1>Phòng & bảng giá</h1>
            <p>Giá và khung giờ được cập nhật trực tiếp bởi Mơ.</p>
        </div><?php render_room_cards(); ?>
    </div><?php }

function room_detail_image_url(string $path): string
{
    return str_starts_with($path, '/images/') ? asset_url($path) : $path;
}

function room_detail_icon(string $name): string
{
    $paths = [
        'left' => '<path d="m15 6-6 6 6 6"/>',
        'right' => '<path d="m9 6 6 6-6 6"/>',
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'expand' => '<path d="M4 9V4h5m6 0h5v5M4 15v5h5m6 0h5v-5"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'home' => '<path d="m3 10 9-7 9 7M5 9v11h5v-6h4v6h5V9"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'bed' => '<path d="M3 18v3m18-3v3M3 12V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5M3 12h18v6H3zM7 12V9h4v3m2 0V9h4v3"/>',
        'bath' => '<path d="M4 12h16v3a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5v-3ZM7 12V5a2 2 0 0 1 4 0M7 20v2M17 20v2"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'air-conditioner' => '<rect x="3" y="4" width="18" height="9" rx="2"/><path d="M3 9h18M7 16v3m5-3v5m5-5v3"/>',
        'fridge' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M5 10h14M8 6v1m0 6v3M8 21v1m8-1v1"/>',
        'wardrobe' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M12 3v18M9 10v3m6-3v3M7 21v1m10-1v1"/>',
        'mirror' => '<rect x="6" y="3" width="12" height="16" rx="6"/><path d="M9 22v-3m6 3v-3M9 8l3-2"/>',
        'sofa' => '<path d="M5 11V8a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3v3M5 15h14M5 11a2 2 0 0 0-4 0v6a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2v-6a2 2 0 0 0-4 0v4M5 11v4M5 19v2m14-2v2"/>',
        'curtains' => '<path d="M3 4h18M4 4v16h6V4m4 0v16h6V4M4 15l6-6m4 0 6 6M7 4v6m10-6v6"/>',
        'lamp' => '<path d="M8 3h8l4 11H4L8 3ZM12 14v7m-4 0h8M18 14v3"/>',
        'camera' => '<path d="M8 5l2-2h4l2 2h4a2 2 0 0 1 2 2v12H2V7a2 2 0 0 1 2-2h4Z"/><circle cx="12" cy="12" r="4"/>',
        'tv' => '<rect x="2" y="4" width="20" height="14" rx="2"/><path d="M8 22h8m-4-4v4M7 9l3 2-3 2"/>',
        'wifi' => '<path d="M2 8a16 16 0 0 1 20 0M5 12a11 11 0 0 1 14 0m-11 4a6 6 0 0 1 8 0"/><circle cx="12" cy="20" r="1"/>',
        'sparkles' => '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3ZM20 2v4m-2-2h4"/>',
    ];
    return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? $paths['check']) . '</svg>';
}

function room_amenity_icon_name(string $label): string
{
    $label = mb_strtolower($label, 'UTF-8');
    $keywords = [
        'bed' => ['giường', 'bed'],
        'air-conditioner' => ['điều hòa', 'điều hoà', 'máy lạnh'],
        'fridge' => ['tủ lạnh', 'minibar'],
        'wardrobe' => ['tủ quần áo', 'tủ áo'],
        'mirror' => ['gương'],
        'sofa' => ['sofa', 'bàn trà'],
        'curtains' => ['rèm'],
        'lamp' => ['đèn', 'ánh sáng'],
        'camera' => ['chụp ảnh', 'decor'],
        'tv' => ['netflix', 'máy chiếu', 'tivi', 'tv'],
        'wifi' => ['wifi', 'wi-fi', 'internet'],
        'bath' => ['nước nóng', 'phòng tắm', 'bồn tắm'],
    ];
    foreach ($keywords as $icon => $terms) {
        foreach ($terms as $term) {
            if (str_contains($label, $term)) {
                return $icon;
            }
        }
    }
    return 'sparkles';
}

function render_room(array $r): void
{
    $coming = $r['status'] === 'COMING_SOON';
    $hidden = $r['status'] === 'HIDDEN';
    $packages = array_values(array_filter($r['packages'], fn($package) => $package['enabled']));
    $images = $r['images'] ?: [['path' => $r['image'], 'caption' => $r['name']]];
    $count = count($images);
    $detailsImage = $images[min(1, $count - 1)];
    ?>
    <section class="room-detail-page" aria-labelledby="room-detail-title" style="--room-background:url('<?= e(asset_url('/images/perks-botanical.png')) ?>')">
        <div class="container room-detail-shell">
            <nav class="room-breadcrumb" aria-label="Đường dẫn trang">
                <a href="/" aria-label="Trang chủ"><?= room_detail_icon('home') ?></a>
                <a href="/rooms">Phòng & giá</a>
                <?= room_detail_icon('right') ?>
                <span aria-current="page"><?= e($r['name']) ?></span>
            </nav>
            <div class="room-detail-layout">
                <div class="room-gallery" data-room-gallery>
                    <figure class="gallery-main">
                        <img data-gallery-main src="<?= e(room_detail_image_url($images[0]['path'])) ?>" alt="<?= e($images[0]['caption'] ?: 'Phòng ' . $r['name']) ?>" fetchpriority="high">
                        <?php if ($count > 1): ?>
                            <button type="button" class="room-gallery-arrow room-gallery-prev" data-gallery-prev aria-label="Ảnh trước"><?= room_detail_icon('left') ?></button>
                            <button type="button" class="room-gallery-arrow room-gallery-next" data-gallery-next aria-label="Ảnh tiếp theo"><?= room_detail_icon('right') ?></button>
                        <?php endif ?>
                        <?php if ($coming): ?><span class="room-status-badge">Sắp ra mắt</span><?php endif ?>
                        <div class="gallery-shade" aria-hidden="true"></div>
                        <figcaption>
                            <span class="room-gallery-count"><b data-gallery-current>01</b> / <?= str_pad((string)$count, 2, '0', STR_PAD_LEFT) ?></span>
                            <span data-gallery-caption><?= e($images[0]['caption']) ?></span>
                        </figcaption>
                        <button type="button" class="room-gallery-expand" data-gallery-expand aria-label="Phóng to ảnh phòng <?= e($r['name']) ?>"><?= room_detail_icon('expand') ?></button>
                    </figure>
                    <?php if ($count > 1): ?>
                        <div class="gallery-thumbs" aria-label="Ảnh phòng <?= e($r['name']) ?>">
                            <?php foreach ($images as $index => $image): ?>
                                <button type="button" class="<?= $index === 0 ? 'selected' : '' ?>" data-gallery-image="<?= e(room_detail_image_url($image['path'])) ?>" data-gallery-caption="<?= e($image['caption'] ?: 'Phòng ' . $r['name']) ?>" aria-label="Xem ảnh <?= $index + 1 ?>: <?= e($image['caption'] ?: $r['name']) ?>" aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>">
                                    <img src="<?= e(room_detail_image_url($image['path'])) ?>" alt="" loading="lazy">
                                </button>
                            <?php endforeach ?>
                        </div>
                    <?php endif ?>
                    <dialog class="room-lightbox" data-room-lightbox aria-label="Ảnh phóng to phòng <?= e($r['name']) ?>">
                        <div class="room-lightbox-toolbar"><span data-lightbox-caption><?= e($images[0]['caption'] ?: $r['name']) ?></span><button type="button" data-lightbox-close aria-label="Đóng ảnh phóng to"><?= room_detail_icon('close') ?></button></div>
                        <div class="room-lightbox-stage">
                            <img data-lightbox-image src="<?= e(room_detail_image_url($images[0]['path'])) ?>" alt="Phòng <?= e($r['name']) ?>">
                            <?php if ($count > 1): ?>
                                <button type="button" class="room-gallery-arrow room-gallery-prev" data-gallery-prev aria-label="Ảnh trước"><?= room_detail_icon('left') ?></button>
                                <button type="button" class="room-gallery-arrow room-gallery-next" data-gallery-next aria-label="Ảnh tiếp theo"><?= room_detail_icon('right') ?></button>
                            <?php endif ?>
                        </div>
                        <p data-lightbox-position>1 / <?= $count ?></p>
                    </dialog>
                </div>
                <article class="room-detail-copy">
                    <header class="room-detail-heading">
                        <h1 id="room-detail-title"><?= e($r['name']) ?></h1>
                        <?php if ($r['subtitle']): ?><p class="room-detail-subtitle"><?= e($r['subtitle']) ?></p><?php endif ?>
                    </header>
                    <div class="room-facts" aria-label="Thông tin phòng">
                        <div><?= room_detail_icon('users') ?><span><b><?= e($r['maxGuests']) ?></b> khách</span></div>
                        <div><?= room_detail_icon('bed') ?><span><b><?= e($r['bedrooms']) ?></b> phòng ngủ</span></div>
                        <div><?= room_detail_icon('bath') ?><span><b><?= e($r['bathrooms']) ?></b> phòng tắm</span></div>
                    </div>
                    <?php if (can_use('availability.hold') && !$coming && !$hidden): ?>
                        <section class="my-4 rounded-xl border border-sky-200 bg-sky-50 p-4" data-room-quote-hold data-room-id="<?= e($r['id']) ?>">
                            <h2 class="font-sans text-base font-semibold">Giữ căn để báo giá</h2>
                            <p class="mt-1 text-sm text-stone-600">Khóa khung giờ đã chọn trong 1 giờ để tư vấn khách. Tự mở khi hết hạn hoặc nhân viên mở lại.</p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <label class="grid gap-1 text-sm">Gói
                                    <select class="timeline-input" data-hold-package>
                                        <?php foreach ($packages as $holdPackage): ?>
                                            <option value="<?= e($holdPackage['id']) ?>"><?= e($holdPackage['name']) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                </label>
                                <label class="grid gap-1 text-sm">Ngày nhận
                                    <input class="timeline-input" type="date" data-hold-date value="<?= e(date('Y-m-d')) ?>" min="<?= e(date('Y-m-d')) ?>">
                                </label>
                                <label class="grid gap-1 text-sm" data-hold-time-label>Giờ nhận
                                    <input class="timeline-input" type="time" data-hold-time step="60">
                                </label>
                            </div>
                            <button class="timeline-button mt-3" type="button" data-hold-create>Khóa căn khẩn cấp · Giữ 1 giờ</button>
                            <p class="mt-2 text-sm text-stone-700" data-hold-status role="status"></p>
                        </section>
                        <script defer src="<?= e(asset_url('/assets/room-quote-hold.js')) ?>"></script>
                    <?php endif ?>
                    <form action="/calendar" method="get" class="room-stay-form">
                        <input type="hidden" name="room" value="<?= e($r['id']) ?>">
                        <fieldset class="room-rates" <?= $coming || $hidden ? 'disabled' : '' ?>>
                            <legend id="room-rates-title">Lựa chọn kỳ nghỉ</legend>
                            <div class="detail-prices">
                                <?php foreach ($packages as $index => $package): ?>
                                    <?php $tag = $coming || $hidden ? 'div' : 'a'; ?>
                                    <<?= $tag ?> class="room-stay-option" data-flex-package <?= $coming || $hidden ? 'aria-disabled="true"' : 'href="' . e('/bookings?' . http_build_query(['room' => $r['id'], 'package' => $package['id']])) . '"' ?>>
                                        <span class="room-stay-number" aria-hidden="true"><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                                        <span class="rate-name"><strong><?= e($package['name']) ?></strong><small><?= e(package_timing_label($package)) ?></small></span>
                                        <span class="rate-price"><?php render_package_sale_price($package); ?><?= room_detail_icon('right') ?></span>
                                    </<?= $tag ?>>
                                <?php endforeach ?>
                            </div>
                            <?php if (!$packages): ?><p class="room-package-empty">Gói lưu trú đang được cập nhật. Nhắn Mơ để được hỗ trợ.</p><?php endif ?>
                        </fieldset>
                        <div class="room-booking-actions">
                            <?php if ($coming): ?>
                                <p class="notice">Không gian này đang được hoàn thiện.</p>
                                <a class="button room-primary-action" href="https://zalo.me/0357907153">Nhắn Mơ để theo dõi <?= room_detail_icon('arrow') ?></a>
                            <?php elseif ($hidden): ?>
                                <p class="notice">Phòng đang ẩn khỏi website. Đây là bản xem trước dành cho quản trị viên.</p>
                            <?php elseif ($packages): ?>
                                <button class="button room-primary-action" type="submit">Xem lịch phòng trống <?= room_detail_icon('arrow') ?></button>
                            <?php else: ?>
                                <a class="button room-primary-action" href="https://zalo.me/0357907153">Nhắn Mơ để được tư vấn <?= room_detail_icon('arrow') ?></a>
                            <?php endif ?>
                            <div class="room-booking-note"><span><?= room_detail_icon('clock') ?> Mơ xác nhận lịch trước khi thanh toán</span><a href="https://zalo.me/0357907153">Cần tư vấn? Nhắn Mơ</a></div>
                        </div>
                    </form>
                </article>
            </div>
            <?php
            require_once APP_ROOT . '/src/room-timeline-view.php';
            render_room_timeline($r);
            ?>
            <section class="room-story-section" aria-labelledby="room-story-title">
                <div class="room-story-intro">
                    <div><h2 id="room-story-title">Không gian của <?= e($r['name']) ?></h2><p><?= nl2br(e($r['description'] ?: $r['subtitle'])) ?></p></div>
                    <blockquote class="room-story-quote">
                        <span class="room-quote-mark" aria-hidden="true">“</span>
                        <p class="room-quote-text">Một không gian<br>được tạo nên để bạn là<br>chính mình.</p>
                    </blockquote>
                </div>
                <?php if (!empty($r['highlights'])): ?>
                <div class="room-story-layout">
                    <figure class="room-story-photo"><img src="<?= e(room_detail_image_url($detailsImage['path'])) ?>" alt="<?= e($detailsImage['caption'] ?: 'Không gian phòng ' . $r['name']) ?>" loading="lazy"></figure>
                    <div class="room-story-amenities">
                        <h3>Điểm đặc biệt của <?= e($r['name']) ?></h3>
                        <ul class="room-amenity-list">
                            <?php foreach ($r['highlights'] as $highlight): ?>
                                <li><?= room_detail_icon('check') ?><span><?= e($highlight) ?></span></li>
                            <?php endforeach ?>
                        </ul>
                    </div>
                </div>
                <?php endif ?>
                <div class="room-information-layout">
                    <section class="room-information room-amenities-panel" aria-labelledby="room-amenities-title">
                        <div class="room-amenities-photo" aria-hidden="true"><img src="<?= e(asset_url('/images/room-amenities-plant.png')) ?>" alt="" loading="lazy" width="887" height="1774"></div>
                        <div class="room-amenities-content">
                            <h3 id="room-amenities-title">Tiện nghi nổi bật</h3>
                            <?php if ($r['tags']): ?>
                                <ul class="room-amenity-list">
                                    <?php foreach ($r['tags'] as $tag): ?>
                                        <li><?= room_detail_icon(room_amenity_icon_name($tag)) ?><span><?= e($tag) ?></span></li>
                                    <?php endforeach ?>
                                </ul>
                            <?php else: ?>
                                <p>Danh sách tiện nghi đang được cập nhật. Bạn có thể nhắn Mơ để tìm hiểu thêm về phòng.</p>
                                <a class="room-story-contact" href="https://zalo.me/0357907153">Hỏi Mơ về căn phòng <?= room_detail_icon('arrow') ?></a>
                            <?php endif ?>
                        </div>
                    </section>
                    <section class="room-information" aria-labelledby="room-information-title">
                        <h3 id="room-information-title">Thông tin phòng</h3>
                        <dl>
                            <div><dt>Tên phòng</dt><dd><?= e($r['name']) ?></dd></div>
                            <div><dt>Sức chứa</dt><dd>Tối đa <?= e($r['maxGuests']) ?> khách</dd></div>
                            <div><dt>Phòng ngủ</dt><dd><?= e($r['bedrooms']) ?> phòng</dd></div>
                            <div><dt>Phòng tắm</dt><dd><?= e($r['bathrooms']) ?> phòng</dd></div>
                            <div><dt>Nhận / trả phòng</dt><dd>Theo gói lưu trú đã chọn</dd></div>
                            <div><dt>Trạng thái</dt><dd><?= $hidden ? 'Đang ẩn' : ($coming ? 'Sắp ra mắt' : 'Đang hoạt động') ?></dd></div>
                        </dl>
                    </section>
                </div>
            </section>
        </div>
    </section>
    <script defer src="<?= e(asset_url('/assets/room-detail.js')) ?>"></script>
    <?php
}

                                                                                                                                                                                        function render_calendar(): void
                                                                                                                                                                                        {
                                                                                                                                                                                            $active = array_filter(rooms(), fn($room) => $room['status'] === 'ACTIVE'); ?><div class="calendar-page" style="--calendar-background:url('<?= e(asset_url('/images/perks-botanical.png')) ?>')">
        <div class="container section">
            <div class="center-heading calendar-heading"><span class="eyebrow">CHỌN NGÀY GHÉ MƠ</span>
                <svg class="calendar-sprig" aria-hidden="true" viewBox="0 0 64 64">
                    <path d="M9 56C20 40 31 27 48 15" />
                    <path d="M23 38C13 30 15 19 21 12c6 10 8 18 2 26ZM31 30C32 17 43 9 57 7c-4 14-12 22-26 23ZM25 39c11-8 22-5 29 1-11 8-20 8-29-1Z" />
                </svg>
                <h1>Lịch phòng</h1>
                <p>Lịch trống được cập nhật theo thời gian thực. Chọn ngày, không gian và khung giờ phù hợp<br class="hidden sm:block"> để bắt đầu một trải nghiệm thật thư giãn tại Mơ.</p>
            </div>
            <div id="calendar-app" class="calendar-layout">
                <section class="panel">
                    <div class="calendar-nav"><button class="icon-button" aria-label="Tháng trước" data-month-prev>‹</button>
                        <h2 data-month-title></h2><button class="icon-button" aria-label="Tháng sau" data-month-next>›</button>
                    </div>
                    <div class="month-grid" data-calendar-grid></div>
                    <div class="calendar-legend"><span><i class="calendar-dot"></i>Còn trống</span><span><i class="calendar-dot booked"></i>Đã có lịch</span><span><i class="calendar-dot unavailable"></i>Chưa kiểm tra / đã qua giờ</span></div>
                    <div class="form-grid form-section">
                        <label class="field">Không gian
                            <select data-cal-room>
                                <?php foreach ($active as $r): ?>
                                    <option value="<?= e($r['id']) ?>"><?= e($r['name']) ?></option>
                                <?php endforeach ?>
                            </select>
                        </label>
                        <label class="field">Khung giờ
                            <select data-cal-package data-flex-package></select>
                        </label>
                        <fieldset class="manual-time-field calendar-time-field" data-cal-time-field hidden>
                            <legend>Giờ nhận phòng · 24 giờ</legend>
                            <input type="hidden" value="14:00" data-cal-time>
                            <input type="hidden" data-cal-hour>
                            <input type="hidden" data-cal-minute>
                            <div class="manual-time-picker">
                                <button type="button" data-time-trigger="hour" aria-haspopup="dialog" aria-controls="calendar-time-popover" aria-expanded="false"><span data-time-hour-label>14 giờ</span><span class="manual-time-chevron" aria-hidden="true"></span></button>
                                <span aria-hidden="true">:</span>
                                <button type="button" data-time-trigger="minute" aria-haspopup="dialog" aria-controls="calendar-time-popover" aria-expanded="false"><span data-time-minute-label>00 phút</span><span class="manual-time-chevron" aria-hidden="true"></span></button>
                            </div>
                            <div id="calendar-time-popover" class="manual-time-popover" popover="auto" role="dialog" aria-label="Chọn giờ nhận phòng theo định dạng 24 giờ">
                                <section class="manual-time-column">
                                    <h4 id="calendar-hour-title">Chọn giờ</h4>
                                    <div class="manual-hour-options" role="listbox" aria-labelledby="calendar-hour-title" data-time-hours></div>
                                </section>
                                <section class="manual-time-column manual-minute-column">
                                    <h4 id="calendar-minute-title">Chọn phút</h4>
                                    <div class="manual-minute-options" role="listbox" aria-labelledby="calendar-minute-title" data-time-minutes></div>
                                    <label class="manual-exact-minute">Phút chính xác
                                        <input type="number" min="0" max="59" step="1" inputmode="numeric" data-time-exact aria-label="Nhập phút chính xác từ 0 đến 59">
                                    </label>
                                </section>
                            </div>
                        </fieldset>
                    </div>
                </section>
                <section class="panel calendar-details">
                    <div class="calendar-nav">
                        <div>
                            <h2 data-selected-title></h2>
                            <p class="calendar-detail-label">Thông tin đặt phòng</p>
                        </div><button class="icon-button" aria-label="Tải lại lịch phòng" data-refresh>↻</button>
                    </div>
                    <div data-availability aria-live="polite" aria-busy="true">
                        <div class="skeleton" style="height:180px"></div>
                    </div>
                    <aside class="calendar-notes"><strong>Một vài lưu ý nhỏ</strong>
                        <ul>
                            <li>Chọn ngày để kiểm tra lịch của từng phòng.</li>
                            <li>Vui lòng kiểm tra kỹ thời gian trước khi đặt.</li>
                            <li>Cần hỗ trợ? <a href="https://zalo.me/0357907153" target="_blank" rel="noopener noreferrer">Nhắn Mơ qua Zalo ↗</a></li>
                        </ul>
                    </aside>
                </section>
            </div>
        </div>
    </div><?php }

                                                                                                                                                                                        function render_bookings(): void
                                                                                                                                                                                        {
                                                                                                                                                                                            $active = array_filter(rooms(), fn($room) => $room['status'] === 'ACTIVE'); ?><div class="container section">
        <div class="center-heading booking-heading"><span class="eyebrow">CHỌN MỘT CUỘC HẸN</span>
            <h1>Chọn khung giờ của bạn</h1>
            <p>Mỗi phòng có lịch riêng. Chọn ngày để xem giá và những khung giờ còn trống.</p>
        </div>
        <div id="booking-app" class="booking-grid">
            <form class="panel booking-form" data-booking-form>
                <input name="packageId" type="hidden" required>
                <div class="booking-step-heading"><span>01</span>
                    <div>
                        <h2>Phòng và ngày ghé</h2>
                    </div>
                </div>
                <div class="form-grid booking-primary-fields"><label class="field">Không gian<select name="roomId"><?php foreach ($active as $r): ?><option value="<?= e($r['id']) ?>"><?= e($r['name']) ?></option><?php endforeach ?></select></label><label class="field">Ngày ghé Mơ<input name="date" type="date" min="<?= date('Y-m-d') ?>" required></label></div>
                <fieldset class="booking-slot-section" data-slot-section tabindex="-1">
                    <legend><span>02</span><strong>Chọn khung giờ</strong></legend>
                    <p class="mb-3 text-sm text-stone-600">Gói giờ cố định vẫn nhận muộn nếu còn trống. Giờ trả và giá gói giữ nguyên.</p>
                    <div class="slot-status-legend" aria-label="Chú thích trạng thái"><span><i class="is-available"></i>Còn trống</span><span><i class="is-selected"></i>Đang chọn</span><span><i class="is-booked"></i>Đã đặt</span></div>
                    <div class="booking-slot-grid" data-slot-grid aria-live="polite" aria-busy="true">
                        <div class="slot-skeleton"></div>
                        <div class="slot-skeleton"></div>
                    </div>
                    <div class="field booking-duration-time" data-duration-time hidden><label for="booking-arrival-time">Giờ nhận phòng</label><input id="booking-arrival-time" name="time" type="time" step="1800"></div>
                </fieldset>
                <div class="form-section booking-guest-section">
                    <div class="booking-step-heading"><span>03</span>
                        <div>
                            <h2>Thông tin của bạn</h2>
                            <p>Để Mơ xác nhận và hỗ trợ bạn nhanh chóng.</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <label class="field">Họ và tên *
                            <input name="guestName" minlength="2" maxlength="100" autocomplete="name" required>
                        </label>
                        <label class="field">Số điện thoại *
                            <input name="guestPhone" type="tel" maxlength="20" autocomplete="tel" required>
                        </label>
                        <label class="field">Email nhận xác nhận *
                            <input name="guestEmail" type="email" maxlength="254" autocomplete="email" required>
                        </label>
                        <label class="field">Số khách *
                            <select name="numberOfGuests" data-guest-count required></select>
                        </label>
                        <?php render_booking_identity_fields(); ?>
                        <label class="field full">Một lời nhắn cho Mơ (không bắt buộc)
                            <textarea name="guestNote" rows="3" maxlength="1000"></textarea>
                        </label>
                    </div>
                </div>
                <?php if (dev_reload_enabled() && env_value('APP_ENV') !== 'production' && is_admin()): ?>
                    <label class="my-5 flex cursor-pointer items-start gap-3 rounded-xl border border-[#dccbb7] bg-[#f3ece1] p-4 text-sm leading-6 text-[#654831]">
                        <input class="mt-1 size-4 shrink-0 cursor-pointer accent-[#79543e] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e]" type="checkbox" name="testPayment" data-test-payment>
                        <span><strong class="block">Test thanh toán tại local</strong>Tạo đơn thử, không chuyển tiền, không gửi email hoặc mã cửa.</span>
                    </label>
                <?php endif ?>
                <div class="notice" data-terms hidden></div><label class="check"><input type="checkbox" name="acceptTerms" required> Tôi đã đọc điều kiện đặt phòng và đồng ý để Mơ sử dụng thông tin liên hệ xử lý đơn này.</label>
                <div data-booking-message aria-live="assertive"></div><button class="button wide booking-submit" type="submit" disabled>Chọn một khung giờ để tiếp tục</button>
            </form>
            <aside class="panel booking-summary" data-summary aria-live="polite"></aside>
        </div><?php render_lookup(); ?>
    </div><?php }
                                                                                                                                                                                        function render_lookup(): void
                                                                                                                                                                                        { ?><section class="panel lookup-panel" id="tra-cuu">
        <h2>Bạn đã có một cuộc hẹn?</h2>
        <p class="muted">Tra cứu bằng mã đặt phòng và số điện thoại đã sử dụng.</p>
        <form class="lookup-form" data-lookup-form><label class="field">Mã đặt phòng<input name="code" required placeholder="MO-…"></label><label class="field">Số điện thoại<input name="phone" type="tel" required></label><button class="button">Tra cứu đặt phòng</button></form>
        <div data-lookup-result></div>
    </section><?php }
                                                                                                                                                                                        function render_payment(): void
                                                                                                                                                                                        {
                                                                                                                                                                                            $queryCode = strtoupper((string)($_GET['code'] ?? ''));
                                                                                                                                                                                            $directCode = preg_match('/^(?:MO|LANG)-[A-F0-9]{10}$/', $queryCode) ? $queryCode : '';
                                                                                                                                                                                            $return = payment_return_credentials($_GET);
                                                                                                                                                                                            $code = $directCode ?: $return['code'];
                ?><div class="container section">
        <div class="payment-container panel text-center" id="payment-app" data-code="<?= e($code) ?>" data-token="<?= e($return['token']) ?>"><span class="eyebrow">HOÀN TẤT CUỘC HẸN</span>
            <h1>Thanh toán đặt phòng</h1>
            <div data-payment-result>
                <p class="notice">Đang tải thông tin thanh toán…</p>
            </div>
        </div>
    </div><?php }
                                                                                                                                                                                        function render_login(string $error): void
                                                                                                                                                                                        { ?><div class="container section">
        <div class="panel payment-container"><span class="eyebrow">DÀNH CHO CHỦ HOME</span>
            <h1>Đăng nhập quản lý</h1>
            <p class="muted">Khách đặt phòng không cần tạo tài khoản.</p>
            <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label class="field">Email<input name="email" type="email" required></label><label class="field">Mật khẩu<input name="password" type="password" required></label><?php if ($error): ?><p class="notice error"><?= e($error) ?></p><?php endif ?><button class="button">Đăng nhập</button></form>
        </div>
    </div><?php }

function render_admin(): void
{
    require_once APP_ROOT . '/src/manual-booking-view.php';
    ?>
    <div class="admin-shell">
        <section data-admin-panel="bookings">
            <div class="admin-page-heading">
                <div>
                    <span class="eyebrow">MỖI LƯỢT GHÉ, MỘT NIỀM VUI</span>
                    <h1>Tổng quan & đặt phòng</h1>
                </div>
                <div class="admin-heading-actions flex flex-wrap items-center gap-3">
                    <button type="button" class="button outline" data-refresh-bookings><?= admin_icon('refresh') ?> Làm mới</button>
                    <button type="button" class="button" data-create-booking><?= admin_icon('plus') ?><span class="hidden sm:inline">Tạo đơn đặt phòng</span><span class="sm:hidden">Tạo đơn</span></button>
                </div>
            </div>
            <div id="admin-bookings"><p class="notice">Đang tải danh sách đơn…</p></div>
            <?php render_manual_booking_dialog() ?>
        </section>
        <section data-admin-panel="rooms" hidden>
            <div class="admin-page-heading">
                <div>
                    <span class="eyebrow">MỖI CĂN PHÒNG, MỘT CÂU CHUYỆN</span>
                    <h1>Không gian của Mơ</h1>
                    <p class="muted">Phòng, hình ảnh, tiện nghi và các gói giá.</p>
                </div>
                <button class="button" type="button" data-add-room><?= admin_icon('plus') ?> Thêm phòng</button>
            </div>
            <div class="admin-filters admin-room-filters">
                <label class="admin-search">
                    Tìm phòng
                    <input type="search" data-room-search placeholder="Tên phòng hoặc đường dẫn…" />
                </label>
                <label>
                    Trạng thái
                    <select data-room-status>
                        <option value="all">Tất cả trạng thái</option>
                        <option value="ACTIVE">Đang hoạt động</option>
                        <option value="COMING_SOON">Sắp ra mắt</option>
                        <option value="HIDDEN">Đang ẩn</option>
                    </select>
                </label>
                <span data-room-count aria-live="polite"></span>
            </div>
            <div class="admin-room-grid" data-admin-rooms><p class="notice">Đang tải phòng…</p></div>
            <div data-room-pagination></div>
        </section>
    </div>
    <div class="room-editor-root" data-room-editor hidden>
        <div class="room-editor-backdrop" data-close-editor></div>
        <aside class="room-editor" role="dialog" aria-modal="true" aria-labelledby="room-editor-title">
            <header>
                <div>
                    <span class="eyebrow">QUẢN LÝ KHÔNG GIAN</span>
                    <h2 id="room-editor-title" data-editor-title>Thêm phòng</h2>
                </div>
                <div class="editor-heading-actions">
                    <span class="dirty-indicator" data-dirty-indicator hidden>Chưa lưu</span>
                    <button class="icon-button" type="button" data-close-editor aria-label="Đóng">
                        <?= admin_icon('x') ?>
                    </button>
                </div>
            </header>
            <form data-room-form>
                <input type="hidden" name="id" />
                <section class="editor-section">
                    <h3>Thông tin cơ bản</h3>
                    <div class="form-grid">
                        <label class="field">
                            Tên phòng *
                            <input name="name" maxlength="100" required />
                        </label>
                        <div class="field admin-slug-field">
                            <label for="room-slug">Đường dẫn phòng (slug)</label>
                            <div class="admin-slug-input">
                                <span>/rooms/</span>
                                <input
                                    id="room-slug"
                                    name="slug"
                                    maxlength="100"
                                    placeholder="phong-may"
                                    spellcheck="false"
                                    autocomplete="off"
                                    aria-describedby="room-slug-error"
                                />
                            </div>
                            <output data-room-url class="admin-room-url" aria-live="polite"></output>
                            <p id="room-slug-error" data-slug-error role="alert" hidden></p>
                            <small data-slug-warning hidden>
                                Đổi đường dẫn sẽ làm liên kết cũ không còn truy cập được.
                            </small>
                        </div>
                        <label class="field full">
                            Nhãn trên ảnh
                            <input name="tag" maxlength="100" placeholder="Ví dụ: Tinh giản & thư thái" aria-describedby="room-image-tag-help" />
                        </label>
                        <label class="field full">
                            Mô tả ngắn
                            <input name="subtitle" maxlength="255" aria-describedby="room-subtitle-help" />
                        </label>
                        <label class="field full">
                            Mô tả chi tiết
                            <textarea name="description" rows="8" maxlength="5000" aria-describedby="room-description-help"></textarea>
                        </label>
                        <label class="field">
                            Trạng thái
                            <select name="visibility">
                                <option value="ACTIVE">Đang hoạt động</option>
                                <option value="HIDDEN">Ẩn khỏi website</option>
                            </select>
                        </label>
                        <label class="check editor-check">
                            <input type="checkbox" name="comingSoon" />
                            Hiển thị “Sắp ra mắt”
                        </label>
                    </div>
                </section>
                <section class="editor-section">
                    <h3>Sức chứa & sắp xếp</h3>
                    <div class="form-grid four">
                        <label class="field">
                            Số khách
                            <input name="maxGuests" type="number" min="1" max="50" value="2" />
                        </label>
                        <label class="field">
                            Phòng ngủ
                            <input name="bedrooms" type="number" min="0" max="20" value="1" />
                        </label>
                        <label class="field">
                            Phòng tắm
                            <input name="bathrooms" type="number" min="0" max="20" value="1" />
                        </label>
                        <label class="field">
                            Thứ tự
                            <input name="sortOrder" type="number" value="0" />
                        </label>
                    </div>
                </section>
                <section class="editor-section">
                    <div class="editor-section-title">
                        <div>
                            <h3>Điểm đặc biệt của phòng</h3>
                        </div>
                    </div>
                    <label class="field">
                        Nội dung nổi bật
                        <textarea name="highlights" rows="6" maxlength="3611" aria-describedby="room-highlights-help" placeholder="Gam màu nhẹ nhàng, tạo cảm giác ấm cúng&#10;Ánh sáng dịu, phù hợp để nghỉ ngơi"></textarea>
                    </label>
                </section>
                <section class="editor-section">
                    <div class="editor-section-title">
                        <div>
                            <h3>Tiện nghi của phòng</h3>
                        </div>
                    </div>
                    <div class="room-amenities-editor">
                        <div class="room-amenities-entry">
                            <label class="field" for="room-amenity-input">
                                Tên tiện nghi
                                <input id="room-amenity-input" data-tag-input maxlength="40" placeholder="Ví dụ: Điều hòa, Máy chiếu, Tủ lạnh mini" aria-describedby="room-amenity-status" />
                            </label>
                            <button class="button outline" type="button" data-add-tag><?= admin_icon('plus') ?> Thêm tiện nghi</button>
                        </div>
                        <p id="room-amenity-status" class="room-amenities-status" role="status" data-tag-status></p>
                        <div class="room-amenities-list-heading"><h4>Danh sách tiện nghi</h4><span data-tag-count></span></div>
                        <ul class="room-amenities-list" data-tag-chips aria-label="Tiện nghi đã thêm"></ul>
                    </div>
                </section>
                <section class="editor-section">
                    <div class="editor-section-title">
                        <div>
                            <h3>Gói thời gian & giá</h3>
                        </div>
                        <button class="button outline small" type="button" data-add-package>
                            <?= admin_icon('plus') ?> Thêm gói
                        </button>
                    </div>
                    <label class="mb-4 grid max-w-sm gap-2 text-sm font-medium">Ưu đãi chung của căn (%)
                        <input name="discountPercent" type="number" min="0" max="99" step="1" value="0" required class="min-h-11 rounded-lg border border-stone-300 px-3 py-2 focus-visible:outline-2 focus-visible:outline-amber-800">
                    </label>
                    <div class="package-editor" data-package-list></div>
                    <template data-package-template>
                        <div class="package-row">
                            <input type="hidden" data-package-field="id" />
                            <input type="hidden" data-package-field="sortOrder" />
                            <label class="field package-name-field">
                                Tên gói
                                <input data-package-field="name" maxlength="80" required />
                            </label>
                            <div class="package-time-fields">
                                <label class="field package-mode-field">
                                    Kiểu thời gian
                                    <select data-package-field="mode">
                                        <option value="FIXED_TIME">Giờ cố định</option>
                                        <option value="DURATION">Theo số phút (khách chọn giờ)</option>
                                    </select>
                                </label>
                                <label class="field" data-duration-field>
                                    Thời lượng (phút)
                                    <input
                                        data-package-field="durationMinutes"
                                        type="number"
                                        min="30"
                                        max="43200"
                                        value="180"
                                    />
                                </label>
                                <label class="field" data-fixed-field hidden>
                                    Nhận phòng
                                    <input data-package-field="checkInTime" type="time" />
                                </label>
                                <label class="field" data-fixed-field hidden>
                                    Trả phòng
                                    <input data-package-field="checkOutTime" type="time" />
                                </label>
                            </div>
                            <div class="package-price-fields">
                                <label class="field">
                                    Giá gốc (đ)
                                    <input
                                        data-package-field="price"
                                        type="number"
                                        min="0"
                                        max="1000000000"
                                        step="1000"
                                        required
                                    />
                                </label>
                                <label class="field">
                                    Ưu đãi riêng (%)
                                    <input data-package-field="discountPercent" type="number" min="0" max="99" step="1" placeholder="VD: 20">
                                </label>
                            </div>
                            <div class="package-row-actions">
                                <output data-package-discount-preview class="package-final-price" aria-live="polite"></output>
                                <label class="package-visibility-switch">
                                    <span>Hiển thị</span>
                                    <input class="sr-only" data-package-field="enabled" type="checkbox" role="switch" checked />
                                    <span class="package-switch-track" aria-hidden="true"><span></span></span>
                                </label>
                                <button
                                    class="icon-button package-remove"
                                    type="button"
                                    data-remove-package
                                    aria-label="Xóa gói"
                                    title="Xóa gói"
                                >
                                    <?= admin_icon('trash') ?>
                                </button>
                            </div>
                        </div>
                    </template>
                </section>
                <section class="editor-section" data-room-images-section>
                    <div class="editor-section-title">
                        <div>
                            <h3>Hình ảnh</h3>
                            <p>Tối đa 12 ảnh, JPEG/PNG/WebP, mỗi ảnh dưới 8 MiB.</p>
                        </div>
                    </div>
                    <div class="room-upload-layout">
                        <label class="upload-zone">
                            <input data-image-input type="file" aria-label="Chọn ảnh phòng" accept="image/jpeg,image/png,image/webp" multiple>
                            <span class="room-upload-symbol"><?= admin_icon('cloud-upload') ?></span>
                            <strong>Thả ảnh vào đây hoặc chọn từ máy</strong>
                            <span>Ảnh đầu tiên sẽ hiển thị làm ảnh bìa</span>
                            <span class="room-upload-choose"><?= admin_icon('photo') ?> Chọn ảnh</span>
                        </label>
                        <aside class="room-upload-info" aria-label="Thông tin tải ảnh">
                            <h4>Thông tin tải ảnh</h4>
                            <div><?= admin_icon('photo') ?><p><strong data-image-pending>0 ảnh chờ lưu</strong><small>Sẽ được tải lên khi bạn lưu phòng</small></p></div>
                            <div><?= admin_icon('crown') ?><p><strong data-image-cover-count>Chưa có ảnh bìa</strong><small>Ảnh đầu tiên trong danh sách là ảnh bìa</small></p></div>
                            <div><?= admin_icon('file-description') ?><p><strong>Định dạng hỗ trợ</strong><small>JPEG, PNG, WebP</small></p></div>
                            <div><?= admin_icon('database') ?><p><strong>Kích thước tối đa</strong><small>Mỗi ảnh không quá 8 MiB · Tối đa 12 ảnh</small></p></div>
                        </aside>
                    </div>
                    <div class="room-images-heading">
                        <div><h4>Ảnh đã chọn <span data-image-count>(0 ảnh)</span></h4></div>
                        <span><?= admin_icon('grip-vertical') ?> Sắp xếp ảnh</span>
                    </div>
                    <div class="image-manager" data-image-list></div>
                    <p class="room-images-status" data-image-status role="status" aria-live="polite"></p>
                </section>
                <footer class="editor-footer">
                    <button class="button outline" type="button" data-close-editor>Hủy</button>
                    <button class="button" type="submit" data-save-room>
                        <?= admin_icon('device-floppy') ?> Lưu phòng
                    </button>
                </footer>
            </form>
        </aside>
    </div>
    <?php
}
