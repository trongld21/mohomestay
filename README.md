# Lặng Home — PHP cho DirectAdmin

Website đặt phòng chạy bằng PHP 8.1+ và MySQL/MariaDB, không cần Node.js hoặc Composer trên server.

## Chạy local

```bash
cp .env.example .env
# sửa thông tin MySQL trong .env
php tools/migrate.php
php -S localhost:8080 -t public public/index.php
```

Mở `http://localhost:8080`. Giữ `BOOKINGS_ENABLED=false` nếu chưa cấu hình payOS và điều khoản đặt phòng.

## Kiểm tra

```bash
php tools/check.php
php tools/test.php
```

## Deploy

Xem [DEPLOY_DIRECTADMIN.md](DEPLOY_DIRECTADMIN.md). Push lên nhánh `main` sẽ upload qua deploy hook HTTPS có chữ ký và tự chạy migration.

Mã chính:

- `public/index.php`: front controller và route trang.
- `src/api.php`: API lịch, đặt/tra cứu phòng, webhook và quản trị.
- `src/booking.php`: xác thực dữ liệu và tính khung giờ.
- `src/payment.php`: chữ ký HMAC và kết nối payOS.
- `database/migrations`: schema MySQL/MariaDB.
- `public/assets/app.js`: giao diện JavaScript thuần, không cần build.
