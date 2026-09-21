# Lặng Home

Website Next.js App Router + TypeScript + Tailwind, PostgreSQL + Prisma, NextAuth và thanh toán QR payOS. Giao diện nâu–kem theo hình tham chiếu.

**Địa chỉ:** 82 đường B18, KDC 91B, Ninh Kiều, Cần Thơ. **Hotline/Zalo:** 0357 907 153.

## Chức năng đã xây dựng

- Landing page render ở server, giới thiệu, bộ sưu tập phòng, FAQ, hướng dẫn smart lock, Zalo, gọi điện và liên kết chỉ đường.
- Pink / White / Black; chi tiết phòng; đổi giá giữa gói 3 giờ, 6 giờ, qua đêm.
- Lịch tháng, lọc phòng, xem khung giờ bận/giữ chỗ/tạm khóa. Lịch công khai không trả thông tin cá nhân.
- Đặt phòng không cần tài khoản; giá tính ở server; kiểm tra ngày, giờ, số khách và liên hệ; giữ phòng 15 phút.
- Khóa dòng PostgreSQL trong transaction trước khi kiểm tra giao nhau, chống hai yêu cầu cùng đặt một phòng. Khoảng thời gian dùng UTC+7, lưu timestamptz, cho phép hai lượt tiếp giáp.
- QR payOS, xác minh HMAC ở phản hồi tạo link và webhook, so khớp số tiền/orderCode, webhook lặp không ghi nhận hai lần. Tiền đến muộn được ghi nhận để chủ home đối soát, không tự xác nhận lại phòng đã nhả.
- Tra cứu qua mã ngẫu nhiên và điện thoại; trang QR dùng token ngẫu nhiên trong URL fragment, không đặt token trong query/log URL.
- Quản lý có đăng nhập: 100 đơn gần nhất, khoản đã thu, đơn cần đối soát, hủy / nhận / trả phòng. Không tự đánh dấu đã thanh toán từ trình duyệt.
- Metadata, robots, sitemap, trang 404 và trạng thái lỗi.

Ảnh hiện là **ảnh minh họa**, được ghi rõ trên website. Logo là nét vẽ tái dựng theo tham chiếu, cần thay bằng file gốc để khớp tuyệt đối. Giá gói mặc định dành cho tối đa 2 khách; chủ home cần xác nhận sức chứa thực tế. Chưa có email/SMS tự động, đồng bộ OTA, mã khóa tự động, hoàn tiền tự động, CRUD phòng hoặc giá ngày lễ.

## Chạy giao diện

```sh
npm install
cp .env.example .env.local
npm run dev
```

Không ghi đè `.env.local` nếu đã có cấu hình. Khi `BOOKINGS_ENABLED=false`, website vẫn xem được đầy đủ; lịch hiện “cần kiểm tra”, đặt phòng chuyển sang liên hệ Lặng, không tạo đơn giả.

## Chạy bằng Docker (Next.js + PostgreSQL)

Cần Docker Desktop đang chạy và Docker Compose v2. Không cần cài PostgreSQL trên máy. Nếu máy không có Node.js, có thể sao chép `.env.docker.example` thành `.env.docker`, thay hai giá trị `GENERATE_*` bằng chuỗi ngẫu nhiên rồi chạy trực tiếp lệnh Compose ở dưới.

```sh
npm run docker:setup
npm run docker:up
```

Mở **http://localhost:3200**. `docker:setup` tạo `.env.docker` với mật khẩu database và NextAuth secret ngẫu nhiên; chạy lại không ghi đè. `.env.local` không được đưa vào Docker image và không bị thay đổi.

Lệnh tương đương:

```sh
docker compose --env-file .env.docker up -d --build
```

Compose chạy theo thứ tự: PostgreSQL qua healthcheck → service `migrate` áp dụng migration và seed 3 phòng → web khởi động. `migrate` kết thúc với mã 0 là bình thường. Chỉ chạy schema mới trên volume riêng của Compose; không tự kết nối database trong `.env.local`.

```sh
npm run docker:status
npm run docker:logs
npm run docker:down
```

`docker:down` dừng và xóa container/network, **giữ volume database**. Không dùng `down -v` nếu muốn giữ dữ liệu. Mật khẩu PostgreSQL trong `.env.docker` phải giữ khớp với volume đã khởi tạo; đổi biến env không tự đổi mật khẩu trong database đang có.

**Cấu hình:** sửa `.env.docker` để đặt admin, payOS, giờ qua đêm và điều kiện đặt phòng. `BOOKINGS_ENABLED=false` mặc định, nên đã có database nhưng chưa mở nhận đặt phòng trực tuyến. Làm theo phần “Bật đặt phòng và thanh toán” rồi đặt `BOOKINGS_ENABLED=true` khi sẵn sàng.

- Database URL bên trong container được Compose tự tạo, hostname là `db`. Không dùng `localhost` để web kết nối PostgreSQL.
- PostgreSQL không mở cổng ra máy host. Vào SQL bằng `docker compose --env-file .env.docker exec db psql -U langhome -d langhomestay`.
- Muốn đổi cổng, sửa cả `APP_PORT` và `NEXT_PUBLIC_SITE_URL`. URL website được dùng lúc build metadata SEO, nên phải rebuild khi thay đổi URL.
- Sau khi sửa code hoặc env: `npm run docker:up` để rebuild/recreate. Đây là chế độ production local, không hot reload.
- Chạy lại migration/seed sau khi cập nhật schema: `docker compose --env-file .env.docker run --rm migrate`.
- Dockerfile có nhiều stage; web chạy bằng user `node`, chỉ chứa dependency production. Prisma CLI nằm trong image riêng cho migration.
- Website chỉ bind vào `127.0.0.1` của máy host. Để triển khai VPS, đặt reverse proxy HTTPS phía trước, cấu hình domain/env và webhook payOS public; không dùng URL localhost cho webhook thật.
- Cấu hình này không ảnh hưởng luồng deploy Vercel; Vercel vẫn build Next.js trực tiếp như hướng dẫn bên dưới.

Tham khảo: [Docker Compose startup order](https://docs.docker.com/compose/how-tos/startup-order/), [Docker hướng dẫn Next.js](https://docs.docker.com/guides/nextjs/).

## Bật đặt phòng và thanh toán

1. Tạo PostgreSQL tại Neon/Supabase. Điền `DATABASE_URL` (pooled cho runtime), `DIRECT_URL` (direct cho migration).
2. Chạy `npm run db:deploy`, rồi `npm run db:seed`. Chỉ seed 3 phòng nếu chưa tồn tại; không ghi đè chỉnh sửa hiện có. Với database có schema sẵn từ bản scaffold, cần backup và baseline migration trước; không chạy migration khởi tạo trực tiếp.
3. Tạo kênh payOS đã được xác minh. Điền 3 khóa `PAYOS_*`, URL HTTPS của website vào `NEXT_PUBLIC_SITE_URL` và `NEXTAUTH_URL`.
4. Đăng ký webhook `https://<domain>/api/payments/webhook` trên payOS. Webhook cần public HTTPS; không đặt sau trang đăng nhập hoặc Vercel Deployment Protection.
5. Chủ home điền `BOOKING_TERMS` (hủy/hoàn tiền, điều kiện nhận phòng), `OVERNIGHT_CHECK_IN`, `OVERNIGHT_CHECK_OUT`. Không mặc định giờ qua đêm chưa được xác nhận.
6. Bật `BOOKINGS_ENABLED=true`. Luồng hiện thanh toán **100% giá gói**, chưa chia cọc. Xác nhận điều kiện thu tiền với chủ home trước khi bật.
7. Kiểm tra giao dịch thật giá nhỏ trên kênh đã cấu hình: tạo QR, quét, webhook xác nhận, tra cứu, hủy/đối soát, giao dịch đến sau thời hạn. Không dùng trang return URL làm bằng chứng đã thu tiền.

Cổng payOS được gọi qua REST, không cần server chạy thường trực. Tài liệu đã đối chiếu:
- [API tạo link](https://payos.vn/docs/api/)
- [Chữ ký payment-requests và webhook](https://payos.vn/docs/tich-hop-webhook/kiem-tra-du-lieu-voi-signature/)
- [Webhook](https://payos.vn/docs/du-lieu-tra-ve/webhook/)

## Quản lý

Vào `/auth/login` hoặc `/admin`. Đặt `NEXTAUTH_SECRET` đủ mạnh, `ADMIN_EMAIL`, `ADMIN_PASSWORD_HASH`. Không có tài khoản/mật khẩu mặc định.

Tạo hash bằng công cụ hỏi mật khẩu ẩn:

```sh
npm run admin:password
```

Lưu kết quả `salt:hash` vào `ADMIN_PASSWORD_HASH` (Vercel Environment Variables hoặc .env.local). Mật khẩu dùng scrypt. Session JWT tối đa 8 giờ. API quản lý kiểm tra session độc lập; người dùng không thể tự đăng ký admin.

## Deploy Vercel

- Import repository, framework Next.js, Node.js 22.
- Build: `npm run build`. Postinstall sinh Prisma client. Database migration chạy riêng một lần qua direct connection.
- Thiết lập env cho đúng môi trường; Preview dùng database và kênh riêng. Chưa cấu hình xong giữ `BOOKINGS_ENABLED=false`.
- Đặt domain, cập nhật URL và đăng ký webhook; redeploy khi sửa env.
- Bật rate limiting/WAF cho `/api/auth/*`, `/api/bookings`, `/api/bookings/lookup` tại Vercel trước khi mở công khai. Giới hạn đơn chờ theo điện thoại trong ứng dụng chưa thay thế chống lạm dụng phân tán.
- Ảnh minh họa Unsplash đã lưu trong `public/images`. Thay bằng ảnh thực tế trong `public/images` và cập nhật `lib/rooms.ts`, `app/page.tsx`. Chỉ cho phép domain ảnh cần dùng.
- Mã khóa gửi riêng cho khách bởi chủ home. Trang quản lý hiển thị khoản cần đối soát; hoàn tiền thực hiện thủ công qua ngân hàng/cổng thanh toán.
- Chưa triển khai lên Vercel trong phiên này.

## Kiểm tra

```sh
npm test
npm run lint
npm run build
```

Test kiểm tra 9 mức giá, xử lý múi giờ/qua nửa đêm, thời gian tiếp giáp, đầu vào không hợp lệ và chữ ký từ ví dụ chính thức của payOS. Integration test riêng (nếu PostgreSQL thử nghiệm có sẵn):

```sh
TEST_DATABASE_URL=postgresql://... npm run test:integration
```

Integration test chỉ chạy trên database có tên chứa `langhome_test`; dùng database riêng, tuyệt đối không trỏ vào dữ liệu khách. Không có giao dịch thu tiền thật trong test.

## Cấu trúc

- `lib/rooms.ts`: danh mục, 9 mức giá, nhãn trạng thái.
- `lib/booking.ts`: xác thực, tính thời gian và giá.
- `lib/payment.ts`: payOS và HMAC.
- `app/api`: đặt phòng, lịch, tra cứu, auth, webhook, quản lý.
- `components/booking`: các luồng tương tác.
- `prisma/schema.prisma`, `prisma/migrations`: dữ liệu và migration.


Nguồn ảnh minh họa: ảnh Unsplash `photo-1611892440504-42a792e24d32`, `photo-1615874959474-d609969a20ed`, `photo-1566665797739-1674de7a421a`, `photo-1616486338812-3dadae4b4ace`.
# mohomestay git init git add README.md git commit -m first commit git branch -M main git remote add origin git@trongld:trongld21/mohomestay.git git push -u origin main
# mohomestay
