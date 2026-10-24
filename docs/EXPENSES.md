# Phiếu chi

Trang `/admin/expenses` nằm trong nhóm Bán hàng. Cấp `expenses.view` để xem và `expenses.create` để lập phiếu; quyền lập phiếu đi kèm quyền xem theo cơ chế tài khoản hiện có.

Lập phiếu ghi thời gian chi (giờ Việt Nam), loại chi, số tiền nguyên 1–1.000.000.000đ, người nhận và ghi chú. Mã PC tự sinh; lưu người tạo và thời gian tạo. Gửi lại cùng yêu cầu không tạo trùng. Phiếu đã lưu giữ nguyên để tra cứu.

Mặc định xem hôm nay; lọc từ/đến ngày (tối đa 367 ngày) và tìm theo mã phiếu, loại chi, người nhận hoặc ghi chú. Danh sách cũ trước, phân trang 30 phiếu. Tổng đã chi gồm mọi phiếu trong bộ lọc. Tổng chi dòng tiền chỉ gồm phiếu tích “Hạch toán vào dòng tiền trong ngày”, theo ngày chi, không theo ngày nhập phiếu. Phiếu chi không thay đổi doanh thu, tồn kho hoặc tự thực hiện chuyển tiền.

Chạy migration `025_expense_vouchers.sql` bằng công cụ migration hiện có khi triển khai. Thời gian lưu UTC, hiển thị và lọc theo Việt Nam (UTC+7).
