#!/bin/bash
set -euo pipefail
cat <<'INFO'
Lặng Home
  npm install
  npm run dev

Xem README.md để cấu hình PostgreSQL, tài khoản quản lý và payOS.
Không ghi đè .env.local đã có. Không cần kết nối database để xem giao diện.
INFO
