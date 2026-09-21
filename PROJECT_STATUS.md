# Trạng thái Lặng Home

Đã triển khai giao diện và backend chính: landing page, 3 phòng / 9 giá gói, lịch theo giờ, đặt phòng chống trùng, QR payOS + webhook có chữ ký, tra cứu và quản lý có đăng nhập.

Đã xác minh:
- TypeScript strict và ESLint: qua.
- Production build: qua.
- 7 unit test: giá, thời gian, đầu vào, chữ ký và yêu cầu tạo thanh toán.
- 8 integration test trên PostgreSQL riêng: đặt đồng thời, bảo vệ dữ liệu, webhook giả/lặp, tiền đến muộn, gói tiếp giáp, ngày khóa, tắt dịch vụ, webhook đến sớm và timeout.
- HTTP: các trang chính trả 200, phòng không tồn tại trả 404, quản lý yêu cầu đăng nhập, API quản lý và webhook giả trả 401.

Giới hạn kiểm chứng:
- Không có trình duyệt khả dụng trong môi trường nên chưa kiểm tra trực quan hoặc tương tác trình duyệt.
- Gateway được giả lập trong integration test; chưa quét/thu tiền thật với payOS.
- Chưa deploy Vercel. Database thử nghiệm không phải database vận hành.

Cần chủ home bổ sung/xác nhận: ảnh và logo gốc; giờ qua đêm; sức chứa phòng; chính sách hủy/hoàn tiền và thu toàn bộ hay đặt cọc; tài khoản payOS, database và domain.

Tài liệu đầy đủ: [README](README.md).
