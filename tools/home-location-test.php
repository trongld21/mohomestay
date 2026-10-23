<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/home-location-view.php';

function location_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$_ENV['HOME_MAP_LATITUDE'] = '';
$_ENV['HOME_MAP_LONGITUDE'] = '';
$config = home_location_map_config();
location_check($config['latitude'] === 10.024753 && $config['longitude'] === 105.7551832, 'Default must use the destination coordinates from the user-confirmed Maps link.');
ob_start();
render_home_location();
$html = (string)ob_get_clean();
location_check(!str_contains($html, '<iframe'), 'Google embed must be removed.');
location_check(!str_contains($html, 'data-map-key='), 'OpenStreetMap must not require or expose an API key.');
location_check(str_contains($html, 'data-home-map'), 'Map must work without key configuration.');
location_check(!str_contains($html, 'Vị trí tham khảo'), 'Confirmed property pin must not show the old area note.');
location_check(str_contains($html, 'destination=10.024753%2C105.7551832'), 'Directions must target the same coordinates as the map marker.');
location_check(str_contains($html, 'maps/dir/'), 'Directions must remain available.');

// Synthetic coordinates test the optional property pin override, not its actual location.
$_ENV['HOME_MAP_LATITUDE'] = '10';
$_ENV['HOME_MAP_LONGITUDE'] = '105';
location_check(home_location_map_config()['latitude'] === 10.0, 'Valid explicit coordinates must override the property marker.');
ob_start();
render_home_location();
$html = (string)ob_get_clean();
location_check(str_contains($html, 'data-map-latitude="10"'), 'Latitude must be rendered.');
location_check(str_contains($html, 'data-map-longitude="105"'), 'Longitude must be rendered.');
location_check(!str_contains($html, 'Vị trí tham khảo'), 'Configured property coordinates must not show the street note.');

foreach ([['', '105'], ['91', '105'], ['10', '-181'], ['NaN', '105']] as [$latitude, $longitude]) {
    $_ENV['HOME_MAP_LATITUDE'] = $latitude;
    $_ENV['HOME_MAP_LONGITUDE'] = $longitude;
    location_check(home_location_map_config()['latitude'] === 10.024753 && home_location_map_config()['longitude'] === 105.7551832, 'Invalid coordinates must fall back to the confirmed property pin.');
}

echo "Home OpenStreetMap config and rendering: OK\n";
