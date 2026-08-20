#!/usr/bin/env bash
# Deploy/update gtk-bot-laravel di VPS. Dipakai untuk stack mana pun
# (production maupun dev) - tinggal taruh script ini di masing-masing
# folder clone (mis. /docker/gtkjombang-dashboard dan
# /docker/gtkjombang-dashboard-dev), branch & domain dibaca dari branch
# git yang lagi checkout & dari .env (APP_ENV_TAG/APP_DOMAIN) di folder itu.
# Jalankan dari root project: ./deploy/deploy.sh
set -euo pipefail

# Folder tempat script ini berada = root project (bukan hardcode path),
# supaya script yang sama valid dipakai di folder prod maupun dev.
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"

echo ">> Folder   : $APP_DIR"
echo ">> Branch   : $BRANCH"
echo ">> Menarik update dari branch $BRANCH"
git pull origin "$BRANCH"

echo ">> Build image Docker baru (app, worker, scheduler)"
docker compose build

echo ">> Menjalankan migrasi database (container sementara)"
docker compose run --rm app php artisan migrate --force

echo ">> Recreate container dengan image baru"
docker compose up -d --force-recreate

# --force-recreate di atas sudah mengganti container worker dengan proses baru
# (jadi tidak ada kode lama yang nyangkut di memori) - queue:restart di sini
# murni jaga-jaga kalau suatu saat langkah recreate di atas diganti jadi
# restart yang lebih ringan (mis. `docker compose restart` tanpa
# --force-recreate), supaya worker tetap dipaksa reload kode baru tanpa
# bergantung penuh pada recreate container.
echo ">> Restart queue worker (jaga-jaga)"
docker compose exec app php artisan queue:restart

echo ">> Bersihkan image lama yang menganggur"
docker image prune -f

echo ">> Status container:"
docker compose ps

echo ">> Deploy selesai."
