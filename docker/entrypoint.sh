#!/bin/sh
set -eu
umask 077

# One-shot CLI commands (including key generation) must not initialize storage.
if [ "${1:-}" = "php-fpm" ]; then
    php /app/bin/console.php init
fi

exec docker-php-entrypoint "$@"
