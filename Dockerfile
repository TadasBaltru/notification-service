# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1-php8.4-alpine AS base

WORKDIR /app

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer/composer:2-bin /composer /usr/bin/composer

# git is needed by Composer to install packages from source
RUN apk add --no-cache git

RUN install-php-extensions \
	apcu \
	intl \
	opcache \
	pdo_mysql \
	zip

COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV SERVER_NAME=":80"

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]

FROM base AS dev

ENV APP_ENV=dev
ENV XDEBUG_MODE=off

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY docker/php/conf.d/app.dev.ini $PHP_INI_DIR/conf.d/

RUN install-php-extensions xdebug
