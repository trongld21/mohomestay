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

## Đơn đặt phòng qua điện thoại / Zalo

Trong **Admin → Tổng quan & đặt phòng → Tạo đơn đặt phòng**, chọn phòng, gói lưu trú,
ngày giờ và nhập tên, số điện thoại khách. Email không bắt buộc. Giờ trả phòng lấy
từ cấu hình gói. Giá chốt mặc định theo giá gói; admin có thể sửa cho riêng đơn này,
không đổi bảng giá. Lịch trùng và ngày tạm khóa được kiểm tra trước khi lưu.
Đơn được xác nhận và giữ lịch ngay, mặc định **Chưa thanh toán**. Có thể chọn
**Đã thanh toán đủ** lúc tạo hoặc **Xác nhận thu tiền** sau đó. Tiền mặt và chuyển
khoản được ghi nhận thủ công, không tạo giao dịch payOS hay tự gửi tin nhắn/email.
Chạy migration `006_manual_bookings.sql` bằng `php tools/migrate.php` trước khi sử dụng.

Trạng thái lưu trú kết hợp tự động và thủ công: đơn đã xác nhận tự hiển thị trong
giờ lưu trú khi đến giờ nhận và đã kết thúc khi đến giờ trả, độc lập với thanh toán.
Trạng thái được tính từ giờ máy chủ khi đọc API; danh sách admin tự tải lại mỗi
30 giây khi không thao tác. Admin có thể nhận sớm (kiểm tra trùng lịch và cập nhật
giờ nhận thực tế, giữ giờ trả/giá) hoặc kết thúc sớm. Trạng thái theo giờ không
xác nhận khách thực sự đã đến. Đơn hủy/chờ thanh toán không tự nhận phòng.

## Kiểm tra PHP

```bash
php tools/check.php
php tools/test.php
php tools/contact-test.php
php tools/asset-test.php
```

## Deploy

Xem [DEPLOY_DIRECTADMIN.md](DEPLOY_DIRECTADMIN.md). Push lên nhánh `main` sẽ kiểm thử và giữ cổng duyệt production, rồi upload một ZIP runtime cùng marker có chữ ký qua API DirectAdmin. Cron trên hosting kiểm tra và hoàn tất cập nhật/migration. Xem hướng dẫn cài `deploy-activate.php` trước khi bật; không cần SSH/SFTP hoặc token GitHub trên hosting.

Mã chính:

- `public/index.php`: front controller và route trang.
- `src/api.php`: API lịch, đặt/tra cứu phòng, webhook và quản trị.
- `src/booking.php`: xác thực dữ liệu và tính khung giờ.
- `src/payment.php`: chữ ký HMAC và kết nối payOS.
- `database/migrations`: schema MySQL/MariaDB.
- `public/assets/app.js`: giao diện JavaScript thuần, không cần build.

## Mã mở cửa

Admin vào **Quản lý mã mở cửa** (`/admin/door-codes`) để tạo nhiều mã dùng chung,
chọn loại ngày/tuần/tháng, đặt giờ bắt đầu/kết thúc và bật/tắt thủ công. Thời gian nhập
và hiển thị dùng giờ Việt Nam; database lưu UTC. Trạng thái hiệu lực được tính tự động
mỗi lần đọc: chưa đến hạn, đang dùng, đã tắt hoặc hết hạn. Không cần cron để loại mã hết hạn.
Muốn dùng lại mã hết hạn, sửa thời gian và bật sử dụng.

Email xác nhận sau khi thanh toán thành công chọn mã đang bật có thời gian bao gồm
thời điểm nhận phòng, ưu tiên ngày → tuần → tháng. Nếu cùng mức ưu tiên có nhiều mã,
email chứa tất cả mã đó kèm thời hạn. Nếu chưa có mã phù hợp, email hướng dẫn liên hệ Mơ.
Mã chỉ được nhập và quản lý trong website; việc đặt thời hạn này không tự cấu hình khóa cửa vật lý.

Chạy `php tools/migrate.php` để tạo bảng `door_codes`, rồi `php tools/door-code-test.php`
để kiểm tra ưu tiên, bật/tắt, hết hạn, múi giờ và nội dung email. Cần cấu hình `MAIL_FROM`
hợp lệ và mail server để gửi thư thực tế.

## Tự động tạo mã cổng TTLock

Tính năng được chuẩn bị sẵn nhưng mặc định **tắt** (`TTLOCK_ENABLED=false`).
Khi tắt, email và mã thủ công hoạt động như trước. Chưa cần nhập thông tin thật vào Git.

Khi bật, chỉ đơn `PAID` và `CONFIRMED`/`CHECKED_IN` mới được cấp mã riêng cho khóa cổng chung.
Thời hạn là nhận phòng trừ 1 giờ đến trả phòng cộng 1 giờ, lưu UTC và hiển thị giờ Việt Nam.
Mã được giữ nguyên khi webhook lặp lại hoặc email cần gửi lại. Email chỉ được gửi sau khi
TTLock xác nhận mã; không dùng mã chung làm phương án thay thế khi đồng bộ thất bại.

### Kích hoạt sau khi TTLock duyệt

1. Backup database, deploy mã nguồn rồi chạy migration `005_booking_access_codes.sql` bằng
   `php tools/migrate.php` ở local hoặc migration của deploy hook trên hosting.
2. Cấu hình riêng trên máy chủ: `TTLOCK_BASE_URL`, `TTLOCK_CLIENT_ID`, `TTLOCK_CLIENT_SECRET`,
   `TTLOCK_USERNAME`, `TTLOCK_PASSWORD_MD5`, `TTLOCK_LOCK_ID`. Username và mật khẩu thuộc
   tài khoản **app TTLock đang quản lý khóa**, không phải tài khoản nhà phát triển.
   `TTLOCK_PASSWORD_MD5` là MD5 chữ thường 32 ký tự của mật khẩu; giữ kín như mật khẩu.
   Chọn API host đúng vùng tài khoản/ứng dụng, không đoán vùng từ vị trí homestay.
3. Kiểm tra `MAIL_FROM`, mail server và quyền quản trị khóa. Khóa phải hỗ trợ thêm/xóa mã
   tùy chọn trực tiếp qua Wi-Fi hoặc gateway (`addType=2`, `deleteType=2`). Chế độ Wi-Fi
   tiết kiệm pin chỉ hỗ trợ lệnh bất đồng bộ chưa được triển khai, cần thử đúng mẫu khóa.
4. Khi đã sẵn sàng thử nghiệm, đặt `TTLOCK_ENABLED=true`. Thử một đơn thanh toán thực tế,
   kiểm tra mã trong app, mở cổng, giờ bắt đầu/kết thúc và nội dung email. Đồng thời thử
   hủy đơn, webhook trùng và lỗi kết nối. Không coi kiểm thử giả lập là xác nhận khóa thật.
5. Trong DirectAdmin, đặt cron mỗi phút: `/duong-dan/php /duong-dan/domain/src/booking-access-worker.php`.
   Đổi đường dẫn theo hosting. Worker chỉ chạy CLI; không mở endpoint cron công khai.
   Local có thể chạy `php tools/sync-booking-access.php`.

Trang `/admin/door-codes` hiển thị mã theo đơn, lỗi đồng bộ, trạng thái email và nút
**Đối soát / thử lại**. Thao tác yêu cầu đăng nhập admin và CSRF. Mã thủ công vẫn chỉ lưu
trên web và không tự cấu hình khóa vật lý.

Worker xử lý lại đơn đang chờ và xóa mã của đơn hủy/mã hết thời hạn khỏi khóa để giải phóng
bộ nhớ. Cần cron để tự thử lại và thu dọn; thời hạn đã ghi trên khóa vẫn do khóa thực thi.
Bản ghi trên web giữ lại để đối soát. Worker có thể xử lý đơn đã xác nhận trước ngày bật
nếu đơn vẫn còn thời hạn; cần rà soát các đơn đang lưu trú khi kích hoạt.

Nếu gửi lệnh bị gián đoạn, trạng thái **Cần đối soát với khóa** được giữ lại. Hệ thống tìm
mã cũ trước; nếu không xác định được kết quả, không tự tạo mã khác hoặc gửi email.
Kiểm tra app TTLock và kết nối khóa rồi bấm đối soát; nếu vẫn chưa khớp cần xử lý thủ công.
Hủy đơn chỉ hoàn tất thu hồi vật lý khi trạng thái là **Đã thu hồi**; nếu khóa mất mạng,
worker sẽ thử lại. Việc sửa mã/thời hạn riêng theo đơn và đổi lịch sau thanh toán chưa
có giao diện; không sửa trực tiếp database để đổi lịch mã đã gửi.

Kiểm thử không gọi API/không gửi thư thật:

```bash
php tools/ttlock-test.php
php tools/booking-access-test.php
```

Kiểm thử tích hợp dùng SQLite tách biệt để kiểm tra trạng thái tạo mã, lỗi mạng,
chống tạo/gửi lặp, gửi lại email và thu hồi. Cần kiểm tra thêm MySQL locking,
migration, cron, khóa thật và giao thư thực tế trước khi vận hành.

Tài liệu API: https://euopen.ttlock.com/doc/oauth2,
https://euopen.ttlock.com/documentPages/htmlPages/cloud/passcode/addEn.html,
https://euopen.ttlock.com/doc/api/v3/lock/listKeyboardPwd,
https://euopen.ttlock.com/doc/api/v3/keyboardPwd/delete.
