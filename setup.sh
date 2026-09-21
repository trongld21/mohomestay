#!/bin/bash
set -euo pipefail
npm install
if [ ! -f .env.local ]; then
  cp .env.example .env.local
fi
cat <<'INFO'
Đã chuẩn bị giao diện Lặng Home. Chạy: npm run dev
Cấu hình database và thanh toán theo README.md trước khi bật đặt phòng trực tuyến.
INFO
