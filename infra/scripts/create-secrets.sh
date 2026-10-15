#!/usr/bin/env bash
set -euo pipefail
umask 077
destination="${1:-}"
[[ "$destination" == /* && "$destination" != / ]] || { echo 'Usage: create-secrets.sh /absolute/dedicated/secret-directory' >&2; exit 1; }
command -v openssl >/dev/null
mkdir -p "$destination"
chmod 700 "$destination"
for name in db_password db_root_password db_backup_password restic_password; do
    [[ ! -e "$destination/$name" ]] || { echo "Refusing to overwrite existing $name" >&2; exit 1; }
done
[[ ! -e "$destination/app_key" ]] || { echo 'Refusing to overwrite an existing APP_KEY' >&2; exit 1; }
for name in db_password db_root_password db_backup_password restic_password; do
    openssl rand -hex 32 > "$destination/$name"
done
printf 'base64:%s\n' "$(openssl rand -base64 32)" > "$destination/app_key"
chmod 600 "$destination"/*
# Compose binds this file into MySQL's /run/secrets directory. MySQL runs its
# initialization scripts as the mysql user, so it must be able to read it.
# The 0700 host parent still prevents other host users from reaching the file;
# only the database service receives this particular secret mount.
chmod 444 "$destination/db_backup_password"
echo 'Secret files created. Keep APP_KEY and restic recovery material independently recoverable.'
