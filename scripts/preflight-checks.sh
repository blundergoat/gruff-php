#!/usr/bin/env bash

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
    BOLD=$'\033[1m'
    DIM=$'\033[2m'
    GREEN=$'\033[32m'
    RED=$'\033[31m'
    RESET=$'\033[0m'
else
    BOLD=''
    DIM=''
    GREEN=''
    RED=''
    RESET=''
fi

line() {
    printf '%s\n' '======================================================================'
}

thin_line() {
    printf '%s\n' '----------------------------------------------------------------------'
}

duration() {
    local seconds=$1
    local minutes=$((seconds / 60))
    local remainder=$((seconds % 60))

    if ((minutes > 0)); then
        printf '%dm %02ds' "$minutes" "$remainder"
    else
        printf '%ds' "$remainder"
    fi
}

command_label() {
    local first=true

    for part in "$@"; do
        if [[ "$first" == true ]]; then
            first=false
        else
            printf ' '
        fi

        printf '%q' "$part"
    done
}

print_header() {
    line
    printf '%sgruff-php preflight checks%s\n' "$BOLD" "$RESET"
    thin_line
    printf '%sProject:%s %s\n' "$DIM" "$RESET" "$REPO_ROOT"

    if command -v php >/dev/null 2>&1; then
        printf '%sPHP:%s     %s\n' "$DIM" "$RESET" "$(php -r 'echo PHP_VERSION;')"
    fi

    printf '%sStarted:%s %s\n' "$DIM" "$RESET" "$(date '+%Y-%m-%d %H:%M:%S %Z')"
    line
}

run_step() {
    local title=$1
    shift
    local started_at
    local finished_at
    local status

    started_at=$(date +%s)

    printf '\n%s%s%s\n' "$BOLD" "$title" "$RESET"
    thin_line
    printf '%sCommand:%s ' "$DIM" "$RESET"
    command_label "$@"
    printf '\n\n'

    "$@"
    status=$?
    finished_at=$(date +%s)

    printf '\n'

    if ((status == 0)); then
        printf '%sPASS%s %s completed in %s.\n' "$GREEN" "$RESET" "$title" "$(duration "$((finished_at - started_at))")"
    else
        printf '%sFAIL%s %s exited with code %d after %s.\n' "$RED" "$RESET" "$title" "$status" "$(duration "$((finished_at - started_at))")"
    fi

    return "$status"
}

print_summary() {
    local total_status=$1
    local phpstan_status=$2
    local test_status=$3
    local elapsed=$4

    printf '\n'
    line
    printf '%sSummary%s\n' "$BOLD" "$RESET"
    thin_line

    if ((phpstan_status == 0)); then
        printf '%sPASS%s PHPStan level 10\n' "$GREEN" "$RESET"
    else
        printf '%sFAIL%s PHPStan level 10 (exit %d)\n' "$RED" "$RESET" "$phpstan_status"
    fi

    if ((test_status == 0)); then
        printf '%sPASS%s PHPUnit test suite\n' "$GREEN" "$RESET"
    else
        printf '%sFAIL%s PHPUnit test suite (exit %d)\n' "$RED" "$RESET" "$test_status"
    fi

    thin_line

    if ((total_status == 0)); then
        printf '%sAll preflight checks passed in %s.%s\n' "$GREEN" "$(duration "$elapsed")" "$RESET"
    else
        printf '%sPreflight failed in %s. Fix the failing step above and rerun.%s\n' "$RED" "$(duration "$elapsed")" "$RESET"
    fi

    line
}

main() {
    local started_at
    local finished_at
    local phpstan_status=0
    local test_status=0
    local total_status=0

    started_at=$(date +%s)
    cd "$REPO_ROOT" || return 1

    print_header

    if ! command -v composer >/dev/null 2>&1; then
        printf '%sFAIL%s Composer is not available on PATH.\n' "$RED" "$RESET"
        return 127
    fi

    run_step "Static analysis" composer phpstan
    phpstan_status=$?

    run_step "Test suite" composer test
    test_status=$?

    if ((phpstan_status != 0 || test_status != 0)); then
        total_status=1
    fi

    finished_at=$(date +%s)
    print_summary "$total_status" "$phpstan_status" "$test_status" "$((finished_at - started_at))"

    return "$total_status"
}

main "$@"
