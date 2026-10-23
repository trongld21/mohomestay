<?php

declare(strict_types=1);

function home_location_map_config(): array
{
    $latitude = filter_var(env_value('HOME_MAP_LATITUDE', ''), FILTER_VALIDATE_FLOAT);
    $longitude = filter_var(env_value('HOME_MAP_LONGITUDE', ''), FILTER_VALIDATE_FLOAT);
    $validCoordinates = $latitude !== false && $longitude !== false
        && $latitude >= -90 && $latitude <= 90
        && $longitude >= -180 && $longitude <= 180;

    return [
        // Destination pin from the user's Maps link: NipUCnPJHmJeBgxA6.
        'latitude' => $validCoordinates ? $latitude : 10.024753,
        'longitude' => $validCoordinates ? $longitude : 105.7551832,
    ];
}

function render_home_location(): void
{
    $address = '82 đường B18, KDC 91B, Ninh Kiều, Cần Thơ';
    $map = home_location_map_config();
    $destination = rawurlencode($map['latitude'] . ',' . $map['longitude']);
    $directionsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . $destination;
    ?>
    <section id="duong-den-mo" class="home-location" aria-labelledby="home-location-title" data-home-reveal>
        <img class="home-location-backdrop" src="<?= e(asset_url('/images/home-location-plaster.webp')) ?>" alt="" width="1920" height="720" loading="lazy" aria-hidden="true">
        <div class="container home-location-layout">
            <div class="home-location-copy">
                <p class="home-location-kicker"><span aria-hidden="true"></span>VỊ TRÍ</p>
                <h2 id="home-location-title">Đường đến <em>Mơ.</em></h2>
                <p class="home-location-intro">Một cuộc hẹn nhỏ ở Cần Thơ.<br>Hẹn gặp bạn tại Mơ nhé.</p>
                <div class="home-location-address-panel">
                    <div class="home-location-address">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z" />
                            <circle cx="12" cy="10" r="2.5" />
                        </svg>
                        <div>
                            <h3>82 đường B18</h3>
                            <p>KDC 91B, Ninh Kiều, Cần Thơ</p>
                        </div>
                    </div>
                    <a class="home-location-directions" href="<?= e($directionsUrl) ?>" target="_blank" rel="noopener noreferrer">
                        <span>Chỉ đường đến Mơ</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 12h16m-6-6 6 6-6 6" />
                        </svg>
                        <span class="sr-only">(mở Google Maps trong tab mới)</span>
                    </a>
                </div>
                <div class="home-location-contact">
                    <span class="home-location-phone-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 3 4 1 1 5-3 2a15 15 0 0 0 6 6l2-3 5 1 1 4c0 1-1 2-3 2C10 21 3 14 3 6c0-2 1-3 2-3Z" /></svg>
                    </span>
                    <span>Cần Mơ hướng dẫn thêm?</span>
                    <a href="tel:0357907153">Gọi 0357 907 153</a>
                </div>
            </div>
            <div class="home-location-map">
                <div class="home-location-map-surface" data-home-map
                    data-map-latitude="<?= e((string)$map['latitude']) ?>"
                    data-map-longitude="<?= e((string)$map['longitude']) ?>"
                >
                    <div class="home-map-canvas" data-map-canvas role="region" aria-label="Bản đồ Mơ Home tại <?= e($address) ?>"></div>
                    <div class="home-map-status" data-map-status>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                        <p role="status" data-map-message>Đang tải bản đồ…</p>
                        <span>Bạn vẫn có thể chọn “Chỉ đường đến Mơ”.</span>
                        <button class="home-map-retry" type="button" data-map-retry hidden>Thử tải lại bản đồ</button>
                    </div>
                    <div class="home-location-map-place">
                        <img src="<?= e(asset_url('/images/mo-home-illustration.png')) ?>" alt="" width="76" height="92" loading="lazy">
                        <div>
                            <strong>82 đường B18</strong>
                            <p>KDC 91B, Ninh Kiều, Cần Thơ</p>
                            <a href="<?= e($directionsUrl) ?>" target="_blank" rel="noopener noreferrer">Chỉ đường đến Mơ <span aria-hidden="true">↗</span><span class="sr-only"> (Google Maps, tab mới)</span></a>
                        </div>
                    </div>
                </div>
                <div class="home-location-map-caption">
                    <span class="home-location-map-name">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Z" />
                            <path d="M9 21v-8h6v8" />
                        </svg>
                        <span>Mơ Home <span class="home-location-map-city">· Cần Thơ</span></span>
                    </span>
                    <a href="<?= e($directionsUrl) ?>" target="_blank" rel="noopener noreferrer">
                        Mở Google Maps
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M7 17 17 7M7 7h10v10" />
                        </svg>
                        <span class="sr-only">(tab mới)</span>
                    </a>
                </div>
            </div>
        </div>
    </section>
    <script defer src="<?= e(asset_url('/assets/home-location.js')) ?>"></script>
    <?php
}
