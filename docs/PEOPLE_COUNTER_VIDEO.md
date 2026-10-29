# Video riêng cho trang đếm người

AI và nguồn SDK chạy trên TNCompany. Trình duyệt xem HLS riêng, không cần đợi
từng lần AI xử lý xong. Nguồn hiện tại là H.264 640x352, 20 FPS; đây chưa phải HD.
Nhánh video dùng libx264 ultrafast một thread, cố định 20 FPS và keyframe mỗi
20 khung để xuất đoạn HLS 1 giây. Mục tiêu đệm của HLS.js là 2 giây; đây không
phải cam kết độ trễ từ camera đến màn hình vì còn đường truyền Imou và hosting.
Không dùng `split_by_time`: cắt giữa keyframe đã gây lỗi giải mã lúc bắt đầu.
Unit video đã áp dụng được lưu tại `services/people-counter/deploy/mo-camera-video.service`.
Giới hạn tổng slice một lõi CPU/1536 MiB được giữ. Mẫu đo 6 giây giải mã đủ
120 khung; nhịp xuất playlist khoảng một giây. Encoder dùng thêm khoảng 12%
một lõi trong phép đo; cần theo dõi khi có người đi qua hoặc tải POS cao.
Đoạn ngắn làm tăng số request PHP và băng thông so với stream copy, nên chưa
coi là kiểm thử tải nhiều người xem. Để hoàn tác, khôi phục unit
`~/.config/systemd/user/mo-camera-video.before-short-segments` rồi daemon-reload
và restart riêng `mo-camera-video`.

## Kết nối HTTPS

Endpoint đã cài trên TNCompany:
`https://tnservice.vn/mo-camera-video.php`.
Mã nguồn endpoint là `services/people-counter/deploy/https-relay.php`, được cài
riêng tại `/var/www/tnservice.vn/public/mo-camera-video.php`, quyền 644. File này
không được đóng gói vào deploy Mơ Home. Cần giữ nó khi triển khai lại TNCompany.
Không đổi `.htaccess`, Apache, PHP-FPM, MariaDB hoặc khởi động lại POS để cài file.

Endpoint độc lập không khởi tạo ứng dụng POS, không truy cập database, chỉ gọi
gateway `127.0.0.1:19460`. Mỗi yêu cầu giới hạn 2 giây và 4 MiB, PHP giới hạn 32 MiB.
Nó vẫn sử dụng PHP worker của TNCompany: phải đo khi có nhiều người xem;
HTTP 200 của trang chủ không thay thế kiểm tra order/in bill tại quầy.

Gateway yêu cầu HMAC SHA-256 của `video:entrance:<expiry>` dùng
`PEOPLE_COUNTER_KEY`. Vé backend có hạn 60 giây; relay chỉ nhận vé qua header
`X-Video-Expires` và `X-Video-Signature`, không qua URL công khai. Endpoint không
lưu khóa trong web root; gateway kiểm tra chữ ký với khóa riêng hiện có.
Playlist trả về chỉ có tên đoạn, không có vé. Không cấu hình access log ghi
hai header xác thực này. Không mở cổng 19460 ra Internet.

## Cấu hình hosting Mơ Home

Sau khi triển khai code trình phát/API, thêm vào `.env` riêng của hosting:

```ini
PEOPLE_COUNTER_VIDEO_ORIGIN=https://tnservice.vn/mo-camera-video.php
```

`PEOPLE_COUNTER_KEY` trên Mơ Home phải khớp khóa gateway đang dùng để nhận sự kiện
AI. Không đưa khóa vào trình duyệt, repository hoặc đổi toàn bộ `.env` hosting.
Để origin trống sẽ giữ JPEG preview. Không dùng `127.0.0.1:19460` trên hosting
Mơ Home vì gateway nằm ở máy TNCompany khác.

Trình duyệt chỉ gọi `/api/admin/people-counter/video` trên Mơ Home. API đó dùng
phiên đăng nhập và quyền Super Admin hiện có, kiểm tra camera bật trước khi gửi
yêu cầu HTTPS sang TNCompany, rồi thay tên đoạn bằng các URL API có xác thực.
Khóa, vé, origin riêng không được trả về trình duyệt. Khi nhận video, số đếm vẫn
cập nhật qua metadata AI. Khi video lỗi, giao diện thử lại và dùng JPEG dự phòng.

## Kiểm chứng và hoàn tác

- HTTPS không có vé, vé sai, vé hết hạn phải trả 403; đường dẫn ngoài allowlist trả 404.
- Vé đúng phải nhận playlist và đoạn video HTTP 200, TLS được kiểm tra bình thường.
- Playlist công khai không chứa `signature=` hoặc địa chỉ gateway riêng.
- API Mơ Home chưa đăng nhập phải từ chối; nhân viên không có quyền camera cũng bị từ chối.
- Kiểm tra phát/pause/tiếp tục trên desktop, mobile và khi ẩn tab trước khi bật production.
- Muốn tắt video trên Mơ Home: xóa giá trị origin; nguồn và AI TNCompany vẫn chạy.

Đã xác minh đường HTTPS và PHP local nhận playlist, API local chưa đăng nhập
trả 401. Chưa xác minh kết nối từ hosting Mơ Home thực tế, phát trình duyệt
hoặc nguồn HD. Push code không tự thêm biến `.env` trên hosting.

Sau tối ưu đoạn 1 giây, đã quan sát trình phát Chrome local phát được video và
vạch vàng. Một mẫu đo ngày 29/10/2026 lúc 20:03:19 UTC+7 hiển thị đồng hồ camera
20:03:16, tương ứng khoảng 3 giây chênh lệch đồng hồ hiển thị. Chưa hiệu chuẩn
độ lệch đồng hồ camera, chưa đo nhiều người xem hoặc đường từ hosting production.

## Vạch và khung nhận diện trên video

`overlay.py` xuất tọa độ chuẩn hóa của vạch đếm và tối đa 20 khung người đã được
tracker AI nhận diện, kèm hướng IN/OUT nếu đã ghi nhận. Metadata đi trong preview
đã ký; PHP kiểm tra tọa độ, loại dữ liệu, số khung và thời điểm trước khi lưu.
Không phát hiện người thì gửi danh sách rỗng, không dựng khung giả.

HLS trên TNCompany bật `program_date_time`. Trình duyệt lưu các snapshot AI gần
nhất, đối chiếu `Hls.playingDate` với timestamp nhận khung hình tại worker và chỉ
vẽ khung nếu độ lệch dưới 750 ms. Vạch được căn theo vùng video thực tế, kể cả
khi có viền đen; khung và nhãn màu vàng được vẽ trên canvas, không cần thêm
bước mã hóa để gắn lớp phủ.
Không có mốc thời gian phù hợp thì chỉ có vạch, không dùng khung cũ.

Mốc này dựa trên thời gian nhận của hai nhánh, chưa phải timestamp gốc của sensor.
Cần người đi qua cửa để kiểm chứng độ khớp thực tế, đặc biệt sau mất mạng hoặc
khởi động lại. Trong native fullscreen chỉ riêng video, canvas không thuộc video;
chế độ xem trang bình thường hiển thị lớp phủ.
