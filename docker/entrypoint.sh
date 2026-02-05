#!/bin/sh
# =============================================================================
# SAM - Docker Entrypoint Script
# Ensures proper permissions and runs startup tasks
# =============================================================================

set -e

echo "🚀 Starting SAM..."

# Fix permissions for storage directories (important for mounted volumes)
echo "📁 Fixing storage permissions..."
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Ensure log directory exists and is writable
mkdir -p /var/www/html/storage/logs
chown www-data:www-data /var/www/html/storage/logs
chmod 775 /var/www/html/storage/logs

# Clear and cache config/routes if in production
if [ "$APP_ENV" = "production" ]; then
    echo "⚡ Caching configuration..."
    php /var/www/html/artisan config:cache || true
    php /var/www/html/artisan route:cache || true
    php /var/www/html/artisan view:cache || true
fi

echo "✅ SAM ready!"

# Execute the main command (supervisord)
exec "$@"
