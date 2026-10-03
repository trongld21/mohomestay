<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/views.php';

function contact_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function contact_markup(): string
{
    ob_start();
    try {
        render_contact_widget();
        return (string)ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

// Exercise the real bootstrap, without stubbing environment helpers.
$_ENV['CONTACT_MESSENGER_URL'] = '';
$_ENV['CONTACT_FACEBOOK_URL'] = '';
$markup = contact_markup();
contact_check(substr_count($markup, 'Sắp kết nối') === 2, 'Unconfigured social channels must remain placeholders');
contact_check(str_contains($markup, 'href="https://zalo.me/0357907153"'), 'Zalo destination changed');
contact_check(str_contains($markup, 'href="tel:0357907153"'), 'Hotline destination changed');

$_ENV['CONTACT_MESSENGER_URL'] = 'https://m.me/mo-home-test';
$_ENV['CONTACT_FACEBOOK_URL'] = 'https://www.facebook.com/mo-home-test';
$markup = contact_markup();
contact_check(str_contains($markup, 'href="https://m.me/mo-home-test"'), 'Configured Messenger missing');
contact_check(!str_contains($markup, 'Sắp kết nối'), 'Configured social channels must be available');

$_ENV['CONTACT_MESSENGER_URL'] = 'javascript:alert(1)';
$_ENV['CONTACT_FACEBOOK_URL'] = 'https://facebook.com.attacker.example/test';
$markup = contact_markup();
contact_check(substr_count($markup, 'Sắp kết nối') === 2, 'Unsafe social destinations must be rejected');
echo "Contact widget runtime tests: OK\n";
