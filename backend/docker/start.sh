#!/usr/bin/env sh
set -eu

PORT_VALUE="${PORT:-10000}"
export PORT="${PORT_VALUE}"
envsubst '${PORT}' \
    < /etc/nginx/render-nginx.conf.template \
    > /tmp/nginx.conf

mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# Render supplies runtime environment variables before this process starts.
# Config caching is safe here; route caching is intentionally omitted because
# the existing web route file contains a closure route.
php artisan config:cache
echo "Running Laravel migrations..."
php artisan:fresh migrate --force
php-fpm -F &
PHP_FPM_PID=$!
nginx -c /tmp/nginx.conf -g 'daemon off;' &
NGINX_PID=$!

shutdown() {
    kill -TERM "$NGINX_PID" "$PHP_FPM_PID" 2>/dev/null || true
    wait "$NGINX_PID" 2>/dev/null || true
    wait "$PHP_FPM_PID" 2>/dev/null || true
}

trap shutdown INT TERM EXIT
wait "$NGINX_PID"
