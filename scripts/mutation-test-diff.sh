#!/usr/bin/env bash
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

MUTATION_SCRIPT_NAME="$(basename "$0")"
export MUTATION_SCRIPT_NAME
exec "$SCRIPT_DIR/mutation-test-full.sh" --diff "$@"
