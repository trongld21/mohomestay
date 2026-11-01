# Nhân sự và tính lương Mơ Home

## Phạm vi đã thống nhất

Chỉ Super Admin truy cập `/admin/personnel`, `/admin/payroll` và `/admin/timesheet`, kể cả gọi API
trực tiếp. Hồ sơ nhân viên độc lập với tài khoản đăng nhập: mã duy nhất, họ tên,
điện thoại, email, vị trí, ngày vào/nghỉ, trạng thái, ghi chú và lương giờ/tháng.
Ngừng sử dụng hồ sơ bằng trạng thái nghỉ việc, giữ lịch sử.
Cột thao tác dùng icon sửa và xóa, có nhãn hỗ trợ bàn phím/trình đọc màn hình.
Sửa hồ sơ cần bấm Lưu hồ sơ rồi xác nhận; hủy xác nhận giữ nguyên dữ liệu đang sửa.
Xóa cần xác nhận và kiểm tra phiên bản. Hồ sơ chuyển sang trạng thái `deleted`,
ẩn khỏi danh sách, chọn nhân viên và đối chiếu chấm công; không cho khôi phục qua
API sửa hoặc thêm vào kỳ mới. Các dòng lương đã có vẫn giữ nguyên và tiếp tục
dùng snapshot của kỳ, kể cả khi hồ sơ bị xóa. Không xóa tài khoản đăng nhập.
Mã nhân viên do máy chủ tự tạo dạng `NV-…`; form không có ô nhập mã. Khi sửa
hồ sơ, mã hiện có được giữ nguyên, kể cả mã cũ đã nhập thủ công.

Kỳ lương có tên và ngày đầu/cuối; luôn cho phép chỉnh sửa. Mỗi nhân viên có
một dòng trong kỳ, sao chép thông tin và mức lương khi thêm; thay đổi hồ sơ không
đổi kỳ cũ. Cho sửa cấu hình riêng trên từng dòng lương.

Ca làm nhập ngày và giờ vào/ra; giờ ra nhỏ hơn giờ vào là qua đêm.
Giao diện không có cột Nghỉ. Ca mới mặc định không trừ nghỉ; dữ liệu phút nghỉ cũ
được giữ để không tự đổi tiền lương khi sửa các trường khác.
Không chấp nhận ca chồng lấn, ca bằng 0 giờ, ca ngoài kỳ hoặc phút nghỉ hết ca.
Lương giờ = tổng (phút thực làm từng ca × đơn giá của ca) / 60, làm tròn một lần
đến đồng. Ca không có đơn giá riêng dùng đơn giá mặc định của dòng lương.
Lương tháng mặc định trả đủ; nếu chọn tính theo ngày công thì bằng lương tháng
× min(ngày công thực tế + nghỉ hưởng lương, ngày công chuẩn) / ngày công chuẩn.
Ngày công thực tế đếm các ngày bắt đầu ca khác nhau.
Thực nhận = max(0, lương cơ bản + phụ cấp − khấu trừ).
Các khoản cộng/trừ phải có tên và số tiền nguyên không âm.

Cột **Lương/h** thay tên Đơn giá. Ba cột Lương cơ bản, Phụ cấp / thưởng, Khấu trừ
mặc định không tích trong Cột hiển thị; Bonus luôn hiển thị cạnh Lương/h.
Bonus là số giờ T7/CN, không phải số tiền. Hệ thống đếm phút theo ngày bắt đầu ca,
hiển thị giờ thập phân hai chữ số. Có thể sửa giờ Bonus trực tiếp rồi xác nhận;
xóa giá trị để trở lại tự tính. Giờ nhập tay không vượt tổng giờ làm và tối đa
hai chữ số thập phân. Lương giờ được cộng tiền Bonus = giờ Bonus × mức cộng
T7/CN (mặc định 1.000đ/giờ, sửa tại form lương của nhân viên trong kỳ).
Khi tự tính, tiền Bonus dùng số phút chính xác, không dùng giờ đã làm tròn.
Tiền Bonus được cộng vào tổng phụ cấp/thưởng đúng một lần và có cột riêng khi
xuất Excel. Nhân viên lương tháng không áp dụng Bonus giờ. Dữ liệu kỳ cũ chưa có
cấu hình Bonus sẽ áp dụng mặc định khi lưu/tính lại; không tự cập nhật tiền cũ
chỉ do mở trang. Giờ Bonus nhập tay được giữ khi sửa/nhập ca và kiểm tra lại với
tổng ca mới; nếu vượt tổng giờ, phải điều chỉnh hoặc xóa giờ Bonus nhập tay.

Không có chức năng chốt hay khóa kỳ. Kỳ từng được chốt trong phiên bản cũ vẫn
cho sửa, nhập ca, đổi tên và xóa. Các cột trạng thái/thông tin chốt cũ giữ lại để
tương thích dữ liệu, không giới hạn thao tác. Máy chủ vẫn khóa giao dịch và kiểm
tra phiên bản khi ghi để tránh ghi đè thay đổi đồng thời.
Không tự tạo phiếu chi hoặc chuyển tiền. Không nhập dữ liệu thật từ tncompany.

Màn Bảng lương luôn hiển thị form tạo kỳ phía trên danh sách. Tùy chọn thêm nhân
viên đang làm được bật sẵn; chỉ lấy nhân viên có ngày làm việc giao với kỳ, sao
chép mức lương trong một transaction. Có thể bỏ chọn để tạo kỳ rỗng.
Danh sách hiển thị 10 kỳ mỗi trang, giữ bộ lọc/trang trong URL. Mọi kỳ có thể đổi
tên hoặc xóa (cần xác nhận). Xóa kỳ lương xóa
toàn bộ dòng lương của kỳ, giữ hồ sơ nhân viên. Kiểm tra phiên bản khi đổi/xóa.
Tab Chấm công và mục sidebar mở trang riêng để chọn nguồn máy chấm công hoặc
Excel nhân viên. Phần nhập và xác nhận ca trong kỳ vẫn có tại
**Nhập ca / sửa** của từng nhân viên.

## Thiết kế và triển khai

Chi tiết kỳ lương gồm tổng thực nhận, số nhân viên, giờ thực làm, tổng ngày công
và số người chưa có ca. Nút Thêm nhân viên mở form ngay trong phần tổng quan;
mọi kỳ đều có thể thêm nhân viên và xóa dòng. Bảng rà soát có tìm theo tên/mã/vị trí,
lọc vị trí, lọc nhanh chưa có ca/theo giờ/lương tháng, sắp theo giờ/tên/thực nhận
và tùy chọn cột lương cơ bản/phụ cấp/khấu trừ. Tổng ở chân bảng tính trên các dòng
đang lọc, tổng phía trên luôn tính toàn kỳ. Giờ hiển thị từ phút chấm công, nút
lịch mở chi tiết ca để chỉnh, không nhập tổng giờ tách rời ca.
Nút lịch mở dialog Chi tiết chấm công riêng: số ca, số dòng cần sửa, giờ thực làm;
thêm/xóa ca, ngày và giờ vào/ra 24 giờ. Nút đổi giờ dùng icon hai chiều, có trạng
thái hover/nhấn và hiệu ứng quay nhẹ, tôn trọng giảm chuyển động. Tổng cập nhật ngay khi sửa,
nhưng chỉ ghi database sau Lưu thay đổi và xác nhận. Hủy/đóng khi đang sửa cần
xác nhận bỏ thay đổi. Lưu giữ nguyên cấu hình lương, phụ cấp và khấu trừ, kiểm tra
phiên bản kỳ tại server; lỗi không làm mất dữ liệu đang nhập. Mọi kỳ đều sửa được, kể cả kỳ mang trạng thái chốt từ bản cũ.
Popup dùng dialog native để giữ focus bàn phím, trả focus về nút lịch khi đóng.
Khi có nhiều ca, cột TT có tay nắm để kéo đổi thứ tự, hỗ trợ chuột/cảm ứng và tự
cuộn vùng danh sách ở mép. Có thể dùng phím ↑/↓, Home/End khi focus tay nắm.
TT tự đánh lại, giờ và đơn giá đi cùng ca; đổi thứ tự không thay tổng giờ/tiền.
Thứ tự chỉ ghi lại sau Lưu thay đổi; hủy giữ thứ tự trước đó.
Ngoài nút Thêm ca cuối popup, dấu + giữa hai hàng cho phép chèn ca đúng vị trí.
Nút hiện khi hover/focus hàng, luôn hiện trên thiết bị không có hover. Ca chèn
mặc định theo ngày của hàng phía trên, giờ trống để nhập; giới hạn vẫn là 200 ca.
Với nhân viên lương giờ, dưới số giờ từng ca có ô đơn giá ngắn và đơn vị đ/h cùng
một hàng, không hiện nhãn “Đơn giá riêng”. Ô trống kế thừa đơn giá mặc định; nhập
số nguyên từ 0 đến 1 tỷ để áp dụng cho riêng ca (0 là đơn giá hợp lệ). Cấu hình
lưu trong `details.shifts[].hourly_rate`, giữ nguyên khi sửa qua form chung.
Nhân viên lương tháng không dùng đơn giá theo ca. Bảng lương ghi rõ khi tính theo
đơn giá từng ca, file xuất ghi cột đơn giá mặc định và tổng tiền đã tính đúng.
Chân popup chấm công không có nút Đóng/Hủy; dùng dấu × hoặc Escape, có xác nhận
nếu còn thay đổi chưa lưu.

Ngay tại đầu Chi tiết bảng lương có menu **Thao tác**, gồm Xuất Excel, Upload Excel
nhân viên và Thêm nhân viên. Menu mở trên lớp popover, hỗ trợ phím mũi tên,
Home/End, Escape và tự đóng khi chọn mục hoặc bấm bên ngoài.
Trong **Upload Excel nhân viên**, chọn nhân viên
đã có trong kỳ rồi tải file `.xlsx` tự chấm công (tối đa 5 MB). Kết quả xem trước
gồm ca, tổng giờ và thực nhận theo cấu hình web; tiền/công thức của file không nhập.
Dòng lỗi chặn nút xác nhận. Khi xác nhận trong popup, toàn bộ ca của đúng nhân viên
trong kỳ được thay thế; đơn giá, phụ cấp/khấu trừ giữ nguyên. Hủy xác nhận giữ bản
xem trước. Kiểm tra phiên bản khi xem/lưu để tránh ghi đè dữ liệu mới; thay đổi kỳ
làm vô hiệu bản xem trước cũ. Có thể dùng biến `PAYROLL_EXCEL_SAMPLE` khi chạy test
trình duyệt để kiểm tra một file mẫu thực tế ngoài repository mà không sửa file đó.

Xuất Excel tải file XLSX thật của toàn kỳ (không phụ thuộc bộ lọc hiển thị), gồm
mức lương snapshot, giờ dạng số thập phân, ngày công, các khoản cộng/trừ và thực nhận.
`src/payroll-export.php` cần PHP zip, chỉ đọc dữ liệu và xóa file tạm sau khi tạo.
Tên nhân viên được ghi dưới dạng chuỗi, không thực thi như công thức Excel.

Giữ khung quản trị hiện có. Hallmark áp dụng cho hai màn hình công việc, dùng bảng,
biểu mẫu rõ nhãn, số tiền căn phải, màu kem/nâu từ hệ thống. Nhân sự có tìm kiếm và
lọc trạng thái; kỳ lương có trang chi tiết và sửa dòng lương ngay trong trang.
Hiển thị trạng thái đang tải, rỗng, lỗi và có dữ liệu; URL giữ màn và kỳ đang chọn.

- `database/migrations/030_personnel_payroll.sql`: bảng nhân viên, kỳ và dòng lương.
- `src/payroll.php`: kiểm tra đầu vào, tính lương, giao dịch và API.
- `src/payroll-view.php`, `public/assets/payroll.js`: giao diện PHP/JavaScript.
- `src/styles/payroll.css`: token và component Tailwind; không sửa CSS cũ.
- `tools/payroll-test.php`: kiểm tra công thức, dữ liệu sai, snapshot và khóa kỳ.

PHP/JS/CSS dùng 4 spaces, UTF-8, LF. SQL dùng truy vấn có tham số; mọi thao tác
ghi kiểm tra CSRF; dữ liệu hiển thị được escape. Không thêm thư viện runtime.

## Kiểm tra và vận hành

Chạy `php tools/migrate.php` trước khi dùng module. Migration chỉ thêm bảng,
idempotent, không sửa dữ liệu phòng hoặc tài khoản. Nhân sự chưa có dữ liệu sẽ rỗng.

Lệnh kiểm tra:

```sh
php tools/payroll-test.php
php tools/check.php
php tools/ui-test.php
php tools/test.php
php tools/room-test.php
php tools/upload-test.php
node --check public/assets/payroll.js
node --check public/assets/payroll-dates.js
node tools/admin-date-picker-test.cjs
php tools/payroll-import-test.php
npm run build:css
git diff --check
```

Kiểm thử trình duyệt bằng Playwright và Chrome đã cài, khi PHP server local đang
chạy ở `http://127.0.0.1:8098`:

```sh
node tools/payroll-browser-test.cjs
```

Máy chạy test cần có package `playwright` (có thể cung cấp qua `NODE_PATH`).
Đổi server bằng `PAYROLL_TEST_URL` (chỉ localhost), trình duyệt bằng
`PAYROLL_BROWSER_CHANNEL`, thư mục ảnh bằng `PAYROLL_ARTIFACT_DIR`.
Test tạo phiên và hồ sơ QA tạm trong database local, rồi xóa đúng các bản ghi đó
trong `finally`. Không chạy trên production. Bộ test PHP dùng SQLite trong bộ nhớ.

## Nhập chấm công Excel

Trong kỳ lương, thêm nhân viên rồi mở **Nhập ca / sửa** và chọn file `.xlsx` tối đa
5 MB. Hosting cần PHP `zip` và `SimpleXML`. File cần đúng một sheet có các cột
Ngày làm (hoặc Ngày), Check-in, Check-out; tối đa 200 ca. Hỗ trợ ngày Excel hoặc
`dd/mm/yyyy`, `yyyy-mm-dd`; giờ Excel hoặc `9:03`. Không chạy công thức nhập liệu,
macro hay liên kết ngoài. Dòng trống được bỏ qua, dòng thiếu dữ liệu/ngoài kỳ hoặc
ca chồng lấn phải sửa trước khi xác nhận. Nếu file có nhiều sheet chấm công, tách
riêng sheet cần nhập để tránh ghép nhầm nhân viên.

Xem trước chưa ghi database. Xác nhận thay toàn bộ ca của đúng nhân viên đang mở,
giữ nguyên mức lương và phụ cấp/khấu trừ đã lưu trong kỳ. Tiền và công thức trong
file không được nhập; server tính lại theo cấu hình web. Chưa hỗ trợ tự nhập mức
thưởng cuối tuần của Excel. Giờ nghỉ mặc định 0; chỉnh lại ca sau nhập nếu cần.
File chỉ đọc tại upload tạm của PHP, không lưu vào public/uploads. Mọi kỳ đều
cho nhập chấm công. Phiên bản kỳ được kiểm tra lại khi lưu để tránh ghi đè thay đổi.

Kiểm tra desktop/mobile, thêm/sửa/nghỉ việc, tạo kỳ, ca qua đêm, phụ cấp/khấu trừ,
sửa kỳ cũ từng bị khóa, từ chối nhân viên truy cập và lỗi console. Chỉ push/deploy khi có yêu cầu.

## Nhập chấm công TXT như tncompany

Trang riêng **Chấm công** tại `/admin/timesheet`, truy cập từ sidebar hoặc tab
**Chấm công** trên danh sách kỳ lương. Có hai lựa chọn riêng:
**Máy chấm công (.txt)** xử lý nhiều nhân viên từ file máy; **Excel nhân viên
(.xlsx)** yêu cầu chọn hồ sơ nhân viên trước, đọc cột Ngày làm, Check-in,
Check-out như mẫu Excel hiện có. Chọn file và khoảng ngày
(hoặc Đợt 1: ngày 1–15, Đợt 2: ngày 16–cuối tháng theo giờ Việt Nam), rồi chọn
**Tính giờ làm**. Kết quả gồm tất cả nhân viên trong file, tổng giờ hợp lệ, giờ
cuối tuần, ca chi tiết và lỗi từng dòng. Đối chiếu mã/tên với hồ sơ nhân sự và
báo rõ khi chưa khớp hoặc khớp nhiều hồ sơ. Thay file/ngày sẽ bỏ kết quả cũ.
Trang này chỉ tính và xem trước, không tạo hồ sơ hay ghi ca vào bảng lương;
luồng xác nhận nhập ca trong từng nhân viên của kỳ lương vẫn được giữ.
API dùng multipart `action=timesheet/preview` tại `/api/admin/payroll`, có kiểm
tra CSRF và quyền superadmin. Parser đọc file một lần rồi tính riêng từng nhóm.

Ở chế độ máy chấm công, chọn file `.txt` UTF-8 tối đa 5 MB. File phân cách
bằng tab, dấu chấm phẩy hoặc dấu phẩy; mã nhân viên ở cột 3, tên ở cột 4,
thời điểm quẹt ở cột 7. Hỗ trợ `yyyy-mm-dd HH:mm:ss` hoặc `dd/mm/yyyy HH:mm:ss`
(có thể bỏ giây). Tiêu đề cột 7 `DateTime` được bỏ qua.

Hệ thống nhóm theo mã và tên trong file, ưu tiên chọn khi mã hoặc tên khớp duy nhất
với hồ sơ đang mở. Luôn kiểm tra mục **Nhân viên trong file TXT**; có thể chọn lại
nếu mã máy chấm công khác mã tự sinh. Dữ liệu của các nhân viên khác không được
ghép vào hồ sơ này. Mỗi lần xác nhận chỉ nhập một nhân viên, không tự tạo hồ sơ.

Các lần quẹt được sắp xếp tăng dần và ghép từng cặp như tncompany. Mốc kết thúc
ngày công là 05:00: quẹt vào từ 05:00 ghép tối đa đến 05:00 hôm sau; quẹt vào
trước 05:00 ghép tối đa đến 05:00 cùng ngày. Giây được bỏ, tính lương đến phút.
Lần quẹt ngoài khoảng kỳ (kể cả giới hạn ca đêm đến 05:00 sau ngày cuối) được đếm
và báo là bỏ qua. Thiếu giờ ra, ngày giờ sai, thời gian trùng hoặc ca chồng lấn
sẽ chặn xác nhận. Tối đa 20.000 dòng, 500 nhân viên trong file và 200 ca mỗi lần nhập.

Luồng xem trước/xác nhận, bảo toàn cấu hình lương, phụ cấp/khấu trừ, CSRF và
giới hạn superadmin giống nhập Excel. File TXT không được lưu lâu dài trên server.
