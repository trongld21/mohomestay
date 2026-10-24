# Bán hàng tại Mơ

Trong sidebar, mở nhóm **Bán hàng** rồi chọn **Bán hàng** hoặc **Quản lý sản phẩm**.

- Quản lý sản phẩm: thêm tên, giá bán; sửa giá hoặc tắt sản phẩm để ngừng bán. Đơn cũ giữ nguyên tên và giá tại lúc chốt.
- Bán hàng: chọn sản phẩm, nhập số lượng, kiểm tra giá chốt, bấm **Chốt đơn & tạo QR**. Mở mã QR trên trang thanh toán payOS để khách chuyển khoản.
- Nếu tạo QR lỗi, đơn vẫn lưu. Bấm **Tạo lại QR** trong đơn hoặc lịch sử; không tạo đơn mới cho cùng giao dịch.
- Lịch sử hiển thị chi tiết, trạng thái thanh toán và doanh thu bán hàng. Chỉ đơn được payOS xác nhận trả tiền mới cộng doanh thu; doanh thu này cũng cộng vào tổng quan quản trị.
- Chưa có quản lý tồn kho hoặc thanh toán tiền mặt trong màn này.

## Quyền

Trong Phân quyền tài khoản có nhóm Bán hàng và Quản lý sản phẩm: xem lịch sử/doanh thu, tạo đơn/QR/kiểm tra thanh toán, chỉnh giá chốt, xem/thêm/sửa sản phẩm. Theo cơ chế hiện có, quyền thao tác kèm quyền xem nhóm tương ứng. Quyền chỉnh giá không tự cho phép tạo đơn.

## Triển khai

Chạy migration `017_retail_sales.sql` bằng công cụ migration hiện có trước khi dùng màn bán hàng. QR dùng payOS và webhook đã xác minh chữ ký; mã đơn bán hàng dùng dải order code riêng. Migration không đổi dữ liệu đặt phòng hoặc các khoản thu cũ.

Nút nhập phụ thu tên/giá trên đơn phòng đã ngừng sử dụng. Các khoản cũ và QR đã chốt giữ lại để đối soát; phụ thu giờ và giờ phát sinh của đặt phòng giữ nguyên.

Có thể chọn phòng liên quan khi tạo đơn (hoặc Không gắn phòng). Lịch sử và chi tiết đơn giữ tên phòng tại lúc chốt, hỗ trợ tra theo tên phòng/mã đơn. Chạy thêm migration `018_retail_sale_room.sql` khi triển khai. Phòng đang ẩn không nằm trong danh sách chọn.

Danh s?ch ch?n ph?ng hi?n th? th?m m? ??n ?ang l?u tr?. ??n b?n l?u booking_id v? m? ??t ph?ng t?i l?c ch?t, cho ph?p tra l?ch s? b?ng m? MO. Migration: `019_retail_sale_booking.sql`. N?u kh?ch c?a ph?ng thay ??i trong l?c t?o ??n, h? th?ng y?u c?u l?m m?i ?? tr?nh g?n nh?m.

Lịch sử bán hàng được tách riêng tại `/admin/sales/history`, cùng nhóm Bán hàng trên sidebar. Trang bán hàng chỉ chứa giỏ hàng và chốt đơn; trang lịch sử giữ tra cứu, phân trang, doanh thu và xem lại đơn/QR. Quyền `sales.view` mở lịch sử, `sales.create` mở bán hàng.
