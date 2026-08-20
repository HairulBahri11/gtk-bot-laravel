#!/bin/sh
set -e

# Cache config/route/view pakai env runtime (bukan saat build image),
# supaya .env yang di-inject lewat env_file selalu up to date.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Kalau container dijalankan dengan command khusus (mis. "docker compose run
# app php artisan migrate --force", atau override command worker/scheduler),
# jalankan command itu. Kalau tidak ada command (service "app" biasa),
# baru start nginx+php-fpm lewat supervisord.
if [ "$#" -eq 0 ]; then
    exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
else
    exec "$@"
fi
