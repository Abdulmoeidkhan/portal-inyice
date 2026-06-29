#!/usr/bin/env sh
set -eu

cd /var/www/html

mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

if [ "${WAIT_FOR_DB:-true}" = "true" ] && [ "${DB_CONNECTION:-mysql}" = "mysql" ]; then
    echo "Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
    tries=0
    until php -r '
        $host = getenv("DB_HOST") ?: "mysql";
        $port = getenv("DB_PORT") ?: "3306";
        $db = getenv("DB_DATABASE") ?: "";
        $user = getenv("DB_USERNAME") ?: "";
        $pass = getenv("DB_PASSWORD") ?: "";
        new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass);
    ' >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge "${DB_WAIT_RETRIES:-60}" ]; then
            echo "MySQL did not become available in time."
            exit 1
        fi
        sleep 2
    done
fi

php artisan storage:link >/dev/null 2>&1 || true

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${CACHE_LARAVEL_CONFIG:-true}" = "true" ]; then
    php artisan optimize:clear
    php artisan optimize
fi

exec "$@"
