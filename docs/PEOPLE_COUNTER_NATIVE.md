# Nguồn SDK chạy nền — bước 1 trước khi triển khai VPS

Người dùng sau đó chọn thêm xử lý offline để tránh chi phí VPS, rồi yêu cầu
giữ luồng online và chuyển offline sang tab riêng. Nguồn native và AI live
đã bật lại theo yêu cầu mới nhất; vẫn chạy trên máy hiện tại, chưa có VPS.
Xem thêm [phương án video đã ghi](PEOPLE_COUNTER_OFFLINE.md).

Trạng thái ngày 28/10/2026: đã tạo chương trình riêng từ header/API chính thức
trong Imou PC OpenSDK. Không cần thao tác trên Imou PC hoặc demo Qt để cấp video.
Chương trình vẫn dùng Qt/Windows và một HWND ẩn cho bộ giải mã của SDK. Chưa xác
minh Windows Service Session 0, đăng xuất RDP, khởi động lại máy hoặc Windows VPS.
Không coi việc chạy ẩn trong phiên Windows hiện tại là đã triển khai 24/7.

## Thành phần

- `services/people-counter/native/bridge.cpp`: nhận phiên riêng qua stdin,
  mở camera bằng OpenSDK và xuất MPEG-TS tới loopback. Chỉ nhận ba host Imou
  được cho phép; không cung cấp URL stream công khai.
- `imou_session.py`: HMAC theo vector chính thức, API chỉ đọc token và danh
  sách camera bind/share, chọn đúng thiết bị/kênh. Giới hạn phản hồi 1 MiB,
  xác minh HTTPS, từ chối redirect và không đoán khóa mã hóa tự đặt.
- `run_native.py`: đọc thông tin Imou từ biến môi trường hoặc file riêng,
  truyền token/AppSecret qua pipe bộ nhớ, không qua command line. Lọc stdout
  về state và số byte; stderr SDK nằm trong log riêng bị Git bỏ qua.
- `sdk_pilot.py --source-port 19456`: nhận nguồn mới, chạy ROI/YOLO và cập
  nhật preview PHP local như trước. Thêm `--record-trial --env-file <file riêng>`
  để lưu và gửi các lượt mới vào bảng thử nghiệm, không gửi vào bảng vận hành.

### Số trực tiếp và báo cáo vận hành

Các thẻ cạnh camera lấy số từ `session` trong preview, cùng nguồn với chữ
`SESSION IN / OUT` trên video; không lấy tổng SQL theo bộ lọc ngày. Tạm dừng
hình ảnh vẫn cập nhật số phiên. Preview được tạo tối đa 3 lần/giây; trình duyệt
đọc mỗi 333 ms, chỉ một request tại một thời điểm và dừng polling khi tab ẩn.
Đây là ảnh cập nhật nhanh, chưa phải video phát mượt đủ FPS. Khi nguồn mất hoặc khung hình quá
30 giây, số trực tiếp chuyển thành dấu gạch để tránh hiển thị dữ liệu cũ.
Báo cáo vận hành theo ngày hiển thị riêng bên dưới, chưa bao gồm lượt thử.

Khi bật ghi lịch sử, `.deploy/sdk-pilot/trial.sqlite` lưu từng lượt và tổng phiên
trong cùng giao dịch. Gửi API có chữ ký HMAC, chống trùng theo ID và chỉ xóa hàng
đợi khi server xác nhận đầy đủ. Khởi động lại hoặc mất kết nối giữ nguyên lượt
chưa gửi. `session.json` là bản checkpoint tương thích; SQLite là nguồn tổng phiên
khi đã có journal. Không sửa các file này khi AI đang chạy.

Chạy migration `027_people_counter_trials` trước khi bật gửi. Bảng riêng
`people_counter_trial_events` chứa lượt chưa đối soát; chọn loại báo cáo AI trên
trang để xem theo ngày, biểu đồ giờ và lịch sử, tự làm mới 5 giây. Báo cáo vận hành
vẫn đọc bảng cũ. Gói gửi đặt `trial: true`, không đổi `calibrated` thành true.

File riêng cấu hình `COUNTER_ENDPOINT` và `PEOPLE_COUNTER_KEY` (tối thiểu 32 ký tự).
Endpoint phải HTTPS; HTTP chỉ cho loopback khi chạy local. Trên máy hiện tại PHP
nghe `::1:8080`, do đó dùng `http://[::1]:8080/api/integration/people-counter`.
Không commit file cấu hình khóa. Sender không tải lại video. Mặc định chỉ gửi
lượt và heartbeat; bật `--send-preview` để gửi thêm JPEG cùng tổng phiên có chữ ký,
tối đa khoảng một ảnh/giây lên website. Ảnh được lưu riêng ngoài public_html,
chỉ người có quyền `people-counter.view` được đọc qua API.

Phiên chuyển đổi ngày 28/10 giữ 7 lượt vào / 10 lượt ra từ summary cuối của
tiến trình cũ. Các tổng này không có thời điểm từng lượt nên không tạo lại lịch sử
SQL. Chưa xác minh độ chính xác hoặc hướng đếm bằng video thực tế.

### Kiểm tra độ giật

Đo trên máy hiện tại: YOLO CPU khoảng 50–80 ms/khung ở log cũ; thử cùng ảnh
với 4 luồng cho median khoảng 66 ms, so với khoảng 73 ms khi dùng 8 luồng.
Pilot giới hạn OpenCV một luồng và PyTorch bốn luồng sau lần khởi tạo predictor,
giữ độ phân giải preview 1280 px. Log có FPS xử lý và khoảng cách khung lớn nhất.

Nguồn UDP từng báo lỗi tham chiếu H.265 và timeout 5 giây. Thử TCP nội bộ chưa
nhận được chuỗi khung ổn định, đã khôi phục UDP; không xem thử nghiệm TCP là bản
sửa đã triển khai. Tăng nhịp preview không khôi phục được lượt qua cửa xảy ra
trong thời gian nguồn gián đoạn. Cần tiếp tục kiểm chứng với video/người thật.

Mỗi lần native chạy có thời hạn tối đa 3600 giây. `--continuous` lấy lại phiên
và chạy tiếp; lỗi tạm thời có backoff, marker stop được chuyển tới chương
trình native để dừng có kiểm soát. Chưa có bằng chứng chạy dài ngày hoặc
phục hồi mọi lỗi của SDK; cần soak test trên chính VPS trước production.

## Build đã kiểm chứng trên máy phát triển

Cần Build Tools C++, Qt 5.12.10 MSVC2017 64-bit và gói PC OpenSDK chính thức.
Không commit DLL SDK, AppSecret, token, LoginInfo của demo hoặc log vendor.

```powershell
& 'C:\Program Files (x86)\Microsoft Visual Studio\18\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin\cmake.exe' `
    -S services/people-counter/native -B .deploy/sdk-native/build `
    -G 'Visual Studio 18 2026' -A x64 `
    '-DCMAKE_PREFIX_PATH=D:/Qt/5.12.10/msvc2017_64' `
    '-DIMOU_SDK_ROOT=D:/WorkSpace/TN_Services/mohomestay/.deploy/imou-demo/depends/lcopensdk' `
    '-DCMAKE_RUNTIME_OUTPUT_DIRECTORY_RELEASE=D:/WorkSpace/TN_Services/mohomestay/.deploy/imou-demo/bin/x64'
& 'C:\Program Files (x86)\Microsoft Visual Studio\18\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin\cmake.exe' `
    --build .deploy/sdk-native/build --config Release --parallel 4
```

Các đường dẫn trên là đường dẫn thật đã build ở máy phát triển; thay bằng
thư mục SDK/Qt trên máy build khác. Thư mục output hiện dùng runtime DLL,
PEM và Qt platform plugins của bộ demo đã được chuẩn bị. Binary không tự
tìm hoặc tải DLL; khi đóng gói riêng cần whitelist runtime cần thiết,
không sao chép toàn thư mục demo có file LoginInfo chứa credentials.

`mohome-imou-bridge.exe --check` kiểm tra tải runtime và khóa callback. Trong
lần thử đầu runtime MSVCP140 cũ của gói vendor gây access violation khi dùng
`std::mutex` từ toolchain mới; đã dùng QMutex cùng runtime Qt của demo và
kiểm thử lại callback thật. Không thay DLL hệ thống hoặc nới kiểm tra bảo mật.

## Chạy trên máy hiện tại

```powershell
.deploy\counter-venv\Scripts\python.exe services/people-counter/run_native.py `
    --executable .deploy/imou-demo/bin/x64/mohome-imou-bridge.exe `
    --env-file .env --device-id DEVICE_SERIAL `
    --udp-port 19456 --duration 1800 --continuous `
    --stop-file .deploy/sdk-native/stop.request

.deploy\counter-venv\Scripts\python.exe services/people-counter/sdk_pilot.py `
    --config .deploy/sdk-pilot/config.json --source-port 19456 --seconds 0 `
    --record-trial --env-file .deploy/sdk-pilot/delivery.env
```

## Hiển thị trên hosting bằng máy AI hiện tại

DirectAdmin chạy PHP/MySQL, không chạy SDK Windows hoặc YOLO. PC vẫn phải bật;
server hiển thị ảnh và báo cáo do PC gửi qua HTTPS. Không cần mở cổng router.

1. Trong `.env` riêng trên hosting, đặt `PEOPLE_COUNTER_KEY` giống khóa trong
   file cấu hình gửi riêng của PC. Không dùng AppSecret Imou hoặc khóa deploy.
2. Tạo `.deploy/sdk-pilot/delivery-server.env` trên PC, không commit:

```ini
COUNTER_ENDPOINT=https://mohomestay.vn/api/integration/people-counter
PEOPLE_COUNTER_KEY=<cùng khóa riêng tối thiểu 32 ký tự với hosting>
```

3. Dừng worker cũ bằng `.deploy/sdk-pilot/stop.request`, đợi thoát sạch rồi bỏ
   marker. Giữ nguồn native SDK đang chạy. Chạy worker với file gửi mới:

```powershell
.deploy\counter-venv\Scripts\python.exe services/people-counter/sdk_pilot.py `
    --config .deploy/sdk-pilot/config.json --source-port 19456 --seconds 0 `
    --record-trial --send-preview --env-file .deploy/sdk-pilot/delivery-server.env
```

4. Mở trang đếm trên hosting, kiểm tra có ảnh mới, tổng phiên, rồi kiểm chứng một
   lượt đi qua cửa và bản ghi thời gian tương ứng. HTTP 401 nghĩa là khóa hai đầu
   chưa khớp; hàng đợi giữ lại lượt chưa được server xác nhận. Hosting có thể cần
   quyền ghi cho thư mục riêng `storage/people-counter` bên cạnh `src`.

Đổi endpoint chỉ gửi các lượt đang chờ và lượt mới. Lượt đã được localhost xác
nhận không được tự nhân bản lên hosting, và tổng 17 lượt cũ không có thời điểm
vẫn không thể dựng thành lịch sử. Đây chưa phải triển khai AI độc lập 24/7.

Tạo `.deploy/sdk-native/stop.request` để dừng nguồn native; tạo
`.deploy/sdk-pilot/stop.request` để dừng AI. Bỏ đúng marker trước khi chạy lại.
Không chạy hai producer preview đồng thời; pilot có khóa dùng chung để chặn
điều đó. Metadata PID riêng nằm trong `.deploy/sdk-native/service-process.json`
và `.deploy/sdk-pilot/bridge-process.json`, không chứa khóa truy cập.

Kiểm thử độc lập đã nhận 2.760.216 byte qua 2.797 datagram, native thoát sạch
với exit code 0, giải mã 601 frame 2304 × 1296 và kiểm tra bằng mắt thấy camera.
Sau đó đã chuyển AI sang cổng 19456 và xác nhận heartbeat RUNNING cùng ảnh mới.
Cổng demo cũ 19454 không còn được AI dùng. Chưa kiểm thử độ chính xác đếm bằng
người đi qua cửa, chưa bật event vận hành hoặc deploy production.

## Bước tiếp theo

Người dùng xác nhận chưa có VPS riêng. Hosting DirectAdmin PHP/MySQL hiện tại
không thay thế được máy chạy Windows OpenSDK/Python. Cần chọn và cấp máy chủ,
kiểm tra nguồn SDK trong phiên không tương tác/session thực tế của máy đó,
cấu hình restart/refresh, rồi nối HMAC ingest về hosting và chạy thử đếm.
Chưa tạo/thuê dịch vụ trả phí, đăng ký Windows startup hoặc push/deploy.
