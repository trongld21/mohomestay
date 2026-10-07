# Hướng dẫn Agent cho dự án Mơ Home

Đọc file này khi bắt đầu mỗi cuộc trò chuyện trong repository. Các quy tắc áp dụng cho mọi thay đổi, kể cả khi người dùng không nhắc lại. Đọc thêm `README.md` và `DEPLOY_DIRECTADMIN.md` trước khi sửa build/deploy.

## Giao diện và Tailwind

- Từ bây giờ, mọi giao diện mới và phần giao diện được sửa phải dùng **Tailwind CSS**.
- Ưu tiên utility classes trong template PHP/JavaScript. Dùng `@apply` trong `src/styles/tailwind.css` cho các trạng thái dùng chung, kể cả nút sinh động bằng JavaScript.
- `public/assets/app.css` là CSS cũ để giữ tương thích. Không thêm CSS mới vào file này; chuyển dần phần đang sửa sang Tailwind, không viết lại toàn bộ ngoài phạm vi yêu cầu.
- Không dùng Tailwind Play CDN trong production. CSS được build sẵn và commit tại `public/assets/tailwind.css`; không sửa trực tiếp file sinh ra này.
- Sau khi đổi template hoặc CSS: chạy `npm ci` nếu cần, rồi `npm run build:css`. Commit cả CSS đã build. Server PHP không cần Node.js.
- Giữ tông kem/nâu hiện có, layout responsive và hành vi đặt phòng/quản trị.
- Mọi nút và mục điều hướng phải có hover, focus bàn phím rõ ràng; không áp dụng hiệu ứng cho nút disabled. Tôn trọng `prefers-reduced-motion`.
- Kiểm tra `php tools/check.php`, `php tools/ui-test.php` và các test liên quan trước khi hoàn tất.

## Format file sau khi sửa

- Format các file nguồn đã chỉnh sửa trước khi hoàn tất; không chỉ thêm code rồi để indent lệch, đoạn code dài dồn một dòng hoặc hàng trăm khoảng trắng trước `function`.
- PHP, JavaScript, CSS: indent 4 spaces; JSON, YAML: indent 2 spaces. Dùng UTF-8, LF, newline cuối file, bỏ khoảng trắng cuối dòng theo `.editorconfig`.
- PHP template: giữ cấu trúc HTML/PHP dễ đọc; JavaScript: tách hàm, nhánh điều kiện và câu lệnh thành các dòng rõ ràng. Bảo toàn chuỗi, Unicode tiếng Việt, nội dung template và hành vi.
- Nếu repository có formatter được cấu hình, dùng formatter đó. Hiện chưa có lệnh `npm run format`: không tuyên bố đã chạy formatter không tồn tại. Format thủ công phần sửa nếu chưa có công cụ tương ứng.
- Không format lại toàn bộ repository ngoài phạm vi yêu cầu. Không format các file sinh tự động như `public/assets/tailwind.css`, `package-lock.json` bằng tay; dùng công cụ tạo file.
- Kiểm tra `git diff --check` và cú pháp sau khi format. Không dùng sửa whitespace hàng loạt có thể đổi nội dung chuỗi.

## Kiểm tra trước khi commit / deploy

1. Xem `git status`, giữ nguyên thay đổi của người dùng; không đưa `.env`, mật khẩu, khóa deploy, `node_modules/`, file preview/test tạm vào commit.
2. Nếu sửa template, JavaScript có utility classes hoặc Tailwind: chạy `npm run build:css`, đưa CSS đã build vào cùng commit với nguồn.
3. Chạy `php tools/check.php`, `php tools/ui-test.php`, `php tools/test.php`, `php tools/room-test.php`, `php tools/upload-test.php`; chạy test liên quan và `node --check` cho JS đã sửa. Sửa lỗi trước khi hoàn tất.
4. Kiểm tra UI thực tế nếu sửa giao diện: desktop, mobile, tương tác và lỗi console; báo rõ nếu chưa xác minh được.
5. Không tự push/deploy chỉ vì các quy tắc này. Khi người dùng yêu cầu push, push `main` sẽ kích hoạt deploy production; commit chỉ file thuộc thay đổi đã được yêu cầu.

## Deploy DirectAdmin: tránh lỗi đã gặp

- Workflow chính: `.github/workflows/deploy-directadmin.yml`. Server chạy PHP/MySQL; không cần Node.js hay source Tailwind.
- Gói deploy gồm `public/` → `.deploy/public_html/`, PHP runtime trong `src/` → `.deploy/src/`, migration trong `database/`.
- **Không upload `src/styles/`**: `src/styles/tailwind.css` là đầu vào build và deploy hook sẽ từ chối với HTTP 422. File cần upload là `public/assets/tailwind.css` đã build.
- Không ghi đè `public/uploads/` (ảnh do admin tải). `.htaccess` được cài thủ công theo `DEPLOY_DIRECTADMIN.md`, không upload qua hook.
- Trước khi thêm loại file vào gói deploy, đối chiếu whitelist trong `public/deploy-hook.php`. Không nới whitelist rộng hoặc bỏ xác thực để né lỗi. Kiểm tra toàn bộ đường dẫn gói trước khi upload.
- Giữ kiểm tra GET `?check=1` trước upload, timeout và retry từng file trong workflow. Không dùng HEAD/HTTP 200 chung để thay cho việc kiểm tra JSON hook.
- Chỉ chạy migration sau khi tất cả file upload thành công. Giữ schema migration idempotent; không reset database để sửa lỗi deploy.
- `curl (28)`: timeout kết nối/truyền dữ liệu; kiểm tra DNS, HTTPS, firewall và log hosting. Retry không đảm bảo xử lý được hosting chặn kết nối.
- HTTP 422 `Unsupported deploy path`: gói chứa đường dẫn không được phép; sửa gói/whitelist có chủ đích, không retry cùng file vô hạn.
- HTTP 401/403: kiểm tra secret `DEPLOY_HOOK_URL`, `DEPLOY_HOOK_KEY`, cấu hình hook và quyền truy cập; không in secret ra log.
- Không kết luận deploy thành công chỉ từ local test hoặc một file upload thành công. Xác minh job GitHub Actions, migration và website; nếu không có quyền xem job, báo rõ giới hạn.

## Môi trường local

- Database local `mohomestay` đã được dùng qua MySQL/MariaDB của XAMPP với cấu hình `.env`. Có cả dịch vụ MySQL94 trên máy: không tự bật dịch vụ này thay XAMPP hoặc chạy cả hai trên cổng 3306.
- Khi lỗi lịch phòng: kiểm tra kết nối thực tế từ `db()` và API `/api/availability` trước khi kết luận đã sửa. Trang có thể hiển thị phòng fallback dù database đang lỗi.
