# Deploy Mơ qua DirectAdmin API + Cron (phương án hiện tại)

Đây là luồng được chọn sau khi xác nhận gói hosting không có SSH/SFTP và đối chiếu hai dự án
Tiệm Vàng Mai Hồng, Thanh Y Các. Không dùng runner Windows của TN và không cần token GitHub trên hosting.

## Luồng vận hành

Push main → GitHub Actions kiểm thử → cổng duyệt production → đóng một ZIP runtime →
DirectAdmin File Manager API nhận ZIP vào thư mục riêng → nhận marker HMAC cuối cùng →
Cron xác minh marker và checksum, kiểm tra PHP, backup và cập nhật file thay đổi → migration →
workflow kiểm tra đúng SHA/run đã kích hoạt và `/api/health`.

Không giải nén thẳng gói qua API lên website: Cron chỉ chọn file runtime trong allowlist,
không sửa `.env`, ảnh tải lên, `.htaccess`, hook cũ hoặc bộ kích hoạt đang chạy. Gói ZIP
chỉ chứa source runtime, không chứa khóa bí mật. Cách này cần 2 upload thay vì từng file;
không bảo đảm khắc phục mọi nguyên nhân chặn IP nếu chưa có log của nhà cung cấp.

## Cài một lần trên hosting

1. Backup database, `.env`, code và ảnh tải lên.
2. Upload/giải nén bộ cài gồm `deploy-activate.php`, `deploy-agent.php` (thư viện kiểm tra/backup),
   `deploy-activate.ini` vào `domains/mohomestay.vn/`, **ngang hàng `.env`**, không trong public_html.
   Giữ `ENABLED=false`. Không dùng `deploy-agent.ini` hoặc token GitHub của phương án cũ.
3. Trong Cron Jobs tạo tác vụ kiểm tra chạy mỗi phút (`*` cho cả 5 trường):

```sh
php "$HOME/domains/mohomestay.vn/deploy-activate.php" --check >> "$HOME/domains/mohomestay.vn/logs/deploy-activate.log" 2>&1
```

Lệnh chỉ kiểm tra PHP 8.1+, zip/pdo_mysql/mbstring và key đang có trong `.env`, tạo thư mục
riêng `.deploy-incoming`, không thay đổi website/database. Xem log để xác nhận PHP CLI đúng.
Nếu `php` mặc định không đúng phiên bản, dùng đường dẫn PHP CLI do hosting xác nhận.
Không suy đoán `/home` hay `/home2` từ username; `$HOME` lấy thư mục tài khoản thực tế.
4. Sau khi kiểm tra đạt, đặt `ENABLED=true` trong `deploy-activate.ini` và đổi cron sang:

```sh
php "$HOME/domains/mohomestay.vn/deploy-activate.php" --run >> "$HOME/domains/mohomestay.vn/logs/deploy-activate.log" 2>&1
```

Chỉ bật khi đã backup và sẵn sàng cho cập nhật; upload bộ cài chưa tự cập nhật website.
Không bật đồng thời cron kéo GitHub theo phương án cũ.

## Cấu hình GitHub environment production

- Variable `DA_URL`: URL HTTPS thật của panel, gồm cổng nếu có (thường 2222); cần hostname có chứng chỉ hợp lệ.
- Secret `DA_USERNAME`: tài khoản DirectAdmin quản lý domain Mơ.
- Secret `DA_LOGIN_KEY`: **Login Key riêng cho deploy**, chỉ cho phép `CMD_API_FILE_MANAGER`.
  Không dùng mật khẩu chính hoặc khóa của TN/Mai Hồng/Thanh Y Các.
- Secret `DEPLOY_HOOK_KEY`: khớp chính xác key có sẵn trong `.env` hosting để ký marker.
  Không sao chép toàn bộ `.env` local lên hosting.

Không gửi các secret vào chat hoặc commit. Cần nhà cung cấp xác nhận API File Manager được
phép và hạn mức upload phù hợp. Tải lên thư mục riêng qua API panel không thay đổi whitelist
của hook website cũ. API từ chối thì dừng, không nới quyền hoặc tắt TLS.

## Kiểm tra lần đầu

Sau khi local tests qua và được phép push, workflow mới cần đi qua gate production.
CI upload ZIP trước rồi marker; nếu tải ZIP lỗi sẽ không gửi marker. Cron chỉ nhận marker
được ký bằng key >=32 ký tự và gói có checksum SHA-256 đúng. File mới được kiểm tra hết
trước khi cập nhật. Chỉ bản có run_id mới hơn bản đã hoàn tất được kích hoạt.

Workflow chờ tối đa 18 lần, cách nhau 10 giây; timeout không có nghĩa hosting đã dừng.
Không chạy deploy khác trước khi kiểm tra trạng thái bản đang chờ.
Status công khai: `/deploy-status.json`, chỉ có SHA/run/trạng thái/thời gian/số file.
Kết quả phải khớp SHA và run hiện tại; không coi status success cũ là deploy mới thành công.

Nếu lỗi: marker đổi đuôi `.failed`, giữ ZIP và backup ngoài web trong `.deploy-incoming`.
`last-failure.json` chỉ ghi giai đoạn và loại lỗi, không ghi secret/SQL/dữ liệu khách.
Khôi phục file được thực hiện khi publish/migration báo lỗi; MySQL DDL có thể đã thay đổi
một phần nên **không có tự rollback database**. Rà soát schema và backup trước khi rerun.
Không tự retry activation thất bại mỗi phút. Sau xử lý nguyên nhân, rerun workflow tạo lại marker.

Giữ quản lý dung lượng backup, ZIP thất bại và log. Agent không tự xóa file cũ trên website.
Tắt bằng `ENABLED=false` hoặc tắt cron. Phần TTLock vẫn tắt riêng theo cấu hình hiện có.

## Kiểm thử

```sh
php tools/directadmin-deploy-test.php
php tools/deploy-agent-test.php
php tools/check.php
```

Tests dùng thư mục và ZIP tách biệt, mô phỏng API; không gọi DirectAdmin, không gửi token,
không chạy migration thật. Chưa xác minh API panel, key/quyền, PHP CLI, Cron và MySQL trên hosting.

---

# Lịch sử phương án trước — không cài đồng thời

# Deploy Mơ Home bằng agent trên hosting

Luồng mới: push `main` → GitHub Actions kiểm thử và duyệt environment `production` →
cron trên hosting đọc kết quả thành công → tải một ZIP cho đúng commit → kiểm tra toàn bộ
file → sao lưu/cập nhật file thay đổi → chạy migration. GitHub Actions không upload từng file
vào hosting nữa. Máy Windows không cần mở liên tục. Chưa có bằng chứng log khẳng định
nguyên nhân chặn IP cũ; hướng này loại bỏ chuỗi request upload vào website.

## Cài lần đầu — chưa tự bật

Thư mục theo File Manager: `domains/mohomestay.vn/`, đang chứa `.env`, `src`, `database`
và `public_html`. Không suy đoán tên tài khoản từ ảnh. Dùng `$HOME` trong lệnh cron hoặc
đường dẫn tuyệt đối được hosting xác nhận. Cần PHP CLI **8.1+** với `curl`, `zip`, `pdo_mysql`.
Thư mục `public_html` trong domain phải là thư mục thật, không phải symlink. Agent sẽ từ chối
các đường dẫn symlink để không ghi vượt thư mục đã chọn.

1. Backup database và mã nguồn hosting trước khi bật cập nhật. Giữ bản sao `.env` và ảnh tải lên.
2. Qua File Manager, upload **một file** `deploy-agent.php` vào `domains/mohomestay.vn/`,
   ngang hàng `.env`, không đặt trong `public_html`. Agent không có endpoint HTTP.
3. Tạo `deploy-agent.ini` tại cùng thư mục từ mẫu `deploy-agent.ini.example`:

```ini
REPOSITORY=trongld21/mohomestay
GITHUB_TOKEN=
ENABLED=false
```

4. Tạo GitHub **fine-grained personal access token**, chỉ chọn repo `mohomestay`, quyền
   **Actions: Read-only**, **Contents: Read-only**, Metadata mặc định. Đặt ngày hết hạn phù hợp.
   Dán token riêng trong `deploy-agent.ini` trên hosting; không gửi vào chat hay commit token.
   Đặt quyền file `600`; agent dùng token để đọc GitHub, không cần quyền ghi repository.
5. Lấy đường dẫn PHP CLI đúng từ nhà cung cấp hosting. Thử `--check` trước, rồi `--plan`.
   Nếu không có Terminal, dùng Cron Jobs mỗi 5 phút với một trong các lệnh bên dưới để
   lấy kết quả trong `logs/deploy-agent.log`, sau đó đổi lại/tắt tác vụ kiểm tra.
   Thay `/DUONG_DAN_PHP_CLI` bằng PHP CLI thật; đây là chỗ trống, không phải lệnh dùng ngay.

```sh
/DUONG_DAN_PHP_CLI "$HOME/domains/mohomestay.vn/deploy-agent.php" --check >> "$HOME/domains/mohomestay.vn/logs/deploy-agent.log" 2>&1
```

`--check` chỉ kiểm tra cấu hình và extension, không gọi mạng, không thay đổi website/database.

```sh
/DUONG_DAN_PHP_CLI "$HOME/domains/mohomestay.vn/deploy-agent.php" --plan >> "$HOME/domains/mohomestay.vn/logs/deploy-agent.log" 2>&1
```

`--plan` gọi GitHub và tải/kiểm tra commit đã qua CI nhưng không cập nhật website/database.
Bản code chứa workflow mới phải được push, hoàn tất kiểm thử và duyệt production trước
khi agent thấy bản đủ điều kiện. Việc kiểm thử local không xác nhận hosting chạy được agent.

6. Sau khi người quản lý xem kết quả `--plan` và đồng ý bật, đặt `ENABLED=true`. Tạo/đổi
   một Cron Job: Minute `*/5`, Hour `*`, Day of Month `*`, Month `*`, Day of Week `*`:

```sh
/DUONG_DAN_PHP_CLI "$HOME/domains/mohomestay.vn/deploy-agent.php" --run >> "$HOME/domains/mohomestay.vn/logs/deploy-agent.log" 2>&1
```

7. Kiểm tra log báo commit đã cập nhật và migration hoàn tất, sau đó xác minh `/api/health`,
   trang chủ, đặt phòng, đăng nhập quản trị và CSS/JS trên website. Không chỉ dựa vào CI xanh.
   Khi không có thay đổi/CI chưa thành công, cron giữ yên, không tải ZIP lại.

## Bảo vệ và phục hồi

- Chỉ nhận bản mới nhất của workflow `deploy-directadmin.yml`, tên `Prepare PHP deployment`,
  nhánh `main`, sự kiện push/manual, đã hoàn tất thành công. Không lấy bản CI cũ khi bản mới lỗi.
  Cổng duyệt `environment: production` trong GitHub Actions được giữ nguyên.
- Xác thực HTTPS, chỉ gửi bearer token tới `api.github.com`; link tải ZIP phải thuộc
  `codeload.github.com`, không chuyển tiếp token theo redirect. Không log token, mật khẩu, URL tải riêng.
- Không sửa `.env`, `public_html/uploads/`, `.htaccess`, deploy hook cũ hoặc agent đang chạy.
  Chỉ cập nhật runtime allowlist tương ứng cấu trúc triển khai hiện có. PHP/CSS/JS đã build
  nằm sẵn trong Git; không chạy npm hoặc lệnh shell từ gói tải về trên hosting.
- Không giải nén ZIP trực tiếp lên website. Giới hạn dung lượng/số file, cấm symlink, kiểm tra
  PHP, kiểm tra đủ file chính trước khi ghi. Chỉ file thay đổi mới được thay; không xóa file cũ.
- Agent khóa để tránh hai cron cùng chạy. ZIP tạm được xóa sau lần chạy; bản sao file thay đổi
  giữ ngoài web trong `.deploy-agent/backup-*`. Cần chủ động quản lý dung lượng backup/log.
- Nếu migration lỗi, agent khôi phục file đã thay và giữ bản backup. MySQL DDL có thể đã
  thay đổi một phần: **không tự rollback database**, phải kiểm tra schema/backup trước khi thử lại.
  Cập nhật từng file không phải chuyển release nguyên khối; tránh chạy trong giờ nhiều khách.
- Cùng commit lỗi quá 3 lần sẽ dừng. Sau khi kiểm tra nguyên nhân, có thể sửa `attempts` về `0`
  trong `.deploy-agent/state.json` để thử lại; không xóa thông tin commit đã deploy.
- Tắt khẩn cấp bằng `ENABLED=false` hoặc tắt Cron Job. Không cần thay mật khẩu ứng dụng.
- Agent là file cài thủ công và không tự cập nhật chính nó. Khi sửa agent, upload lại file này
  sau khi xem thay đổi. Cơ chế TTLock vẫn mặc định tắt và cần kiểm tra khóa thật riêng.

## Kiểm thử local

```sh
php tools/deploy-agent-test.php
php tools/check.php
```

Kiểm thử agent dùng ZIP và thư mục giả lập, không gọi GitHub/hosting và không chạy migration thật.
Có kiểm tra chọn file, bảo vệ dữ liệu, chỉ chấp nhận CI hợp lệ, lỗi PHP và khôi phục khi migration lỗi.
Chưa xác minh token GitHub, đường dẫn PHP CLI, extension, cron hoặc quyền ghi trên hosting thật.

---

# Tài liệu cơ chế HTTPS cũ — không còn là luồng tự động mặc định

Các mục bên dưới chỉ phục vụ đối chiếu cấu hình cũ. Workflow mới không thực hiện upload
hoặc gọi migration qua hook. Không chạy lại workflow upload cũ đồng thời với agent.

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
   Để gửi email xác nhận, tạo trước một hộp thư cùng tên miền trong **E-mail Manager → E-mail Accounts**, rồi thêm `MAIL_FROM=booking@<domain>` và `MAIL_REPLY_TO=<email nhận phản hồi>` vào `.env`.
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
- Thanh toán thử một đơn, kiểm tra trang `/payment` tự khôi phục đúng đơn và email xác nhận có mã đơn, phòng, gói, giờ nhận/trả cùng tổng tiền. Nếu thư vào spam, bật SPF/DKIM trong **E-mail Manager → SPF/DKIM** của DirectAdmin.
- Giữ `BOOKINGS_ENABLED=false` đến khi payOS, giờ qua đêm và điều khoản được cấu hình xong.

## Lưu ý

- Không commit hoặc đặt `.env` trong `public_html`.
- Nếu upload báo HTTP 413, tăng `upload_max_filesize` và `post_max_size` trong PHP Settings của DirectAdmin.
- Nếu báo 404 ở bước upload, kiểm tra `DEPLOY_HOOK_KEY` trên server/GitHub và chắc chắn file hook mới đã được upload thủ công.
- Nếu báo 422 `Unsupported deploy path` tại `apple-touch-icon.svg` hoặc `favicon.svg`, server vẫn đang chạy allowlist của hook cũ. Workflow mới upload hook trước và chờ `assetSvgAllowed: true`; nếu chính bước upload hook thất bại, thay `public_html/deploy-hook.php` thủ công bằng file mới rồi chạy lại workflow.
- Nếu migration lỗi, kiểm tra các biến `DB_*` và extension `pdo_mysql`.
- Workflow không tự xóa file cũ; cần backup database định kỳ.
- Sao lưu cả database và `public_html/uploads/rooms/`; ảnh upload không nằm trong Git.

### HTTP 422: `public_html/p.zip`

ZIP archives are not runtime files accepted by the hook. The workflow excludes ZIP files from the public package and validates every package path with `php public/deploy-hook.php --check-package .deploy` before uploading. Keep ZIP uploads disabled in the hook. If another path is rejected, correct the package to match the allowlist before running the workflow again.
