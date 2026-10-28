# Mơ Home — PHP cho DirectAdmin

Website đặt phòng chạy bằng PHP 8.1+ và MySQL/MariaDB, không cần Node.js hoặc Composer trên server.

## Đếm lượt người vào / ra

Trang quản trị có hai tab riêng: **Trực tiếp** và **Video đã ghi**. Trực tiếp
giữ nguồn SDK/AI chạy nền trên máy hiện tại; máy cần bật khi sử dụng luồng này.
Tab Video đã ghi mở file `report.json` trên trình duyệt, không gọi luồng camera
hay tải file lên máy chủ. Để xử lý video theo ca/ngày, xuất MP4 từ Imou, mở
`tools/Dem-video.cmd`, nhận CSV, báo cáo và video có vạch đếm. Hướng dẫn:
[docs/PEOPLE_COUNTER_OFFLINE.md](docs/PEOPLE_COUNTER_OFFLINE.md).

Luồng trực tiếp:

Trang `/admin/people-counter` hiển thị lượt vào/ra, biểu đồ theo giờ và lịch sử
qua cửa. Cần migration `026_people_counter.sql` và `027_people_counter_trials.sql`, khóa `PEOPLE_COUNTER_KEY` riêng
và dịch vụ AI Python chạy trên máy xử lý riêng. Chỉ tài khoản Super Admin được
xem camera/báo cáo này; quyền không cấp cho nhân viên. Khi chưa kết nối camera,
trang hiển thị trạng thái chờ.
Chi tiết cấu hình, kiểm thử video, giới hạn và cách vận hành:
[docs/PEOPLE_COUNTER.md](docs/PEOPLE_COUNTER.md).

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

## Bản đồ OpenStreetMap ở trang chủ

Phần “Đường đến Mơ” sử dụng Leaflet 1.9.4 với bản đồ OpenStreetMap,
không cần tài khoản hoặc API key. SDK chỉ tải khi section vị trí vào màn hình.
Đường dẫn CDN được cố định phiên bản và kiểm tra SRI.

Mặc định ghim Mơ Home tại tọa độ **10.024753, 105.7551832**, lấy từ điểm đến
trong link Maps chủ nhà xác nhận: https://maps.app.goo.gl/NipUCnPJHmJeBgxA6.
Dùng tọa độ điểm đến, không dùng tọa độ tâm màn hình hay điểm xuất phát của đường đi.
Ghim có nhãn “Mơ Home” luôn hiển thị. Nút chỉ đường mở Google Maps với cùng tọa độ
điểm đến, không đính kèm vị trí xuất phát của người đã chia sẻ link.
Không gọi geocoding mỗi lượt xem.

Nếu cần cập nhật vị trí sau này, có thể ghi đè tọa độ trong `.env`:

```ini
HOME_MAP_LATITUDE=
HOME_MAP_LONGITUDE=
```

Giữ attribution © OpenStreetMap contributors luôn nhìn thấy; không tải hàng loạt,
không prefetch vùng bản đồ, không bypass cache. Tile được tải trực tiếp từ
`https://tile.openstreetmap.org/{z}/{x}/{y}.png`, dùng Referer hợp lệ và cache mặc định
của trình duyệt. Giới hạn zoom 19, không bật retina tải thêm tile. Dịch vụ cộng đồng
không có SLA; khi lưu lượng tăng cần cân nhắc nhà cung cấp tile phù hợp.
Nếu SDK hoặc tile lỗi, phần địa chỉ và chỉ đường vẫn hoạt động, có nút thử lại.

Tài liệu: https://leafletjs.com/examples/quick-start/ và
https://operations.osmfoundation.org/policies/tiles/.

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

Trang Lịch phòng tự kiểm tra lại mỗi 15 giây và khi quay về tab. Chấm màu theo
phòng, gói và giờ nhận đang chọn; không biểu thị tình trạng chung của mọi phòng.
API `/api/availability` mặc định lấy 31 ngày, nhận `days` từ 1 đến 62 để kiểm tra
trọn tháng cùng các gói kéo dài tối đa 30 ngày. Giờ quá hạn dùng đồng hồ máy chủ;
trạng thái còn trống độc lập với việc có mở thanh toán trực tuyến hay không.

Danh sách phòng trên Trang chủ tự kiểm tra `/api/rooms/available-now` mỗi 15 giây
và khi khách quay lại tab. Chỉ hiện phòng đang hoạt động, không có đơn chiếm giờ
hiện tại hoặc khóa lịch trong ngày. Đơn chờ thanh toán còn hạn giữ chỗ được tính
là bận; đơn tương lai, đã hủy hoặc hết hạn giữ chỗ không chặn phòng hiện tại.
Trang Phòng & giá vẫn hiển thị đầy đủ. Khi mất kết nối, Trang chủ ẩn danh sách
chưa xác minh và tự thử lại.

Trang đặt phòng thành công hiển thị ảnh phòng, thông tin lưu trú và hướng dẫn
nhận phòng. Có thể điền `CHECKIN_GUIDE_URL`, `HOUSE_RULES_URL` (HTTPS), `WIFI_NAME`
và `WIFI_PASSWORD` trong `.env`. Khi chưa cấu hình, trang hướng khách liên hệ Mơ;
nội quy dùng `BOOKING_TERMS` hiện có. Không đưa mật khẩu Wi-Fi vào source code.
Mã cổng hiện khi đơn đã thanh toán và còn trong thời gian lưu trú. Admin test
trên local có thể xem mã đã lưu, đang Bật và phù hợp thời gian nhận phòng của
đơn thử đã xác nhận; khách và production không nhận mã thật từ đơn thử.
Wi-Fi đã cấu hình hiển thị cả trên đơn thử đã xác nhận để kiểm tra giao diện.
Khi bật TTLock, chỉ đọc mã đã đồng bộ thành công cho đúng đơn; test không tự
sinh mã mới trên khóa.

### Callback thanh toán giả (local)

Đăng nhập admin rồi mở `/dev/payments`. Chọn phòng/gói và thời gian còn trống,
tạo đơn thử, mở màn thanh toán ở tab mới, sau đó bấm gửi callback thành công.
Tab thanh toán tự chuyển tới trang thành công sau khi API xác nhận đơn.

Hoặc dùng ngay form khách hàng ở `/bookings`: đăng nhập admin trên local, tích
**Test thanh toán tại local**, điền thông tin và tạo đơn. Trang `/payment` của
đơn thử có nút **Giả lập thanh toán thành công**, không mở payOS. Bỏ tích để
dùng luồng thanh toán thật. Lựa chọn test không hiển thị trên production hoặc
cho khách chưa đăng nhập admin.

- `POST /api/dev/payments/create`: các trường như tạo đơn thủ công, gồm `requestId`
  (32 ký tự hex); trả `bookingCode`, `orderCode`, `amount`, `paymentUrl`.
- `POST /api/dev/payments/callback`: JSON `{ "orderCode": "...", "amount": 200000 }`.
- Cả hai yêu cầu phiên admin, header `X-CSRF-Token`, kết nối loopback và PHP local
  hoặc `APP_ENV=development`. Production trả 404. Không đổi hoặc bỏ xác thực webhook thật.
- Chỉ áp dụng đơn có `is_test=1`, không gửi email/mã cửa hay giao dịch ngân hàng.
  Đơn thử giữ lịch 15 phút, callback thành công giữ lịch như đơn đã xác nhận;
  hãy hủy đơn thử trong admin sau khi test. Doanh thu không tính đơn thử.
- Chạy `php tools/migrate.php` trước khi dùng (`007_test_bookings.sql`).

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

## Mã TTLock tự sinh không cần gateway (cập nhật)

Mã mới dùng `/v3/keyboardPwd/get`, loại 3 có thời hạn, không dùng custom/add.
Migration 006 giữ mã cũ đã cấp ở chế độ CUSTOM; chỉ chuyển đơn FAILED lỗi -2012 chưa có
remote_id sang GENERATED để có thể bấm Đối soát / thử lại sau deploy. Không cần đổi key .env.
Khoảng đệm 1 giờ được làm tròn ra ngoài theo giờ: lưu trú 11:30–12:00 → mã 10:00–13:00.
Giới hạn của TTLock: phải dùng lần đầu trong 24 giờ từ thời điểm bắt đầu hiệu lực,
không phải từ thời điểm đặt phòng hoặc sinh mã. Email hiển thị thời hạn thực tế và điều kiện này.

Khi không có gateway, không thể bảo đảm thu hồi mã trước hạn từ xa. Đơn hủy sẽ được báo
FAILED kèm yêu cầu xóa mã qua Bluetooth tại khóa; không báo Đã thu hồi chỉ vì hủy trên web.
Mã hết thời hạn được đóng trên web theo thời hạn, không gọi xóa từ xa. READY của mã tự sinh
nghĩa là cloud TTLock đã trả mã hợp lệ, chưa chứng minh mở cổng thật thành công.
Timeout tìm lại mã bằng tên định danh đơn và thời hạn trước khi cấp lại, giữ số 0 đầu.
Không tự sinh mã mới khi chưa xác định được kết quả lần gửi trước.

## Ưu đãi căn và từng gói

Trong tạo/sửa căn, nhập ưu đãi chung 0–99% và ưu đãi riêng mỗi gói.
Gói để trống kế thừa mức chung; nhập 0% bỏ giảm cho gói đó; nhập phần trăm khác
ưu tiên hoàn toàn mức riêng, không cộng dồn. Giá gốc được giữ trong database.
API cung cấp `basePrice`, `discountPercent`, `effectiveDiscountPercent` và `price`
(giá sau giảm, làm tròn đến 1.000đ: phần lẻ dưới 500đ xuống, từ 500đ lên; không giảm giữ giá gốc), dùng cho danh sách, đặt phòng, admin tạo bill và thanh toán.
Khi sửa căn, ô giá luôn nạp lại giá gốc để không giảm lặp. Các đơn đã tạo giữ tổng tiền/snapshot cũ.
Chạy migration `007_package_discounts.sql` trước khi dùng; mặc định căn 0%, gói kế thừa.
Kiểm thử: `php tools/room-discount-test.php`.

### Tạo mã TTLock trực tiếp trên web

Trang `/admin/door-codes` có biểu mẫu **Tạo mã trực tiếp trên khóa TTLock**,
không cần đơn đặt phòng và không tự gửi email. Nhập tên và thời hạn theo giờ Việt Nam;
web làm tròn bắt đầu xuống, kết thúc lên theo giờ, không cộng đệm của đơn đặt phòng.
Tên trên TTLock kèm mã nhận dạng WEB để đối soát khi mất kết nối. Yêu cầu chưa rõ
kết quả được giữ ở UNKNOWN; nút đối soát không tự tạo thêm mã nếu chưa tìm thấy mã cũ.
Mã READY hiển thị PIN và thời hạn thực tế. Chỉ quản trị viên có CSRF hợp lệ được tạo.

Cần migration `008_manual_access_codes.sql` và cấu hình TTLock đang bật.
Mã cần được sử dụng lần đầu trong 24 giờ từ lúc bắt đầu hiệu lực.
Không hỗ trợ sửa/thu hồi sớm mã tự sinh qua web khi khóa không có gateway;
muốn thu hồi cần dùng app tại khóa qua Bluetooth. **Lưu mã có sẵn** vẫn chỉ lưu
mã nhập tay trên web, không ghi mã đó vào khóa.
Kiểm thử độc lập: `php tools/manual-access-test.php` (SQLite và TTLock giả lập).

## Tài khoản và phân quyền quản trị

Chạy `php tools/migrate.php` để áp dụng `009_accounts.sql` trước khi sử dụng.
Tài khoản `ADMIN_EMAIL` / `ADMIN_PASSWORD_HASH` trong môi trường máy chủ là Admin gốc,
luôn có toàn quyền và là tài khoản duy nhất được quản lý tài khoản, cấp quyền.

- `/admin/accounts`: tạo, sửa tên/email, đặt lại mật khẩu, khóa hoặc mở tài khoản.
- `/admin/permissions`: cấp quyền riêng cho đặt phòng, quản lý phòng và mã mở cửa.
- Tài khoản mới mặc định không có quyền. Menu và API kiểm tra quyền theo từng chức năng.
- Thu hồi quyền áp dụng từ yêu cầu tiếp theo. Khóa tài khoản hoặc lưu thông tin tài khoản
  sẽ vô hiệu hóa phiên cũ; nhân viên cần đăng nhập lại.
- Kiểm thử quyền độc lập bằng `php tools/account-test.php` (cần PDO SQLite).

### Quyền chi tiết cho nhân viên

Trang `/admin/permissions` chia quyền theo thao tác:
- Đặt phòng: xem tổng quan/danh sách, tạo đơn, cập nhật lưu trú/hủy đơn, xác nhận thu tiền.
- Phòng: xem/xem trước, tạo, sửa thông tin/gói giá, quản lý ảnh, ẩn/hiện, xóa.
- Mã mở cửa: xem, tạo, sửa, bật/tắt, xóa, đối soát/thử lại.

Chọn một thao tác sẽ tự kèm quyền xem trang tương ứng khi lưu. Quyền quản lý ảnh
độc lập với sửa thông tin phòng. Quyền sửa thông tin không thay đổi trạng thái ẩn/hiện;
quyền sửa mã không thay đổi trạng thái bật/tắt. Tạo đơn đã thanh toán cần thêm quyền
xác nhận thu tiền. API vẫn chặn thao tác không có quyền dù gọi trực tiếp.
Các quyền tổng quát đã lưu trước đây được hiểu là đầy đủ các quyền trong nhóm;
Admin có thể bỏ bớt từng quyền rồi lưu, không cần migration mới.

Quyền `bookings.overview` (Xem tổng quan và thống kê) độc lập với
`bookings.view` (Xem danh sách đặt phòng). Chỉ có quyền tổng quan thì chỉ nhận
số liệu tổng hợp qua `/api/admin/booking-overview`, không nhận chi tiết đơn/khách.
Quyền xem danh sách không tự cấp quyền tổng quan; Admin có thể chọn cả hai.

### Lọc danh sách đặt phòng trong admin

Nút Khoảng ngày lưu trú mở lịch hai tháng, lọc các đơn có thời gian lưu trú giao
với khoảng ngày đã chọn theo giờ Việt Nam. Ngày kết thúc được tính hết ngày;
đơn trả đúng 00:00 không chiếm ngày tiếp theo. Bỏ lọc ngày để xem mọi thời gian.
Mặc định ẩn đơn đã kết thúc, đã hủy và hết hạn. Bật Hiện cả đơn đã xong hoặc
chọn trạng thái cụ thể để xem lại. Đơn đã hủy luôn được ẩn mặc định, kể cả đã thanh toán; khi xem lại vẫn được
đánh dấu cần đối soát nếu đã nhận tiền. Đơn đã kết thúc nhưng chưa thu tiền
vẫn hiển thị để đối soát. Bộ lọc chỉ thay đổi hiển thị,
không xóa dữ liệu và không thay đổi các số liệu tổng quan.

Màu nền và khung: vàng chờ thanh toán, xanh dương đã xác nhận, xanh lá đang lưu
trú, đỏ cần đối soát, xám lịch sử. Trạng thái bằng chữ luôn được giữ kèm màu.
Kiểm thử: `node tools/admin-booking-filters-test.cjs`.

### CCCD hai mặt cho khách đặt trên web

Bước Thông tin của bạn bắt buộc ảnh CCCD mặt trước và mặt sau khi tạo đơn web.
Khách xem trước và thay ảnh trước khi gửi; đơn thiếu ảnh bị từ chối ở máy chủ.
Đơn tạo trong admin qua Điện thoại hoặc Zalo không bị yêu cầu CCCD.
Đơn cũ không bị thay đổi. Trình tải chỉ kiểm tra định dạng, dung lượng và kích
thước ảnh, không OCR hoặc xác minh giấy tờ là thật hay khớp người đặt.

Chạy migration `010_booking_identity.sql`. Hai ảnh được mã hóa AES-256-GCM
trong bảng riêng, gắn với đơn trong cùng transaction. Cần `APP_KEY` hiện có
ít nhất 16 ký tự; sao lưu khóa cùng quy trình bảo mật riêng và giữ nguyên khóa
để đọc lại dữ liệu đã mã hóa. Không đổi APP_KEY tùy tiện sau khi đã có ảnh.
Ảnh không nằm trong public/uploads, không trả trong API tra cứu khách và không
gửi kèm email. Xóa đơn trong database sẽ xóa hai ảnh theo khóa ngoại.

Trong admin, nút Xem CCCD mở hai ảnh qua API yêu cầu phiên đăng nhập và quyền
`bookings.identity` (Xem ảnh CCCD của khách). Admin gốc có quyền; tài khoản chỉ
được cấp bookings.view không tự có quyền xem ảnh. Nhóm quyền bookings đầy đủ
bao gồm quyền xem CCCD. Phản hồi ảnh có no-store và nosniff.

Chấp nhận JPEG, PNG, WebP tối đa 3 MiB mỗi mặt, tối đa 24 megapixel và 8000 px
mỗi cạnh; HEIC/PDF/SVG không được nhận. Hosting cần upload_max_filesize ít
nhất 3M, post_max_size ít nhất 8M; max_allowed_packet MySQL đủ cho một ảnh.
Ảnh được giữ cùng lịch sử đơn cho đến khi xóa đơn; hiện chưa tự xóa theo hạn.
Kiểm thử: `php tools/booking-identity-test.php` dùng ảnh giả và SQLite, không
đưa giấy tờ thật vào kiểm thử hoặc nguồn Git.

### Nhận muộn với gói giờ cố định

Sau nửa đêm và trước giờ trả phòng, chọn ngày hôm nay sẽ đặt phần còn lại của
đêm đang diễn ra. Ví dụ lúc 01:00 ngày 24, gói 21:00–09:00 nhận ngay và trả
09:00 ngày 24, giữ nguyên giá gói và kiểm tra trùng lịch. Từ 09:00, ngày 24
lại ứng với đêm 24 → sáng 25. Ngày tương lai vẫn dùng giờ nhận buổi tối.

Gói FIXED_TIME vẫn cho đặt sau giờ bắt đầu nếu chưa hết giờ trả. Giờ nhận được
đẩy đến phút kế tiếp tại lúc xác nhận đặt, giữ nguyên giá và giờ trả của gói.
Kiểm tra trùng đơn và khóa phòng áp dụng từ giờ nhận thực tế đến giờ trả.
Gói DURATION vẫn yêu cầu giờ nhận trong tương lai để giữ đủ thời lượng.
Ngày gốc của gói qua đêm được giữ khi máy chủ kiểm tra lại giá và lịch trong
transaction; nhận sau nửa đêm không tự đẩy giờ trả sang thêm một ngày.

### Superadmin đổi phòng và sửa lịch lưu trú

Trong **Tổng quan & đặt phòng**, superadmin chọn **Đổi phòng / sửa lịch** ở đơn
còn hiệu lực, chọn phòng và nhập ngày/giờ nhận, trả cùng lý do. Cho phép sửa về
quá khứ theo giờ Việt Nam. Ngày tạo đơn, gói/giá đã chốt và thanh toán giữ nguyên.
Nhân viên không được gọi API này dù đã có quyền cập nhật đơn thông thường.

Thao tác dùng chung kiểm tra tồn phòng với ma trận **Tìm phòng trống**, gồm lịch
khách, 1 giờ dọn phòng, khóa phòng và giữ báo giá. Sau khi lưu, ma trận giải phóng
phòng cũ và tính lại lịch cùng thời gian dọn ở phòng mới. Lý do và dữ liệu trước/sau
được ghi trong `booking_stay_changes`; chạy migration `013_booking_stay_changes`
bằng `php tools/migrate.php` trước khi dùng tính năng.

Đơn đã xác nhận trả/phát sinh phụ thu hoặc đang khóa khẩn cấp phải xử lý nghiệp vụ
đó trước. Nếu cần đổi thời hạn của đơn đã cấp/đang đối soát mã TTLock, hệ thống
yêu cầu xử lý mã cửa trước; không tự sửa thời hạn mã đã cấp.

## Lịch theo giờ, giữ căn và trả phòng

- Màn nhân viên: `/admin/availability`. Cấp quyền **Tìm phòng trống** và từng quyền khóa khách quá giờ, giữ khung giờ báo giá, xác nhận trả phòng/QR trong trang phân quyền.
- Chi tiết phòng có ma trận 1 giờ; bấm ô xem khoảng bận chính xác. Dữ liệu công khai không chứa mã đơn, tên khách hay ghi chú nhân viên.
- Giữ báo giá khóa đúng khung giờ được chọn trong 1 giờ kể từ lúc thao tác, tự hết hạn mà không cần cron; nhân viên có quyền được mở sớm.
- Dọn phòng: chặn 1 giờ sau giờ checkout dự kiến; khi nhân viên xác nhận trả phòng, dùng giờ trả thực tế. Đặt mới, tạo đơn thủ công, nhận sớm và callback thanh toán đều kiểm tra lại trên server.
- Đến giờ checkout chỉ đánh dấu quá giờ, không coi khách đã rời phòng. Nhân viên kiểm tra, khóa khẩn cấp nếu khách còn ở, và xác nhận trả khi khách rời.
- Phụ thu: miễn 15 phút, phần còn lại làm tròn giờ lên × 50.000đ. Chốt giờ và số tiền một lần, tạo link QR payOS riêng và nhận webhook để đánh dấu đã thu. Lỗi payOS vẫn giữ khoản phải thu để thử lại.
- Chạy migration `011_room_operations` và `012_room_quote_holds` trước khi dùng. Không reset dữ liệu. Kiểm tra mới: `php tools/room-operations-test.php`, `php tools/room-operations-db-test.php`, `node tools/room-timeline-test.cjs`.
- Mã cổng mới không còn vào sớm 1 giờ. Mã TTLock tự sinh offline chỉ chính xác giờ tròn: giờ nhận lẻ được làm tròn lên để không vào sớm (10:30 → 11:00). Có gateway hoạt động thì đặt `TTLOCK_CODE_MODE=CUSTOM` để mã mới kích hoạt đúng phút; chế độ GENERATED dùng khi không có gateway; mã đã cấp trước thay đổi vẫn giữ thời hạn vật lý cũ.

Form tạo đơn admin mặc định **Theo gói lưu trú**. Khi cần, chuyển sang **Thời gian tùy chọn** và tự chọn ngày giờ (không điền sẵn). Với tùy chọn, chọn **Nhận phòng ngay bây giờ** hoặc nhập giờ nhận, chọn riêng ngày/giờ trả (kể cả nhiều ngày) và nhập giá chốt. Đơn tùy chọn không yêu cầu phòng có gói; vẫn kiểm tra sức chứa, trùng lịch, khóa căn và giờ dọn phòng trên server.

Chỉ tài khoản **Super Admin** (tài khoản gốc) được tạo đơn với ngày nhận trong quá khứ hoặc vượt giới hạn 12 tháng, ở cả chế độ theo gói và tùy chọn. Bỏ chọn **Nhận phòng ngay bây giờ** để tự chọn ngày giờ. Quyền này lấy từ phiên đăng nhập trên server, không cấp qua quyền nhân viên; vẫn kiểm tra giờ trả sau giờ nhận, trùng lịch, khóa và dọn phòng.

Super Admin có thể hủy cả đơn đang lưu trú hoặc đã kết thúc từ danh sách đặt phòng. Đơn đã hủy được loại khỏi doanh thu, lịch giữ theo đơn được giải phóng; tiền đã nhận và dữ liệu đối soát vẫn giữ nguyên. Hủy đơn không tự chuyển khoản hoàn tiền cho khách.

Khi xác nhận trả phòng, ô **Xác nhận thu phụ thu và tạo mã chuyển khoản** mặc định không tích. Không tích thì chỉ ghi giờ trả và không tạo khoản phụ thu/QR; tích mới chốt phụ thu theo quy tắc và tạo link QR. Quyết định được giữ nguyên khi gửi lại yêu cầu.

Khoản phụ thu chưa thu được đánh dấu đỏ trong màn Tìm phòng trống. Super Admin có nút **Hủy phụ thu** ngay trên thẻ: hủy link payOS đã tạo trước khi chốt hủy, giữ nguyên số tiền cũ/người hủy/thời điểm để đối soát. Khoản đã thanh toán không được hủy bằng nút này. Cần migration `013_surcharge_cancellation`.

## Email thông báo nội bộ

Sau migration `014_staff_email_notifications`, hệ thống gửi tới **mohomestay01@gmail.com** khi đơn đặt phòng được xác nhận (online đã thanh toán hoặc đơn nhân viên tạo), khi xác nhận khách trả phòng và khi xác nhận thu phụ thu. Không gửi thông báo cho đơn test hay đơn online mới giữ 15 phút chưa thanh toán. Email chứa mã đơn, phòng, tên/điện thoại khách, giờ lưu trú và số tiền; không chứa PIN, token hoặc ảnh CCCD.

Dùng cấu hình `MAIL_FROM` và mail server hiện có của hosting. Sự kiện được lưu cùng giao dịch đặt/trả phòng, gửi sau khi lưu thành công; mỗi đơn/mỗi loại thông báo có một bản ghi. Thư bị từ chối được thử lại tối đa 5 lần, cách nhau ít nhất 15 phút. Trường hợp tiến trình dừng đúng sau khi mail server nhận thư vẫn có thể gửi lặp khi phục hồi; Message-ID ổn định hỗ trợ đối soát.

Đặt cron mỗi phút (độc lập TTLock):
```sh
php /duong-dan/domain/src/staff-notifications-worker.php
```
Kiểm thử không gửi mail thật: `php tools/staff-notifications-test.php`. PHP mail trả thành công chỉ xác nhận mail server nhận xử lý, cần kiểm tra hộp thư/spam để xác minh giao thư thực tế.

Khi chưa nhận được thư, chạy `php src/staff-notifications-worker.php --check` (hoặc thêm `--check` vào cron tạm thời) để kiểm tra cấu hình, DB, số thư theo trạng thái và 5 sự kiện mới nhất; lệnh không gửi mail và không in dữ liệu khách. `SENT` là mail server đã nhận xử lý, chưa đảm bảo Gmail đã nhận; `FAILED` là gửi lỗi; không có bản ghi thì kiểm tra migration 014, code đã deploy và sự kiện xác nhận sau deploy.
