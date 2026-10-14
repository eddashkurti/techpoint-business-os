#!/usr/bin/env bash
set -euo pipefail
umask 077
cd "$(realpath "$(dirname "$0")/../..")"
[[ ! -e .env ]] || { echo 'CI must run in an isolated checkout without a runtime .env' >&2; exit 1; }
cp .env.example .env
printf '\nDB_PASSWORD=%s\nMYSQL_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)" "$(openssl rand -hex 24)" >> .env
project="tpciverify-$(date -u +%Y%m%d%H%M%S)-$$"
[[ "$project" =~ ^tpciverify-[0-9]+-[0-9]+$ ]] || exit 1
[[ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$project")" && -z "$(docker volume ls -q --filter "label=com.docker.compose.project=$project")" ]] || exit 1
compose() { docker compose --project-name "$project" --env-file .env -f infra/compose.dev.yaml "$@"; }
cleanup() { compose down --volumes; }
trap cleanup EXIT
compose build app
compose up -d db
compose run --rm app composer install --prefer-dist --no-interaction
compose run --rm app php artisan key:generate --force
compose run --rm app php artisan techpoint:routes
docker run --rm -v "$PWD:/app" -w /app -e WAYFINDER_SKIP=1 node:24-bookworm-slim bash -c 'npm ci && npm run build && npx vue-tsc --noEmit && npm audit --audit-level=high'
compose run --rm app bash infra/scripts/test-dev.sh
python3 -m unittest discover -s infra/tests
