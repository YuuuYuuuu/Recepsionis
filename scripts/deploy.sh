#!/usr/bin/env bash
# Deploy script — dijalankan di VPS (self-hosted runner atau manual SSH).
set -euo pipefail

ROOT="${RECEPSIONIS_ROOT:-/www/wwwroot/Recepsionis}"
cd "$ROOT"

echo "==> git pull origin main"
git pull origin main

echo "==> php migrations/ensure_latest_schema.php"
php migrations/ensure_latest_schema.php

echo "==> npm build visitor-app"
cd visitor-app
npm ci
npm run build
cd ..

echo "==> uploads permissions"
mkdir -p uploads/branding uploads/floor_plans uploads/tv_info uploads/prodi
chmod -R 775 uploads

echo "Deploy selesai."
