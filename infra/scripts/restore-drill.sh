#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$(realpath "$0")")/backup-common.sh"
snapshot="${1:-}"
expected_revision="${2:-}"
[[ "$snapshot" =~ ^[a-f0-9]{8,64}$ && "$expected_revision" =~ ^[a-f0-9]{40}$ ]] || { echo 'Usage: restore-drill.sh SNAPSHOT_ID EXPECTED_40_CHARACTER_SOURCE_COMMIT' >&2; exit 1; }
restic check
drill_root="$(mktemp -d "$BACKUP_STAGING_DIRECTORY/drill.XXXXXXXX")"
chmod 700 "$drill_root"
restic restore "$snapshot" --target "$drill_root/recovered"
mapfile -t manifests < <(find "$drill_root/recovered" -type f -name manifest.json)
[[ "${#manifests[@]}" == 1 ]] || { echo 'Expected one backup bundle manifest' >&2; exit 1; }
export RESTORE_BUNDLE="$(dirname "${manifests[0]}")"
python3 "$TECHPOINT_ROOT/infra/scripts/verify-backup.py" "$RESTORE_BUNDLE"
recorded_revision="$(jq -r .source_revision "$RESTORE_BUNDLE/manifest.json")"
[[ "$recorded_revision" == "$expected_revision" ]] || { echo 'Backup source commit does not match the requested restore release' >&2; exit 1; }
export RESTORE_APP_IMAGE="$(compose config --format json | jq -r '.services.app.image')"
# Require an explicitly labeled image; never infer that the current image is compatible.
image_revision="$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$RESTORE_APP_IMAGE")"
[[ "$image_revision" == "$expected_revision" ]] || { echo 'Image revision label differs from backup; obtain the correct reviewed image before restoring' >&2; exit 1; }
project="tprestore-$(date -u +%Y%m%d%H%M%S)-${RANDOM}"
[[ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$project")" && -z "$(docker volume ls -q --filter "label=com.docker.compose.project=$project")" ]] || { echo 'Restore project identity already exists' >&2; exit 1; }
export RESTORE_SECRET_DIRECTORY="$drill_root/secrets"
mkdir -m 700 "$RESTORE_SECRET_DIRECTORY"
openssl rand -hex 32 > "$RESTORE_SECRET_DIRECTORY/db_password"
openssl rand -hex 32 > "$RESTORE_SECRET_DIRECTORY/db_root_password"
restore_compose() { docker compose --project-name "$project" -f "$TECHPOINT_ROOT/infra/compose.restore.yaml" "$@"; }
cleanup() {
    status=$?
    trap - EXIT
    # Only these independently named restore containers are stopped; live ERP is untouched.
    restore_compose down || status=1
    echo "Drill artifacts/volumes retained under $drill_root and project $project for review."
    exit "$status"
}
trap cleanup EXIT
restore_compose up -d --wait db
gzip -dc "$RESTORE_BUNDLE/database.sql.gz" | restore_compose exec -T db bash -c 'MYSQL_PWD="$(cat /run/secrets/restore_root_password)" exec mysql --user=root --database=techpoint_restore'
restore_compose run --rm --no-deps --entrypoint tar validate --extract --gzip --file=/restore/attachments.tar.gz --directory=/var/www/html/storage/app --no-same-owner --no-same-permissions
restore_count_exec() { restore_compose exec -T db bash -c 'MYSQL_PWD="$(cat /run/secrets/restore_db_password)" exec mysql --user=techpoint_restore --database=techpoint_restore --batch --skip-column-names "$@"' bash "$@"; }
restored_tables="$(restore_count_exec --execute="SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;")"
[[ -n "$restored_tables" ]] || { echo 'Restored database has no tables' >&2; exit 1; }
while IFS= read -r table; do
    [[ "$table" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { echo 'Unexpected restored table name' >&2; exit 1; }
    restore_count_exec --execute="SELECT '$table', COUNT(*) FROM \`$table\`;"
done <<< "$restored_tables" > "$drill_root/restored-table-counts.tsv"
diff -u "$RESTORE_BUNDLE/table-counts.tsv" "$drill_root/restored-table-counts.tsv"
restore_compose run --rm --no-deps validate > "$drill_root/reconciliation.json"
jq empty "$drill_root/reconciliation.json"
printf '%s\n' "$snapshot" > "$BACKUP_STATUS_DIRECTORY/latest-tested.snapshot-id"
date -u +%FT%TZ > "$BACKUP_STATUS_DIRECTORY/last-restore-success.utc"
echo 'Restore passed: exact table counts, attachment checksums, and application stock/journal reconciliation. Review encrypted-field access and external document references before a real cutover.'
