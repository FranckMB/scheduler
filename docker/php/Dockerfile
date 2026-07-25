FROM php:8.4-fpm AS base

ARG UID=1000
ARG GID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PATH="/root/.composer/vendor/bin:${PATH}"

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        ca-certificates \
        unzip \
        libicu-dev \
        libzip-dev \
        libpq-dev \
        libonig-dev \
        libxml2-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        pkg-config \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        sockets \
        zip \
    && mkdir -p /usr/src/php/ext/redis \
    && curl -fsSL https://github.com/phpredis/phpredis/archive/6.3.0.tar.gz | tar xz -C /usr/src/php/ext/redis --strip-components 1 \
    && docker-php-ext-install redis \
    && echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/zz-memory.ini \
    && echo "max_execution_time = 0" >> /usr/local/etc/php/conf.d/zz-memory.ini \
    && rm -rf /var/lib/apt/lists/*

# Client PostgreSQL 16 (pg_dump/pg_restore/psql) pour app:db:backup / restore-check :
# le client Debian par défaut est en 15 et pg_dump refuse un serveur plus récent —
# on prend la version EXACTE du serveur (postgres:16) via le dépôt PGDG officiel.
RUN apt-get update \
    && apt-get install -y --no-install-recommends gnupg lsb-release \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/pgdg.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-16 \
    && rm -rf /var/lib/apt/lists/*

RUN usermod -u ${UID} www-data && groupmod -g ${GID} www-data

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

EXPOSE 9000

CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# prod — self-contained image: the code and vendor/ live IN the image, no bind
# mount. Deploys pull a tagged image; rollback = run the previous tag. The
# default (base) stage above stays untouched for dev/CI compose builds.
FROM base AS prod

# rclone backs the optional BACKUP_SYNC_COMMAND off-site hook (DatabaseBackupCommand).
RUN apt-get update \
    && apt-get install -y --no-install-recommends rclone \
    && rm -rf /var/lib/apt/lists/*

# Prod PHP overrides (zz-prod sorts after zz-memory, so these win): bounded
# execution instead of the dev max_execution_time=0, and opcache that never
# re-stats files — safe ONLY because the code in this image cannot change.
RUN { \
        echo "max_execution_time = 60"; \
        echo "opcache.enable = 1"; \
        echo "opcache.validate_timestamps = 0"; \
        echo "opcache.memory_consumption = 192"; \
        echo "opcache.max_accelerated_files = 20000"; \
        echo "expose_php = Off"; \
    } > /usr/local/etc/php/conf.d/zz-prod.ini

# .dockerignore keeps var/, vendor/, config/jwt and every *.local env file out
# of the build context — only committed code and safe env defaults get in.
COPY backend/ /app/backend/
WORKDIR /app/backend

ENV APP_ENV=prod
# --no-scripts: flex auto-scripts (cache:clear) would need runtime env/DB.
# Warmup is best-effort — Symfony rebuilds whatever is missing at first boot.
RUN composer install --no-dev --classmap-authoritative --no-interaction --no-progress --no-scripts \
    && php bin/console assets:install public --env=prod --no-interaction || true

# Runtime-written dirs (volumes mount over most of them in the prod compose):
# var/backups (dumps), var/storage/logos, public/exports (pdf-worker), var
# cache/log, config/jwt (mounted read-only from the host at deploy time).
RUN mkdir -p var/cache var/log var/backups var/storage/logos public/exports config/jwt \
    && chown -R www-data:www-data var public/exports

USER www-data