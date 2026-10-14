#!/usr/bin/env bash
set -euo pipefail
for name in APP_KEY DB_PASSWORD; do
    file_name="${name}_FILE"
    file_path="${!file_name:-}"
    if [[ -n "$file_path" ]]; then
        [[ -s "$file_path" ]] || { echo "Missing required $name secret file" >&2; exit 1; }
        printf -v "$name" '%s' "$(cat "$file_path")"
        export "$name"
    fi
done
[[ -n "${APP_KEY:-}" && -n "${DB_PASSWORD:-}" ]] || { echo 'APP_KEY and DB_PASSWORD are required' >&2; exit 1; }
if [[ "${APP_ENV:-production}" == production ]]; then
    [[ "${APP_DEBUG:-false}" == false ]] || { echo 'Production debug must be false' >&2; exit 1; }
    [[ "${APP_URL:-}" == https://* ]] || { echo 'Production APP_URL must use HTTPS' >&2; exit 1; }
fi
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
gosu www-data php artisan package:discover --no-ansi >&2
gosu www-data php artisan config:cache --no-ansi >&2
# FPM master opens the Docker log pipe as root, then starts www-data workers.
# Background workers and maintenance commands run entirely as www-data.
if [[ "${1:-}" == php-fpm ]]; then exec "$@"; fi
exec gosu www-data "$@"
