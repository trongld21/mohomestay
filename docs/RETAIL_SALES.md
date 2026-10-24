# Bán hàng tại Mơ

Trong sidebar, mở nhóm **Bán hàng** rồi chọn **Bán hàng** hoặc **Quản lý sản phẩm**.

- Quản lý sản phẩm: thêm tên, giá bán; sửa giá hoặc tắt sản phẩm để ngừng bán. Đơn cũ giữ nguyên tên và giá tại lúc chốt.
- Bán hàng: chọn sản phẩm, nhập số lượng, kiểm tra giá chốt, bấm **Chốt đơn & tạo QR**. Mở mã QR trên trang thanh toán payOS để khách chuyển khoản.
- Nếu tạo QR lỗi, đơn vẫn lưu. Bấm **Tạo lại QR** trong đơn hoặc lịch sử; không tạo đơn mới cho cùng giao dịch.
- Lịch sử hiển thị chi tiết, trạng thái thanh toán và doanh thu bán hàng. Đơn được payOS xác nhận hoặc nhân viên có quyền xác nhận chuyển khoản trước mới cộng doanh thu; doanh thu này cũng cộng vào tổng quan quản trị.
- Thanh toán tiền mặt chưa được hỗ trợ trong màn này.

## Quyền

Trong Phân quyền tài khoản có nhóm Bán hàng và Quản lý sản phẩm: xem lịch sử/doanh thu, tạo đơn/QR/kiểm tra thanh toán, chỉnh giá chốt, xem/thêm/sửa sản phẩm. Theo cơ chế hiện có, quyền thao tác kèm quyền xem nhóm tương ứng. Quyền chỉnh giá không tự cho phép tạo đơn.

## Triển khai

Chạy migration `017_retail_sales.sql` bằng công cụ migration hiện có trước khi dùng màn bán hàng. QR dùng payOS và webhook đã xác minh chữ ký; mã đơn bán hàng dùng dải order code riêng. Migration không đổi dữ liệu đặt phòng hoặc các khoản thu cũ.

Nút nhập phụ thu tên/giá trên đơn phòng đã ngừng sử dụng. Các khoản cũ và QR đã chốt giữ lại để đối soát; phụ thu giờ và giờ phát sinh của đặt phòng giữ nguyên.

Có thể chọn phòng liên quan khi tạo đơn (hoặc Không gắn phòng). Lịch sử và chi tiết đơn giữ tên phòng tại lúc chốt, hỗ trợ tra theo tên phòng/mã đơn. Chạy thêm migration `018_retail_sale_room.sql` khi triển khai. Phòng đang ẩn không nằm trong danh sách chọn.

Danh sách chọn phòng hiển thị thêm mã đơn đang lưu trú. Đơn bán lưu booking_id và mã đặt phòng tại lúc chốt, cho phép tra lịch sử bằng mã MO. Migration: `019_retail_sale_booking.sql`. Nếu khách của phòng thay đổi trong lúc tạo đơn, hệ thống yêu cầu làm mới để tránh gắn nhầm.

Lịch sử bán hàng được tách riêng tại `/admin/sales/history`, cùng nhóm Bán hàng trên sidebar. Trang bán hàng chỉ chứa giỏ hàng và chốt đơn; trang lịch sử giữ tra cứu, phân trang, doanh thu và xem lại đơn/QR. Quyền `sales.view` mở lịch sử, `sales.create` mở bán hàng.

## Nhập kho và tồn kho

Chạy migration `020_retail_inventory.sql`. Sản phẩm có tồn ban đầu bằng 0; nhập số lượng thực tế trong **Nhập kho & tồn kho** để đối soát. Migration không trừ lại các đơn lịch sử.

- Nhập kho: chọn sản phẩm, số lượng nhập và ghi chú lô hàng; hệ thống lưu phiếu và người nhập. Gửi lại cùng phiếu không cộng tồn thêm lần nữa.
- Tồn giảm khi chốt đơn bán hàng, gồm cả đơn đang chờ thanh toán hoặc chưa tạo được QR. Tạo lại QR/callback thanh toán không trừ thêm. Không tự hoàn tồn khi QR gặp lỗi vì đơn vẫn có thể được thanh toán.
- Được bán khi hết hàng hoặc tồn âm; số tồn tiếp tục giảm xuống âm; kiểm tra/trừ/lưu đơn trong cùng transaction.
- Quản lý sản phẩm có cờ Quản lý tồn kho: tắt cho mục dịch vụ như phụ thu giờ, bật cho hàng vật lý. Sửa sản phẩm không cho nhập đè trực tiếp số tồn.
- Quyền `inventory.view` xem tồn và biến động, `inventory.receive` nhập kho (kèm quyền xem theo cơ chế hiện có). Các quyền này tách khỏi tạo đơn và sửa sản phẩm.

Migration `021_retail_negative_stock.sql` chuyển số tồn và tồn sau biến động sang số nguyên có dấu. Được bán khi tồn bằng 0 hoặc âm; nhập kho cộng vào số tồn hiện tại để bù phần âm.

## Lịch sử, giảm giá và chốt thanh toán

- Lịch sử mặc định ngày hôm nay theo giờ Việt Nam, thứ tự cũ nhất trước. Chọn từ ngày/đến ngày, trạng thái và từ khóa (phòng, mã BH/MO hoặc tên sản phẩm); thống kê doanh thu và số lượng sản phẩm theo đúng bộ lọc. Số lượng đã bán gồm đơn tặng hoàn thành, loại đơn chờ/hủy. Các mốc lọc dùng ngày tạo đơn.
- Quyền `sales.discount` cho nhập giảm giá nguyên 0–100%. Giảm trên giá chốt trước giảm; server tự tính tiền, không tin tổng tiền cuối do trình duyệt gửi. 100% là đơn tặng hoàn thành, doanh thu 0đ, vẫn trừ tồn, không tạo QR 0đ.
- Quyền `sales.cancel` cho hủy đơn PENDING: nhập lý do, xác minh QR đã đóng/chưa nhận tiền, hoàn lại đúng tồn đã trừ và lưu người hủy. Không hủy đơn đã thanh toán hoặc hoàn kho hai lần khi gửi lại.
- Quyền `sales.complete` cho nhân viên đã kiểm tra tiền vào ngân hàng xác nhận hoàn thành đơn chuyển khoản trước. Nhập thông tin đối soát; QR chờ được đóng để tránh khách trả thêm. Lưu người xác nhận và ghi chú; doanh thu ghi nhận một lần.
- Migration `022_retail_management.sql` thêm trạng thái hủy, giảm giá, thông tin đối soát và biến động hoàn kho.

## Email báo sắp hết

Migration `024_retail_stock_alerts.sql`. Hàng có quản lý tồn ở mức **≤5** gửi email tới `ADMIN_EMAIL`, dùng `MAIL_FROM`. Mỗi sản phẩm báo một lần trong mỗi đợt tồn thấp; khi nhập/hoàn lên >5 sẽ sẵn sàng báo lại. Thông báo được lưu trong transaction, gửi sau commit. Lỗi mail không hủy đơn; hàng đợi thử lại sau 15 phút khi API bán hàng/kho hoạt động, tối đa 5 lần. Không gửi cảnh báo cho sản phẩm dịch vụ.
