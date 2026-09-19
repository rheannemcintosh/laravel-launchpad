# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Production image. The Sail development image lives in docker/8.4.
#
# Stage 1: build PHP deps + frontend assets
# Needs both PHP (Wayfinder generates route types via `artisan` during the
# Vite build) and Node, so the build runs on the PHP CLI image with Node added.
# ---------------------------------------------------------------------------
FROM serversideup/php:8.4-cli AS build

USER root

# Node 22 (for `npm run build` / vite-plus)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# PHP dependencies (no dev, no scripts — artisan isn't fully wired yet)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

# Node dependencies
COPY package.json package-lock.json ./
RUN npm ci

# Application source
COPY . .

# Finalise the autoloader, then build assets.
# A throwaway APP_KEY lets `artisan` boot for Wayfinder generation. It is
# removed with .env afterwards and never reaches the runtime image.
RUN composer dump-autoload --optimize --no-dev \
    && cp .env.example .env \
    && php artisan key:generate \
    && npm run build \
    && rm -rf node_modules .env

# ---------------------------------------------------------------------------
# Stage 2: runtime — nginx + php-fpm, listening on :8080
# ---------------------------------------------------------------------------
FROM serversideup/php:8.4-fpm-nginx AS runtime

USER root

# Microsoft ODBC driver + sqlsrv / pdo_sqlsrv (required for SQL Server and Azure SQL).
# Base image is Debian 13 (trixie); PHPIZE_DEPS is the toolchain PECL needs and
# is purged again afterwards to keep the image small.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends curl ca-certificates; \
    curl -fsSL -o /tmp/pmc.deb https://packages.microsoft.com/config/debian/13/packages-microsoft-prod.deb; \
    dpkg -i /tmp/pmc.deb; \
    rm /tmp/pmc.deb; \
    apt-get update; \
    ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 unixodbc-dev; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install sqlsrv pdo_sqlsrv; \
    docker-php-ext-enable sqlsrv pdo_sqlsrv; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

# Run Laravel optimisation + migrations automatically on container start.
# These are what makes a cold start self-serve.
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_CONFIG_CACHE=true \
    AUTORUN_LARAVEL_ROUTE_CACHE=true \
    AUTORUN_LARAVEL_VIEW_CACHE=true \
    AUTORUN_LARAVEL_STORAGE_LINK=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    PHP_OPCACHE_ENABLE=1

WORKDIR /var/www/html

COPY --from=build --chown=www-data:www-data /app/vendor ./vendor
COPY --from=build --chown=www-data:www-data /app/public/build ./public/build
COPY --chown=www-data:www-data . .

USER www-data

EXPOSE 8080
