#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if ! command -v composer >/dev/null 2>&1; then
  echo "error: composer is not available on PATH" >&2
  exit 127
fi

composer install --no-interaction --prefer-dist --no-progress "$@"
composer audit:dependencies
