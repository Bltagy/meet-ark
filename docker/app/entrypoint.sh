#!/bin/bash
set -e

cd /var/www/html

# Copy .env if not present
if [ ! -f .env ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
fi

# Set database environment variables in .env if needed
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=pgsql/' .env || true
sed -i 's/^# DB_HOST=.*/DB_HOST=postgres/' .env || true
sed -i 's/^DB_HOST=.*/DB_HOST=postgres/' .env || true
sed -i 's/^# DB_PORT=.*/DB_PORT=5432/' .env || true
sed -i 's/^DB_PORT=.*/DB_PORT=5432/' .env || true
sed -i 's/^# DB_DATABASE=.*/DB_DATABASE=meet_ark/' .env || true
sed -i 's/^DB_DATABASE=.*/DB_DATABASE=meet_ark/' .env || true
sed -i 's/^# DB_USERNAME=.*/DB_USERNAME=meet_ark/' .env || true
sed -i 's/^DB_USERNAME=.*/DB_USERNAME=meet_ark/' .env || true
sed -i 's/^# DB_PASSWORD=.*/DB_PASSWORD=secretpassword/' .env || true
sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=secretpassword/' .env || true
sed -i 's|^APP_URL=.*|APP_URL=http://localhost:8088|' .env || true

# Install composer dependencies if vendor is missing
if [ ! -d vendor ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist
fi

# Generate APP_KEY if missing
if ! grep -q "^APP_KEY=base64:" .env; then
    echo "Generating Application Key..."
    php artisan key:generate --force
fi

# Wait for PostgreSQL to become ready
echo "Waiting for PostgreSQL at ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
until pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-meet_ark}" > /dev/null 2>&1; do
    sleep 1
done
echo "PostgreSQL is ready."

# Run database migrations
echo "Running migrations..."
php artisan migrate --force

# Check if public/build assets exist, otherwise build
if [ ! -f public/build/manifest.json ]; then
    echo "Building frontend assets..."
    npm run build
fi

exec "$@"
