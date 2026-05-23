#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

HOST="${GRUFF_DASHBOARD_HOST:-127.0.0.1}"
PORT="${GRUFF_DASHBOARD_PORT:-8765}"
PROJECT="${GRUFF_DASHBOARD_PROJECT:-$ROOT_DIR}"
SCAN_TIMEOUT="${GRUFF_DASHBOARD_SCAN_TIMEOUT:-120}"

if [[ $# -gt 0 ]]; then
  PATHS=("$@")
elif [[ -d src ]]; then
  PATHS=(src)
else
  PATHS=(.)
fi

printf 'Starting gruff dashboard\n'
printf '  URL:     http://%s:%s/\n' "$HOST" "$PORT"
printf '  Project: %s\n' "$PROJECT"
printf '  Paths:   %s\n' "${PATHS[*]}"
printf '\n'

exec php bin/gruff-php dashboard "${PATHS[@]}" \
  --project "$PROJECT" \
  --host "$HOST" \
  --port "$PORT" \
  --scan-timeout "$SCAN_TIMEOUT"
