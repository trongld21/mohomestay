# Triển khai

Nguồn hướng dẫn hiện hành: [README — Deploy Vercel](README.md#deploy-vercel).

- [ ] PostgreSQL riêng cho Production; pooled URL cho runtime, direct URL cho migration.
- [ ] Migration và seed trên database đúng môi trường.
- [ ] URL HTTPS, NextAuth secret, tài khoản quản lý.
- [ ] Kênh payOS, 3 khóa API, webhook công khai và kiểm tra giao dịch thật.
- [ ] Giờ qua đêm, sức chứa, điều kiện hủy/hoàn tiền và thanh toán 100% được chủ home xác nhận.
- [ ] Thay ảnh minh họa và logo tái dựng bằng file gốc.
- [ ] Rate limiting/WAF cho đăng nhập, tạo đơn và tra cứu.
- [ ] Bật `BOOKINGS_ENABLED=true` rồi kiểm tra lại lịch, QR, tra cứu và quản lý.

Website chưa được deploy trong phiên xây dựng này. Chưa xác minh thanh toán thật do chưa có kênh payOS.
