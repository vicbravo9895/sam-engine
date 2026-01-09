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
# Stage 2: Node.js builder for frontend assets
# -----------------------------------------------------------------------------
FROM node:22-alpine AS node-builder

WORKDIR /app

# Copy package files first for better caching
COPY package.json package-lock.json ./

# Install dependencies (ignore optional platform-specific binaries that may not match)
RUN npm ci --include=dev --ignore-scripts || npm install --include=dev

# Copy source files needed for build
COPY resources/ ./resources/
COPY vite.config.ts tsconfig.json components.json tailwind.config.* ./
COPY public/ ./public/

# Build frontend assets
RUN npm run build

# -----------------------------------------------------------------------------
# Stage 3: PHP dependencies builder
# -----------------------------------------------------------------------------
FROM base AS php-builder

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies (production only)
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# Copy application code
COPY . .

# Run post-install scripts
RUN composer dump-autoload --optimize

# -----------------------------------------------------------------------------
# Stage 4: Production image
# -----------------------------------------------------------------------------
FROM base AS production

# Create www-data user if not exists and set up directories
RUN addgroup -g 82 -S www-data 2>/dev/null || true \
    && adduser -u 82 -D -S -G www-data www-data 2>/dev/null || true

# Copy PHP dependencies and application from builder
COPY --from=php-builder /var/www/html /var/www/html

# Copy built frontend assets from node builder
COPY --from=node-builder /app/public/build /var/www/html/public/build

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
