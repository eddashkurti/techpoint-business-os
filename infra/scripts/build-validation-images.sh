#!/usr/bin/env bash
set -euo pipefail
root="$(realpath "$(dirname "$0")/../..")"
cd "$root"
[[ -z "$(git -c "safe.directory=$root" status --porcelain)" ]] || { echo 'Commit source changes before building revision-labeled release images.' >&2; exit 1; }
revision="$(git -c "safe.directory=$root" rev-parse HEAD)"
[[ "$revision" =~ ^[a-f0-9]{40}$ ]] || exit 1
docker build --target app --build-arg "APP_BUILD_REVISION=$revision" -t techpoint-business-os:validation -f infra/php/Dockerfile .
docker build --target web --build-arg "APP_BUILD_REVISION=$revision" -t techpoint-web:validation -f infra/php/Dockerfile .
