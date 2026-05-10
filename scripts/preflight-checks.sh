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
    local with_mutation=$1

    line
    printf '%sgruff-php preflight checks%s\n' "$BOLD" "$RESET"
    thin_line
    printf '%sProject:%s %s\n' "$DIM" "$RESET" "$REPO_ROOT"

    if command -v php >/dev/null 2>&1; then
        printf '%sPHP:%s     %s\n' "$DIM" "$RESET" "$(php -r 'echo PHP_VERSION;')"
    fi

    printf '%sStarted:%s %s\n' "$DIM" "$RESET" "$(date '+%Y-%m-%d %H:%M:%S %Z')"
    if ((with_mutation == 1)); then
        printf '%sMutation:%s enabled (unit test suite)\n' "$DIM" "$RESET"
    else
        printf '%sMutation:%s skipped (pass --with-mutation to run unit mutation analysis)\n' "$DIM" "$RESET"
    fi
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

print_gruff_report_summary() {
    local report_path=$1

    # shellcheck disable=SC2016
    php -r '
$report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$summary = $report["summary"] ?? [];
$findings = $summary["findings"] ?? [];
$score = $report["score"]["composite"] ?? null;

printf("Report: %s\n", $argv[1]);
printf(
    "Files: %d discovered, %d parsed, %d parse errors\n",
    (int) ($summary["filesDiscovered"] ?? 0),
    (int) ($summary["filesParsed"] ?? 0),
    (int) ($summary["parseErrors"] ?? 0),
);
printf(
    "Findings: %d total (%d advisory, %d warning, %d error)\n",
    (int) ($findings["total"] ?? 0),
    (int) ($findings["advisory"] ?? 0),
    (int) ($findings["warning"] ?? 0),
    (int) ($findings["error"] ?? 0),
);

if (is_array($score)) {
    printf("Score: %s (%.2f/100)\n", (string) ($score["grade"] ?? "n/a"), (float) ($score["score"] ?? 0));
}

$mutation = $report["mutation"]["totals"] ?? null;
if (is_array($mutation)) {
    printf(
        "Mutation: MSI %.2f%%, covered MSI %.2f%%, survived %d/%d\n",
        (float) ($mutation["msi"] ?? 0),
        (float) ($mutation["coveredMsi"] ?? 0),
        (int) ($mutation["survivedMutants"] ?? 0),
        (int) ($mutation["totalMutants"] ?? 0),
    );
}
' "$report_path"
}

gruff_source_scan() {
    local report_path="${TMPDIR:-/tmp}/gruff-preflight-source-scan.json"

    printf 'Underlying: php bin/gruff analyse src --fail-on none --format json > %s\n\n' "$report_path"
    php bin/gruff analyse src --fail-on none --format json > "$report_path"
    local status=$?

    if [[ -s "$report_path" ]]; then
        print_gruff_report_summary "$report_path" || return $?
    fi

    return "$status"
}

gruff_unit_mutation_scan() {
    local report_path="${TMPDIR:-/tmp}/gruff-preflight-unit-mutation.json"

    printf 'Underlying: php bin/gruff analyse src --fail-on none --format json --infection-run --infection-config infection.json5 --infection-report infection-report.json > %s\n' "$report_path"
    printf 'Mutation source: src\n'
    printf 'Mutation test suite: PHPUnit unit (--testsuite=unit from infection.json5)\n\n'
    php bin/gruff analyse src --fail-on none --format json --infection-run --infection-config infection.json5 --infection-report infection-report.json > "$report_path"
    local status=$?

    if [[ -s "$report_path" ]]; then
        print_gruff_report_summary "$report_path" || return $?
    fi

    return "$status"
}

print_summary() {
    local total_status=$1
    local phpstan_status=$2
    local test_status=$3
    local gruff_status=$4
    local mutation_status=$5
    local with_mutation=$6
    local elapsed=$7

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

    if ((gruff_status == 0)); then
        printf '%sPASS%s Gruff source scan\n' "$GREEN" "$RESET"
    else
        printf '%sFAIL%s Gruff source scan (exit %d)\n' "$RED" "$RESET" "$gruff_status"
    fi

    if ((with_mutation == 1)); then
        if ((mutation_status == 0)); then
            printf '%sPASS%s Unit mutation analysis\n' "$GREEN" "$RESET"
        else
            printf '%sFAIL%s Unit mutation analysis (exit %d)\n' "$RED" "$RESET" "$mutation_status"
        fi
    else
        printf '%sSKIP%s Unit mutation analysis (pass --with-mutation)\n' "$DIM" "$RESET"
    fi

    thin_line

    if ((total_status == 0)); then
        printf '%sAll preflight checks passed in %s.%s\n' "$GREEN" "$(duration "$elapsed")" "$RESET"
    else
        printf '%sPreflight failed in %s. Fix the failing step above and rerun.%s\n' "$RED" "$(duration "$elapsed")" "$RESET"
    fi

    line
}

usage() {
    cat <<'USAGE'
Usage: scripts/preflight-checks.sh [--with-mutation|--mutation]

Runs the standard preflight gate:
  - PHPStan
  - PHPUnit
  - Gruff source scan

Options:
  --with-mutation  Also run Infection via Gruff against the PHPUnit unit suite.
  --mutation       Alias for --with-mutation.
  -h, --help       Show this help.
USAGE
}

main() {
    local started_at
    local finished_at
    local with_mutation=0
    local phpstan_status=0
    local test_status=0
    local gruff_status=0
    local mutation_status=0
    local total_status=0

    while (($# > 0)); do
        case "$1" in
            --with-mutation|--mutation)
                with_mutation=1
                ;;
            -h|--help)
                usage
                return 0
                ;;
            *)
                printf '%sFAIL%s Unknown option: %s\n' "$RED" "$RESET" "$1" >&2
                usage >&2
                return 64
                ;;
        esac

        shift
    done

    started_at=$(date +%s)
    cd "$REPO_ROOT" || return 1

    print_header "$with_mutation"

    if ! command -v composer >/dev/null 2>&1; then
        printf '%sFAIL%s Composer is not available on PATH.\n' "$RED" "$RESET"
        return 127
    fi

    run_step "Static analysis" composer phpstan
    phpstan_status=$?

    run_step "Test suite" composer test
    test_status=$?

    run_step "Gruff source scan" gruff_source_scan
    gruff_status=$?

    if ((with_mutation == 1)); then
        run_step "Unit mutation analysis" gruff_unit_mutation_scan
        mutation_status=$?
    fi

    if ((phpstan_status != 0 || test_status != 0 || gruff_status != 0 || mutation_status != 0)); then
        total_status=1
    fi

    finished_at=$(date +%s)
    print_summary "$total_status" "$phpstan_status" "$test_status" "$gruff_status" "$mutation_status" "$with_mutation" "$((finished_at - started_at))"

    return "$total_status"
}

main "$@"
