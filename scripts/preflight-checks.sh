#!/usr/bin/env bash

# Run the local checks CI uses to verify a gruff-php change.
#
# Review the version, static analysis, tests, and source scan before preparing a release.
# Pass --mutate-diff or --mutate-full when you also need an Infection result.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Keep colour in an interactive terminal. A captured CI log or NO_COLOR request gets plain output.
if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
    BOLD=$'\033[1m'
    DIM=$'\033[2m'
    GREEN=$'\033[32m'
    RED=$'\033[31m'
    YELLOW=$'\033[33m'
    BLUE=$'\033[34m'
    RESET=$'\033[0m'
# Plain markers keep captured output easy to read and search.
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
RELEASE_VERSION=""

# Separate the results visually in the terminal.
rule() {
    printf '  %s\n' "${DIM}────────────────────────────────────────────${RESET}"
}

# Format elapsed time in a unit that stays readable in the check summary.
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

    # Fast checks are easier to compare in milliseconds.
    if ((elapsed_ms < 1000)); then
        printf '%dms' "$elapsed_ms"
        return
    fi

    seconds=$((elapsed_ms / 1000))
    frac=$(((elapsed_ms % 1000) / 100))

    # Short runs need only seconds. Longer runs also show minutes.
    if ((seconds < 60)); then
        printf '%d.%ds' "$seconds" "$frac"
        return
    fi

    minutes=$((seconds / 60))
    remainder=$((seconds % 60))
    printf '%dm %02d.%ds' "$minutes" "$remainder" "$frac"
}

# Show when this batch of local checks started.
header() {
    printf '\n'
    printf '  %sPreflight Check%s\n' "$BOLD" "$RESET"
    printf '  %s%s%s\n' "$DIM" "$(date '+%Y-%m-%d %H:%M:%S')" "$RESET"
    rule
    printf '\n'
}

# Start the next labelled result row and include it in the check total.
step() {
    local label=$1

    TOTAL=$((TOTAL + 1))
    printf '  %s %-40s' "$ARROW" "$label"
}

# Record a passing check and show its details when available.
pass() {
    local detail=${1:-}

    PASSED=$((PASSED + 1))
    # A check with useful output can show its count or timing beside the result.
    if [[ -n "$detail" ]]; then
        printf '%s  %s%s%s\n' "$PASS" "$DIM" "$detail" "$RESET"
    else
        printf '%s\n' "$PASS"
    fi
}

# Record a failed check so the final summary can name it.
fail() {
    local label=$1

    FAILED=$((FAILED + 1))
    FAILURES+=("$label")
    printf '%s\n' "$FAIL"
}

# Explain a skipped check without counting it as a pass or failure.
skip() {
    local reason=${1:-skipped}

    printf '%s  %s%s%s\n' "$SKIP" "$DIM" "$reason" "$RESET"
}

# Indent command output so it reads as details under its check.
indent_output() {
    # Preserve each diagnostic line when nesting it below the check name.
    while IFS= read -r line; do
        printf '    %s%s%s\n' "$DIM" "$line" "$RESET"
    done
}

# Run a configured check and show its evidence beside the elapsed time.
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

    # A zero exit means this check can be shown as passed.
    if ((status == 0)); then
        pass "${output:+$output }$elapsed"
    else
        fail "$label"
        # Show recent output when it can explain why the named check failed.
        if [[ -n "$output" ]]; then
            printf '%s\n' "$output" | tail -20 | indent_output
        fi
        printf '    %sexit %d after %s%s\n' "$DIM" "$status" "$elapsed" "$RESET"
    fi

    return "$status"
}

# Run the configured static analysis and keep its details for failures.
static_analysis_check() {
    local output
    local status

    output=$(composer phpstan 2>&1)
    status=$?

    # Print the PHPStan diagnostics only when the check needs attention.
    if ((status != 0)); then
        printf '%s\n' "$output"
    fi

    return "$status"
}

# Run the PHPUnit suite and show test and assertion counts on success.
test_suite_check() {
    local output
    local status
    local summary

    output=$(composer test 2>&1)
    status=$?
    summary=$(printf '%s\n' "$output" | grep -oE '[0-9]+ tests, [0-9]+ assertions' | tail -1 || true)

    # Keep a successful test row short and leave the full output available on failure.
    if ((status == 0)); then
        printf '%s' "$summary"
    else
        printf '%s\n' "$output"
    fi

    return "$status"
}

# Check locked dependencies for published security advisories.
dependency_audit_check() {
    local output
    local status

    output=$(composer audit:dependencies 2>&1)
    status=$?

    # Keep the successful audit row short. Failed audits keep the detail the maintainer needs.
    if ((status == 0)); then
        printf '%s' "$(printf '%s\n' "$output" | tail -1)"
    else
        printf '%s\n' "$output"
    fi

    return "$status"
}

# Check that the CLI, release markers, and supported-version line agree.
version_consistency_check() {
    local release_version=${1:-}

    # shellcheck disable=SC2016
    php -r '
$releaseVersion = $argv[1] ?? "";
$applicationPath = "src/Cli/Application.php";
$changelogPath = "CHANGELOG.md";
$binaryPath = "bin/gruff-php";
$errors = [];

$fail = static function (string $message) use (&$errors): void {
    $errors[] = $message;
};

// Refuse a release override that does not name a three-part version.
if ($releaseVersion !== "" && !preg_match("/^[0-9]+\.[0-9]+\.[0-9]+$/", $releaseVersion)) {
    $fail("release version must look like X.Y.Z, got: " . $releaseVersion);
}

$applicationBody = file_get_contents($applicationPath);
// Report a missing application version source with the path to restore.
if ($applicationBody === false) {
    $fail("could not read " . $applicationPath);
    $applicationVersion = "";
} elseif (!preg_match("/public const VERSION = \x27([^\x27]+)\x27;/", $applicationBody, $match)) {
    $fail("could not read Application::VERSION from " . $applicationPath);
    $applicationVersion = "";
} else {
    $applicationVersion = $match[1];
}

$cliOutput = [];
$cliStatus = 0;
exec(PHP_BINARY . " " . escapeshellarg($binaryPath) . " --version 2>&1", $cliOutput, $cliStatus);
$cliText = trim(implode("\n", $cliOutput));
// A failing CLI version command cannot confirm what customers install.
if ($cliStatus !== 0) {
    $fail("php " . $binaryPath . " --version failed: " . $cliText);
    $cliVersion = "";
} elseif (!preg_match("/^gruff-php\s+(\S+)$/", $cliText, $match)) {
    $fail("unexpected CLI version output: " . $cliText);
    $cliVersion = "";
} else {
    $cliVersion = $match[1];
}

// The displayed CLI version must match the source constant used by the package.
if ($applicationVersion !== "" && $cliVersion !== "" && $applicationVersion !== $cliVersion) {
    $fail("Application::VERSION " . $applicationVersion . " does not match CLI version " . $cliVersion);
}

$changelogBody = file_get_contents($changelogPath);
// A missing changelog prevents checking the version a reader sees in release notes.
if ($changelogBody === false) {
    $fail("could not read " . $changelogPath);
    $changelogVersion = "";
    $changelogState = "";
} elseif (!preg_match("/^## ([0-9]+\.[0-9]+\.[0-9]+) - (Unreleased|[0-9]{4}-[0-9]{2}-[0-9]{2})[ \t]*$/m", $changelogBody, $match)) {
    $fail("could not find top release heading in " . $changelogPath);
    $changelogVersion = "";
    $changelogState = "";
} else {
    $changelogVersion = $match[1];
    $changelogState = $match[2];
}

// Compare the shared release number once both source and changelog were read.
if ($applicationVersion !== "" && $changelogVersion !== "") {
    $baseVersion = preg_replace("/-.*/", "", $applicationVersion);
    // Report the two version values when the release notes point to another version.
    if ($baseVersion !== $changelogVersion) {
        $fail("Application::VERSION base " . $baseVersion . " does not match CHANGELOG top version " . $changelogVersion);
    }

    # A development build needs an Unreleased changelog section.
    if (str_contains($applicationVersion, "-")) {
        // Refuse a development suffix paired with a released changelog entry.
        if ($changelogState !== "Unreleased") {
            $fail("prerelease/dev version " . $applicationVersion . " requires an Unreleased CHANGELOG heading for " . $baseVersion);
        }
    } elseif ($changelogState === "Unreleased") {
        $fail("release version " . $applicationVersion . " requires a dated CHANGELOG heading; run scripts/bump-version.sh " . $applicationVersion);
    }
}

// A requested release version adds stricter checks before tagging.
if ($releaseVersion !== "") {
    // The source version must match the exact version the maintainer plans to publish.
    if ($applicationVersion !== "" && $applicationVersion !== $releaseVersion) {
        $fail("Application::VERSION is " . $applicationVersion . ", expected release " . $releaseVersion . "; run scripts/bump-version.sh " . $releaseVersion);
    }
    // The latest changelog entry must describe the version being published.
    if ($changelogVersion !== "" && $changelogVersion !== $releaseVersion) {
        $fail("CHANGELOG top version is " . $changelogVersion . ", expected release " . $releaseVersion);
    }
    // Publishing needs a dated changelog entry rather than an Unreleased heading.
    if ($changelogState !== "" && $changelogState === "Unreleased") {
        $fail("CHANGELOG entry for " . $releaseVersion . " is still Unreleased; run scripts/bump-version.sh " . $releaseVersion);
    }
}

// Documentation stamps must match Application::VERSION exactly; bump-version.sh rewrites all of
// them, so any mismatch here names the stale file. CLI golden fixtures are deliberately absent
// from this list: their stamps are normalised to Application::VERSION at compare time in
// tests/Console/AnalyseCliTest.php, so they cannot drift.
$documentStampChecks = [
    ["README.md", "/\| Current source \| `([^`]+)` \|/", "README.md current-source stamp"],
    ["docs/gruff-cli-summary.md", "/^gruff-php (\S+) summary$/m", "docs/gruff-cli-summary.md header stamp"],
    ["docs/gruff-cli-summary.md", "/\"version\": \"([^\"]+)\"/", "docs/gruff-cli-summary.md JSON example stamp"],
];
foreach ($documentStampChecks as [$documentPath, $stampPattern, $stampLabel]) {
    $documentBody = file_get_contents($documentPath);
    // A missing versioned document means its public stamp cannot be checked.
    if ($documentBody === false) {
        $fail("could not read " . $documentPath);
        continue;
    }
    # A document with no matching CLI stamp would leave the release version unverified.
    if (!preg_match($stampPattern, $documentBody, $match)) {
        $fail("could not find " . $stampLabel);
        continue;
    }
    // A stale document stamp could point readers at the wrong package version.
    if ($applicationVersion !== "" && $match[1] !== $applicationVersion) {
        $fail($stampLabel . " is " . $match[1] . " but Application::VERSION is " . $applicationVersion . "; run scripts/bump-version.sh " . $applicationVersion);
    }
}

// SUPPORT.md names a release series, not a version, so each of its release-line sentences must name the series
// Application::VERSION belongs to; a stale line there once promised support for 0.3.x while 0.5.2 shipped.
$supportBody = file_get_contents("SUPPORT.md");
// Missing support documentation leaves the advertised PHP version unchecked.
if ($supportBody === false) {
    $fail("could not read SUPPORT.md");
} elseif ($applicationVersion !== "") {
    $supportedLine = preg_replace("/^(\d+\.\d+)\..*$/", "$1.x", $applicationVersion);
    preg_match_all("/current `(\d+\.\d+\.x)` release line/", $supportBody, $supportMatches);
    // The minimum PHP guidance needs a support line to compare.
    if ($supportMatches[1] === []) {
        $fail("could not find the SUPPORT.md release-line sentence");
    }
    foreach ($supportMatches[1] as $supportLine) {
        // A stale support line may lead someone to install an unsupported PHP version.
        if ($supportLine !== $supportedLine) {
            $fail("SUPPORT.md names the " . $supportLine . " release line but Application::VERSION is " . $applicationVersion . "; the supported line is " . $supportedLine);
        }
    }
}

// Show all mismatched version facts in one run so a maintainer can fix them together.
if ($errors !== []) {
    fwrite(STDOUT, implode("\n", $errors));
    exit(1);
}

$detail = $applicationVersion . " matches CLI";
// Include the changelog match in the success message when it was available.
if ($changelogVersion !== "" && $changelogState !== "") {
    $detail .= " and CHANGELOG " . $changelogVersion . " - " . $changelogState;
}
// Include the requested release version when verifying a release cut.
if ($releaseVersion !== "") {
    $detail .= " for release " . $releaseVersion;
}
echo $detail;
' "$release_version"
}

# Read the machine report and show a compact finding total and grade.
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

# Some diagnostic reports have no score to display.
if (is_array($score)) {
    $parts[] = sprintf("%s %.2f/100", (string) ($score["grade"] ?? "n/a"), (float) ($score["score"] ?? 0));
}

echo implode(", ", $parts);
' "$report_path"
}

# Analyse the project and keep its JSON and stderr separate for readable results.
gruff_php_check() {
    local report_path="${TMPDIR:-/tmp}/gruff-preflight-analysis.json"
    local error_path="${TMPDIR:-/tmp}/gruff-preflight-analysis.err"
    local status
    local printed=0

    # --no-cache: the release gate must re-run every rule fresh. The result cache keys on tool version +
    # config + rule ids, not rule source, so a warm cache masks rule-LOGIC changes made within one version
    # and the gate would pass (or fail) on stale findings. See .goat-flow/learning-loop/footguns/commands.md.
    php bin/gruff-php analyse --fail-on advisory --no-cache --format json > "$report_path" 2> "$error_path"
    status=$?

    # Show the scan totals when the analyser could write a report.
    if [[ -s "$report_path" ]]; then
        gruff_report_summary "$report_path" || return $?
        printed=1
    fi

    # Preserve any stderr from analysis so failures can be diagnosed.
    if [[ -s "$error_path" ]]; then
        # Separate diagnostic text from the previously printed report summary.
        if ((printed)); then
            printf '\n'
        fi
        cat "$error_path"
    fi

    return "$status"
}

# Run the requested Infection scope and summarize its output.
mutation_check() {
    local mode=$1
    local output
    local status
    local summary_line

    output=$(scripts/mutation-test-"$mode".sh 2>&1)
    status=$?

    # A completed mutation run can show a compact result.
    if ((status == 0)); then
        summary_line=$(printf '%s\n' "$output" | grep -E 'MSI |All [0-9]+/[0-9]+ checks passed|no edited unit test files' | tail -1 || true)
        printf '%s' "${summary_line:-completed}"
    else
        printf '%s\n' "$output"
    fi

    return "$status"
}

# ---------------------------------------------------------------------------
# Documentation drift (M09 task 14). The owned documentation must agree with the
# live rule catalogue, name only rules that ship, cite only decisions this port
# carries, and link only to pages that exist. Every extraction fails closed: a
# document that states no catalogue size or names no rule is a defect, not a pass.
# ---------------------------------------------------------------------------

# Extract the live catalogue facts once so every drift assertion reads one snapshot.
# Extract current catalogue counts and rule IDs for the documentation check.
docs_drift_facts() {
    local catalogue_file=$1
    GRUFF_DOCS_CATALOGUE="$catalogue_file" php <<'PHP'
<?php
$listing = json_decode((string)file_get_contents((string)getenv('GRUFF_DOCS_CATALOGUE')), true);
$rules = is_array($listing) ? ($listing['rules'] ?? null) : null;
# An empty catalogue cannot prove that the documented counts match shipped rules.
if (!is_array($rules) || $rules === []) {
    fwrite(STDERR, "false-empty: list-rules published no rules under rules\n");
    exit(1);
}
$ids = array_map(static fn(array $rule): string => (string)$rule['id'], $rules);
sort($ids);
$pillars = array_values(array_unique(array_map(static fn(array $rule): string => (string)$rule['pillar'], $rules)));
sort($pillars);
$shapes = count(array_filter($rules, static fn(array $rule): bool => ($rule['falsePositiveShapes'] ?? []) !== []));
$pillarCounts = array_map(
    static fn(string $pillar): string => $pillar . ':' . count(array_filter($rules, static fn(array $rule): bool => $rule['pillar'] === $pillar)),
    $pillars,
);
// Rule ids are matched by their own prefix, not by pillar name: `docs.` and `waste.` ids live in
// pillars with other names, and a scan keyed on pillar names never sees them.
$idPrefixes = array_values(array_unique(array_map(static fn(string $id): string => explode('.', $id)[0], $ids)));
sort($idPrefixes);
$prefixNotPillar = $idPrefixes[0];
foreach ($idPrefixes as $prefix) {
    # Keep one rule ID prefix that differs from its pillar for the drift fixture.
    if (!in_array($prefix, $pillars, true)) {
        $prefixNotPillar = $prefix;
        break;
    }
}
echo 'count=' . count($rules) . "\n";
echo 'pillars=' . count($pillars) . "\n";
echo 'shapes=' . $shapes . "\n";
echo 'pillarNames=' . implode('|', $pillars) . "\n";
echo 'pillarCounts=' . implode('|', $pillarCounts) . "\n";
echo 'idPrefixes=' . implode('|', $idPrefixes) . "\n";
echo 'prefixNotPillar=' . $prefixNotPillar . "\n";
echo 'ids=' . implode(' ', $ids) . "\n";
PHP
}

# Compare every per-pillar table row in the README with the live catalogue. A row that names a count
# the catalogue does not have, or a table shorter than the catalogue's pillar list, is a stale claim.
docs_drift_pillar_table() {
    local readme=$1 facts=$2
    local backtick='`'
    local pillar_counts row_pillar row_count live_count table_rows=0 pillars seen_pillars=" "
    pillars=$(docs_fact "$facts" pillars)
    pillar_counts="|$(docs_fact "$facts" pillarCounts)|"

    # Compare each documented pillar with the current rule count.
    while IFS='|' read -r row_pillar row_count; do
        table_rows=$((table_rows + 1))
        # A repeated row keeps the row count equal to the pillar count while hiding a missing pillar.
        if [[ "$seen_pillars" == *" $row_pillar "* ]]; then
            printf 'docs drift: source-revision: README.md pillar table lists %s twice\n' "$row_pillar"
            return 1
        fi
        seen_pillars+="$row_pillar "
        live_count=$(sed -n "s/.*|${row_pillar}:\([0-9]*\)|.*/\1/p" <<<"$pillar_counts")
        # Name the stale pillar and both counts so the maintainer knows what to update.
        if [[ "$live_count" != "$row_count" ]]; then
            printf 'docs drift: source-revision: README.md pillar table says %s has %s rules but list-rules has %s\n' \
                "$row_pillar" "$row_count" "${live_count:-no such pillar}"
            return 1
        fi
    done < <(grep -oE "^\| *${backtick}[a-z-]+${backtick} *\| *[0-9]+ *\|$" "$readme" \
        | sed -E "s/^\| *${backtick}([a-z-]+)${backtick} *\| *([0-9]+) *\|$/\1|\2/")

    # A partial table leaves at least one shipped pillar unrepresented; zero rows is checked after false-empty.
    if ((table_rows > 0 && table_rows != pillars)); then
        printf 'docs drift: source-revision: README.md pillar table has %s rows but list-rules has %s pillars\n' \
            "$table_rows" "$pillars"
        return 1
    fi

    printf '%s' "$table_rows"
}

# Read one fact from the extracted facts block.
docs_fact() {
    local facts=$1 key=$2
    sed -n "s/^${key}=//p" <<<"$facts"
}

# Capture the live catalogue facts, or the reason the catalogue could not be read.
docs_drift_live_facts() {
    local catalogue facts status

    # A private temp file, so two checkouts running preflight at once never read each other's catalogue.
    catalogue=$(mktemp "${TMPDIR:-/tmp}/gruff-php-docs-drift.XXXXXX.json") || return 1
    # If the CLI cannot list rules, there is no catalogue to check the documentation against.
    if ! php bin/gruff-php list-rules --format=json >"$catalogue" 2>/dev/null; then
        rm -f -- "$catalogue"
        printf 'docs drift: list-rules --format=json failed'
        return 1
    fi
    facts=$(docs_drift_facts "$catalogue" 2>&1)
    status=$?
    rm -f -- "$catalogue"
    printf '%s' "$facts"
    return "$status"
}

# Compare the owned documentation under one root with the live catalogue facts. Runs against the
# real checkout, and against synthetic copies in the fixture harness, so both share one contract.
docs_drift_check_root() {
    local docs_root=$1 facts=$2
    local readme="$docs_root/README.md" rules_doc="$docs_root/docs/rules.md"
    local count pillars shapes ids id_prefixes
    local claim claims=0 doc token decision link line history relative tracked_check=1
    local documents=() mentioned=() phantom=() bad_decisions=() dead=()

    count=$(docs_fact "$facts" count)
    pillars=$(docs_fact "$facts" pillars)
    shapes=$(docs_fact "$facts" shapes)
    ids=" $(docs_fact "$facts" ids) "
    id_prefixes=$(docs_fact "$facts" idPrefixes)

    # Open both reader-facing documents before comparing their catalogue claims.
    for doc in "$readme" "$rules_doc"; do
        # A missing document needs to be restored before the release check can proceed.
        if [[ ! -f "$doc" ]]; then
            printf 'docs drift: false-empty: %s is missing\n' "${doc#"$docs_root"/}"
            return 1
        fi
    done

    # Source-revision claims: every stated catalogue size must equal the live catalogue.
    while IFS= read -r claim; do
        claims=$((claims + 1))
        # Report a stale total when the rules page and current catalogue disagree.
        if [[ "$claim" != "Total rules: $count" ]]; then
            printf 'docs drift: source-revision: docs/rules.md says "%s" but list-rules has %s rules\n' "$claim" "$count"
            return 1
        fi
    done < <(grep -oE 'Total rules: [0-9]+' "$rules_doc")
    # Check each published count of rules with false-positive guidance.
    while IFS= read -r claim; do
        claims=$((claims + 1))
        # Name both counts when the rules page overstates or understates available guidance.
        if [[ "$claim" != "$shapes of the $count rules publish" ]]; then
            printf 'docs drift: source-revision: docs/rules.md says "%s" but list-rules publishes falsePositiveShapes on %s of %s rules\n' \
                "$claim" "$shapes" "$count"
            return 1
        fi
    done < <(grep -oE '[0-9]+ of the [0-9]+ rules publish' "$rules_doc")
    # Check how the README describes the size and breadth of the catalogue.
    while IFS= read -r claim; do
        claims=$((claims + 1))
        # Flag a README claim that would show readers the wrong rule or pillar total.
        if [[ "$claim" != "$count rules across $pillars pillars" ]]; then
            printf 'docs drift: source-revision: README.md says "%s" but list-rules has %s rules across %s pillars\n' \
                "$claim" "$count" "$pillars"
            return 1
        fi
    done < <(grep -oE '[0-9]+ rules across [0-9]+ pillars' "$readme")
    # Check the README's alternate catalogue-count wording as well.
    while IFS= read -r claim; do
        claims=$((claims + 1))
        # Reject stale totals wherever the entry page mentions them.
        if [[ "$claim" != "contains $count registry rules" ]]; then
            printf 'docs drift: source-revision: README.md says "%s" but list-rules has %s rules\n' "$claim" "$count"
            return 1
        fi
    done < <(grep -oE 'contains [0-9]+ registry rules' "$readme")
    local table_rows
    table_rows=$(docs_drift_pillar_table "$readme" "$facts") || {
        printf '%s\n' "$table_rows"
        return 1
    }
    claims=$((claims + table_rows))
    # A table or count must exist somewhere for the gate to check catalogue accuracy.
    if ((claims == 0)); then
        printf 'docs drift: false-empty: README.md and docs/rules.md state no catalogue size\n'
        return 1
    fi
    # Even correct prose counts cannot stand in for the README's pillar-by-pillar table.
    if ((table_rows == 0)); then
        printf 'docs drift: source-revision: README.md pillar table is missing\n'
        return 1
    fi

    # Phantom rule ids: a backticked rule id in the README or docs must be a rule that ships. Ids are
    # matched by their own prefix, not by pillar name. UPGRADING.md is history by design, and so is a
    # line that says an id was retired or removed in a named version; every other line is checked in full.
    mapfile -t documents < <(find "$docs_root/docs" -maxdepth 1 -name '*.md' 2>/dev/null | sort)
    documents+=("$readme")
    # Inspect each documentation line that names a rule ID.
    while IFS= read -r line; do
        history=0
        # Keep an explicit historical citation when it says which release removed the rule.
        if [[ "$line" =~ (retired|removed) && "$line" =~ [0-9]+\.[0-9]+ ]]; then
            history=1
        fi
        # Collect all documented IDs from this line before comparing them with the catalogue.
        while IFS= read -r token; do
            # Count each cited rule once even when several guides mention it.
            if [[ " ${mentioned[*]} " != *" $token "* ]]; then
                mentioned+=("$token")
            fi
            # Flag absent rules that are presented as current, collecting each ID once.
            if [[ "$ids" != *" $token "* ]] && ((history == 0)) && [[ " ${phantom[*]} " != *" $token "* ]]; then
                phantom+=("$token")
            fi
        done < <(grep -oE "\`($id_prefixes)\.[a-z0-9-]+\`" <<<"$line" | tr -d '`')
    done < <(cat "${documents[@]}" | grep -E "\`($id_prefixes)\.[a-z0-9-]+\`")
    # An empty rule reference list cannot exercise the rule-ID drift check.
    if ((${#mentioned[@]} == 0)); then
        printf 'docs drift: false-empty: the documentation names no rule id\n'
        return 1
    fi
    # List all unsupported current rule IDs so the maintainer can fix their references together.
    if ((${#phantom[@]} > 0)); then
        printf 'docs drift: phantom rule ids not in list-rules: %s\n' "${phantom[*]}"
        return 1
    fi

    # Decision namespace: every ADR the documentation cites must exist in this port's decisions, and a
    # citation that carries a slug must name that exact record, so a number borrowed from another port
    # or from the family cannot pass against an unrelated local record with the same number.
    # Check every cited decision against the decision files available to readers.
    while IFS= read -r decision; do
        # A slugged ADR citation must link to that exact decision, not an unrelated decision with the same number.
        if [[ "$decision" == ADR-[0-9][0-9][0-9]-* ]]; then
            [[ -f "$REPO_ROOT/.goat-flow/learning-loop/decisions/$decision.md" ]] || bad_decisions+=("$decision")
        elif ! compgen -G "$REPO_ROOT/.goat-flow/learning-loop/decisions/$decision-*.md" >/dev/null; then
            bad_decisions+=("$decision")
        fi
    done < <(cat "${documents[@]}" "$docs_root/UPGRADING.md" 2>/dev/null | grep -oE 'ADR-[0-9]{3}(-[a-z0-9]+)*' | sort -u)
    # Tell the maintainer which cited decisions could not be found.
    if ((${#bad_decisions[@]} > 0)); then
        printf 'docs drift: decision-namespace: %s cited but absent from .goat-flow/learning-loop/decisions\n' \
            "${bad_decisions[*]}"
        return 1
    fi

    # Entry-page links: every relative link from the README must name a file or directory tracked in
    # this repository. A path that exists on disk but escapes the repository, or is not tracked, is
    # dead for every reader of the published repository. A fixture copy carries the documentation, so
    # a link into the copy resolves there first.
    git -C "$REPO_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1 || tracked_check=0
    # Resolve each relative README link from the entry page.
    while IFS= read -r link; do
        relative=$(realpath -m --relative-to="$REPO_ROOT" "$REPO_ROOT/$link")
        # A link outside the repository will not work for someone reading a published checkout.
        if [[ "$relative" == ".." || "$relative" == ../* ]]; then
            dead+=("$link")
        elif [[ "$docs_root" != "$REPO_ROOT" && -e "$docs_root/$link" ]]; then
            continue
        elif ((tracked_check == 0)); then
            [[ -e "$REPO_ROOT/$relative" ]] || dead+=("$link")
        elif ! git -C "$REPO_ROOT" ls-files --error-unmatch -- "$relative" >/dev/null 2>&1; then
            dead+=("$link")
        fi
    done < <(grep -oE '\]\([^)#[:space:]]+' "$readme" | sed 's/^](//' | grep -vE '^(https?://|mailto:)' | sort -u)
    # List broken entry links together so they can be repaired in one pass.
    if ((${#dead[@]} > 0)); then
        printf 'docs drift: entry-page link does not resolve: %s\n' "${dead[*]}"
        return 1
    fi

    printf '%s rules, %s pillars, %s with guidance; %s rule ids and every cited decision resolve' \
        "$count" "$pillars" "$shapes" "${#mentioned[@]}"
}

docs_drift_check() {
    local facts status

    facts=$(docs_drift_live_facts)
    status=$?
    # Show the catalogue error when live facts cannot be collected.
    if ((status != 0)); then
        printf '%s' "$facts"
        return "$status"
    fi
    docs_drift_check_root "$REPO_ROOT" "$facts"
}

# Run the drift check against one mutated copy and require the named rejection.
expect_docs_drift_rejection() {
    local case_name=$1 expected=$2 docs_root=$3 facts=$4 output

    # A deliberately damaged copy must fail or this fixture proves a gap in the check.
    if output=$(docs_drift_check_root "$docs_root" "$facts" 2>&1); then
        printf 'docs drift fixture %s: the mutation passed the gate\n' "$case_name"
        return 1
    fi
    # Verify the intended error so an unrelated problem cannot make a broken fixture appear covered.
    if [[ "$output" != *"$expected"* ]]; then
        printf 'docs drift fixture %s: rejected for the wrong reason; expected "%s", got: %s\n' \
            "$case_name" "$expected" "$output"
        return 1
    fi
}

# Prove the drift gate rejects each mutation class without touching the real documentation
# (M09 task 16): false-empty, phantom rule, decision number and borrowed slug, source-revision,
# dead and escaping links, and missing, stale, or duplicated pillar-table rows.
docs_drift_fixture_check() {
    local facts status harness valid root count prefix_not_pillar first_decision
    local backtick='`'

    facts=$(docs_drift_live_facts)
    status=$?
    # A failed catalogue load stops the fixture run before comparing copies against incomplete facts.
    if ((status != 0)); then
        printf '%s' "$facts"
        return "$status"
    fi
    count=$(docs_fact "$facts" count)
    prefix_not_pillar=$(docs_fact "$facts" prefixNotPillar)
    first_decision=$(find "$REPO_ROOT/.goat-flow/learning-loop/decisions" -maxdepth 1 -name 'ADR-[0-9][0-9][0-9]-*.md' | sort | head -n 1)
    first_decision=$(basename "$first_decision")
    first_decision=${first_decision:0:7}

    harness=$(mktemp -d "${TMPDIR:-/tmp}/gruff-php-docs-fixtures.XXXXXX") || return 1
    valid="$harness/valid"
    mkdir -p "$valid"
    cp "$REPO_ROOT/README.md" "$REPO_ROOT/UPGRADING.md" "$valid/"
    cp -R "$REPO_ROOT/docs" "$valid/docs"
    # Require the clean documentation copy to pass before testing the intentional changes.
    if ! docs_drift_check_root "$valid" "$facts" >/dev/null 2>&1; then
        printf 'docs drift fixture: the unmodified copy failed the gate'
        rm -rf -- "$harness"
        return 1
    fi

    root="$harness/false-empty"
    cp -R "$valid" "$root"
    printf '# gruff-php\n\nSee the docs.\n' >"$root/README.md"
    printf '# Rules\n\nSee list-rules.\n' >"$root/docs/rules.md"
    expect_docs_drift_rejection false-empty 'false-empty' "$root" "$facts" || { rm -rf -- "$harness"; return 1; }

    root="$harness/phantom-rule"
    cp -R "$valid" "$root"
    # The prefix is chosen from one that is not a pillar name, the class a pillar-keyed scan misses.
    printf '\nThe %s%s.phantom-rule%s rule is documented here.\n' "$backtick" "$prefix_not_pillar" "$backtick" >>"$root/README.md"
    expect_docs_drift_rejection phantom-rule 'phantom rule ids' "$root" "$facts" || { rm -rf -- "$harness"; return 1; }

    root="$harness/decision-namespace"
    cp -R "$valid" "$root"
    printf '\nSee ADR-999 for the rationale.\n' >>"$root/README.md"
    expect_docs_drift_rejection decision-namespace 'decision-namespace' "$root" "$facts" || { rm -rf -- "$harness"; return 1; }

    root="$harness/source-revision"
    cp -R "$valid" "$root"
    sed -i "s/^Total rules: $count$/Total rules: $((count + 1))/" "$root/docs/rules.md"
    expect_docs_drift_rejection source-revision 'source-revision' "$root" "$facts" || { rm -rf -- "$harness"; return 1; }

    root="$harness/dead-link"
    cp -R "$valid" "$root"
    printf '\n[Missing page](docs/missing-page.md)\n' >>"$root/README.md"
    expect_docs_drift_rejection dead-link 'entry-page link' "$root" "$facts" || { rm -rf -- "$harness"; return 1; }

    root="$harness/stale-pillar-table"
    cp -R "$valid" "$root"
    sed -i -E "0,/^(\| *${backtick}[a-z-]+${backtick} *\| *)[0-9]+( *\|)$/s//\1999\2/" "$root/README.md"
    expect_docs_drift_rejection stale-pillar-table 'pillar table' "$root" "$facts" || { rm -rf -- "$harness"; return 1; }

    root="$harness/missing-pillar-table"
    cp -R "$valid" "$root"
    sed -i -E "/^\| *${backtick}[a-z-]+${backtick} *\| *[0-9]+ *\|$/d" "$root/README.md"
    expect_docs_drift_rejection missing-pillar-table 'pillar table is missing' "$root" "$facts" || { rm -rf -- "$harness"; return 1; }

    # A real decision number with another record's slug must not pass on the number alone.
    root="$harness/borrowed-decision-slug"
    cp -R "$valid" "$root"
    printf '\nSee %s-not-this-record for the rationale.\n' "$first_decision" >>"$root/README.md"
    expect_docs_drift_rejection borrowed-decision-slug 'decision-namespace' "$root" "$facts" || { rm -rf -- "$harness"; return 1; }

    # A link that exists on this disk but leaves the repository is dead for every published reader.
    root="$harness/escaping-link"
    cp -R "$valid" "$root"
    printf '\n[Family contract](../FAMILY-CONTRACT.md)\n' >>"$root/README.md"
    expect_docs_drift_rejection escaping-link 'entry-page link' "$root" "$facts" || { rm -rf -- "$harness"; return 1; }

    # A repeated table row keeps the row count right while a pillar goes missing.
    root="$harness/duplicated-pillar-row"
    cp -R "$valid" "$root"
    awk -v tick="$backtick" '
        { lines[NR] = $0 }
        $0 ~ ("^[|] *" tick "[a-z-]+" tick " *[|] *[0-9]+ *[|]$") { rows[++found] = NR }
        END { for (i = 1; i <= NR; i++) print (i == rows[1] ? lines[rows[2]] : lines[i]) }
    ' "$root/README.md" >"$root/README.md.next" && mv "$root/README.md.next" "$root/README.md"
    expect_docs_drift_rejection duplicated-pillar-row 'pillar table' "$root" "$facts" || { rm -rf -- "$harness"; return 1; }

    rm -rf -- "$harness"
    printf '10 mutations rejected'
}

# Print the overall check result and point to failed steps.
summary() {
    local elapsed

    elapsed=$(elapsed_since "$START_TIME")
    printf '\n'
    rule
    printf '\n'

    # Optional skips still allow overall success when every check that ran passed.
    if ((FAILED == 0)); then
        printf '  %sAll %d/%d checks passed%s  %s(%s)%s\n' "$GREEN$BOLD" "$PASSED" "$TOTAL" "$RESET" "$DIM" "$elapsed" "$RESET"
        printf '\n'
        return 0
    fi

    printf '  %s%d/%d checks failed%s  %s(%s)%s\n' "$RED$BOLD" "$FAILED" "$TOTAL" "$RESET" "$DIM" "$elapsed" "$RESET"
    printf '\n'
    # List each failed step so the caller can find its diagnostic above.
    for failure in "${FAILURES[@]}"; do
        printf '    %s  %s\n' "$FAIL" "$failure"
    done
    printf '\n'

    return 1
}

# Describe the supported preflight options for callers running it locally or in CI.
usage() {
    cat <<'USAGE'
Usage: scripts/preflight-checks.sh [--release-version X.Y.Z] [--mutate-diff|--mutate-full]

Runs the standard preflight gate:
  - Version consistency
  - PHPStan
  - PHPUnit
  - Composer dependency audit
  - Gruff full-project scan
  - Documentation drift (README.md and docs/ agree with list-rules, cite only carried decisions, link only to real pages)
  - Documentation drift fixtures (each mutation class is rejected: false-empty, phantom rule, decision number and slug, source-revision, dead and escaping links, stale and duplicated pillar rows)

Options:
  --release-version X.Y.Z
                   Require the CLI, Application::VERSION, and CHANGELOG.md to
                   match a dated release version.
  --mutate-diff    Run Infection using edited PHPUnit unit test files only.
  --mutate-full    Run the full Infection mutation suite against unit tests.
  -h, --help       Show this help.
USAGE
}

# Run the checks from the repository root and return a failing status when a required check fails.
main() {
    local phpstan_status=0
    local test_status=0
    local audit_status=0
    local gruff_status=0
    local docs_drift_status=0
    local docs_fixtures_status=0
    local mutation_status=0
    local version_status=0

    # Read launch options before running checks so invalid invocations cannot look successful.
    while (($# > 0)); do
        case "$1" in
            --release-version)
                # An explicit release check needs the version to compare with the CLI.
                if [[ $# -lt 2 ]]; then
                    printf '%sMissing value for --release-version.%s\n' "$RED" "$RESET" >&2
                    usage >&2
                    return 64
                fi
                RELEASE_VERSION="$2"
                shift
                ;;
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

    # Report a missing Composer installation before running checks that depend on it.
    if ! command -v composer >/dev/null 2>&1; then
        step "Composer"
        fail "Composer"
        printf '    %sComposer is not available on PATH.%s\n' "$DIM" "$RESET"
        summary
        return 127
    fi

    run_step "Version consistency" version_consistency_check "$RELEASE_VERSION"
    version_status=$?

    run_step "Static analysis (PHPStan L10)" static_analysis_check
    phpstan_status=$?

    run_step "Tests (PHPUnit)" test_suite_check
    test_status=$?

    run_step "Dependency audit (Composer)" dependency_audit_check
    audit_status=$?

    run_step "Gruff full-project scan" gruff_php_check
    gruff_status=$?

    run_step "Documentation drift" docs_drift_check
    docs_drift_status=$?

    run_step "Documentation drift fixtures" docs_drift_fixture_check
    docs_fixtures_status=$?

    # Run the requested Infection scope. Ordinary preflight leaves mutation testing optional.
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

    # Preserve a nonzero result for CI even if a later check produced a passing row.
    if ((version_status != 0 || phpstan_status != 0 || test_status != 0 || audit_status != 0 || gruff_status != 0 || docs_drift_status != 0 || docs_fixtures_status != 0 || mutation_status != 0)); then
        return 1
    fi

    return "$summary_status"
}

main "$@"
