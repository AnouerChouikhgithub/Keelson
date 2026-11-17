# syntax=docker/dockerfile:1

# Production image for the Symfony API: dependencies and the warm cache are
# built in a throwaway stage, and the runtime stage carries only what is needed
# to serve requests.
#
#   docker build -t keelson-api .
#   docker run --rm -p 9000:9000 -e APP_SECRET=... -e DATABASE_URL=... keelson-api
#
# The image serves FastCGI on 9000 for a web server (see frontend/nginx.conf in
# docker-compose.prod.example.yml). It runs as www-data, never as root.

FROM php:8.3-fpm-alpine AS vendor

# No extensions are compiled here on purpose. This stage only resolves and warms
# the container, which needs neither PostgreSQL nor ICU; the runtime stage installs
# them once, into the image that actually serves requests.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

# Dependencies first, so a source change does not reinstall them. `--no-dev`
# keeps phpunit, phpstan and the fixer out of the runtime; `--no-scripts` defers
# the cache warm-up until the code is present.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
        --no-scripts --no-autoloader

COPY . .

# The production autoloader is authoritative and optimised, and the container is
# warmed with the production environment so the first request does not compile the
# container graph. The values below are compile-time placeholders: the container is
# built with no .env at all, `%env()%` is stored as a reference and resolved at
# runtime, so nothing here is baked into the image. Every one of them must be
# supplied by the runtime environment — app:config:check refuses to serve without.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && APP_ENV=prod APP_DEBUG=0 \
       APP_SECRET='build-time-placeholder-secret-never-used-at-runtime' \
       DATABASE_URL='postgresql://placeholder:placeholder@127.0.0.1:5432/placeholder?serverVersion=16&charset=utf8' \
       DEFAULT_URI='https://placeholder.invalid' \
       FRONTEND_URL='https://placeholder.invalid' \
       MAILER_DSN='smtp://127.0.0.1:25' \
       TRUSTED_HOSTS='placeholder.invalid' \
       REQUIRE_VERIFIED_EMAIL=true \
       php bin/console cache:warmup --no-debug \
    && rm -rf var/cache/prod/*~

FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache fcgi icu-libs libpq \
    && rm -rf /var/cache/apk/*

# Same extensions as the build stage, compiled again because the runtime stage is
# a different base layer (the alternative is copying the extension directory,
# which ties the image to the exact PHP build).
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl opcache \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY docker/php.ini /usr/local/etc/php/conf.d/99-keelson.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-keelson.conf

WORKDIR /app
COPY --from=vendor --chown=www-data:www-data /app /app

# Only the log and cache directories are writable; the code is not.
RUN mkdir -p var/log var/cache && chown -R www-data:www-data var

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

USER www-data

EXPOSE 9000

# /healthz is answered by the application itself, so a container that is up but
# cannot boot the kernel (missing secret, broken cache) is reported unhealthy.
# php-fpm answers FastCGI, not HTTP, so the check speaks FastCGI to it and looks
# for the body the health endpoint returns. A container that is up but cannot boot
# the kernel (missing secret, broken cache) answers with an error page instead.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD REQUEST_METHOD=GET SCRIPT_NAME=/index.php SCRIPT_FILENAME=/app/public/index.php \
        PATH_INFO=/healthz REQUEST_URI=/healthz SERVER_NAME=localhost \
        cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q '"status":"ok"'

CMD ["php-fpm", "--nodaemonize"]
