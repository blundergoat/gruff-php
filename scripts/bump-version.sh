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
#   - If the new version is not a prerelease tag and CHANGELOG.md still has an
#     "Unreleased" marker for that version, stamps it with today's date (or
#     --release-date when supplied).
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

if [[ $# -eq 1 && ( "$1" == "-h" || "$1" == "--help" ) ]]; then
  usage 0
fi

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

# shellcheck disable=SC2016
CURRENT_VERSION="$(php -r '
  $path = $argv[1];
  $body = file_get_contents($path);
  if ($body === false) { exit(1); }
  if (!preg_match("/public const VERSION = \x27([^\x27]+)\x27;/", $body, $match)) { exit(2); }
  echo $match[1];
' "$APPLICATION_PHP")" || {
  echo "error: could not read Application::VERSION from $APPLICATION_PHP" >&2
  exit 1
}

IS_PRERELEASE=0
if [[ "$NEW_VERSION" == *-* ]]; then
  IS_PRERELEASE=1
fi

CHANGELOG_STATE=""
STAMP_DATE=""
if [[ -f "$CHANGELOG" && $IS_PRERELEASE -eq 0 ]]; then
  STAMP_DATE="${RELEASE_DATE:-$(date +%Y-%m-%d)}"
  # shellcheck disable=SC2016
  CHANGELOG_STATE="$(php -r '
    $path = $argv[1];
    $ver  = $argv[2];
    $body = file_get_contents($path);
    if ($body === false) { fwrite(STDERR, "read failed\n"); exit(1); }

    $unreleased = "## " . $ver . " - Unreleased";
    if (preg_match("/^" . preg_quote($unreleased, "/") . "[ \t]*$/m", $body)) {
      echo "unreleased";
      exit(0);
    }

    if (preg_match("/^## " . preg_quote($ver, "/") . " - [0-9]{4}-[0-9]{2}-[0-9]{2}[ \t]*$/m", $body)) {
      echo "dated";
      exit(0);
    }

    echo "missing";
  ' "$CHANGELOG" "$NEW_VERSION")"
fi

if [[ "$CURRENT_VERSION" == "$NEW_VERSION" ]]; then
  echo "Application::VERSION is already $NEW_VERSION; nothing to do."
else
  # shellcheck disable=SC2016
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
    if ($updated === null) { fwrite(STDERR, "VERSION replacement failed\n"); exit(1); }
    if ($count !== 1) { fwrite(STDERR, "VERSION constant not found\n"); exit(1); }
    if (file_put_contents($path, $updated) === false) { fwrite(STDERR, "write failed\n"); exit(1); }
  ' "$APPLICATION_PHP" "$NEW_VERSION"
  echo "Updated Application::VERSION: $CURRENT_VERSION -> $NEW_VERSION"
fi

if [[ -n "$CHANGELOG_STATE" ]]; then
  if [[ "$CHANGELOG_STATE" == "unreleased" ]]; then
    # shellcheck disable=SC2016
    php -r '
      $path  = $argv[1];
      $ver   = $argv[2];
      $date  = $argv[3];
      $body  = file_get_contents($path);
      if ($body === false) { fwrite(STDERR, "read failed\n"); exit(1); }
      $body  = preg_replace(
        "/^## " . preg_quote($ver, "/") . " - Unreleased[ \t]*$/m",
        "## $ver - $date",
        $body,
        1,
        $count
      );
      if ($body === null) { fwrite(STDERR, "changelog replacement failed\n"); exit(1); }
      if ($count !== 1) { fwrite(STDERR, "changelog heading not stamped\n"); exit(1); }
      if (file_put_contents($path, $body) === false) { fwrite(STDERR, "write failed\n"); exit(1); }
    ' "$CHANGELOG" "$NEW_VERSION" "$STAMP_DATE"
    echo "Stamped CHANGELOG entry: ## $NEW_VERSION - $STAMP_DATE"
  elif [[ "$CHANGELOG_STATE" == "dated" ]]; then
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
