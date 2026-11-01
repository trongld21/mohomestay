# Ưu đãi lặp lại và khách quay lại

## Mục tiêu

Admin tạo nhiều chương trình giảm giá phòng theo lịch lặp hoặc lịch sử lưu trú. Website và form tạo đơn admin dùng cùng quy tắc tính giá. Không cộng dồn: lấy phần trăm cao nhất trong các ưu đãi đủ điều kiện và áp dụng một lần lên giá gốc của gói.

## Phương án

- Mở rộng trường giảm giá hiện tại: ít thay đổi giao diện nhưng khó quản lý nhiều lịch độc lập.
- Thêm danh sách chương trình ưu đãi: quản lý riêng tên, trạng thái, phạm vi và điều kiện; tích hợp với bộ tính giá hiện tại. Đây là phương án đề xuất.

Giữ cấu hình giảm giá phòng/gói hiện tại; mức giảm đang có hiệu lực của cấu hình này là một ứng viên để so với chương trình mới. Không thay đổi quy tắc kế thừa giảm giá riêng của gói và giảm giá chung của phòng.

## Quy tắc đã thống nhất

- Không cộng dồn các chương trình, kể cả ưu đãi khách quay lại.
- Khách có ưu đãi 10% đặt trong khung giờ giảm 20% được giảm 20%.
- Nhận diện khách cũ khi số điện thoại hoặc email trùng lịch sử lưu trú.

## Thiết kế đề xuất

Admin có danh sách chương trình và form tạo/sửa: tên, bật/tắt, phần trăm giảm từ 1–99%, phòng/gói áp dụng (hoặc tất cả), ngày bắt đầu/kết thúc tùy chọn.

Loại lịch lặp cho chọn thứ trong tuần và khung giờ nhận phòng. Ví dụ thứ 2–thứ 6, 06:00–15:00 giảm 20%. Điều kiện dùng ngày giờ nhận phòng thực tế theo múi giờ Việt Nam, không dùng thời điểm bấm đặt. Mốc bắt đầu được tính, mốc kết thúc không được tính; 15:00 nằm ngoài khung 06:00–15:00. Phiên bản đầu yêu cầu giờ kết thúc lớn hơn giờ bắt đầu; lịch qua đêm có thể tách thành hai chương trình.

Loại khách quay lại cho cấu hình số lần lưu trú hoàn tất tối thiểu, mặc định một lần trước đó, và mức giảm, ví dụ 10%. Chỉ tính đơn CHECKED_OUT hợp lệ, không tính đơn hủy, hết hạn, đang giữ chỗ hoặc đơn test. Đếm mỗi đơn một lần dù trùng cả hai thông tin. Chuẩn hóa điện thoại Việt Nam để 0 và +84 khớp nhau; email bỏ khoảng trắng đầu/cuối và so sánh không phân biệt hoa thường; email trống không được dùng để khớp khách.

Khách đổi điện thoại nhưng giữ email cũ vẫn được nhận diện. Không tự gộp hồ sơ hoặc suy ra thêm danh tính qua các đơn trung gian.

Giá dự kiến được cập nhật khi đổi phòng, gói, ngày giờ hoặc thông tin liên hệ. Máy chủ kiểm tra lại điều kiện và giá khi tạo đơn; không tin phần trăm hoặc giá gửi từ trình duyệt. Phản hồi công khai chỉ trả kết quả ưu đãi cần hiển thị, không trả lịch sử lưu trú hoặc thông tin của đơn cũ.

Giảm giá chỉ áp dụng tiền gói, không giảm phụ thu giờ, hàng bán hoặc khoản phát sinh. Giữ cách làm tròn tiền hiện tại. Đơn lưu giá gốc, tên chương trình và mức giảm đã áp dụng để sửa chương trình sau này không thay đổi đơn cũ.

Form admin đề xuất giá sau ưu đãi cho đơn theo gói; nếu admin nhập giá thỏa thuận thì giữ giá đã nhập, không tự giảm lần nữa. Đơn thời gian tùy chọn không có giá gốc của gói nên tiếp tục dùng giá thỏa thuận.

## Phạm vi và kiểm tra

Ngày hiệu lực bao gồm cả ngày bắt đầu và kết thúc; bỏ trống cả hai nghĩa là không giới hạn. Khung giờ hỗ trợ mốc kết thúc 24:00 để tách lịch qua đêm. Nếu nhiều ưu đãi cùng mức giảm, chọn ổn định theo ID để thông tin lưu trên đơn nhất quán. Lịch sử lưu trú chỉ tính những lần đã thực sự kết thúc trước thời điểm báo giá/tạo đơn, không tính đơn có ngày trả phòng trong tương lai.

Thêm migration idempotent, API quản trị có kiểm tra quyền, giao diện Tailwind theo tông hiện tại, và bộ tính giá dùng chung ở máy chủ. Không push hoặc deploy trong phạm vi này.

Kiểm tra lịch theo thứ, ngày và ranh giới giờ; khách đổi số giữ email; chuẩn hóa liên hệ; loại trừ đơn không đủ điều kiện; nhiều ưu đãi cùng khớp; tính lại giá phía máy chủ; giá thỏa thuận và snapshot đơn cũ. Chạy các kiểm tra PHP/JS bắt buộc của repository, build CSS và kiểm tra trình duyệt desktop/mobile.

## Trạng thái

Người dùng đã duyệt thiết kế và yêu cầu triển khai ngày 2026-11-01. Các chi tiết ở phần thiết kế đề xuất được áp dụng theo bản duyệt này.
