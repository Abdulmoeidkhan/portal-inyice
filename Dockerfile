# syntax=docker/dockerfile:1

# ── Stage 1: Build React + Ant Design PWA assets ──────────────────────────────
FROM node:20-alpine AS frontend-builder

WORKDIR /app

# Cache node_modules layer separately from source
COPY package*.json ./
RUN npm ci

COPY . .
RUN mkdir -p public/build && npm run build

# ── Stage 2: Laravel 13 PHP-FPM runtime ───────────────────────────────────────
FROM php:8.4-fpm-alpine AS app

ARG APP_ENV=production
ARG APP_DEBUG=false

WORKDIR /var/www/html

# System deps + PHP extensions required by Laravel 13
# linux-headers required for pcntl on Alpine
RUN apk add --no-cache \
    bash \
    curl \
    git \
    icu-dev \
    libzip-dev \
    linux-headers \
    oniguruma-dev \
    unzip \
    zip \
    $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
    bcmath \
    intl \
    mbstring \
    opcache \
    pcntl \
    pdo_mysql \
    zip \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear /var/cache/apk/*

# Opcache tuning for Laravel production
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.jit_buffer_size=128M'; \
    echo 'opcache.jit=1255'; \
} > /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application source
COPY . /var/www/html

# Pull in pre-built React/AntD assets from frontend stage
COPY --from=frontend-builder /app/public/build /var/www/html/public/build

# Install PHP dependencies (no dev, optimised autoloader)
RUN if [ -f composer.json ]; then \
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev; \
    fi

RUN if [ -f artisan ]; then php artisan storage:link || true; fi

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

COPY ./.docker/entrypoint.sh /usr/local/bin/inyice-entrypoint
RUN chmod +x /usr/local/bin/inyice-entrypoint

ENV APP_ENV=${APP_ENV}
ENV APP_DEBUG=${APP_DEBUG}

EXPOSE 9000

ENTRYPOINT ["inyice-entrypoint"]
CMD ["php-fpm"]

# ── Stage 3: Nginx runtime with built public assets ───────────────────────────
FROM nginx:1.27-alpine AS web

COPY ./.docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
