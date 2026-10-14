#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$(realpath "$0")")/backup-common.sh"
# Repository initialization is deliberately a separate operator command.
restic init
restic check
