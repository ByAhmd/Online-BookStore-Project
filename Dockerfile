# syntax=docker/dockerfile:1
#
# BookWise: PHP 8.3 + Apache serving public/ (see docs/DEPLOYMENT.md).
#
#   docker compose up --build          local store with a MySQL container
#   docker build -t bookwise .         image only
#
# The container runs as www-data and listens on $PORT (default 8080).
# docker/entrypoint.sh waits for the database, installs or migrates the schema
# and, with APP_DEMO=true, serves the built-in payment simulator at /stripe-sim.

############################################################################
# Stage 1: Composer dependencies, without development packages
############################################################################
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
# The PHP extensions (pdo_mysql, gd, ...) are installed in the runtime stage;
# this stage only downloads packages, so their platform checks are skipped here.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
        --no-scripts --no-autoloader --ignore-platform-req='ext-*'

# src/ is autoloaded through "files" entries, so it must exist for the dump.
# (--classmap-authoritative is not used: it does not suit "files" autoloading.)
COPY src/ src/
RUN composer dump-autoload --no-dev --optimize --no-interaction

############################################################################
# Stage 2: runtime
############################################################################
FROM php:8.3-apache

# PHP extensions: pdo_mysql, gd with JPEG/PNG/WebP, opcache. Only the shared
# libraries they need are kept; the -dev packages are removed again.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd opcache pdo_mysql; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    find /usr/local -type f -executable -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | cut -d: -f1 \
        | sort -u \
        | xargs -rt apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -r ' \
        foreach (["pdo_mysql", "gd", "mbstring", "curl", "fileinfo", "json", "openssl", "Zend OPcache"] as $ext) { \
            if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: $ext\n"); exit(1); } \
        } \
        foreach (["JPEG Support", "PNG Support", "WebP Support"] as $feature) { \
            if (empty(gd_info()[$feature])) { fwrite(STDERR, "gd lacks $feature\n"); exit(1); } \
        }'

# Production php.ini plus the BookWise overrides (no displayed errors, no
# X-Powered-By, opcache).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/bookwise.ini /usr/local/etc/php/conf.d/zz-bookwise.ini

# Apache: listen on $PORT, serve public/ only, demo-only simulator alias.
ENV PORT=8080
COPY docker/apache/ports.conf /etc/apache2/ports.conf
COPY docker/apache/site.conf /etc/apache2/sites-available/bookwise.conf
COPY docker/apache/server.conf /etc/apache2/conf-available/zz-bookwise.conf
RUN set -eux; \
    sed -i 's/\r$//' /etc/apache2/ports.conf /etc/apache2/sites-available/bookwise.conf /etc/apache2/conf-available/zz-bookwise.conf; \
    a2enmod headers remoteip; \
    a2dismod -f autoindex status; \
    a2dissite 000-default; \
    a2ensite bookwise; \
    a2enconf zz-bookwise

# The application: only what it needs at run time. From tests/ only the
# payment simulator is copied (the demo serves it at /stripe-sim).
WORKDIR /var/www/bookwise
COPY --from=vendor /app/vendor/ vendor/
COPY bin/ bin/
COPY database/ database/
COPY docker/ docker/
COPY lang/ lang/
COPY public/ public/
COPY src/ src/
COPY tests/Support/stripe_mock_router.php tests/Support/stripe_mock_router.php

# Code stays owned by root (read-only for the web server); only the log and
# upload directories are writable by www-data.
RUN set -eux; \
    sed -i 's/\r$//' docker/*.sh; \
    chmod 0755 docker/entrypoint.sh; \
    mkdir -p storage/logs public/uploads/avatars public/uploads/covers; \
    chown -R www-data:www-data storage public/uploads

# Fail the build on an Apache configuration error, with and without the simulator.
RUN set -e; \
    . /etc/apache2/envvars; \
    apache2 -t; \
    apache2 -t -D BOOKWISE_STRIPE_SIM

USER www-data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 \
    CMD ["php", "/var/www/bookwise/docker/healthcheck.php"]

ENTRYPOINT ["/var/www/bookwise/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
