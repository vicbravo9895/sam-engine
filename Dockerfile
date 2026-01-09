# =============================================================================
# SAM - Laravel Production Dockerfile
# Multi-stage build for optimized production image
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1: Base image with PHP and system dependencies
# -----------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        pcntl \
        zip \
        opcache \
        intl \
        mbstring \
        bcmath

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# Stage 2: Builder with PHP + Node.js (needed for Wayfinder plugin)
# -----------------------------------------------------------------------------
FROM base AS builder

# Install Node.js (required for Vite build with Wayfinder plugin)
RUN apk add --no-cache nodejs npm

# Copy ALL application code first (needed for artisan)
COPY . .

# Install PHP dependencies (skip scripts first, run after setup)
RUN composer install \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# Install Node dependencies
RUN npm ci --include=dev || npm install --include=dev

# Create minimal .env for artisan commands during build
# Wayfinder needs to run php artisan to generate route types
RUN if [ -f .env.example ]; then cp .env.example .env; else \
    echo "APP_NAME=SAM" > .env && \
    echo "APP_ENV=production" >> .env && \
    echo "APP_KEY=" >> .env && \
    echo "APP_DEBUG=false" >> .env && \
    echo "DB_CONNECTION=sqlite" >> .env; \
    fi

# Ensure directories exist, generate key, and run post-install scripts
RUN mkdir -p bootstrap/cache storage/framework/{cache,sessions,views} \
    && php artisan key:generate --force \
    && composer run-script post-autoload-dump 2>/dev/null || true

# Build frontend assets (wayfinder will generate route types)
RUN npm run build

# Remove dev dependencies and optimize for production
# Also remove the build-time .env (production will use env vars)
# Clear package cache to remove references to dev-only service providers
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    && php artisan package:discover --ansi \
    && php artisan config:clear \
    && php artisan route:clear \
    && php artisan view:clear \
    && rm -rf node_modules \
    && rm -f .env

# -----------------------------------------------------------------------------
# Stage 3: Production image (minimal)
# -----------------------------------------------------------------------------
FROM base AS production

# Create www-data user if not exists
RUN addgroup -g 82 -S www-data 2>/dev/null || true \
    && adduser -u 82 -D -S -G www-data www-data 2>/dev/null || true

# Copy application from builder (without node_modules)
COPY --from=builder /var/www/html /var/www/html

# Copy Docker configuration files
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create nginx pid directory
RUN mkdir -p /run/nginx

# Expose HTTP port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget --no-verbose --tries=1 --spider http://localhost/up || exit 1

# Start supervisor (manages nginx + php-fpm)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
