#!/usr/bin/env bash
# Exercise native Linux file permissions, including when development uses NTFS.
set -euo pipefail
root="$(realpath "$(dirname "$0")/../..")"
volume="tpsecretcheck-$(date -u +%Y%m%d%H%M%S)-$$"
[[ "$volume" =~ ^tpsecretcheck-[0-9]+-[0-9]+$ ]] || exit 1
[[ -z "$(docker volume ls -q --filter "name=^${volume}$")" ]] || exit 1
docker volume create "$volume" >/dev/null
cleanup() { status=$?; trap - EXIT; docker volume rm "$volume" >/dev/null; exit "$status"; }
trap cleanup EXIT
docker run --rm -v "$volume:/secrets" -v "$root/infra/scripts/create-secrets.sh:/make-secrets.sh:ro" --entrypoint bash mysql:8.4 -c 'bash /make-secrets.sh /secrets/protected >/dev/null; test "$(stat -c %a /secrets/protected)" = 700; test "$(stat -c %a /secrets/protected/db_backup_password)" = 444; for name in app_key db_password db_root_password restic_password; do test "$(stat -c %a /secrets/protected/$name)" = 600 || exit 1; done'
mount="$(docker volume inspect --format '{{.Mountpoint}}' "$volume")"
[[ "$mount" == /* && "$mount" == *"/$volume/_data" ]] || exit 1
# Compose binds individual files, so the private host parent is not in the container path.
docker run --rm --user mysql -v "$mount/protected/db_backup_password:/run/secrets/db_backup_password:ro" -v "$mount/protected/db_root_password:/run/secrets/db_root_password:ro" -v "$mount/protected/app_key:/run/secrets/app_key:ro" -v "$mount/protected/db_password:/run/secrets/db_password:ro" -v "$mount/protected/restic_password:/run/secrets/restic_password:ro" --entrypoint bash mysql:8.4 -c 'test -r /run/secrets/db_backup_password; for name in app_key db_password db_root_password restic_password; do if test -r /run/secrets/$name; then exit 1; fi; done'
docker run --rm --user mysql -v "$volume:/secrets:ro" --entrypoint bash mysql:8.4 -c '! test -r /secrets/protected/db_backup_password'
echo 'PASS: MySQL can read its mounted backup secret; other credential files and the private host directory remain inaccessible to its unprivileged user.'
