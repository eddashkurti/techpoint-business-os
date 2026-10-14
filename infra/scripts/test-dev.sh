#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html
if [ "${APP_ENV:-}" != local ] || [ "${DB_HOST:-}" != db ]; then
  echo 'Refusing the local test runner outside the isolated development container.' >&2
  exit 1
fi
export APP_ENV=testing
export DB_DATABASE=techpoint_testing
export DB_USERNAME=root
export DB_PASSWORD="${MYSQL_ROOT_PASSWORD:?Test migration credential missing}"
php artisan config:clear >/dev/null
php vendor/bin/phpunit "$@"
