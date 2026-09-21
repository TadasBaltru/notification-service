#!/bin/sh
set -e

# Mirrors the upstream FrankenPHP entrypoint: a leading option means "run the server".
if [ "${1#-}" != "$1" ]; then
	set -- frankenphp run "$@"
fi

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	if [ ! -d vendor ]; then
		echo "Installing Composer dependencies (first start, this takes a minute)..."
		composer install --prefer-dist --no-progress --no-interaction
	fi

	mkdir -p var/cache var/log
fi

exec docker-php-entrypoint "$@"
