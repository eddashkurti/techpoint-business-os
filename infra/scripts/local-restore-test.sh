#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html
[[ "${APP_ENV:-}" == local && "${DB_HOST:-}" == db ]] || { echo 'Local test container required' >&2; exit 1; }
export APP_ENV=testing DB_DATABASE=techpoint_testing DB_USERNAME=root
export DB_PASSWORD="${MYSQL_ROOT_PASSWORD:?}"
php artisan config:clear >/dev/null
php artisan migrate:fresh --force >/dev/null
php tests/Support/create_restore_fixture.php
export MYSQL_PWD="$DB_PASSWORD"
target="techpoint_restore_drill_$(date -u +%Y%m%d%H%M%S)_$$"
[[ "$target" =~ ^techpoint_restore_drill_[0-9_]+$ ]] || exit 1
mkdir -p work/restore-tests
bundle="$(mktemp -d work/restore-tests/drill.XXXXXXXX)"
chmod 700 "$bundle"
# Use the matching MySQL 8.4 client in the development DB container through the host driver.
echo "$target" > "$bundle/target-database"
php -r '$pdo=new PDO("mysql:host=".getenv("DB_HOST"),"root",getenv("DB_PASSWORD"));$name=$argv[1];if(!preg_match("/^techpoint_restore_drill_[0-9_]+$/D",$name))exit(1);$pdo->exec("CREATE DATABASE `".$name."` CHARACTER SET utf8mb4");' "$target"
echo "$bundle"
echo 'Fixture ready for host-side MySQL 8.4 dump/restore validation.'
