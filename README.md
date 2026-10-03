# Mơ Home — PHP cho DirectAdmin

Website đặt phòng chạy bằng PHP 8.1+ và MySQL/MariaDB, không cần Node.js hoặc Composer trên server.

## Chạy local

```bash
cp .env.example .env
# sửa thông tin MySQL trong .env
php tools/migrate.php
php -S localhost:8080 -t public public/index.php
```

Mở `http://localhost:8080`. Giữ `BOOKINGS_ENABLED=false` nếu chưa cấu hình payOS và điều khoản đặt phòng.

Khi chạy PHP local, trang tự tải lại sau khi lưu thay đổi trong khoảng 1,5 giây.
Để Tailwind tự build khi lưu template/CSS, chạy `npm run watch:css` ở terminal khác.
Nếu dùng Apache local, đặt `APP_ENV=development` trong `.env` để bật reload.
Reload chỉ hoạt động với truy cập loopback, không bật trên website production.

## CSS với Tailwind

Mọi phần giao diện mới hoặc chỉnh sửa dùng Tailwind CSS theo [AGENTS.md](AGENTS.md).
CSS cũ được giữ để tương thích; các trạng thái chung nằm ở `src/styles/tailwind.css`.
Build theo [Tailwind CLI](https://tailwindcss.com/docs/installation/tailwind-cli):

```bash
npm ci
npm run build:css
# Khi phát triển:
npm run watch:css
```

Commit `public/assets/tailwind.css` sau mỗi thay đổi. Deploy chỉ cần CSS đã build,
server PHP không cần Node.js. Không dùng Play CDN và không sửa file CSS sinh ra trực tiếp.

CSS, JavaScript và ảnh tĩnh trong template dùng `asset_url()` để thêm phiên bản
theo nội dung file. Khi file thay đổi, URL tự đổi, không cần tăng `?v=` thủ công
hoặc hard refresh. Dùng helper này cho các tài nguyên tĩnh mới.

Nút “Nhắn Mơ” mở danh sách liên hệ ở trang chủ. Zalo và Hotline dùng số
0357 907 153. Khi có tài khoản chính thức, đặt `CONTACT_MESSENGER_URL` và
`CONTACT_FACEBOOK_URL` trong `.env` bằng link HTTPS của Mơ; khi chưa cấu hình,
hai mục này hiển thị “Sắp kết nối” và không dẫn tới tài khoản khác.

## Kiểm tra PHP

```bash
php tools/check.php
php tools/test.php
php tools/contact-test.php
php tools/asset-test.php
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
