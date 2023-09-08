FROM php:8.0-cli AS prod-runtime

ARG APP_UID=3000
ARG APP_GID=3000
RUN set -o errexit -o nounset \
    && groupadd --system --gid ${APP_GID} app \
    && useradd --system --uid ${APP_UID} --gid app --no-create-home app

RUN apt update && apt install -y  \
    libxml2-dev \
 && rm -rf /var/lib/apt/lists/*

# PHP
RUN docker-php-ext-install -j$(nproc) \
    soap \
    pcntl

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

#COPY rootfs /

#RUN mkdir /app-ephemeral && chown app:app /app-ephemeral

# ==========================================
# BUILDER
# ==========================================
FROM prod-runtime AS builder
ARG BUILDER_UID=1000
ARG BUILDER_GID=1000
#VOLUME /home/builder/.gradle

RUN set -o errexit -o nounset \
    && groupadd --system --gid ${BUILDER_GID} builder \
    && useradd --system --gid builder --uid ${BUILDER_UID} --shell /bin/bash --create-home builder

RUN apt-get update && apt-get install -y  \
    curl \
    git \
    openjdk-17-jdk-headless \
    unzip

# Gradle
# https://docs.gradle.org/current/userguide/compatibility.html
ENV GRADLE_HOME=/opt/gradle
ENV GRADLE_VERSION=7.6.1
ARG GRADLE_DOWNLOAD_SHA256=6147605a23b4eff6c334927a86ff3508cb5d6722cd624c97ded4c2e8640f1f87

RUN set -o errexit -o nounset \
    && echo "Downloading Gradle" \
    && curl -L --output gradle.zip "https://services.gradle.org/distributions/gradle-${GRADLE_VERSION}-bin.zip" \
    \
    && echo "Checking download hash" \
    && echo "${GRADLE_DOWNLOAD_SHA256} *gradle.zip" | sha256sum --check - \
    \
    && echo "Installing Gradle" \
    && unzip gradle.zip \
    && rm gradle.zip \
    && mv "gradle-${GRADLE_VERSION}" "${GRADLE_HOME}/" \
    && ln --symbolic "${GRADLE_HOME}/bin/gradle" /usr/bin/gradle \
    \
    && echo "Testing Gradle installation" \
    && gradle --version

# Gradle cache directory
# This must be after install
RUN mkdir /home/builder/.gradle && chown builder:builder /home/builder/.gradle
ENV GRADLE_USER_HOME='/home/builder/.gradle'

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ==========================================
# PRODUCTION
# ==========================================
FROM builder AS prod-builder
WORKDIR /app-src

COPY composer.json composer.lock /app-src
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

COPY lib /app-src/lib
COPY scripts /app-src/scripts

# -----------------------------------------
FROM prod-runtime AS prod

COPY --from=prod-builder /app-src /app

WORKDIR /app
USER app

# ==========================================
# DEVELOMENT
# ==========================================
FROM prod-runtime AS dev

RUN pecl install \
    xdebug
RUN docker-php-ext-enable \
    xdebug

ARG APM_AGENT_VERSION=1.7.0
ENV APM_INSTALL_DEB_FILE=/tmp/apm-agent-php_${APM_AGENT_VERSION}.deb
RUN curl -L -o ${APM_INSTALL_DEB_FILE} -v https://github.com/elastic/apm-agent-php/releases/download/v${APM_AGENT_VERSION}/apm-agent-php_${APM_AGENT_VERSION}_all.deb \
    && dpkg -i ${APM_INSTALL_DEB_FILE} \
    && rm ${APM_INSTALL_DEB_FILE}

WORKDIR /app
USER app
