#!/usr/bin/env bash
set -euo pipefail
umask 077
root="$(realpath "$(dirname "$0")/../..")"
cd "$root"
[[ -f .env && -f artisan && -f infra/compose.dev.yaml ]] || exit 1
compose() { docker compose --env-file .env -f infra/compose.dev.yaml "$@"; }
compose run --rm app bash infra/scripts/local-restore-test.sh
bundle="$(find "$root/work/restore-tests" -mindepth 1 -maxdepth 1 -type d -name 'drill.*' -printf '%T@ %p\n' | sort -nr | head -n 1 | cut -d' ' -f2-)"
[[ "$bundle" == "$root/work/restore-tests/drill."* && -f "$bundle/target-database" ]] || exit 1
target="$(cat "$bundle/target-database")"
[[ "$target" =~ ^techpoint_restore_drill_[0-9_]+$ ]] || exit 1
compose exec -T db bash -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --user=root --single-transaction --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF --hex-blob --triggers techpoint_testing' | gzip -c > "$bundle/database.sql.gz"
gzip -dc "$bundle/database.sql.gz" | compose exec -T db bash -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root --database="$1"' bash "$target"
count_sql() { compose exec -T db bash -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root --batch --skip-column-names --database="$1" --execute="SELECT (SELECT COUNT(*) FROM journals),(SELECT COUNT(*) FROM journal_lines),(SELECT COUNT(*) FROM stock_movements),(SELECT SUM(on_hand) FROM stock_balances),(SELECT SUM(value) FROM stock_balances),(SELECT HEX(name) FROM parties LIMIT 1);"' bash "$1"; }
count_sql techpoint_testing > "$bundle/source.tsv"
count_sql "$target" > "$bundle/restored.tsv"
diff -u "$bundle/source.tsv" "$bundle/restored.tsv"
compose run --rm --no-deps -e DB_DATABASE="$target" app bash -c 'export DB_USERNAME=root DB_PASSWORD="$MYSQL_ROOT_PASSWORD"; exec php artisan techpoint:reconcile --json' > "$bundle/reconciliation.json"
compose run --rm --no-deps -e DB_DATABASE="$target" app bash -c 'export DB_USERNAME=root DB_PASSWORD="$MYSQL_ROOT_PASSWORD"; exec php tests/Support/verify_restore_encryption.php' > "$bundle/encryption-check.txt"
echo 'PASS: MySQL 8.4 nonempty backup restored into an independently named database; stock, journals, Unicode and encrypted-field access verified.'
echo 'This is a local restoration test. A real off-server backup destination and recovery drill remain required before production.'
