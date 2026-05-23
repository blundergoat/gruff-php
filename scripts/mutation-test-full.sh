#!/usr/bin/env bash
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

MODE="full"
if [[ "${1:-}" == "--diff" ]]; then
    MODE="diff"
    shift
fi

CONFIG="${MUTATION_CONFIG:-infection.json5}"
BASE="${MUTATION_DIFF_BASE:-HEAD}"
THREADS="${MUTATION_THREADS:-16}"
SHOW_MUTATIONS="${MUTATION_SHOW_MUTATIONS:-0}"
REPORT="${MUTATION_REPORT:-infection-report.json}"

TOTAL=0
PASSED=0
FAILED=0
SKIPPED=0
START_NS="$(date +%s%N)"
EDITED_TEST_FILES=()
TEST_FILTER=""

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
    BOLD=$'\033[1m'
    DIM=$'\033[2m'
    GREEN=$'\033[32m'
    RED=$'\033[31m'
    YELLOW=$'\033[33m'
    NC=$'\033[0m'
else
    BOLD=''
    DIM=''
    GREEN=''
    RED=''
    YELLOW=''
    NC=''
fi

usage() {
    local script_name
    script_name="${MUTATION_SCRIPT_NAME:-$(basename "$0")}"

    if [[ "$MODE" == "diff" ]]; then
        cat <<USAGE
Usage: scripts/${script_name} [options]

Run Infection using edited PHPUnit unit test files as the oracle.

Options:
  --base REF              Git diff base ref for edited tests (default: ${BASE})
  --threads N             Infection worker threads (default: ${THREADS})
  --show-mutations N      Infection mutation detail level (default: ${SHOW_MUTATIONS})
  --config PATH           Infection config file (default: ${CONFIG})
  -h, --help              Show this help text

Environment:
  MUTATION_DIFF_BASE      Default diff base ref for edited tests
  MUTATION_THREADS        Default Infection worker threads
  MUTATION_CONFIG         Default Infection config file
  MUTATION_REPORT         JSON report path from infection config
USAGE
    else
        cat <<USAGE
Usage: scripts/${script_name} [options]

Run the full Infection mutation suite for unit-test quality.

Options:
  --threads N             Infection worker threads (default: ${THREADS})
  --show-mutations N      Infection mutation detail level (default: ${SHOW_MUTATIONS})
  --config PATH           Infection config file (default: ${CONFIG})
  -h, --help              Show this help text

Environment:
  MUTATION_THREADS        Default Infection worker threads
  MUTATION_CONFIG         Default Infection config file
  MUTATION_REPORT         JSON report path from infection config
USAGE
    fi
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --base)
            BASE="${2:-}"
            shift 2
            ;;
        --base=*)
            BASE="${1#*=}"
            shift
            ;;
        --threads)
            THREADS="${2:-}"
            shift 2
            ;;
        --threads=*)
            THREADS="${1#*=}"
            shift
            ;;
        --show-mutations)
            SHOW_MUTATIONS="${2:-}"
            shift 2
            ;;
        --show-mutations=*)
            SHOW_MUTATIONS="${1#*=}"
            shift
            ;;
        --config)
            CONFIG="${2:-}"
            shift 2
            ;;
        --config=*)
            CONFIG="${1#*=}"
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown option: %s\n\n' "$1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

cd "$REPO_ROOT" || exit 1

elapsed_ms() {
    local start_ns="$1"
    local end_ns
    end_ns="$(date +%s%N)"
    printf '%d' "$(((end_ns - start_ns) / 1000000))"
}

format_duration() {
    local ms="$1"

    if ((ms >= 10000)); then
        printf '%ss' "$((ms / 1000))"
    elif ((ms >= 1000)); then
        printf '%s.%ss' "$((ms / 1000))" "$(((ms % 1000) / 100))"
    else
        printf '%sms' "$ms"
    fi
}

print_header() {
    local title
    if [[ "$MODE" == "diff" ]]; then
        title="Mutation Test (Diff)"
    else
        title="Mutation Test (Full)"
    fi

    printf '\n  %s%s%s\n' "$BOLD" "$title" "$NC"
    printf '  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')"
    printf '  ────────────────────────────────────────────\n\n'
}

pass() {
    local label="$1"
    local detail="${2:-}"
    TOTAL=$((TOTAL + 1))
    PASSED=$((PASSED + 1))
    printf '  ▸ %-38s %s✔%s' "$label" "$GREEN" "$NC"
    if [[ -n "$detail" ]]; then
        printf '  %s' "$detail"
    fi
    printf '\n'
}

fail() {
    local label="$1"
    local detail="${2:-}"
    TOTAL=$((TOTAL + 1))
    FAILED=$((FAILED + 1))
    printf '  ▸ %-38s %s✘%s' "$label" "$RED" "$NC"
    if [[ -n "$detail" ]]; then
        printf '  %s' "$detail"
    fi
    printf '\n'
}

skip() {
    local label="$1"
    local detail="${2:-}"
    TOTAL=$((TOTAL + 1))
    SKIPPED=$((SKIPPED + 1))
    printf '  ▸ %-38s %s○%s' "$label" "$YELLOW" "$NC"
    if [[ -n "$detail" ]]; then
        printf '  %s' "$detail"
    fi
    printf '\n'
}

summary() {
    local total_ms
    total_ms="$(elapsed_ms "$START_NS")"

    printf '\n  ────────────────────────────────────────────\n\n'
    if ((FAILED == 0)); then
        printf '  %sAll %d/%d checks passed%s  %s(%s)%s\n\n' "$GREEN$BOLD" "$PASSED" "$TOTAL" "$NC" "$DIM" "$(format_duration "$total_ms")" "$NC"
    else
        printf '  %s%d/%d checks failed%s  %s(%s)%s\n\n' "$RED$BOLD" "$FAILED" "$TOTAL" "$NC" "$DIM" "$(format_duration "$total_ms")" "$NC"
    fi
}

die_after_summary() {
    summary
    exit 1
}

report_summary() {
    local path="$1"

    if [[ ! -f "$path" ]]; then
        printf 'no JSON report'
        return
    fi

    # shellcheck disable=SC2016
    php -r '
        $path = $argv[1];
        try {
            $report = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            echo "invalid JSON report";
            exit(0);
        }

        $stats = $report["stats"] ?? [];
        $total = (int) ($stats["totalMutantsCount"] ?? 0);
        $msi = (float) ($stats["msi"] ?? 0);
        $coveredMsi = (float) ($stats["coveredCodeMsi"] ?? 0);
        $escaped = (int) ($stats["escapedCount"] ?? (is_array($report["escaped"] ?? null) ? count($report["escaped"]) : 0));
        $timedOut = (int) ($stats["timedOutCount"] ?? (is_array($report["timeouted"] ?? null) ? count($report["timeouted"]) : 0));
        $errors = (int) ($stats["errorCount"] ?? (is_array($report["errored"] ?? null) ? count($report["errored"]) : 0));

        printf(
            "MSI %.2f%%, covered MSI %.2f%%, escaped/timed-out/errors %d/%d/%d of %d",
            $msi,
            $coveredMsi,
            $escaped,
            $timedOut,
            $errors,
            $total
        );
    ' "$path"
}

print_failure_output() {
    local output="$1"

    if [[ -z "$output" ]]; then
        return
    fi

    printf '\n%s\n' "$output" | tail -n 30 | sed 's/^/    /'
}

validate_config() {
    if [[ ! -f "$CONFIG" ]]; then
        fail "Infection config" "missing ${CONFIG}"
        return
    fi
    pass "Infection config" "$CONFIG"

    if grep -q -- '--testsuite=unit' "$CONFIG"; then
        pass "Mutation test suite" "PHPUnit unit (--testsuite=unit)"
    else
        fail "Mutation test suite" "${CONFIG} must restrict Infection to PHPUnit unit tests"
    fi
}

validate_binary() {
    if [[ -x "vendor/bin/infection" ]]; then
        pass "Infection executable" "vendor/bin/infection"
    else
        fail "Infection executable" "missing vendor/bin/infection; run composer install"
    fi
}

is_unit_test_file() {
    local path="$1"

    [[ "$path" == tests/*Test.php ]] \
        && [[ "$path" != tests/Console/* ]] \
        && [[ "$path" != *IntegrationTest.php ]] \
        && [[ -f "$path" ]]
}

unit_test_class_name() {
    local path="$1"
    local class_name

    class_name="$(
        grep -Eho '^[[:space:]]*(final[[:space:]]+|abstract[[:space:]]+)?class[[:space:]]+[A-Za-z_][A-Za-z0-9_]*' "$path" \
            | sed -E 's/.*class[[:space:]]+//' \
            | head -1
    )"

    if [[ -n "$class_name" ]]; then
        printf '%s' "$class_name"
        return
    fi

    basename "$path" .php
}

build_test_filter() {
    local filters=()
    local path
    local class_name
    local IFS='|'

    for path in "${EDITED_TEST_FILES[@]}"; do
        class_name="$(unit_test_class_name "$path")"
        if [[ -n "$class_name" ]]; then
            filters+=("$class_name")
        fi
    done

    printf '%s' "${filters[*]}"
}

validate_diff_scope() {
    local candidates=()
    local path
    local edited_count
    declare -A seen=()

    if git rev-parse --verify "${BASE}^{commit}" >/dev/null 2>&1; then
        pass "Diff base" "$BASE"
        mapfile -t candidates < <(git diff --name-only --diff-filter=AM "$BASE" -- tests | grep -E '\.php$' || true)
    else
        skip "Diff base" "unknown ref ${BASE}; checking untracked unit tests only"
    fi

    while IFS= read -r path; do
        candidates+=("$path")
    done < <(git ls-files --others --exclude-standard -- tests | grep -E '\.php$' || true)

    for path in "${candidates[@]}"; do
        if is_unit_test_file "$path" && [[ -z "${seen[$path]:-}" ]]; then
            seen[$path]=1
            EDITED_TEST_FILES+=("$path")
        fi
    done

    edited_count="${#EDITED_TEST_FILES[@]}"

    if ((edited_count == 0)); then
        skip "Edited unit test files" "none under tests relative to ${BASE}"
        skip "Mutation testing (Infection)" "no edited unit test files"
        return
    fi

    TEST_FILTER="$(build_test_filter)"

    pass "Edited unit test files" "${edited_count} file(s)"
    pass "PHPUnit test filter" "$TEST_FILTER"
}

run_infection() {
    local label="Mutation testing (Infection)"
    local step_start
    local status
    local output
    local detail
    local test_framework_options
    local cmd=(
        vendor/bin/infection
        run
        --configuration "$CONFIG"
        --threads="$THREADS"
        --show-mutations="$SHOW_MUTATIONS"
        --no-progress
    )

    if [[ "$MODE" == "diff" ]]; then
        test_framework_options="--testsuite=unit --filter=$TEST_FILTER"
        cmd+=("--test-framework-options=$test_framework_options")
    fi

    rm -f "$REPORT"
    export XDEBUG_MODE="${XDEBUG_MODE:-coverage}"

    step_start="$(date +%s%N)"
    output="$("${cmd[@]}" 2>&1)"
    status=$?

    detail="$(report_summary "$REPORT") $(format_duration "$(elapsed_ms "$step_start")")"
    if ((status == 0)); then
        pass "$label" "$detail"
        return
    fi

    fail "$label" "$detail"
    print_failure_output "$output"
}

print_header
validate_config
validate_binary

if [[ "$MODE" == "full" ]]; then
    if [[ -d src ]]; then
        pass "Mutation source" "src"
    else
        fail "Mutation source" "missing src"
    fi
else
    validate_diff_scope
fi

if ((FAILED > 0)); then
    die_after_summary
fi

if [[ "$MODE" == "full" || ${#EDITED_TEST_FILES[@]} -gt 0 ]]; then
    run_infection
fi

summary
if ((FAILED > 0)); then
    exit 1
fi
