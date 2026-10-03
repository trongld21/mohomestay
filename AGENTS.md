# Quy tắc giao diện Mơ Home

- Từ bây giờ, mọi giao diện mới và phần giao diện được sửa phải dùng **Tailwind CSS**.
- Ưu tiên utility classes trong template PHP/JavaScript. Dùng `@apply` trong `src/styles/tailwind.css` cho các trạng thái dùng chung, kể cả nút sinh động bằng JavaScript.
- `public/assets/app.css` là CSS cũ để giữ tương thích. Không thêm CSS mới vào file này; chuyển dần phần đang sửa sang Tailwind, không viết lại toàn bộ ngoài phạm vi yêu cầu.
- Không dùng Tailwind Play CDN trong production. CSS được build sẵn và commit tại `public/assets/tailwind.css`; không sửa trực tiếp file sinh ra này.
- Sau khi đổi template hoặc CSS: chạy `npm ci` nếu cần, rồi `npm run build:css`. Commit cả CSS đã build. Server PHP không cần Node.js.
- Giữ tông kem/nâu hiện có, layout responsive và hành vi đặt phòng/quản trị.
- Mọi nút và mục điều hướng phải có hover, focus bàn phím rõ ràng; không áp dụng hiệu ứng cho nút disabled. Tôn trọng `prefers-reduced-motion`.
- Kiểm tra `php tools/check.php`, `php tools/ui-test.php` và các test liên quan trước khi hoàn tất.
