# Chạy Lặng Home

1. `npm install`
2. Nếu chưa có `.env.local`, sao chép `.env.example` sang `.env.local`.
3. `npm run dev`, mở http://localhost:3000.

Giao diện chạy được khi chưa kết nối database. Để bật lịch thực, thanh toán QR và quản lý, làm theo [README](README.md). Không bật `BOOKINGS_ENABLED` trước khi xác nhận điều kiện đặt phòng và hoàn tất kết nối payOS.
