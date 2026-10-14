#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$(realpath "$0")")/backup-common.sh"
exec 9>"$BACKUP_STATUS_DIRECTORY/operations.lock"
flock -n 9 || { echo 'Another ERP backup/deployment operation is running' >&2; exit 1; }
restic cat config >/dev/null
for service in app queue scheduler; do
    compose ps --status running --services | grep -qx "$service" || { echo "Required service $service is not running" >&2; exit 1; }
done
if compose exec -T app test -f storage/framework/down; then
    echo 'ERP already in maintenance; refusing to assume ownership of that state' >&2
    exit 1
fi
bundle="$(mktemp -d "$BACKUP_STAGING_DIRECTORY/snapshot.XXXXXXXX")"
paused=false
resume() {
    if [[ "$paused" == true ]]; then
        # Leave maintenance enabled on a failed restart; report a nonzero result.
        compose up -d --no-deps --wait app || return 1
        compose up -d --no-deps queue scheduler || return 1
        compose exec -T app php artisan up --no-ansi || return 1
        paused=false
    fi
}
cleanup() {
    status=$?
    trap - EXIT
    if ! resume; then
        echo 'CRITICAL: service recovery failed; maintenance remains enabled. Operator action is required.' >&2
        status=1
    fi
    # Exact files in the unique private directory only; retain failed captures for investigation.
    if [[ "$status" == 0 ]]; then
        rm -f -- "$bundle/database.sql.gz" "$bundle/attachments.tar.gz" "$bundle/app_key" "$bundle/manifest.json" "$bundle/table-counts.tsv" "$bundle/SHA256SUMS" "$bundle/snapshot.json"
        rmdir -- "$bundle"
    fi
    exit "$status"
}
trap cleanup EXIT
paused=true
compose exec -T app php artisan down --retry=60 --no-ansi
# Drain workers and PHP requests before taking the combined database/file snapshot.
compose stop -t 100 queue scheduler
compose stop -t 70 app
nontransactional="$(database_exec --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND engine <> 'InnoDB';")"
[[ "$nontransactional" == 0 ]] || { echo 'Only InnoDB tables support this backup consistency policy' >&2; exit 1; }
enabled_events="$(database_exec --execute="SELECT COUNT(*) FROM information_schema.events WHERE EVENT_SCHEMA=DATABASE() AND STATUS='ENABLED';")"
[[ "$enabled_events" == 0 ]] || { echo 'Enabled MySQL events must be reviewed/quiesced before this combined backup' >&2; exit 1; }
capture_table_counts > "$bundle/table-counts.tsv"
compose exec -T db bash -c 'MYSQL_PWD="$(cat /run/secrets/db_backup_password)" exec mysqldump --user=techpoint_backup --single-transaction --quick --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF --hex-blob --triggers --events "$MYSQL_DATABASE"' | gzip -c > "$bundle/database.sql.gz"
compose run --rm --no-deps --entrypoint tar app -C /var/www/html/storage/app -cf - . | gzip -c > "$bundle/attachments.tar.gz"
cp "$SECRETS_DIRECTORY/app_key" "$bundle/app_key"
app_image_id="$(docker inspect --format '{{.Image}}' "$(compose ps -a -q app)")"
git_revision="$(docker image inspect "$app_image_id" | jq -r '.[0].Config.Labels["org.opencontainers.image.revision"]')"
[[ "$git_revision" =~ ^[a-f0-9]{40}$ ]] || { echo 'Running application image must carry its reviewed 40-character Git revision label' >&2; exit 1; }
jq -n --arg utc "$(date -u +%FT%TZ)" --arg revision "$git_revision" --arg host "$BACKUP_HOST_ID" \
    '{format:1,captured_at_utc:$utc,source_revision:$revision,host:$host,files:["database.sql.gz","attachments.tar.gz","app_key","table-counts.tsv"],consistency:"InnoDB transaction; ERP writers paused; attachments captured before resume"}' > "$bundle/manifest.json"
(cd "$bundle" && sha256sum database.sql.gz attachments.tar.gz app_key table-counts.tsv manifest.json > SHA256SUMS)
resume
restic backup --json --host "$BACKUP_HOST_ID" --tag "$BACKUP_TAG" "$bundle" > "$bundle/snapshot.json"
snapshot_id="$(jq -r 'select(.message_type=="summary") | .snapshot_id' "$bundle/snapshot.json" | tail -n 1)"
[[ "$snapshot_id" =~ ^[a-f0-9]{8,64}$ ]] || { echo 'Backup did not return a snapshot ID' >&2; exit 1; }
restic check
# Group by stable host/tag rather than the unique staging pathname.
restic forget --host "$BACKUP_HOST_ID" --tag "$BACKUP_TAG" --group-by host,tags --keep-daily 7 --keep-weekly 4 --keep-monthly 12 --prune
printf '%s\n' "$snapshot_id" > "$BACKUP_STATUS_DIRECTORY/latest.snapshot-id"
date -u +%FT%TZ > "$BACKUP_STATUS_DIRECTORY/last-success.utc"
echo "Encrypted off-server snapshot completed: $snapshot_id"
