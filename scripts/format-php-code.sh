#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

exec vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php "$@"
