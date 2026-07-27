#!/usr/bin/env bash
# Deploy/update gtk-bot-laravel (dashboard.gtkjombang.com) di VPS.
# Jalankan dari root project: ./deploy/deploy.sh
set -euo pipefail

APP_DIR="/docker/gtkjombang-dashboard"
BRANCH="main"

cd "$APP_DIR"

echo ">> Menarik update dari branch $BRANCH"
git pull origin "$BRANCH"

echo ">> Build image Docker baru (app, worker, scheduler)"
docker compose build

echo ">> Menjalankan migrasi database (container sementara)"
docker compose run --rm app php artisan migrate --force

echo ">> Recreate container dengan image baru"
docker compose up -d --force-recreate

echo ">> Bersihkan image lama yang menganggur"
docker image prune -f

echo ">> Status container:"
docker compose ps

echo ">> Deploy selesai."
