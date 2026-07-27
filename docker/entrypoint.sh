#!/bin/sh
set -e

# Cache config/route/view pakai env runtime (bukan saat build image),
# supaya .env yang di-inject lewat env_file selalu up to date.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
