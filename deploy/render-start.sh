#!/bin/sh
set -eu

: "${APP_KEY:?Configure APP_KEY in Render}"
: "${DB_URL:?Configure the Neon PostgreSQL URL in Render}"

export APP_ENV=production
export APP_DEBUG=false
export DB_CONNECTION=pgsql
export SESSION_DRIVER=database
export SESSION_SECURE_COOKIE=true
export CACHE_STORE=database
export LOG_CHANNEL=stderr

if [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
    export ASSET_URL="$RENDER_EXTERNAL_URL"
fi

php artisan migrate --force --no-interaction
php artisan db:seed --class=BusinessSettingsSeeder --force --no-interaction
php artisan tournaments:purge-proof-images --no-interaction

if [ -n "${CUELAB_HOSTED_DEMO_PASSWORD:-}" ]; then
    php artisan db:seed --class=CueLabDemoSeeder --force --no-interaction
fi

if [ "${PORT:-80}" != 80 ]; then
    sed -i "s/^Listen 80$/Listen $PORT/" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/" /etc/apache2/sites-available/000-default.conf
fi

php artisan schedule:work --no-interaction &
exec apache2-foreground
