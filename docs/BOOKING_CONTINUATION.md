# Gói ở tiếp cùng khách

Trong bảng đặt phòng, nhân viên có quyền tạo đơn chọn **Ở tiếp / thêm gói** ở đơn còn hiệu lực. Popup chỉ hiện gói có giờ nhận đúng bằng giờ trả gói trước và đủ lịch trống sau đó. Ví dụ 18:00–21:00 nối gói qua đêm 21:00–09:00.

Gói sau tự kế thừa thông tin khách và phòng từ đơn trước; server kiểm tra liên kết, cùng phòng/khách và thời gian liền nhau. Chỉ bỏ giờ dọn giữa hai gói này; sau gói cuối vẫn có 1 giờ dọn. Đơn mới có giá và thanh toán riêng. Quyền xác nhận đã thu tiền vẫn là `bookings.payment`.

Không sửa riêng phòng/giờ hoặc trả riêng gói trước khi đang có gói nối. Xử lý gói cuối trước; hủy gói nối sẽ khôi phục giờ dọn cho gói trước. Khách khác và các đơn không liên kết vẫn phải chừa đủ giờ dọn.

Triển khai migration `023_booking_continuation.sql` trước khi dùng. Không có thao tác bỏ giờ dọn tùy ý trên luồng đặt phòng công khai.
