#!/usr/bin/env bash
set -euo pipefail
# Only the isolated development container. The root password stays in process memory.
cd /var/www/html
if [ "${APP_ENV:-}" != local ] || [ "${DB_HOST:-}" != db ] || [ "${DB_DATABASE:-}" != techpoint ]; then
  echo 'Refusing development migration outside the isolated local database.' >&2
  exit 1
fi
export DB_USERNAME=root
export DB_PASSWORD="${MYSQL_ROOT_PASSWORD:?Local migration credential missing}"
if [ "${1:-}" = --reset ]; then
  php artisan migrate:fresh --force
else
  php artisan migrate --force
fi
php artisan db:seed --force
