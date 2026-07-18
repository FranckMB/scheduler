FROM php:8.4-fpm

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