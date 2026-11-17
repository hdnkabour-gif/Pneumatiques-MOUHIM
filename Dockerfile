# PNEU MOUHIM Manager – image unique (local + Railway)
# FrankenPHP = PHP 8.4 + serveur web Caddy dans un seul conteneur.
FROM dunglas/frankenphp:1-php8.4-bookworm AS base

# pg_dump refuses a server newer than itself ("server version mismatch"), so the
# client must be >= the PostgreSQL server version. Railway runs PostgreSQL 18.
ARG PG_CLIENT_VERSION=18
# 1 = include dev tools (Pest, Pint) for local use; production keeps 0.
ARG COMPOSER_DEV=0

RUN install-php-extensions pdo_pgsql pgsql intl zip gd bcmath opcache pcntl exif \
    && apt-get update && apt-get install -y --no-install-recommends ca-certificates curl gnupg unzip git \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y --no-install-recommends postgresql-client-${PG_CLIENT_VERSION} \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.d"

WORKDIR /app

COPY docker/php.ini /usr/local/etc/php/app.d/app.ini

# ---- Dépendances (couche mise en cache) ----
COPY composer.json composer.lock ./
RUN composer install $([ "$COMPOSER_DEV" = "1" ] || echo --no-dev) --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---- Code ----
COPY . .
RUN composer dump-autoload --optimize $([ "$COMPOSER_DEV" = "1" ] || echo --no-dev) \
    && php artisan filament:assets \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["web"]
