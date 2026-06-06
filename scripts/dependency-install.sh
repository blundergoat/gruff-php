#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if ! command -v composer >/dev/null 2>&1; then
  echo "error: composer is not available on PATH" >&2
  exit 127
fi

if ! command -v npm >/dev/null 2>&1; then
  echo "error: npm is not available on PATH" >&2
  exit 127
fi

composer install --no-interaction --prefer-dist --no-progress "$@"
composer audit:dependencies

if [[ -f package-lock.json ]]; then
  npm ci --no-audit
else
  npm install --no-audit
fi
npm audit
