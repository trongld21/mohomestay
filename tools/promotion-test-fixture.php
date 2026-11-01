<?php

declare(strict_types=1);

function promotion_test_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS promotions (
        id TEXT PRIMARY KEY,
        name TEXT,
        type TEXT,
        percent INTEGER,
        enabled INTEGER,
        start_date TEXT,
        end_date TEXT,
        weekdays TEXT,
        start_time TEXT,
        end_time TEXT,
        min_stays INTEGER,
        room_ids TEXT,
        package_ids TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS booking_promotion_snapshots (
        booking_id TEXT PRIMARY KEY,
        base_price INTEGER,
        price INTEGER,
        discount_percent INTEGER,
        promotion_id TEXT,
        promotion_name TEXT
    )');
}

function promotion_test_database(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    promotion_test_schema($pdo);
    $pdo->exec('CREATE TABLE bookings (
        id TEXT PRIMARY KEY,
        guest_phone TEXT,
        guest_email TEXT,
        guest_name TEXT,
        status TEXT,
        is_test INTEGER,
        check_out TEXT
    )');
    $pdo->exec('CREATE TABLE booking_checkout_charges (
        booking_id TEXT PRIMARY KEY,
        actual_checkout_at TEXT
    )');
    return $pdo;
}
