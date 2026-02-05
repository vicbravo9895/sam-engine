#!/bin/sh
# =============================================================================
# SAM - Horizon Docker Entrypoint Script
# Ensures proper permissions for storage volumes
# =============================================================================

set -e

echo "🚀 Starting SAM Horizon..."

# Fix permissions for storage directories (important for mounted volumes)
echo "📁 Fixing storage permissions..."
mkdir -p /var/www/html/storage/logs \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/app/public/evidence \
    /var/www/html/storage/app/public/dashcam-media

chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

echo "✅ Horizon ready!"

# Execute the main command
exec "$@"
