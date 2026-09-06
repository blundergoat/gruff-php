#!/usr/bin/env bash

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PLATFORM_SLUG="$(uname -s | tr '[:upper:]' '[:lower:]')-$(uname -m)"
BASELINE_FILE="$SCRIPT_DIR/performance-baselines/${PLATFORM_SLUG}.json"
RUN_LOG_DIR="$REPO_ROOT/.goat-flow/logs/perf/m50-baseline"

WALL_TOLERANCE="${GRUFF_PERF_WALL_TOLERANCE:-20}"
MEM_TOLERANCE="${GRUFF_PERF_MEM_TOLERANCE:-25}"
# Sub-200ms baselines are dominated by process startup and scheduling noise;
# ±20% there is meaningless. Corpora whose baseline wallMs is below this floor
# can warn but never fail. Override with GRUFF_PERF_WALL_FLOOR_MS.
WALL_NOISE_FLOOR_MS="${GRUFF_PERF_WALL_FLOOR_MS:-200}"

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
    BOLD=$'\033[1m'
    DIM=$'\033[2m'
    GREEN=$'\033[32m'
    RED=$'\033[31m'
    YELLOW=$'\033[33m'
    # shellcheck disable=SC2034  # reserved for future table emphasis parity
    BLUE=$'\033[34m'
    RESET=$'\033[0m'
else
    BOLD=''
    DIM=''
    GREEN=''
    RED=''
    YELLOW=''
    # shellcheck disable=SC2034  # reserved for future table emphasis parity
    BLUE=''
    RESET=''
fi

PASS="${GREEN}pass${RESET}"
WARN="${YELLOW}warn${RESET}"
FAIL="${RED}fail${RESET}"

usage() {
    cat <<EOF
${BOLD}Usage:${RESET} scripts/test-performance.sh [options]

Measure gruff-php analyse wall time, peak memory, and per-rule cost against a
reproducible local corpus. Compares each run to a checked-in baseline.json so
regressions surface deterministically.

${BOLD}Modes${RESET}
  --quick               1 run, no warmup, medium corpus only. Fast sanity check.
  --full                Warmup + 3 timed runs per corpus, median reported. (default)

${BOLD}Actions${RESET}
  --check               Compare current results to ${BASELINE_FILE/$REPO_ROOT\//}.
                        Exit 1 on regression. (default)
  --baseline            Overwrite the baseline file from the current run. Prompts
                        for confirmation unless --yes is supplied.
  --yes                 Skip the baseline-overwrite confirmation prompt.

${BOLD}Selection${RESET}
  --corpus=NAME         small | medium | large | external | all. Default: all (full) or medium (quick).
                        small    = src/Results/Diff       (7 files, warmup-sized)
                        medium   = src                    (production code)
                        large    = full self-scan         (everything per .gruff.yaml)
                        external = scan a third-party path set via
                                   \$GRUFF_PERF_EXTERNAL_PATH or --external=PATH
                                   (runs with --no-config so the external project's
                                   own config or built-in defaults apply).
  --external=PATH       Absolute path to an external project to scan when
                        --corpus includes "external". Equivalent to setting
                        GRUFF_PERF_EXTERNAL_PATH.

${BOLD}Output${RESET}
  --json                Emit the full result document on stdout instead of a table.
  --help                Show this message.

${BOLD}Tolerances${RESET}
  GRUFF_PERF_WALL_TOLERANCE  Wall-time regression threshold, percent. Default 20.
  GRUFF_PERF_MEM_TOLERANCE   Peak-memory regression threshold, percent. Default 25.
  GRUFF_PERF_WALL_FLOOR_MS   Baselines below this wall time can warn but never
                             fail. Default 200 (sub-200ms is process-startup noise).

${BOLD}Examples${RESET}
  scripts/test-performance.sh --quick
  scripts/test-performance.sh --full --corpus=medium
  scripts/test-performance.sh --baseline --yes
  GRUFF_PERF_WALL_TOLERANCE=10 scripts/test-performance.sh --check

${DIM}Baselines are machine- and PHP-version-specific. Regenerate baseline.json
after switching machines, upgrading PHP, or making intentional rule changes.${RESET}
EOF
}

MODE="full"
ACTION="check"
CORPUS_SELECTION=""
ASSUME_YES=0
EMIT_JSON=0
EXTERNAL_PATH="${GRUFF_PERF_EXTERNAL_PATH:-}"

for arg in "$@"; do
    case "$arg" in
        --quick) MODE="quick" ;;
        --full) MODE="full" ;;
        --check) ACTION="check" ;;
        --baseline) ACTION="baseline" ;;
        --yes) ASSUME_YES=1 ;;
        --json) EMIT_JSON=1 ;;
        --corpus=*) CORPUS_SELECTION="${arg#*=}" ;;
        --external=*) EXTERNAL_PATH="${arg#*=}" ;;
        --help|-h) usage; exit 0 ;;
        *) echo "${RED}Unknown argument: ${arg}${RESET}" >&2; usage >&2; exit 2 ;;
    esac
done

if ! command -v jq >/dev/null 2>&1; then
    echo "${RED}jq is required.${RESET}" >&2
    exit 2
fi
if ! command -v git >/dev/null 2>&1; then
    echo "${RED}git is required for source provenance.${RESET}" >&2
    exit 2
fi

if [[ -z "$CORPUS_SELECTION" ]]; then
    if [[ "$MODE" == "quick" ]]; then
        CORPUS_SELECTION="medium"
    else
        CORPUS_SELECTION="all"
    fi
fi

declare -a CORPORA
case "$CORPUS_SELECTION" in
    small)    CORPORA=(small) ;;
    medium)   CORPORA=(medium) ;;
    large)    CORPORA=(large) ;;
    external) CORPORA=(external) ;;
    all)      CORPORA=(small medium large) ;;
    *) echo "${RED}Unknown corpus: ${CORPUS_SELECTION}${RESET}" >&2; exit 2 ;;
esac

for corpus in "${CORPORA[@]}"; do
    if [[ "$corpus" != "external" ]]; then continue; fi
    if [[ -z "$EXTERNAL_PATH" ]]; then
        echo "${RED}--corpus=external requires GRUFF_PERF_EXTERNAL_PATH or --external=PATH.${RESET}" >&2
        exit 2
    fi
    if [[ ! -d "$EXTERNAL_PATH" ]]; then
        echo "${RED}External path is not a directory: ${EXTERNAL_PATH}${RESET}" >&2
        exit 2
    fi
done

corpus_paths() {
    case "$1" in
        small)    echo "src/Results/Diff" ;;
        medium)   echo "src" ;;
        large)    echo "" ;;
        external) echo "$EXTERNAL_PATH" ;;
        *) return 1 ;;
    esac
}

corpus_extra_args() {
    case "$1" in
        external) printf '%s\n' "--no-config" ;;
        *) ;;
    esac
}

mkdir -p "$RUN_LOG_DIR"

runtime_source_identity() {
    # shellcheck disable=SC2016  # PHP owns the dollar-prefixed expressions
    php -r '
        $root = $argv[1];
        $included = ["bin/gruff-php", "src", "composer.json", "composer.lock"];
        $files = [];
        $visit = static function (string $path) use (&$visit, &$files): void {
            if (is_link($path) || is_file($path)) {
                $files[$path] = true;
                return;
            }
            $entries = scandir($path);
            if ($entries === false) {
                throw new RuntimeException("Unable to read runtime source path: " . $path);
            }
            foreach ($entries as $entry) {
                if ($entry !== "." && $entry !== "..") {
                    $visit($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
        };
        foreach ($included as $relative) {
            $visit($root . DIRECTORY_SEPARATOR . $relative);
        }
        $paths = array_keys($files);
        sort($paths, SORT_STRING);
        $manifest = [];
        foreach ($paths as $path) {
            $bytes = is_link($path) ? readlink($path) : file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException("Unable to hash runtime source path: " . $path);
            }
            $manifest[] = [
                "path" => str_replace(DIRECTORY_SEPARATOR, "/", substr($path, strlen($root) + 1)),
                "sha256" => hash("sha256", $bytes),
            ];
        }
        $pretty = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $canonical = preg_replace_callback(
            "/^( +)/m",
            static fn (array $match): string => str_repeat(" ", intdiv(strlen($match[1]), 2)),
            $pretty,
        ) . "\n";
        echo json_encode([
            "includedPaths" => $included,
            "fileCount" => count($manifest),
            "digest" => "sha256:" . hash("sha256", $canonical),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    ' "$REPO_ROOT"
}

run_once() {
    local corpus="$1"
    local detail_mode="$2"
    local paths
    paths="$(corpus_paths "$corpus")"
    local tmpfile
    tmpfile="$(mktemp)"

    local -a cmd
    cmd=(php "$REPO_ROOT/bin/gruff-php" analyse)
    if [[ -n "$paths" ]]; then
        # shellcheck disable=SC2206
        cmd+=($paths)
    fi
    # --no-cache is load-bearing: without it a warm .gruff-cache from an earlier scan in the same
    # session (composer check and scripts/preflight-checks.sh both run gruff) turns the measurement
    # into a cache-hit benchmark. Measured 2026-08-12 on the medium corpus: 57ms/16MB warm against
    # 2211ms/21MB cold, for identical filesParsed=275.
    cmd+=(--no-cache --format=json --fail-on=none --print-runtime "--runtime-mode=${detail_mode}")

    local extra_args
    extra_args="$(corpus_extra_args "$corpus")"
    if [[ -n "$extra_args" ]]; then
        # shellcheck disable=SC2206
        cmd+=($extra_args)
    fi

    "${cmd[@]}" >/dev/null 2>"$tmpfile"
    local exit_code=$?

    if [[ $exit_code -ne 0 ]]; then
        echo "${RED}analyse exited ${exit_code} for corpus ${corpus}. stderr:${RESET}" >&2
        cat "$tmpfile" >&2
        rm -f "$tmpfile"
        return 1
    fi

    local payload_line
    payload_line="$(tail -n 1 "$tmpfile")"
    rm -f "$tmpfile"

    if ! echo "$payload_line" | jq empty >/dev/null 2>&1; then
        echo "${RED}runtime payload not valid JSON for corpus ${corpus}.${RESET}" >&2
        echo "$payload_line" >&2
        return 1
    fi

    printf '%s\n' "$payload_line"
}

median_field() {
    local field="$1"
    shift
    printf '%s\n' "$@" | jq -s "[ .[].${field} ] | sort | .[ (length / 2 | floor) ]"
}

run_corpus() {
    local corpus="$1"
    local runs
    local detail_mode

    if [[ "$MODE" == "quick" ]]; then
        runs=1
        detail_mode="summary"
    else
        runs=3
        detail_mode="detailed"
        # Warmup
        run_once "$corpus" "summary" >/dev/null || return 1
    fi

    local payloads=()
    for ((i = 0; i < runs; i++)); do
        local payload
        payload="$(run_once "$corpus" "$detail_mode")" || return 1
        payloads+=("$payload")
    done

    local last_payload="${payloads[$((runs - 1))]}"
    local wall_median
    wall_median="$(median_field wallMs "${payloads[@]}")"
    local peak_median
    peak_median="$(median_field peakBytes "${payloads[@]}")"

    local files_parsed rules_executed phases rules
    files_parsed="$(echo "$last_payload" | jq '.filesParsed')"
    rules_executed="$(echo "$last_payload" | jq '.rulesExecuted')"
    phases="$(echo "$last_payload" | jq '.phases')"
    if [[ "$detail_mode" == "detailed" ]]; then
        rules="$(echo "$last_payload" | jq '.rules')"
    else
        rules="[]"
    fi

    jq -n \
        --arg corpus "$corpus" \
        --argjson wallMs "$wall_median" \
        --argjson peakBytes "$peak_median" \
        --argjson filesParsed "$files_parsed" \
        --argjson rulesExecuted "$rules_executed" \
        --argjson phases "$phases" \
        --argjson rules "$rules" \
        --argjson runCount "$runs" \
        '{corpus: $corpus, wallMs: $wallMs, peakBytes: $peakBytes, filesParsed: $filesParsed, rulesExecuted: $rulesExecuted, phases: $phases, rules: $rules, runCount: $runCount}'
}

build_run_document() {
    local php_version
    php_version="$(php -r 'echo PHP_VERSION;')"
    local tool_version
    tool_version="$(php "$REPO_ROOT/bin/gruff-php" --version 2>&1 | head -1)"
    local created_at
    created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    local uname_string cpu_model git_commit git_dirty harness_sha wrapper_sha runtime_source
    uname_string="$(uname -srm 2>/dev/null || echo unknown)"
    cpu_model="$(awk -F': ' '/^model name/ {print $2; exit}' /proc/cpuinfo 2>/dev/null || true)"
    [[ -n "$cpu_model" ]] || cpu_model="unknown"
    git_commit="$(git -C "$REPO_ROOT" rev-parse HEAD)"
    if [[ -z "$(git -C "$REPO_ROOT" status --porcelain=v1)" ]]; then
        git_dirty=false
    else
        git_dirty=true
    fi
    # shellcheck disable=SC2016  # PHP owns $argv
    harness_sha="sha256:$(php -r 'echo hash_file("sha256", $argv[1]);' "$SCRIPT_DIR/test-performance.sh")"
    # shellcheck disable=SC2016  # PHP owns $argv
    wrapper_sha="sha256:$(php -r 'echo hash_file("sha256", $argv[1]);' "$REPO_ROOT/bin/gruff-php")"
    runtime_source="$(runtime_source_identity)"

    local corpora_json="[]"
    local corpus
    for corpus in "${CORPORA[@]}"; do
        local row
        row="$(run_corpus "$corpus")" || return 1
        corpora_json="$(jq -n --argjson existing "$corpora_json" --argjson row "$row" '$existing + [$row]')"
    done

    jq -n \
        --arg schemaVersion "gruff-perf.v1" \
        --arg phpVersion "$php_version" \
        --arg toolVersion "$tool_version" \
        --arg createdAt "$created_at" \
        --arg mode "$MODE" \
        --arg platform "$PLATFORM_SLUG" \
        --arg uname "$uname_string" \
        --arg cpu "$cpu_model" \
        --arg gitCommit "$git_commit" \
        --argjson gitDirty "$git_dirty" \
        --arg harnessSha256 "$harness_sha" \
        --arg wrapperSha256 "$wrapper_sha" \
        --argjson runtimeSource "$runtime_source" \
        --argjson corpora "$corpora_json" \
        '{
            schemaVersion: $schemaVersion,
            createdAt: $createdAt,
            host: {platform: $platform, uname: $uname, cpu: $cpu, php: $phpVersion},
            source: {
                gitCommit: $gitCommit,
                gitDirty: $gitDirty,
                runtimeSource: $runtimeSource,
                artifact: {kind: "live-wrapper", sha256: $wrapperSha256},
                harnessSha256: $harnessSha256,
                toolVersion: $toolVersion
            },
            runner: {mode: $mode, cache: "disabled"},
            corpora: $corpora
        }'
}

print_table_header() {
    printf '%s\n' "${BOLD}corpus    metric         baseline       current       delta     status${RESET}"
    printf '%s\n' "${DIM}-------   -----------    -----------    ----------    ------    ------${RESET}"
}

format_delta() {
    local delta="$1"
    if [[ -z "$delta" || "$delta" == "null" ]]; then
        printf -- "--    "
    else
        printf '%+6.1f%%' "$delta"
    fi
}

classify() {
    local delta="$1"
    local tolerance="$2"
    if [[ -z "$delta" || "$delta" == "null" ]]; then
        echo "$WARN"; return
    fi
    local abs
    abs="$(awk -v d="$delta" 'BEGIN { print (d < 0 ? -d : d) }')"
    if awk -v a="$abs" -v t="$tolerance" 'BEGIN { exit !(a <= t) }'; then
        echo "$PASS"
    elif awk -v a="$abs" -v t="$tolerance" 'BEGIN { exit !(a <= t * 1.5) }'; then
        echo "$WARN"
    else
        echo "$FAIL"
    fi
}

compare_to_baseline() {
    local run_doc="$1"
    if [[ ! -f "$BASELINE_FILE" ]]; then
        echo "${YELLOW}No baseline at ${BASELINE_FILE}. Run with --baseline to create one.${RESET}" >&2
        return 2
    fi

    local baseline
    baseline="$(cat "$BASELINE_FILE")"

    local any_fail=0
    print_table_header

    local corpora
    corpora="$(echo "$run_doc" | jq -r '.corpora[].corpus')"
    while IFS= read -r corpus; do
        [[ -z "$corpus" ]] && continue
        local cur_wall cur_peak base_wall base_peak
        cur_wall="$(echo "$run_doc" | jq -r --arg c "$corpus" '.corpora[] | select(.corpus==$c) | .wallMs')"
        cur_peak="$(echo "$run_doc" | jq -r --arg c "$corpus" '.corpora[] | select(.corpus==$c) | .peakBytes')"
        base_wall="$(echo "$baseline" | jq -r --arg c "$corpus" '.corpora[$c].wallMs // empty')"
        base_peak="$(echo "$baseline" | jq -r --arg c "$corpus" '.corpora[$c].peakBytes // empty')"

        local wall_delta="null"
        local peak_delta="null"
        if [[ -n "$base_wall" && "$base_wall" != "0" ]]; then
            wall_delta="$(awk -v c="$cur_wall" -v b="$base_wall" 'BEGIN { printf "%.2f", (c - b) * 100.0 / b }')"
        fi
        if [[ -n "$base_peak" && "$base_peak" != "0" ]]; then
            peak_delta="$(awk -v c="$cur_peak" -v b="$base_peak" 'BEGIN { printf "%.2f", (c - b) * 100.0 / b }')"
        fi

        local wall_status peak_status
        wall_status="$(classify "$wall_delta" "$WALL_TOLERANCE")"
        peak_status="$(classify "$peak_delta" "$MEM_TOLERANCE")"

        if [[ -n "$base_wall" ]] && awk -v b="$base_wall" -v f="$WALL_NOISE_FLOOR_MS" 'BEGIN { exit !(b < f) }'; then
            if [[ "$wall_status" == "$FAIL" ]]; then
                wall_status="$WARN"
            fi
        fi

        local base_wall_disp="${base_wall:--}"
        local base_peak_disp="${base_peak:--}"
        printf '%-9s %-14s %-14s %-13s %s    %s\n' \
            "$corpus" "wallMs" "$base_wall_disp" "$cur_wall" "$(format_delta "$wall_delta")" "$wall_status"
        printf '%-9s %-14s %-14s %-13s %s    %s\n' \
            "$corpus" "peakBytes" "$base_peak_disp" "$cur_peak" "$(format_delta "$peak_delta")" "$peak_status"

        if [[ "$wall_status" == "$FAIL" || "$peak_status" == "$FAIL" ]]; then
            any_fail=1
        fi
    done <<<"$corpora"

    if [[ $any_fail -eq 1 ]]; then
        return 1
    fi
    return 0
}

print_top_rules() {
    local run_doc="$1"
    local detail_count
    detail_count="$(echo "$run_doc" | jq '[.corpora[] | select(.rules | length > 0)] | length')"
    if [[ "$detail_count" -eq 0 ]]; then
        return 0
    fi
    echo
    echo "${BOLD}Top rules by total time (last corpus, last run)${RESET}"
    echo "$run_doc" | jq -r '.corpora | last.rules[0:10][] | "  \(.ruleId)\t\(.totalNs) ns\t\(.invocations) calls"' | column -t -s $'\t'
}

write_baseline() {
    local run_doc="$1"
    if [[ -f "$BASELINE_FILE" && $ASSUME_YES -ne 1 ]]; then
        echo
        echo "${YELLOW}Existing baseline at ${BASELINE_FILE}.${RESET}"
        read -r -p "Overwrite baseline? [y/N] " reply
        if [[ ! "$reply" =~ ^[Yy]$ ]]; then
            echo "${YELLOW}Cancelled. Baseline not changed.${RESET}"
            return 1
        fi
    fi

    mkdir -p "$(dirname "$BASELINE_FILE")"
    local doc
    doc="$(echo "$run_doc" | jq '{schemaVersion, createdAt, host, source, runner, corpora: (.corpora | map({(.corpus): {wallMs: .wallMs, peakBytes: .peakBytes, filesParsed: .filesParsed, rulesExecuted: .rulesExecuted, topRules: (.rules[0:10] // [])}}) | add // {})}')"
    printf '%s\n' "$doc" >"$BASELINE_FILE"
    echo "${GREEN}Wrote baseline to ${BASELINE_FILE}${RESET}"
}

main() {
    local run_doc
    run_doc="$(build_run_document)" || exit 1

    local timestamp
    timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
    printf '%s\n' "$run_doc" >"${RUN_LOG_DIR}/run-${timestamp}.json"

    if [[ $EMIT_JSON -eq 1 ]]; then
        printf '%s\n' "$run_doc"
    fi

    case "$ACTION" in
        baseline)
            write_baseline "$run_doc" || exit 1
            ;;
        check)
            if [[ $EMIT_JSON -eq 0 ]]; then
                compare_to_baseline "$run_doc"
                local cmp_status=$?
                print_top_rules "$run_doc"
                exit $cmp_status
            else
                compare_to_baseline "$run_doc" >/dev/null
                exit $?
            fi
            ;;
    esac
}

main
