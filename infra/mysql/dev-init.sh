#!/usr/bin/env bash
set -euo pipefail
# Development only: the app account can use a separate testing database.
[[ "$MYSQL_USER" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { echo 'Invalid development DB user' >&2; exit 1; }
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root <<SQL
CREATE DATABASE IF NOT EXISTS techpoint_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON techpoint_testing.* TO '${MYSQL_USER}'@'%';
SQL
