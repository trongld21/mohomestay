# Deploy Lặng Home lên DirectAdmin

Ứng dụng dùng PHP 8.1+ và MySQL/MariaDB. Workflow deploy qua HTTPS vì hosting không mở FTP/SFTP ra Internet.

## Cấu trúc trên server

```text
domains/ten-mien-cua-ban/
├── .env                       # không nằm trong public_html
├── src/
├── database/
└── public_html/
    ├── deploy-hook.php
    ├── index.php
    ├── .htaccess
    ├── assets/
    └── images/
```

## Cài đặt lần đầu

1. Trong DirectAdmin tạo database và user MySQL.
2. Upload `.env` vào `domains/<domain>/.env`.
3. Upload duy nhất file `public/deploy-hook.php` của repository vào `domains/<domain>/public_html/deploy-hook.php`.
4. Nếu DirectAdmin hỏi ghi đè file, chọn xác nhận.
5. Đảm bảo website đã có HTTPS hợp lệ.

Deploy hook là file độc lập, nên lần đầu chưa cần upload `src` hoặc các file khác. PHP cần cho phép upload ít nhất 10 MB; gói hiện tại nhỏ hơn giới hạn này.

## GitHub Secrets

Vào **Settings → Secrets and variables → Actions** và tạo:

| Secret | Giá trị |
|---|---|
| `DEPLOY_HOOK_URL` | `https://example.com/deploy-hook.php` |
| `DEPLOY_HOOK_KEY` | Giống chính xác `DEPLOY_HOOK_KEY` trong `.env` |

Các secret FTP cũ không còn được sử dụng và có thể xóa.

## Chạy deploy

Push nhánh `main`, hoặc chọn **Actions → Deploy PHP to DirectAdmin → Run workflow**. Workflow sẽ:

1. Kiểm tra PHP và chạy unit test.
2. Đóng gói đúng cấu trúc DirectAdmin.
3. Upload từng file qua HTTPS với chữ ký HMAC SHA-256.
4. Gọi deploy hook để tự chạy migration MySQL.

Deploy hook chỉ nhận các đường dẫn nằm trong danh sách cho phép, từ chối `.env`, giới hạn kích thước file và kiểm tra chữ ký trước khi ghi.

## Kiểm tra

- `https://example.com/api/health` trả JSON có `status: ok`.
- Kiểm tra `/`, `/calendar`, `/bookings`, `/auth/login`.
- Webhook payOS: `https://example.com/api/payments/webhook`.
- Giữ `BOOKINGS_ENABLED=false` đến khi payOS, giờ qua đêm và điều khoản được cấu hình xong.

## Lưu ý

- Không commit hoặc đặt `.env` trong `public_html`.
- Nếu upload báo HTTP 413, tăng `upload_max_filesize` và `post_max_size` trong PHP Settings của DirectAdmin.
- Nếu báo 404 ở bước upload, kiểm tra `DEPLOY_HOOK_KEY` trên server/GitHub và chắc chắn file hook mới đã được upload thủ công.
- Nếu migration lỗi, kiểm tra các biến `DB_*` và extension `pdo_mysql`.
- Workflow không tự xóa file cũ; cần backup database định kỳ.
