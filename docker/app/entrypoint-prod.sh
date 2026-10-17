#!/bin/bash
set -e

# Production Entrypoint for Meet-Ark Laravel Application

cd /var/www/html

# Validate required application key
if [ -z "${APP_KEY}" ]; then
    echo "ERROR: APP_KEY is not set. A persistent key must be configured in your environment." >&2
    echo "Generate one using: php artisan key:generate --show" >&2
    exit 1
fi

# Ensure runtime directories exist with appropriate permissions
mkdir -p /var/www/html/storage/framework/{cache/data,sessions,views}
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# If a custom command is provided (e.g. php artisan migrate, php artisan test), execute it directly
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

# Start Supervisord managing PHP-FPM and Nginx
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
