#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
exec "$@"
