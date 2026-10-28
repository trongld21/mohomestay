# Đếm video đã ghi theo ca/ngày — phương án đã chọn

Người dùng chọn thêm phương án này ngày 28/10/2026, sau đó yêu cầu giữ cả luồng
online và đưa offline vào tab riêng. Tab Trực tiếp là mặc định tại
`/admin/people-counter`; tab Video đã ghi ở `?tab=offline`. Nguồn SDK và AI
live đã bật lại theo yêu cầu mới. Không dùng tab offline để tự bật/tắt live.
Riêng xử lý offline không cần VPS hay máy bật liên tục: máy chỉ chạy AI khi
người dùng đưa video đã xuất vào xử lý. Camera vẫn ghi hình bằng cơ chế sẵn có.

## Dùng trên máy hiện tại

1. Xuất video gốc từ Imou, ưu tiên MP4. Giữ cả vùng cửa, không quay lại màn
   hình ứng dụng hoặc tăng tốc video. Cấu hình hiện dành cho camera lễ tân.
2. Mở `tools/Dem-video.cmd` bằng File Explorer.
3. Dán đường dẫn file MP4; có thể kéo file vào cửa sổ lệnh. Nhập thời điểm
   bắt đầu ghi nếu biết, ví dụ `2026-10-28T19:00:00`. Mặc định giờ Việt Nam.
   Hai số lượt vào/ra thực tế là tùy chọn; bỏ trống khi chưa đối chiếu tay.
4. Chờ xử lý xong. Thư mục kết quả tự mở, đọc `summary.txt`, `events.csv` và
   xem `annotated.mp4` để kiểm tra các lượt qua cửa. Không cần giữ máy bật
   ngoài thời gian xử lý; đây không phải báo cáo trực tiếp.

Có thể mở tab **Video đã ghi** trên website và chọn `report.json` để xem tổng
lượt, thời lượng, trạng thái hiệu chỉnh và chênh lệch AI trừ thực tế. File chỉ
được đọc trong browser, không gửi lên hosting hay cộng vào database. Tab này
không chạy YOLO hoặc nhận upload MP4; video được xử lý bằng công cụ local ở trên.

Không cần AppSecret hoặc Safety Code khi xử lý file. Processor không gọi
Imou API, không kết nối stream và không gửi sự kiện lên database website.

## Kết quả

Mỗi lần chạy tạo thư mục riêng trong `storage/people-counter/offline/`:

- `summary.txt`: tổng IN/OUT, trạng thái xử lý, trạng thái hiệu chỉnh, chênh
  lệch AI trừ số tham chiếu vận hành nếu người dùng nhập.
- `events.csv`: từng lượt, mốc phút/giây trong video, giờ camera nếu đã nhập
  thời điểm bắt đầu, tổng lũy kế. Có UTF-8 BOM để mở bằng Excel.
- `report.json`: dữ liệu đầy đủ và SHA-256 video để nhận biết video đã xử
  lý trước đó. Không cộng hai báo cáo của cùng video thành hai nguồn riêng.
- `annotated.mp4`: video có khung theo dõi và vạch đếm; bỏ bằng `--no-preview`
  nếu chỉ cần báo cáo và muốn giảm dung lượng ghi đĩa.

Giờ sự kiện được tính từ thời điểm video, không lấy thời gian máy đang xử
lý. Không nhập giờ bắt đầu thì để trống cột giờ camera và chỉ dùng media time.
Vạch và ROI đã dùng trong thử nghiệm trực tiếp vẫn cần hiệu chỉnh bằng video
người đi thật. `calibrated: false` giữ thông báo chưa xác minh trong báo cáo;
không đổi thành true chỉ vì chương trình xuất được file.

File không đọc được không sinh kết quả 0 lượt. Không ghi đè thư mục báo cáo
cũ. Video bị thay đổi khi đang xử lý hoặc giải mã thiếu so với metadata sẽ
báo `incomplete`. Đọc đủ frame không chứng minh đã phát hiện đúng mọi người
hoặc video nguồn không có đoạn mất hình. Bị ngắt giữa chừng không có báo cáo
hoàn tất; CSV tạm không phải kết quả cuối.

## Chạy bằng dòng lệnh

Từ thư mục repository, dùng venv đã chuẩn bị:

```powershell
.deploy\counter-venv\Scripts\python.exe services/people-counter/video_report.py `
    --video 'D:\Videos\le-tan-ca-toi.mp4' `
    --start '2026-10-28T19:00:00+07:00' --expected-in 12 --expected-out 10
```

`config.offline.example.json` dùng weights local hiện có và ROI camera lễ
tân. Khi cài sang máy khác, cài `requirements.txt`, đặt weights đã chọn ở
đường dẫn local trong config. Không tự tải model trong lúc xử lý hoặc dùng
URL video thay cho file. Chỉ xử lý một video mỗi lần; các clip được xử lý
độc lập nên một lượt đi qua đúng ranh giới hai clip có thể bị bỏ sót. Không
tự giả định các file rời nhau là một video liên tục.

Giữ video và báo cáo trong thư mục riêng tư; không đưa lên `public/uploads/`
hay commit Git. Hosting DirectAdmin không xử lý YOLO hoặc chạy CMD này.
Trang live/API vẫn hoạt động riêng, không dùng làm kết quả báo cáo offline.
Chưa có tính năng tải video để chạy AI trên hosting production.
