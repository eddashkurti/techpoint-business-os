#!/usr/bin/env bash
set -euo pipefail
root="$(realpath "$(dirname "$0")/../..")"
cd "$root"
revision="$(git -c "safe.directory=$root" rev-parse HEAD)"
[[ "$revision" =~ ^[a-f0-9]{40}$ ]] || exit 1
docker build --target app --build-arg "APP_BUILD_REVISION=$revision" -t techpoint-business-os:validation -f infra/php/Dockerfile .
docker build --target web --build-arg "APP_BUILD_REVISION=$revision" -t techpoint-web:validation -f infra/php/Dockerfile .
