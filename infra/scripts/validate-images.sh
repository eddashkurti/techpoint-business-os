#!/usr/bin/env bash
# Isolated runtime check: no published application/database ports and no business data.
set -euo pipefail
umask 077
root="$(realpath "$(dirname "$0")/../..")"
[[ -f "$root/artisan" && -f "$root/infra/compose.yaml" ]] || exit 1
project="tpimagecheck-$(date -u +%Y%m%d%H%M%S)-$$"
[[ "$project" =~ ^tpimagecheck-[0-9]+-[0-9]+$ ]] || exit 1
[[ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$project")" && -z "$(docker volume ls -q --filter "label=com.docker.compose.project=$project")" ]] || exit 1
mkdir -p "$root/work/image-validation"
bundle="$(mktemp -d "$root/work/image-validation/check.XXXXXXXX")"
mkdir -m 700 "$bundle/secrets" "$bundle/tls" "$bundle/tls/live" "$bundle/tls/live/erp.validation.invalid"
bash "$root/infra/scripts/create-secrets.sh" "$bundle/secrets" >/dev/null
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=erp.validation.invalid' -keyout "$bundle/tls/live/erp.validation.invalid/privkey.pem" -out "$bundle/tls/live/erp.validation.invalid/fullchain.pem" >/dev/null 2>&1
cat > "$bundle/compose.env" <<ENV
COMPOSE_PROJECT_NAME=$project
APP_URL=https://erp.validation.invalid
ERP_HOST=erp.validation.invalid
APP_IMAGE=techpoint-business-os
WEB_IMAGE=techpoint-web
APP_IMAGE_TAG=validation
DB_DATABASE=techpoint_validation
DB_USERNAME=techpoint_validation
SECRETS_DIRECTORY=$bundle/secrets
TLS_DIRECTORY=$bundle/tls
ACME_DIRECTORY=$bundle/acme
ENV
compose() { docker compose --project-name "$project" --env-file "$bundle/compose.env" -f "$root/infra/compose.yaml" "$@"; }
cleanup() { status=$?; trap - EXIT; compose down --volumes >/dev/null 2>&1 || true; exit "$status"; }
trap cleanup EXIT
compose up -d --wait db app
compose --profile maintenance run --rm migrate > "$bundle/migrations.log" 2>&1
compose --profile maintenance run --rm migrate php artisan db:seed --force > "$bundle/seeding.log" 2>&1
compose exec -T app php artisan techpoint:reconcile --json > "$bundle/reconciliation.json"
compose exec -T db bash -c 'MYSQL_PWD="$(cat /run/secrets/db_password)" exec mysql --user="$MYSQL_USER" --database="$MYSQL_DATABASE" --batch --skip-column-names --execute="SHOW GRANTS FOR CURRENT_USER;"' > "$bundle/application-grants.txt"
python3 - "$bundle/application-grants.txt" <<'PY'
import sys
value=open(sys.argv[1],encoding='utf-8').read()
assert 'SELECT, INSERT, UPDATE, DELETE' in value
assert not any(word in value for word in ['ALL PRIVILEGES','CREATE','ALTER','DROP','TRIGGER','GRANT OPTION'])
print('PASS: runtime database user has CRUD permissions without schema/trigger privileges.')
PY
docker run --rm --network "${project}_private" -e ERP_HOST=erp.validation.invalid -e 'NGINX_ENVSUBST_FILTER=^ERP_HOST$' -v "$root/infra/nginx/production.conf.template:/etc/nginx/templates/default.conf.template:ro" -v "$bundle/tls:/certificates:ro" techpoint-web:validation nginx -t > "$bundle/nginx-validation.log" 2>&1
echo 'PASS: isolated production PHP-FPM/database startup, privileged migrations, runtime reconciliation and TLS Nginx configuration.'
echo 'Only generated validation secrets/certificates were used. No business deployment or browser acceptance was performed.'
