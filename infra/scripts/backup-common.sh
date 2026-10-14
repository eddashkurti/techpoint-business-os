#!/usr/bin/env bash
# Source from executable backup commands; never turn on shell tracing.
set -euo pipefail
umask 077
config_path="${TECHPOINT_BACKUP_CONFIG:-/etc/techpoint/backup.env}"
[[ -r "$config_path" ]] || { echo 'Backup configuration file is unavailable' >&2; exit 1; }
# This is a trusted root-owned shell assignment file, not an uploaded document.
source "$config_path"
for program in docker restic jq sha256sum gzip flock python3; do
    command -v "$program" >/dev/null || { echo "Missing $program" >&2; exit 1; }
done
: "${TECHPOINT_ROOT:?}" "${COMPOSE_ENV_FILE:?}" "${SECRETS_DIRECTORY:?}" "${RESTIC_REPOSITORY:?}" "${RESTIC_PASSWORD_FILE:?}" "${BACKUP_HOST_ID:?}" "${BACKUP_TAG:?}"
[[ "$TECHPOINT_ROOT" == /* && "$TECHPOINT_ROOT" != / && -f "$TECHPOINT_ROOT/artisan" ]] || { echo 'Invalid application root' >&2; exit 1; }
# A local path is intentionally rejected as the only automated backup repository.
case "$RESTIC_REPOSITORY" in
    s3:https://*|b2:*|sftp:*|rest:https://*) ;;
    *) echo 'An encrypted remote repository is required; local-only backups are refused' >&2; exit 1 ;;
esac
[[ "$RESTIC_REPOSITORY" != *YOUR_* && "$RESTIC_REPOSITORY" != *example.invalid* ]] || { echo 'Backup endpoint is still a placeholder' >&2; exit 1; }
[[ -s "$RESTIC_PASSWORD_FILE" ]] || { echo 'Restic recovery password file unavailable' >&2; exit 1; }
if [[ -n "${AWS_ACCESS_KEY_ID_FILE:-}" ]]; then
    [[ -s "$AWS_ACCESS_KEY_ID_FILE" && -s "${AWS_SECRET_ACCESS_KEY_FILE:-}" ]] || { echo 'Backup provider secret files unavailable' >&2; exit 1; }
    export AWS_ACCESS_KEY_ID="$(cat "$AWS_ACCESS_KEY_ID_FILE")"
    export AWS_SECRET_ACCESS_KEY="$(cat "$AWS_SECRET_ACCESS_KEY_FILE")"
fi
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE
export RESTIC_CACHE_DIR="${RESTIC_CACHE_DIR:-/var/cache/techpoint-restic}"
BACKUP_STAGING_DIRECTORY="${BACKUP_STAGING_DIRECTORY:-/var/lib/techpoint/backup-staging}"
BACKUP_STATUS_DIRECTORY="${BACKUP_STATUS_DIRECTORY:-/var/lib/techpoint/backup-status}"
for path in "$BACKUP_STAGING_DIRECTORY" "$BACKUP_STATUS_DIRECTORY" "$RESTIC_CACHE_DIR"; do
    [[ "$path" == /* && "$path" != / ]] || { echo 'Backup paths must be absolute dedicated directories' >&2; exit 1; }
    mkdir -p "$path"
    chmod 700 "$path"
done
compose() { docker compose --env-file "$COMPOSE_ENV_FILE" -f "$TECHPOINT_ROOT/infra/compose.yaml" "$@"; }
database_exec() {
    compose exec -T db bash -c 'MYSQL_PWD="$(cat /run/secrets/db_backup_password)" exec mysql --user=techpoint_backup --database="$MYSQL_DATABASE" --batch --skip-column-names "$@"' bash "$@"
}
capture_table_counts() {
    local table tables
    tables="$(database_exec --execute="SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;")" || return 1
    [[ -n "$tables" ]] || { echo 'Application database contains no tables' >&2; return 1; }
    while IFS= read -r table; do
        [[ "$table" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { echo 'Unexpected database table name' >&2; return 1; }
        database_exec --execute="SELECT '$table', COUNT(*) FROM \`$table\`;"
    done <<< "$tables"
}
