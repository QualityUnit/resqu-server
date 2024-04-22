FROM php:8.2-cli AS prod-runtime

ARG APP_UID=3000
ARG APP_GID=3000
RUN set -o errexit -o nounset \
    && groupadd --system --gid ${APP_GID} app \
    && useradd --system --uid ${APP_UID} --gid app --no-create-home app

RUN apt update && apt install -y  \
    htop \
    libxml2-dev \
    procps \
 && rm -rf /var/lib/apt/lists/*

# PHP
RUN docker-php-ext-install -j$(nproc) \
    pcntl

RUN pecl install -o -f \
    redis \
 && pecl clear-cache \
 && docker-php-ext-enable \
    redis

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

#COPY rootfs /

# ==========================================
# BUILDER
# ==========================================
FROM prod-runtime AS builder
ARG BUILDER_UID=1000
ARG BUILDER_GID=1000

RUN set -o errexit -o nounset \
    && groupadd --system --gid ${BUILDER_GID} builder \
    && useradd --system --gid builder --uid ${BUILDER_UID} --shell /bin/bash --create-home builder

RUN apt-get update && apt-get install -y  \
    curl \
    git \
    unzip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ==========================================
# PRODUCTION
# ==========================================
FROM builder AS prod-builder
WORKDIR /app-src
COPY . /app-src/
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

# -----------------------------------------
FROM prod-runtime AS prod

COPY --from=prod-builder /app-src /app

WORKDIR /app
USER app
CMD ["php", "scripts/startManagers.php", "/app-config/config.yml"]

# ==========================================
# DEVELOMENT
# ==========================================
FROM prod-runtime AS dev

RUN pecl install \
    xdebug
RUN docker-php-ext-enable \
    xdebug

WORKDIR /app
USER app
