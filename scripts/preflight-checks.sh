#!/usr/bin/env bash

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
    BOLD=$'\033[1m'
    DIM=$'\033[2m'
    GREEN=$'\033[32m'
    RED=$'\033[31m'
    YELLOW=$'\033[33m'
    BLUE=$'\033[34m'
    RESET=$'\033[0m'
else
    BOLD=''
    DIM=''
    GREEN=''
    RED=''
    YELLOW=''
    BLUE=''
    RESET=''
fi

PASS="${GREEN}✔${RESET}"
FAIL="${RED}✘${RESET}"
SKIP="${YELLOW}○${RESET}"
ARROW="${BLUE}▸${RESET}"

TOTAL=0
PASSED=0
FAILED=0
FAILURES=()
START_TIME=$(date +%s%N)
MUTATION_MODE=off

rule() {
    printf '  %s\n' "${DIM}────────────────────────────────────────────${RESET}"
}

elapsed_since() {
    local started_at=$1
    local finished_at
    local elapsed_ms
    local seconds
    local minutes
    local remainder
    local frac

    finished_at=$(date +%s%N)
    elapsed_ms=$(((finished_at - started_at) / 1000000))

    if ((elapsed_ms < 1000)); then
        printf '%dms' "$elapsed_ms"
        return
    fi

    seconds=$((elapsed_ms / 1000))
    frac=$(((elapsed_ms % 1000) / 100))

    if ((seconds < 60)); then
        printf '%d.%ds' "$seconds" "$frac"
        return
    fi

    minutes=$((seconds / 60))
    remainder=$((seconds % 60))
    printf '%dm %02d.%ds' "$minutes" "$remainder" "$frac"
}

header() {
    printf '\n'
    printf '  %sPreflight Check%s\n' "$BOLD" "$RESET"
    printf '  %s%s%s\n' "$DIM" "$(date '+%Y-%m-%d %H:%M:%S')" "$RESET"
    rule
    printf '\n'
}

step() {
    local label=$1

    TOTAL=$((TOTAL + 1))
    printf '  %s %-40s' "$ARROW" "$label"
}

pass() {
    local detail=${1:-}

    PASSED=$((PASSED + 1))
    if [[ -n "$detail" ]]; then
        printf '%s  %s%s%s\n' "$PASS" "$DIM" "$detail" "$RESET"
    else
        printf '%s\n' "$PASS"
    fi
}

fail() {
    local label=$1

    FAILED=$((FAILED + 1))
    FAILURES+=("$label")
    printf '%s\n' "$FAIL"
}

skip() {
    local reason=${1:-skipped}

    printf '%s  %s%s%s\n' "$SKIP" "$DIM" "$reason" "$RESET"
}

indent_output() {
    while IFS= read -r line; do
        printf '    %s%s%s\n' "$DIM" "$line" "$RESET"
    done
}

run_step() {
    local label=$1
    shift
    local started_at
    local output
    local status
    local elapsed

    step "$label"
    started_at=$(date +%s%N)
    output=$("$@" 2>&1)
    status=$?
    elapsed=$(elapsed_since "$started_at")

    if ((status == 0)); then
        pass "${output:+$output }$elapsed"
    else
        fail "$label"
        if [[ -n "$output" ]]; then
            printf '%s\n' "$output" | tail -20 | indent_output
        fi
        printf '    %sexit %d after %s%s\n' "$DIM" "$status" "$elapsed" "$RESET"
    fi

    return "$status"
}

static_analysis_check() {
    local output
    local status

    output=$(composer phpstan 2>&1)
    status=$?

    if ((status != 0)); then
        printf '%s\n' "$output"
    fi

    return "$status"
}

test_suite_check() {
    local output
    local status
    local summary

    output=$(composer test 2>&1)
    status=$?
    summary=$(printf '%s\n' "$output" | grep -oE '[0-9]+ tests, [0-9]+ assertions' | tail -1 || true)

    if ((status == 0)); then
        printf '%s' "$summary"
    else
        printf '%s\n' "$output"
    fi

    return "$status"
}

dependency_audit_check() {
    local output
    local status

    output=$(composer audit --locked 2>&1)
    status=$?

    if ((status == 0)); then
        printf '%s' "$(printf '%s\n' "$output" | tail -1)"
    else
        printf '%s\n' "$output"
    fi

    return "$status"
}

gruff_report_summary() {
    local report_path=$1

    # shellcheck disable=SC2016
    php -r '
$report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$summary = $report["summary"] ?? [];
$findings = $summary["findings"] ?? [];
$score = $report["score"]["composite"] ?? null;

$parts = [
    sprintf(
        "%d findings (advisory=%d, warning=%d, error=%d)",
        (int) ($findings["total"] ?? 0),
        (int) ($findings["advisory"] ?? 0),
        (int) ($findings["warning"] ?? 0),
        (int) ($findings["error"] ?? 0),
    ),
];

if (is_array($score)) {
    $parts[] = sprintf("%s %.2f/100", (string) ($score["grade"] ?? "n/a"), (float) ($score["score"] ?? 0));
}

echo implode(", ", $parts);
' "$report_path"
}

gruff_php_check() {
    local report_path="${TMPDIR:-/tmp}/gruff-preflight-analysis.json"
    local error_path="${TMPDIR:-/tmp}/gruff-preflight-analysis.err"
    local status
    local printed=0

    php bin/gruff-php analyse --fail-on advisory --format json > "$report_path" 2> "$error_path"
    status=$?

    if [[ -s "$report_path" ]]; then
        gruff_report_summary "$report_path" || return $?
        printed=1
    fi

    if [[ -s "$error_path" ]]; then
        if ((printed)); then
            printf '\n'
        fi
        cat "$error_path"
    fi

    return "$status"
}

mutation_check() {
    local mode=$1
    local output
    local status
    local summary_line

    output=$(scripts/mutation-test-"$mode".sh 2>&1)
    status=$?

    if ((status == 0)); then
        summary_line=$(printf '%s\n' "$output" | grep -E 'MSI |All [0-9]+/[0-9]+ checks passed|no edited unit test files' | tail -1 || true)
        printf '%s' "${summary_line:-completed}"
    else
        printf '%s\n' "$output"
    fi

    return "$status"
}

summary() {
    local elapsed

    elapsed=$(elapsed_since "$START_TIME")
    printf '\n'
    rule
    printf '\n'

    if ((FAILED == 0)); then
        printf '  %sAll %d/%d checks passed%s  %s(%s)%s\n' "$GREEN$BOLD" "$PASSED" "$TOTAL" "$RESET" "$DIM" "$elapsed" "$RESET"
        printf '\n'
        return 0
    fi

    printf '  %s%d/%d checks failed%s  %s(%s)%s\n' "$RED$BOLD" "$FAILED" "$TOTAL" "$RESET" "$DIM" "$elapsed" "$RESET"
    printf '\n'
    for failure in "${FAILURES[@]}"; do
        printf '    %s  %s\n' "$FAIL" "$failure"
    done
    printf '\n'

    return 1
}

usage() {
    cat <<'USAGE'
Usage: scripts/preflight-checks.sh [--mutate-diff|--mutate-full]

Runs the standard preflight gate:
  - PHPStan
  - PHPUnit
  - Composer dependency audit
  - Gruff full-project scan

Options:
  --mutate-diff    Run Infection using edited PHPUnit unit test files only.
  --mutate-full    Run the full Infection mutation suite against unit tests.
  -h, --help       Show this help.
USAGE
}

main() {
    local phpstan_status=0
    local test_status=0
    local audit_status=0
    local gruff_status=0
    local mutation_status=0

    while (($# > 0)); do
        case "$1" in
            --mutate-diff)
                MUTATION_MODE="diff"
                ;;
            --mutate-full)
                MUTATION_MODE="full"
                ;;
            -h|--help)
                usage
                return 0
                ;;
            *)
                printf '%sUnknown option:%s %s\n' "$RED" "$RESET" "$1" >&2
                usage >&2
                return 64
                ;;
        esac

        shift
    done

    cd "$REPO_ROOT" || return 1

    header

    if ! command -v composer >/dev/null 2>&1; then
        step "Composer"
        fail "Composer"
        printf '    %sComposer is not available on PATH.%s\n' "$DIM" "$RESET"
        summary
        return 127
    fi

    run_step "Static analysis (PHPStan L10)" static_analysis_check
    phpstan_status=$?

    run_step "Tests (PHPUnit)" test_suite_check
    test_status=$?

    run_step "Dependency audit (Composer)" dependency_audit_check
    audit_status=$?

    run_step "Gruff full-project scan" gruff_php_check
    gruff_status=$?

    if [[ "$MUTATION_MODE" == "diff" ]]; then
        run_step "Mutation testing (edited unit tests)" mutation_check diff
        mutation_status=$?
    elif [[ "$MUTATION_MODE" == "full" ]]; then
        run_step "Mutation testing (full unit suite)" mutation_check full
        mutation_status=$?
    else
        step "Mutation testing (Infection)"
        skip "use --mutate-diff or --mutate-full to enable"
    fi

    summary
    local summary_status=$?

    if ((phpstan_status != 0 || test_status != 0 || audit_status != 0 || gruff_status != 0 || mutation_status != 0)); then
        return 1
    fi

    return "$summary_status"
}

main "$@"
