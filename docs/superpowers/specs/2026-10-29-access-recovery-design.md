# Khôi phục mã theo đơn

Người dùng đã duyệt: sửa phản hồi đối soát và thêm nút Tạo lại mã cho đơn chưa có mã. Tìm mã trên TTLock trước, chỉ tạo nếu không tìm thấy; không thay mã READY. Đơn phải đã thanh toán, còn hiệu lực và được xác nhận/đang lưu trú. Giữ quyền door-codes.retry, CSRF và khóa chống xử lý đồng thời.

Không tự động tạo lại UNKNOWN trong cron. Không xóa mã hoặc hứa thu hồi mã offline. Trả trạng thái thực tế và thông báo ngay tại đơn. Chưa xác định nguyên nhân lỗi sản xuất ban đầu do chưa truy cập được DB; bổ sung phân loại lỗi an toàn, không ghi mã PIN/token.

Đã cân nhắc: tự tạo lại mọi UNKNOWN có nguy cơ tạo trùng; giữ chỉ đối soát làm đơn kẹt vĩnh viễn. Chọn thao tác tạo lại có chủ đích của quản trị sau khi quét danh sách mã đầy đủ.
