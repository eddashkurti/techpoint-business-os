#!/usr/bin/env bash
set -euo pipefail
[[ "$MYSQL_DATABASE" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { echo 'Invalid DB name' >&2; exit 1; }
[[ "$MYSQL_USER" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { echo 'Invalid application DB username' >&2; exit 1; }
backup_password="$(cat /run/secrets/db_backup_password)"
grant_database="${MYSQL_DATABASE//_/\\_}"
# The supplied setup script creates hexadecimal passwords. Restrict this init
# input rather than interpolating arbitrary unescaped SQL secret text.
[[ "$backup_password" =~ ^[a-f0-9]{64}$ ]] || { echo 'Backup password must be 64 hex characters' >&2; exit 1; }
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root <<SQL
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${grant_database}\`.* TO '${MYSQL_USER}'@'%';
CREATE USER IF NOT EXISTS 'techpoint_backup'@'%' IDENTIFIED BY '${backup_password}';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON \`${grant_database}\`.* TO 'techpoint_backup'@'%';
SQL
unset backup_password
