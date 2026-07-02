FROM php:8.4-fpm

ARG UID=1000
ARG GID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PATH="/root/.composer/vendor/bin:${PATH}"

WORKDIR /app

# Reliable extension installer (pulls from GitHub w/ checksums) — pecl.php.net
# is flaky (504 / corrupt tarballs) and was breaking the redis build in CI.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
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
    && install-php-extensions redis \
    && echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/zz-memory.ini \
    && echo "max_execution_time = 0" >> /usr/local/etc/php/conf.d/zz-memory.ini \
    && rm -rf /var/lib/apt/lists/*

RUN usermod -u ${UID} www-data && groupmod -g ${GID} www-data

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

EXPOSE 9000

CMD ["php-fpm"]