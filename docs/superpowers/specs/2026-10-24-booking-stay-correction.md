# Điều chỉnh phòng và lịch lưu trú bởi superadmin

## Phạm vi

- Superadmin sửa phòng, ngày/giờ nhận và trả của đơn chưa hủy/chưa trả phòng.
- Chấp nhận ngày quá khứ theo giờ Việt Nam; không sửa thời điểm tạo đơn.
- Giữ nguyên mã đơn, khách, giấy tờ, gói đã chốt, giá và thanh toán (người dùng xác nhận giữ giá).
- Nhân viên không thấy nút và bị API từ chối, kể cả có quyền sửa đơn thông thường.
- Phòng đích phải đang hoạt động, đủ sức chứa, không trùng lịch/dọn phòng/khóa/giữ báo giá.
- Kiểm tra phiên bản phòng/lịch cũ để tránh ghi đè thao tác đồng thời.
- Lưu lý do và lịch sử trước/sau trong cùng transaction; khóa cả phòng cũ và mới theo thứ tự.
- Không tự đổi mã TTLock đã cấp. Nếu đổi thời hạn của đơn có mã đã cấp/chưa rõ trạng thái,
  yêu cầu xử lý mã cửa trước. Mã chưa từng cấp được cập nhật lịch chờ trong cùng transaction.
- Đơn đã xác nhận trả phòng/phụ thu hoặc đang khóa khẩn cấp phải xử lý nghiệp vụ đó trước.

## Thực hiện và xác minh

1. Test quyền, ngày quá khứ, giữ giá, lịch trùng, rollback, dữ liệu đã thay đổi và mã cửa.
2. Thêm API riêng và bảng lịch sử; tái sử dụng kiểm tra tồn phòng hiện tại.
3. Thêm hộp thoại Tailwind ở danh sách đơn, chỉ superadmin mở được.
4. Build CSS, chạy kiểm tra PHP/JS và kiểm thử trình duyệt desktop/mobile.

Không thay đổi luồng đặt phòng công khai hoặc quyền nhân viên hiện có.
