# Đếm lượt vào / ra Mơ Home

**Tương thích hosting MariaDB 10.3.16:** migration 026 dùng
`VALUES ... ON DUPLICATE KEY UPDATE id = id` để tạo camera mặc định.
Câu `INSERT ... SELECT ... WHERE NOT EXISTS` không có `FROM` trước đây báo
SQL 1064 trên phiên bản này, dù qua được MariaDB 10.4/MySQL 8.4 local.
Nếu deploy cũ đã tạo hai bảng nhưng schema vẫn ở 025, push bản sửa để Cron
chạy lại 026/027; không xóa bảng hoặc tự đánh dấu migration đã hoàn tất.
CI chạy `tools/people-counter-migration-test.php` với MariaDB 10.3.16 riêng,
kiểm tra tạo mới, chạy lại và giữ nguyên cấu hình/lượt cũ. Script test từ chối
DSN ngoài database thử nghiệm `counter_migration_test` trên loopback.

**Cập nhật ghi lịch sử 28/10/2026:** SDK pilot có `--record-trial` để lưu
các lượt mới vào journal SQLite và gửi có chữ ký vào bảng thử nghiệm riêng
`people_counter_trial_events` (migration 027). Trang trực tiếp mặc định xem
“Lượt AI ghi nhận · chưa đối soát”; có thể chuyển sang dữ liệu vận hành.
Lịch sử/biểu đồ làm mới 5 giây, số phiên vẫn giữ cả các tổng cũ không có thời
điểm. Không dựng lại thời gian cho 17 lượt trước khi bật ghi lịch sử.
Chi tiết cấu hình và giới hạn ở [nguồn native](PEOPLE_COUNTER_NATIVE.md).
Các mô tả “không gửi event” bên dưới nói về lần thử trước khi bật tùy chọn này.

**Giao diện hiện tại:** giữ cả tab Trực tiếp và tab Video đã ghi theo yêu cầu
mới nhất. SDK/AI trực tiếp đã bật lại trên máy hiện tại; chưa deploy VPS.
Tab offline đọc báo cáo đã xuất mà không gọi stream/API camera hoặc gửi dữ
liệu lên server. Dùng [hướng dẫn offline](PEOPLE_COUNTER_OFFLINE.md) khi muốn
đếm theo ca/ngày mà không giữ máy bật liên tục cho phần xử lý đó.

## Phạm vi và tiêu chí nghiệm thu

Đếm mỗi lần một người đi qua đoạn vạch tại cửa, tách VÀO và RA. Một người đi
ba lần được tính ba lượt. Không nhận diện khuôn mặt, không suy ra số khách duy
nhất, số người còn trong home hoặc gắn lượt qua cửa vào đơn đặt phòng.

Camera lễ tân Imou Bullet 2C Pro được chia sẻ quyền xem. Đã kiểm thử nhận video
qua OpenSDK và tách được nguồn chạy nền, nhưng chưa triển khai VPS hoặc xác
nhận độ chính xác bằng lượt người thật. Người dùng đã chọn hướng offline.

Kiến trúc: dịch vụ Python trên máy chủ riêng đọc video → phát hiện người và
theo dõi bằng YOLO/ByteTrack → đếm qua vạch → hàng đợi SQLite bền vững → API
PHP xác thực HMAC → MySQL → báo cáo quản trị. Hosting PHP không chạy AI.

- Camera đầu tiên có mã `entrance`, tên Lễ tân / cửa chính.
- API chỉ nhận camera đã đăng ký, mỗi lượt có ID chống cộng trùng khi gửi lại.
- Lưu giờ UTC, lọc và trình bày ngày theo Asia/Ho_Chi_Minh.
- Báo cáo có tổng vào/ra, số liệu từng giờ, lịch sử phân trang, tình trạng kết nối.
- Chỉ Super Admin được xem module, camera và API báo cáo. Quyền
  `people-counter.view` không thể cấp cho nhân viên; quyền cũ đã lưu cũng bị chặn.
- Không có dữ liệu / lỗi API / chưa migration phải hiển thị rõ, không giả số liệu.
- Trang quản trị xem khung hình đã có khung người, vạch đếm và nhãn IN/OUT,
  cập nhật khoảng 1 lần/giây. Chỉ giữ khung JPEG gần nhất trong `storage/people-counter/`
  ngoài public, thay thế mỗi lần nhận, không lưu lịch sử video/âm thanh.
  Không lưu khuôn mặt nhận dạng, URL camera hoặc mật khẩu trên website.
- Chỉ một worker cho mỗi camera. Reset theo dõi sau mất video; không nối quỹ đạo
  trước và sau khoảng mất kết nối. Không tự phát lại video cũ như video trực tiếp.

## Kế hoạch triển khai

1. Migration, nhận lượt có xác thực và báo cáo; kiểm thử dữ liệu sai, retry,
   rollback và ranh giới ngày Việt Nam.
2. Dịch vụ AI độc lập; kiểm thử vào/ra, đi lại nhiều lần, rung quanh vạch,
   ngoài đoạn vạch, mất theo dõi, hàng đợi và ký request.
3. Giao diện Tailwind và phân quyền; build CSS, kiểm tra desktop/mobile.
4. Hướng dẫn chạy, cấu hình mẫu và toàn bộ kiểm tra bắt buộc của repository.

## Quy ước và kiểm tra

PHP/JS/Python dùng 4 spaces, JSON 2 spaces, UTF-8/LF. Hàm PHP có tiền tố
`people_counter_`; SQL dùng prepared statements. Ví dụ:

```php
function people_counter_utc(DateTimeImmutable $time): string
{
    return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
```

```sh
php tools/people-counter-test.php
python -m unittest discover -s services/people-counter/tests -v
node --check public/assets/people-counter.js
npm run build:css
php tools/check.php
php tools/ui-test.php
php tools/test.php
php tools/room-test.php
php tools/upload-test.php
git diff --check
```

Source PHP trong `src/`, migration trong `database/migrations/`, worker và
unit tests trong `services/people-counter/`; integration test PHP trong `tools/`.
Test dùng SQLite và quỹ đạo giả, không gửi video hoặc dữ liệu khách thật.

Luôn xác thực và kiểm thử trước hoàn tất. Chỉ triển khai production, cấp phí
cloud hoặc kết nối camera thật khi có quyền/cấu hình tương ứng. Không commit
secret, sửa `.env` thật, tự push/deploy hoặc giả định chia sẻ app là quyền API.

## Bật phần website

1. Triển khai mã PHP và CSS đã build theo quy trình hiện có. Chạy
   `php tools/migrate.php` trên môi trường đích để tạo migration 026 (không reset DB).
2. Tạo khóa riêng bằng `php -r "echo bin2hex(random_bytes(32));"` ở terminal riêng.
   Lưu cùng giá trị `PEOPLE_COUNTER_KEY` vào `.env` website và môi trường worker.
   Không gửi khóa vào chat, URL hoặc JavaScript trình duyệt.
3. Admin truy cập `/admin/people-counter`. Với nhân viên, cấp quyền
   **Lượt vào / ra → Xem lượt vào / ra và tình trạng camera**.
4. Nếu chưa có worker, màn hình hiển thị **Chưa kết nối camera**. Nếu migration
   hoặc DB lỗi, trang báo lỗi thay vì hiển thị số 0 như dữ liệu hợp lệ.

## Máy chủ AI riêng (Linux, Python 3.11/3.12)

Chỉ cài thư mục `services/people-counter/` trên máy chủ AI; thư mục này không
thuộc gói deploy DirectAdmin. Cấu hình mẫu của vạch chỉ là ví dụ, bắt buộc hiệu
chỉnh với video thực tế trước khi dùng kết quả vận hành.
Mẫu đặt `calibrated: false`; chỉ chuyển thành `true` sau khi đã thử đúng vạch và
hướng bằng video. Worker từ chối chạy live khi chưa xác nhận hiệu chỉnh này.

```sh
cd /opt/mo-people-counter
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
cp config.example.json config.json
mkdir -p data
.venv/bin/python -c "from ultralytics import YOLO; YOLO('yolo11n.pt')"
```

OpenCV cần thư viện hệ thống theo bản Linux, thường là `libgl1` và `libglib2.0-0`.
Tải trọng số `yolo11n.pt` từ nguồn Ultralytics tin cậy, đặt tại thư mục worker hoặc
chỉnh `model` thành đường dẫn đầy đủ. Lần chạy đầu Ultralytics có thể tải trọng số
và dependency tracker khi còn thiếu; `lap` đã được khai báo trong requirements.
Chuẩn bị trước và kiểm tra môi trường
trước khi chạy dưới systemd. Không dùng file trọng số không rõ nguồn.

Thiết lập ba biến môi trường trong file riêng `/etc/mo-people-counter.env`, quyền
`0600`, theo mẫu dưới đây (thay giá trị trên máy chủ, không commit):

```ini
COUNTER_STREAM_URL=rtsp://AUTHORIZED_CAMERA_STREAM
COUNTER_ENDPOINT=https://YOUR_HOME_DOMAIN/api/integration/people-counter
PEOPLE_COUNTER_KEY=YOUR_SEPARATE_RANDOM_KEY
```

`COUNTER_STREAM_URL` chấp nhận RTSP/RTSPS/RTMP/RTMPS/HTTPS live mà máy chủ truy cập được.
Không mở camera công khai để lấy URL. Với Imou cloud, chủ thiết bị cần cấp quyền
và xác minh khả năng lấy luồng, giới hạn và phí. Worker hiện dùng URL đã cấp;
**chưa có adapter đăng nhập Imou hoặc tự gia hạn URL cloud hết hạn**. Khi URL
đổi, cập nhật file môi trường và khởi động lại worker. Không tự kết nối chỉ bằng
số serial hoặc quyền xem chia sẻ trong app.

```ini
# /etc/systemd/system/mo-people-counter.service
[Unit]
Description=Mo Home people counter
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=mo-counter
WorkingDirectory=/opt/mo-people-counter
EnvironmentFile=/etc/mo-people-counter.env
ExecStart=/opt/mo-people-counter/.venv/bin/python worker.py --config config.json
Restart=on-failure
RestartSec=10
TimeoutStopSec=45
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/opt/mo-people-counter/data
Environment=YOLO_CONFIG_DIR=/opt/mo-people-counter/data/ultralytics

[Install]
WantedBy=multi-user.target
```

Tạo user dịch vụ `mo-counter` và cấp quyền đọc source/trọng số, quyền ghi `data/`
trước khi bật service. Chạy duy nhất một worker/camera. File lock ngăn hai tiến
trình cùng thư mục state, nhưng không ngăn hai máy chủ riêng chạy cùng camera.

## Hiệu chỉnh bằng video đã được phép sử dụng

```sh
.venv/bin/python worker.py --config config.json --video door-test.mp4 --preview data/preview.mp4
```

Chế độ video local cũng chạy được trên Windows với Python và các dependency
tương ứng; chế độ dịch vụ live và khóa tiến trình dành cho Linux.

Chế độ này chỉ in tổng và tùy chọn tạo video có khung theo dõi, vạch, tổng IN/OUT;
không gửi dữ liệu vào website. Không commit video thực. `--preview` chỉ được dùng
với `--video`. Live mặc định gửi JPEG đã vẽ khung để xem trên trang quản trị;
đặt `send_preview: false` nếu chỉ cần gửi số lượt.

- `line_start`, `line_end`: tọa độ x/y từ 0 đến 1 trên toàn khung hình. Chỉ đếm
  giao với đoạn vạch hữu hạn, không đếm phần đường kéo dài ngoài cửa.
- `inside_side`: `1` là phía có tích có hướng dương so với mũi tên từ start đến
  end; với vạch ngang trái → phải, phía dưới ảnh là phía trong. Đặt `-1` để đảo.
- Dùng điểm giữa cạnh dưới khung người (vị trí chân), xác nhận qua 2 khung hình,
  có vùng đệm `margin` tránh rung tại vạch. Có thể cần điều chỉnh khi che khuất.
- Thử đi vào/ra một mình, nhiều người cùng lúc, đứng ở cửa, quay đầu, ngày/đêm;
  so sánh với lượt đếm thủ công trước khi nghiệm thu. Mất ID có thể đếm thiếu
  hoặc thừa; không cam kết độ chính xác khi chưa chạy trên camera thật.
- Thời điểm lượt live là lúc worker nhận khung hình, chưa phải timestamp gốc
  camera. HLS/cloud có độ trễ cần đo và chấp nhận khi đối soát.
- Nếu inference chậm, worker bỏ khung cũ để giữ độ trễ thấp. Khách đi nhanh có
  thể bị bỏ sót; phải đo năng lực máy chủ với video thực và điều chỉnh model/GPU.

## Hợp đồng API và khôi phục

`POST /api/integration/people-counter`, JSON UTF-8 tối đa 256 KiB:

```json
{
  "cameraId": "entrance",
  "status": "RUNNING",
  "frameAt": "2026-10-27T12:00:00Z",
  "events": [
    {"id": "0123456789abcdef0123456789abcdef", "direction": "IN", "occurredAt": "2026-10-27T12:00:00Z"}
  ]
}
```

Header `X-Counter-Timestamp` là Unix seconds. `X-Counter-Signature` là HMAC-SHA256
hex của `timestamp + '.' + raw_body`, dùng khóa riêng. Cho phép lệch 300 giây;
máy chủ cần đồng bộ giờ. ID là 32 ký tự hex lowercase, mỗi lượt một UUID mới.
Tối đa 100 lượt/batch, UTC đến độ chính xác giây, không quá 31 ngày. Batch cùng
ID/nội dung được chấp nhận nhưng không cộng lại; ID khác nội dung trả 409 và
rollback toàn bộ batch. Chỉ nhận camera đã đăng ký và enabled.

HTTP 200 trả `accepted` (số lượt nhận, gồm retry) và `inserted` (số lượt mới).
Worker chỉ xóa các ID trong hàng đợi sau khi nhận đúng xác nhận. Mất mạng,
timeout, HTTP lỗi hoặc response không hợp lệ đều giữ hàng đợi. Hàng đợi giới hạn
100.000 lượt; đầy thì worker dừng, giữ dữ liệu và cần vận hành xử lý. Không tự
xóa sự kiện cũ: nếu gián đoạn quá 31 ngày, cần sao lưu/đối soát hàng đợi trước
khi xử lý các lượt quá hạn đang làm batch bị từ chối. HTTP 401: kiểm tra khóa/giờ;
409: xung đột ID; 422: payload/camera/thời gian; 503: DB hoặc migration.

Có thể kèm trường `preview: {frameAt, jpeg}`: JPEG base64 tối đa 180.000 ký tự,
tối đa 1280 px/cạnh, thời điểm trong 30 giây gần nhất. Worker gửi ảnh tối đa 960 px,
JPEG quality 65 khoảng 1 lần/giây; việc gửi ảnh không thay đổi thuật toán đếm.
Endpoint `/api/admin/people-counter/preview?cameraId=entrance` yêu cầu phiên đăng
nhập và quyền `people-counter.view`. Không có đường dẫn public cho ảnh. Sau 30
giây thiếu khung mới, API/UI ẩn ảnh và báo gián đoạn; file JPEG mới nhất vẫn nằm
trong lưu trữ riêng cho đến lần ghi thay thế hoặc xóa theo quy trình vận hành.
Ảnh snapshot khoảng 1 fps dùng quan sát AI, chưa phải luồng video full-frame-rate
hoặc âm thanh. URL cloud Imou hết hạn vẫn cần xử lý ở nguồn.

Heartbeat khoảng 10 giây khi rảnh; quá 90 giây không nhận tín hiệu/khung mới thì
UI báo gián đoạn. Video mất làm reset tracking, không suy diễn số lượt bị mất.
Tình trạng hiện tại không chứng minh đủ video cho toàn khoảng báo cáo. Phiên bản
này chưa lưu biểu đồ uptime hay tự bù video mất. Tổng theo giờ gộp các ngày trong
bộ lọc (tối đa 31 ngày), lịch sử 50 lượt/trang. Dữ liệu sự kiện được giữ cho đến
khi có chính sách lưu trữ/xóa riêng; chưa có tác vụ tự xóa.

## Nguồn kỹ thuật và điều kiện sử dụng

### Kiểm tra camera chia sẻ bằng OpenAPI, chưa cần Safety Code

`tools/imou-check.php` lấy token từ App ID/App Secret và gọi API chỉ đọc
`listDeviceDetailsByPage` với `source=bindAndShare`. Không thêm/xóa/đổi quyền
camera, không dùng mật khẩu tài khoản Imou, không in token, playToken hoặc mật
khẩu thiết bị. Việc này chỉ xác minh camera có xuất hiện dưới quyền ứng dụng;
chưa chứng minh có quyền lấy luồng video, và không tự vượt quyền chia sẻ.

Nhập trực tiếp ba giá trị vào `.env` riêng, không gửi App Secret vào chat:

```ini
IMOU_APP_ID=YOUR_APP_ID
IMOU_APP_SECRET=YOUR_PRIVATE_APP_SECRET
IMOU_API_HOST=https://openapi-sg.easy4ip.com
```

Host trên là ví dụ cho **East Asia**. Chọn đúng vùng trong Imou Cloud → thông tin
tài khoản (My Information): `sg` East Asia, `fk` Central Europe, `or` Western America.
Không đoán vùng theo vị trí home hoặc tự gửi khóa thử nhiều máy chủ.

```sh
php tools/imou-check.php --check
php tools/imou-check.php
php tools/imou-check.php --live-check=DEVICE_ID
php tools/imou-test.php
```

`--check` chỉ kiểm tra cấu hình, không gọi Imou. Chạy không cờ sẽ tạo một token
trong bộ nhớ rồi phân trang tối đa 500 camera. Không chạy lặp liên tục; đây là
công cụ chẩn đoán thủ công, chưa phải adapter gia hạn video cho worker.
Danh sách rỗng là kết quả cần báo rõ, không mặc định rằng quyền xem app tương
đương quyền API. Công cụ không thể hoàn tất khi chưa có khóa/vùng được cấu hình.

`--live-check=DEVICE_ID` kiểm tra riêng HLS (`getLiveStreamInfo`), RTMP
(`queryDeviceRtmpLive`) và quyền xem bằng ImouPlayer (`getKitToken`, chỉ type 1).
Kết quả chỉ có tên API và mã lỗi, không có URL hoặc token. Không tạo luồng,
không thay đổi quyền hoặc liên kết camera. Exit 2 nếu một trong ba API từ chối;
mã 0 chỉ chứng minh API nhận yêu cầu, chưa chứng minh phát được video.
`OP1009` là không đủ quyền thao tác; không thể coi việc liệt kê được camera
chia sẻ là đã có quyền cung cấp video cho worker. Không tự bật/mua dịch vụ để
né lỗi này. Cần kiểm tra điều kiện quyền hoặc dịch vụ của Open Platform.

[Quy cách ký request và vùng API](https://open.imoulife.com/book/http/develop.html),
[lấy token](https://open.imoulife.com/book/http/accessToken.html),
[danh sách camera có chia sẻ](https://open.imoulife.com/book/http/device/manage/query/listDeviceDetailsByPage.html).

- [Ultralytics tracking](https://docs.ultralytics.com/modes/track/): person class 0,
  ByteTrack, `persist=True` chỉ cho chuỗi khung hình liên tục.
- [Ultralytics licensing](https://www.ultralytics.com/license): kiểm tra nghĩa vụ
  AGPL-3.0 hoặc giấy phép Enterprise phù hợp trước khi đưa phần YOLO vào hệ thống
  thương mại đóng mã nguồn. Đây là dependency tùy chọn trên máy chủ AI.
- [OpenCV video I/O](https://docs.opencv.org/4.x/d4/d15/group__videoio__flags__base.html):
  timeout mở/đọc video qua FFmpeg.
- [Imou Cloud Live](https://open.imoulife.com/book/readme/liveguide/01APIUseForStreaming.html):
  phụ thuộc model, quyền tài khoản và dịch vụ đăng ký; không phải bằng chứng đã
  kết nối được camera lễ tân.

## Kiểm thử trình duyệt local

`tools/people-counter-browser-test.cjs` dùng Playwright/Chrome và dữ liệu báo cáo
giả lập, không cần camera hoặc ghi dữ liệu nghiệp vụ. Khởi động PHP local ở cổng
8093 với các biến môi trường **chỉ cho process test**:
`ADMIN_EMAIL=counter-test@example.test`, `ADMIN_PASSWORD_HASH` là kết quả
`password_hash('counter-browser-test-only', PASSWORD_DEFAULT)`. Không lưu tài
khoản này vào `.env` hoặc production. Chạy:

```sh
php -S 127.0.0.1:8093 -t public public/index.php
# Ở terminal khác có Playwright trong Node module path:
node tools/people-counter-browser-test.cjs
```

Test xác minh layout quản trị, desktop/mobile, menu bàn phím/điện thoại, chờ kết
nối, có lượt, mất kết nối, lỗi API, phân trang, giờ Việt Nam, API ẩn với người
chưa đăng nhập và API ingest không tạo session. Ảnh kiểm tra nằm trong `.deploy/`,
không nằm trong gói deploy hay Git.

Trang bộ đếm dùng bố cục responsive theo viewport: từ 1536 px đặt số liệu cạnh
camera; laptop/tablet đặt số liệu bên dưới; mobile chia hai ô vào/ra và một ô
tổng rộng cả hàng. Video giữ `object-contain`, giới hạn chiều cao theo viewport
để không cắt người hoặc vạch đếm. Mobile ẩn cột tên camera lặp lại trong bảng
lịch sử (camera vẫn được chọn ở bộ lọc), nút và bộ lọc có chiều cao tối thiểu
44 px. Các trang quản trị khác không bị đổi bố cục bởi thay đổi này.

Chrome đã được kiểm tra với dữ liệu giả lập ở 1920×1080 (màn hình FHD thường
dùng cho 24 inch), 2560×1440, laptop 1440×900/1366×768, tablet
1024×768/768×1024, mobile 390×844/320×720, màn hình ngang 844×390 và
viewport thu phóng 960×540. Không coi số inch là breakpoint CSS. Ảnh chụp nằm
trong `.deploy/counter-ui/`, dữ liệu 52/12 trong ảnh chỉ là fixture kiểm thử.

## Kết quả xác minh local

### Thử nghiệm lấy hình từ Imou PC trên Windows

`services/people-counter/window_pilot.py` đọc vùng video trong **một cửa sổ
Imou PC đang phát**, bằng Windows Graphics Capture. Không cần API Cloud Live,
Safety Code, thay đổi quyền chia sẻ hoặc máy tính đặt tại home. Máy chạy thử
vẫn phải duy trì app và phiên desktop hoạt động; đây chưa phải dịch vụ 24/7.
Đã dùng kỹ năng computer-use để xác định đúng cửa sổ, kích thước và vùng video
trước khi chạy. Cần xác định lại các giá trị khi thay đổi màn hình hoặc layout.

```powershell
python -m pip install --only-binary=:all: -r services/people-counter/requirements-windows.txt
python services/people-counter/window_pilot.py `
    --config .deploy/window-pilot/config.json `
    --window-hwnd WINDOW_HANDLE --window-size 1920 1080 `
    --roi 0.171 0.10 0.998 0.931 --seconds 120
```

Config dùng cấu trúc `config.example.json`, đường dẫn `model` trỏ tới file trọng
số đã có trên máy. Không tải trọng số tự động. Các giá trị ROI trên chỉ là ví dụ
cho layout đã kiểm tra, không áp dụng cho mọi máy. Nguồn phải đúng process
`Imou_en.exe`. Khi cửa sổ thu nhỏ hoặc đổi kích thước, cầu nối tạm chờ, không
đếm từ ảnh cũ. Mở lại cửa sổ với kích thước đã chọn sẽ tiếp tục từ frame mới và
reset tracker để không cộng lượt qua khoảng gián đoạn. Đóng hẳn Imou kết thúc
process; mở lại app cần khởi động lại cầu nối với window handle mới.
Chỉ giữ frame mới nhất, reset tracker khi gián đoạn, bỏ frame quá cũ hoặc có
pixel không thay đổi hơn 10 giây. Kiểm tra pixel không bảo đảm phát hiện mọi kiểu
mất tín hiệu (ví dụ thông báo reconnect có animation); cần kiểm tra độ ổn định
trước khi triển khai dài hạn.

Mặc định chạy hữu hạn 120 giây, tối đa 3600 giây. Dùng `--seconds 0` để chạy
liên tục tới khi đóng Imou, dừng process hoặc tạo file
`.deploy/window-pilot/stop.request`. Xóa file yêu cầu dừng này trước khi chạy lại.
Có khóa process để tránh hai cầu nối cùng ghi preview. Không cài tự khởi động
Windows và không khẳng định độ ổn định 24/7 chỉ từ chế độ liên tục.
Xuất `latest.jpg`,
`detection.jpg` khi thấy người, `crossing.jpg` khi có lượt thử, `preview.json`
và `summary.json` vào `.deploy/window-pilot/` riêng tư. Không ghi video dài hạn,
không gửi event hoặc cập nhật bảng thống kê. Vạch/hướng chỉ để hiệu chỉnh; kết
quả luôn có nhãn `PILOT / TEST LINE / NO OPERATIONAL EVENTS`.

Cờ `--publish-local-preview` cập nhật riêng file `storage/people-counter/entrance.json`
cho trang xem camera local hiện có (vẫn yêu cầu đăng nhập quản trị), không gửi
ảnh ra hosting hay Internet. Ảnh hết hạn sau 30 giây nếu thử nghiệm dừng.
File heartbeat `entrance.source.json` cập nhật mỗi giây để UI phân biệt đang
khởi động, thu nhỏ, thay đổi bố cục, đứng hình và cầu nối dừng. Heartbeat quá
15 giây sẽ báo cầu nối PC mất tín hiệu, không kết luận camera mất mạng.
Frame mới từ worker ingest có xác thực sẽ thay thế trạng thái cầu nối thử cũ.
Không cộng lượt thử vào báo cáo vận hành. Khi máy chủ PHP chạy ở nơi khác,
file local này không tự truyền đến máy chủ đó.

Nguồn tham khảo: [Windows Graphics Capture](https://learn.microsoft.com/en-us/windows/uwp/audio-video-camera/screen-capture),
[thư viện windows-capture](https://github.com/NiiightmareXD/windows-capture/tree/main/windows-capture-python).

Ngày 28/10/2026, lần chạy đầu 30 giây nhận 248 frame từ camera lễ tân thật,
nhận diện tối đa 1 người và ghi nhận 1 lượt IN thử nghiệm. Chưa đánh giá độ chính
xác, chưa xác minh đủ cả hướng ra và vào, chưa chạy ổn định 24/7.
Đã xác minh thêm trên Chrome desktop/mobile: API preview có đăng nhập trả ảnh
camera thật, ảnh tiếp tục đổi theo thời gian, không có lỗi JavaScript. Ảnh chụp
kiểm tra nằm tại `.deploy/window-pilot/web-camera.png` và `web-camera-mobile.png`.
Đã bổ sung test logic tạm chờ/tiếp tục, heartbeat hết hạn và UI các trạng thái
nguồn PC. Thử tự động thu nhỏ cửa sổ qua computer-use chưa thao tác được nút
thu nhỏ nên chưa xác minh chu kỳ minimize/restore thật; không coi test giả lập
là bằng chứng phục hồi Windows đã hoàn tất.
Đã khởi động đúng MySQL của XAMPP sau khi phát hiện cổng 3306 chưa có dịch vụ;
`db()` kết nối đúng database local và các bảng bộ đếm sẵn có. MySQL94 giữ dừng.

- Kiểm thử PHP xác thực HMAC, chống cộng trùng, rollback batch, ngày Việt Nam,
  phân trang, camera tắt/mất kết nối và quyền nhân viên.
- 11 kiểm thử Python cho hướng qua vạch, chống rung, mất dấu, giữ khung mới
  nhất, hàng đợi bền vững và chỉ xác nhận gửi khi server trả đúng receipt.
- Chrome desktop/mobile kiểm tra khung quản trị, menu, biểu đồ, phân trang,
  trạng thái chờ/đang chạy/gián đoạn/lỗi và lỗi JavaScript.
- YOLO11n/ByteTrack thật chạy video tổng hợp chuyển động từ ảnh mẫu Ultralytics:
  ghi nhận 3 lượt IN, 3 lượt OUT; xuất MP4 có khung theo dõi và vạch. Công cụ
  tái lập: `tools/people-counter-smoke.py --image PATH --weights PATH` bằng venv AI.
  Đây là smoke test trên ảnh mẫu, chưa đánh giá độ chính xác của camera home.
- Đã áp dụng migration 026 trên DB local và kiểm tra API HTTP 200, trạng thái
  WAITING bằng MySQL thật. Ngày 28/10/2026 đã xác minh API Imou thật thấy 10
  camera chia sẻ online. Riêng camera lễ tân, cả `getLiveStreamInfo`,
  `queryDeviceRtmpLive` và `getKitToken` đều trả `OP1009`; chưa nhận được video.
  Chưa bật worker trên máy chủ 24/7 và chưa deploy production.

### Thử nghiệm nguồn Imou OpenSDK trực tiếp, ngày 28/10/2026

Đã build demo C++/Qt của Imou bằng Build Tools 2026 và Qt 5.12.10 MSVC2017
64-bit. Dùng đúng host East Asia `openapi-sg.easy4ip.com:443`, demo phát được
camera lễ tân qua P2P. Đây là một đường SDK khác với ba API HTTP trả OP1009
ở trên; không còn kết luận rằng chưa nhận được video bằng mọi cách.

Bản demo thử nghiệm `LCOpenSDKDemo-sdk-bridge.exe` đăng ký
`LCOpenSDK_VideoPlay::setStreamCallback(OUTPUT_STREAM_FORMAT_TS)` và chuyển
MPEG-TS qua UDP chỉ tới `127.0.0.1:19454`. Không chụp cửa sổ hoặc màn hình.
Mã và binary vendor được giữ trong `.deploy/imou-demo`, chưa đóng gói thành
dịch vụ production hoặc bộ build có thể tái lập từ repository.

Kiểm tra thật nhận 604.984 byte trong 631 datagram, giải mã 150 frame ở
2304 × 1296. Frame đầu khi bắt luồng giữa chừng có lỗi H.265; các frame sau
đã kiểm tra bằng mắt thấy rõ camera. Không dùng việc decoder trả `true`
cho frame đầu để kết luận ảnh hợp lệ.

`services/people-counter/sdk_pilot.py` đọc nguồn UDP cố định trên, chạy YOLO
và ByteTrack, dùng lại logic qua vạch và ghi preview riêng tư cho PHP local.
Lần chạy 35 giây xử lý 88 frame, lượt thử IN/OUT là 0/0, không gửi sự kiện
vận hành. Đây chưa phải phép đo độ chính xác: cần đối chiếu lượt qua cửa thật,
kiểm tra vật treo bị nhận nhầm và hiệu chỉnh vạch trước khi dùng số liệu.

Ví dụ chạy từ thư mục repository, sau khi demo đang phát camera:

```powershell
.deploy\counter-venv\Scripts\python.exe services\people-counter\sdk_pilot.py --config .deploy/sdk-pilot/config.json --seconds 90
```

Config cần weights local và các trường hình học như thử nghiệm Windows ở trên.
`--seconds 0` giữ thử nghiệm chạy liên tục trên máy hiện tại. Tạo file
`.deploy/sdk-pilot/stop.request` để dừng; bỏ marker này trước khi chạy lại.
SDK pilot và window pilot dùng chung khóa để tránh ghi đè preview lẫn nhau.
Chỉ chạy một nguồn; dừng window pilot bằng marker của nó trước khi chuyển sang
SDK. Không cần mở Imou PC để cấp hình cho đường SDK này, nhưng vẫn phải giữ
demo SDK và tiến trình Python chạy. Chưa xác minh đóng/thu nhỏ demo, tự khởi
động lại, chạy không màn hình hoặc vận hành 24/7 trên máy chủ ngoài home.

Đã gặp ảnh xám một phần và video chậm khoảng 16 giây ở đường SDK UDP. Log có
lỗi thiếu khung tham chiếu H.265. SDK pilot hiện đọc luồng đẩy liên tục, không
áp dụng nhịp đọc theo FPS như nguồn HLS; tăng receive buffer lên 4 MiB và FIFO
16384 packet, giới hạn 2 luồng giải mã, bật `discardcorrupt`/`err_detect=explode`.
Kiểm thử hồi quy xác minh burst frame được đọc ngay dù FPS khai báo thấp,
trong khi hành vi mặc định cho worker HLS giữ nguyên. Sau sửa đã chạy thử
50 giây và quan sát ảnh đầy đủ, thời gian camera gần thời gian máy hơn
(khoảng 4 giây tại mẫu kiểm tra). Đây là kiểm chứng ngắn, chưa bảo đảm mọi
khung đều nguyên vẹn hoặc độ trễ ổn định dài hạn với UDP. Cần tiếp tục theo
dõi trước khi chuyển sang số liệu vận hành; thử nghiệm vẫn không gửi event.

Để loại nhận nhầm vật treo ở hành lang sau, SDK pilot hỗ trợ `detection_roi`
là `[left, top, right, bottom]` chuẩn hóa theo toàn ảnh. Cấu hình camera lễ tân
hiện dùng `[0, 0, 0.65, 1]`: chỉ phần bên trái chứa cửa chính được đưa vào YOLO;
preview vẫn giữ toàn ảnh. Không chỉ giấu box sau khi suy luận. Vạch đếm phải
nằm trong ROI, nếu không tiến trình từ chối cấu hình. Box được quy đổi lại
tọa độ toàn ảnh, bảo toàn ID/confidence và vị trí chân; không sửa dữ liệu
tọa độ nội bộ của tracker. Không cấu hình ROI thì giữ hành vi toàn ảnh.

SDK pilot thu nhỏ ảnh làm việc về tối đa 1280 px trước khi xử lý và camera
thử nghiệm dùng `image_size: 512`. Đã kiểm tra ảnh trực tiếp không còn box tại
vật treo ngoài vùng, log ghi 0 người trong cảnh trống. Có log `inferenceMs`
trung bình tối đa 60 khung gần nhất để đo tải. Đây không phải bảo đảm loại
mọi nhận nhầm: cần thử người thật đi vào/ra, đứng gần biên ROI và đi theo nhóm
trước khi xác nhận độ chính xác. Chỉ SDK pilot áp dụng ROI này; worker khác
không tự nhận cùng cấu hình ROI.

Ảnh xem SDK hiện xuất tối đa 1280 × 720 từ ảnh làm việc, bắt đầu ở JPEG quality
85 thay cho preview 960 px/quality 65 trước đó. Kích thước suy luận ROI vẫn là
512, không tăng theo ảnh xem. Encoder chỉ giảm chất lượng hoặc độ phân giải
khi cần để giữ JPEG ≤130.000 byte và base64 trong giới hạn PHP. Test bao gồm
ảnh nhiều nhiễu, giữ ảnh nguồn nguyên vẹn và bảo toàn kích thước 1280 khi vừa
ngân sách. Ảnh camera thật sau thay đổi đã đo 1280 × 720, 126.831 byte JPEG.
Heartbeat SDK bỏ qua lỗi khóa đọc file tạm thời trên Windows và ghi lại ở
khung sau, thay vì dừng toàn bộ producer; heartbeat cũ vẫn hết hạn bình thường.

Bước tách nguồn SDK khỏi demo đã có chương trình chạy ẩn riêng và AI đã chuyển
sang cổng riêng 19456. Xem [hướng dẫn nguồn native](PEOPLE_COUNTER_NATIVE.md)
để build, chạy/dừng, bằng chứng kiểm thử và các phần chưa triển khai trên VPS.
