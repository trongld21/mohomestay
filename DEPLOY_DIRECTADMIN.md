# Deploy Lặng Home lên DirectAdmin

Ứng dụng production là PHP 8.1+ thuần, MySQL/MariaDB và không cần Node.js hay Composer trên hosting.

## 1. Tạo database

Trong DirectAdmin, vào **Account Manager → MySQL Management** rồi tạo database và user. Ghi lại hostname (thường là `localhost`), tên database, user và mật khẩu.

## 2. Deploy lần đầu

Workflow upload theo cấu trúc:

```text
domains/ten-mien-cua-ban/
├── .env                       # nằm ngoài public_html
├── src/
├── database/
├── tools/
└── public_html/
    ├── index.php
    ├── .htaccess
    ├── assets/
    └── images/
```

Trong GitHub repository, vào **Settings → Secrets and variables → Actions**, tạo:

| Secret | Giá trị |
|---|---|
| `FTP_SERVER` | Host FTP DirectAdmin, không thêm `ftp://` |
| `FTP_USERNAME` | Tài khoản FTP |
| `FTP_PASSWORD` | Mật khẩu FTP |
| `DEPLOY_REMOTE_DIR` | Ví dụ `domains/example.com/` — phải có `/` cuối |
| `DEPLOY_HOOK_URL` | `https://example.com/deploy-hook.php` |
| `DEPLOY_HOOK_KEY` | Giống `DEPLOY_HOOK_KEY` trong `.env` |

Workflow dùng FTPS explicit. Nếu host chỉ bật FTP thường, đổi `protocol: ftps` thành `ftp` (không khuyến nghị vì dữ liệu truyền không được mã hóa).

Push nhánh `main` hoặc chọn **Actions → Deploy PHP to DirectAdmin → Run workflow**. Lần đầu sẽ upload mã nguồn; deploy hook chỉ hoạt động sau khi tạo `.env`.

## 3. Tạo `.env`

Trong File Manager, tại thư mục domain (cùng cấp `public_html`), sao chép `.env.example` thành `.env`, rồi điền:

- `APP_URL`: domain HTTPS thật, không có `/` cuối.
- `APP_KEY`, `DEPLOY_HOOK_KEY`: hai chuỗi ngẫu nhiên khác nhau, tối thiểu 32 ký tự.
- Các biến `DB_*`: thông tin database DirectAdmin.
- `ADMIN_EMAIL` và `ADMIN_PASSWORD_HASH`.
- giờ qua đêm, điều khoản và khóa payOS.

Tạo hash mật khẩu ở máy có PHP: `php tools/password.php`.

Giữ `BOOKINGS_ENABLED=false` cho đến khi migration, payOS, giờ nhận/trả phòng và điều khoản đều đúng. `.env` bị Git ignore và workflow loại trừ nên deploy không ghi đè bí mật.

## 4. Khởi tạo database và auto deploy

Sau khi có `.env`, chạy lại workflow. Bước cuối gọi deploy hook và tự áp dụng migration. Kiểm tra:

- `https://example.com/api/health` trả JSON `status: ok`.
- Trang `/calendar`, `/bookings`, `/auth/login` hoạt động.
- PHP có `pdo_mysql`, `curl`, `mbstring`, `openssl`.
- Webhook payOS là `https://example.com/api/payments/webhook`.

Khi hoàn tất, đổi `BOOKINGS_ENABLED=true`. Mỗi push sau sẽ kiểm tra syntax, upload phần thay đổi và chạy migration.

## Lưu ý

- Không đặt `.env` trong `public_html`.
- Bật SSL/Let's Encrypt trước khi nhận thanh toán.
- Nếu route 404, kiểm tra Apache cho phép `.htaccess` và rewrite.
- Workflow không dùng “clean slate”, nhưng vẫn cần backup database định kỳ.
