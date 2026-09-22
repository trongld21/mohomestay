# Deploy Mơ Home lên DirectAdmin

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
    ├── images/
    └── uploads/rooms/          # ảnh do admin tải, không bị deploy ghi đè
```

## Cài đặt lần đầu

1. Trong DirectAdmin tạo database và user MySQL.
2. Upload `.env` vào `domains/<domain>/.env`.
3. Upload `public/deploy-hook.php` vào `domains/<domain>/public_html/deploy-hook.php`.
4. Upload `public/.htaccess` vào `domains/<domain>/public_html/.htaccess`. File này được cài thủ công một lần vì ModSecurity thường chặn upload `.htaccess` qua HTTP.
5. Tạo `public_html/uploads/`, upload `public/uploads/.htaccess` vào đó và tạo thư mục `public_html/uploads/rooms/`. Đặt quyền thư mục `755`; nếu PHP không ghi được ảnh thì dùng `775` theo cấu hình user Apache/PHP của hosting. Không dùng `777` nếu không thật sự bắt buộc.
6. Nếu DirectAdmin hỏi ghi đè file, chọn xác nhận.
7. Đảm bảo website đã có HTTPS hợp lệ.
8. Mở `https://<domain>/deploy-hook.php?check=1`; sau lần deploy mới phải thấy `hook: https-v3`, `envLoaded: true`, `keyConfigured: true`, `assetSvgAllowed: true`.

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
3. Upload `deploy-hook.php` trước, xác nhận hook mới hỗ trợ asset SVG, sau đó upload các file còn lại qua HTTPS với chữ ký HMAC SHA-256.
4. Gọi deploy hook để tự chạy migration MySQL.

Workflow cố ý loại toàn bộ `public/uploads/` khỏi gói deploy. Ảnh phòng do admin tải lên sẽ được giữ nguyên qua mọi lần push.

Deploy hook chỉ nhận các đường dẫn nằm trong danh sách cho phép, từ chối `.env`, giới hạn kích thước file và kiểm tra chữ ký trước khi ghi.

## Kiểm tra

- `https://example.com/api/health` trả JSON có `status: ok`.
- Kiểm tra `/images/mo-home-illustration.png`, `/`, `/rooms`, một URL chi tiết phòng, `/calendar`, `/bookings`, `/auth/login`.
- Đăng nhập `/admin`, mở tab **Quản lý phòng**, thử thêm một phòng ẩn, tải ảnh, sửa tag/gói giá rồi xóa phòng thử nghiệm.
- Nút **Xem trước** trong admin mở cả phòng đang ẩn; URL preview yêu cầu đăng nhập và không được lập chỉ mục.
- Chạy migration `002_room_management.sql` trước khi sử dụng tab phòng; workflow sẽ tự làm bước này sau upload.
- Webhook payOS: `https://example.com/api/payments/webhook`.
- Giữ `BOOKINGS_ENABLED=false` đến khi payOS, giờ qua đêm và điều khoản được cấu hình xong.

## Lưu ý

- Không commit hoặc đặt `.env` trong `public_html`.
- Nếu upload báo HTTP 413, tăng `upload_max_filesize` và `post_max_size` trong PHP Settings của DirectAdmin.
- Nếu báo 404 ở bước upload, kiểm tra `DEPLOY_HOOK_KEY` trên server/GitHub và chắc chắn file hook mới đã được upload thủ công.
- Nếu báo 422 `Unsupported deploy path` tại `apple-touch-icon.svg` hoặc `favicon.svg`, server vẫn đang chạy allowlist của hook cũ. Workflow mới upload hook trước và chờ `assetSvgAllowed: true`; nếu chính bước upload hook thất bại, thay `public_html/deploy-hook.php` thủ công bằng file mới rồi chạy lại workflow.
- Nếu migration lỗi, kiểm tra các biến `DB_*` và extension `pdo_mysql`.
- Workflow không tự xóa file cũ; cần backup database định kỳ.
- Sao lưu cả database và `public_html/uploads/rooms/`; ảnh upload không nằm trong Git.
