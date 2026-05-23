#!/usr/bin/env bash
set -euo pipefail

# Update the gruff-php application version and stamp the matching changelog entry.
#
# Usage:
#   scripts/bump-version.sh <new-version> [--release-date YYYY-MM-DD]
#
# Examples:
#   scripts/bump-version.sh 0.1.0
#   scripts/bump-version.sh 0.1.0 --release-date 2026-05-19
#   scripts/bump-version.sh 0.2.0-dev   # post-release dev bump
#
# Behaviour:
#   - Rewrites Application::VERSION in src/Console/Application.php.
#   - If the new version is not a *-dev/*-rc/*-alpha/*-beta tag and CHANGELOG.md
#     still has an "Unreleased" marker for that version, stamps it with today's
#     date (or --release-date when supplied).
#   - Refuses to overwrite an already-dated changelog heading.
#   - Prints a diff summary on success.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

APPLICATION_PHP="src/Console/Application.php"
CHANGELOG="CHANGELOG.md"

usage() {
  sed -n '3,18p' "$0" | sed 's/^# \{0,1\}//'
  exit "${1:-1}"
}

if [[ $# -lt 1 ]]; then
  usage 1
fi

NEW_VERSION="$1"
shift

RELEASE_DATE=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --release-date)
      [[ $# -ge 2 ]] || { echo "error: --release-date requires a value" >&2; exit 1; }
      RELEASE_DATE="$2"
      shift 2
      ;;
    -h|--help)
      usage 0
      ;;
    *)
      echo "error: unknown argument: $1" >&2
      usage 1
      ;;
  esac
done

if [[ ! "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.]+)?$ ]]; then
  echo "error: version must look like X.Y.Z or X.Y.Z-suffix, got: $NEW_VERSION" >&2
  exit 1
fi

if [[ -n "$RELEASE_DATE" && ! "$RELEASE_DATE" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
  echo "error: --release-date must be YYYY-MM-DD, got: $RELEASE_DATE" >&2
  exit 1
fi

if [[ ! -f "$APPLICATION_PHP" ]]; then
  echo "error: $APPLICATION_PHP not found (run from project root)" >&2
  exit 1
fi

CURRENT_VERSION="$(grep -oE "VERSION = '[^']+'" "$APPLICATION_PHP" | head -n1 | sed "s/VERSION = '\(.*\)'/\1/")"
if [[ -z "$CURRENT_VERSION" ]]; then
  echo "error: could not read Application::VERSION from $APPLICATION_PHP" >&2
  exit 1
fi

if [[ "$CURRENT_VERSION" == "$NEW_VERSION" ]]; then
  echo "Application::VERSION is already $NEW_VERSION; nothing to do."
else
  php -r '
    $path = $argv[1];
    $new  = $argv[2];
    $body = file_get_contents($path);
    if ($body === false) { fwrite(STDERR, "read failed\n"); exit(1); }
    $updated = preg_replace(
      "/(public const VERSION = )\x27[^\x27]+\x27;/",
      "$1\x27" . $new . "\x27;",
      $body,
      1,
      $count
    );
    if ($count !== 1) { fwrite(STDERR, "VERSION constant not found\n"); exit(1); }
    file_put_contents($path, $updated);
  ' "$APPLICATION_PHP" "$NEW_VERSION"
  echo "Updated Application::VERSION: $CURRENT_VERSION -> $NEW_VERSION"
fi

IS_PRERELEASE=0
if [[ "$NEW_VERSION" == *-* ]]; then
  IS_PRERELEASE=1
fi

if [[ -f "$CHANGELOG" && $IS_PRERELEASE -eq 0 ]]; then
  STAMP_DATE="${RELEASE_DATE:-$(date +%Y-%m-%d)}"
  if grep -qE "^## ${NEW_VERSION} - Unreleased\s*$" "$CHANGELOG"; then
    php -r '
      $path  = $argv[1];
      $ver   = $argv[2];
      $date  = $argv[3];
      $body  = file_get_contents($path);
      $body  = preg_replace(
        "/^## " . preg_quote($ver, "/") . " - Unreleased\s*$/m",
        "## $ver - $date",
        $body,
        1,
        $count
      );
      if ($count !== 1) { fwrite(STDERR, "changelog heading not stamped\n"); exit(1); }
      file_put_contents($path, $body);
    ' "$CHANGELOG" "$NEW_VERSION" "$STAMP_DATE"
    echo "Stamped CHANGELOG entry: ## $NEW_VERSION - $STAMP_DATE"
  elif grep -qE "^## ${NEW_VERSION} - [0-9]{4}-[0-9]{2}-[0-9]{2}\s*$" "$CHANGELOG"; then
    echo "CHANGELOG already has a dated entry for $NEW_VERSION; left untouched."
  else
    echo "warning: no '## $NEW_VERSION - Unreleased' heading found in $CHANGELOG" >&2
    echo "         add the entry manually before tagging." >&2
  fi
fi

echo
echo "Next steps:"
echo "  composer validate --strict"
echo "  composer check"
echo "  composer test"
echo "  git diff $APPLICATION_PHP $CHANGELOG"
