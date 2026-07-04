<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Severity;
use InvalidArgumentException;

/**
 * The pass/fail gate for a scan: the finding-count caps that decide whether `gruff-php analyse`
 * exits clean or reports a failure, which is the green/red status a user's CI job keys off.
 *
 * A gate can cap the total number of findings, cap each severity (error, warning, advisory)
 * on its own, and carry a nested sub-gate weighed only against findings that are new since the
 * baseline. It is the single source of truth for that verdict: the simple `--fail-on <severity>`
 * flag desugars to an equivalent gate through `fromFailOn()`, while the richer `failureConditions:`
 * block in a user's `.gruff-php.yaml` is parsed by `fromConfig()`.
 */
final readonly class FailThresholds
{
    /**
     * The order severities are checked in, worst first, so the failure a user sees names their most
     * serious breach rather than a lesser one that also tripped.
     *
     * @var list<value-of<Severity>>
     */
    private const SEVERITY_ORDER = ['error', 'warning', 'advisory'];

    /**
     * Assembles a gate from its caps, rejecting any negative cap up front so a malformed config
     * fails loudly at construction instead of silently failing every run (a negative cap can never be met).
     *
     * @param int|null             $total - Maximum total findings allowed; null means the total is uncapped.
     * @param array<string, int>   $severityCounts - Maximum findings allowed per severity value, keyed by severity value; an empty map caps no severity.
     * @param FailThresholds|null  $newFindingsGate - Sub-gate judged against new-since-baseline findings only; null means no separate new-findings gate applies.
     * @throws InvalidArgumentException When any cap is negative.
     */
    public function __construct(
        public ?int $total,
        public array $severityCounts,
        public ?FailThresholds $newFindingsGate = null,
    ) {
        // A negative total cap could never be met, so reject it now rather than let it fail every run silently.
        if ($total !== null && $total < 0) {
            throw new InvalidArgumentException('Total finding cap must be a non-negative integer.');
        }

        // Check each per-severity cap the user supplied for that same impossible-to-satisfy negative value.
        foreach ($severityCounts as $severity => $cap) {
            // One bad cap is enough to reject the whole gate, and naming its severity tells the user which to fix.
            if ($cap < 0) {
                throw new InvalidArgumentException(sprintf('Severity cap for "%s" must be a non-negative integer.', $severity));
            }
        }
    }

    /**
     * Translates a `--fail-on <severity>` command-line flag into an equivalent gate, so the simple
     * flag and the richer config block both funnel through the same evaluation path.
     *
     * @param FailThreshold $threshold - The severity the user passed to `--fail-on`, e.g. `--fail-on error`.
     *
     * @return self - A gate that fails on any finding at or above that severity - or, for `--fail-on none`, a gate with no caps that never fails - reproducing the flag exactly.
     */
    public static function fromFailOn(FailThreshold $threshold): self
    {
        $severityCounts = match ($threshold) {
            FailThreshold::None => [],
            FailThreshold::Error => [Severity::Error->value => 0],
            FailThreshold::Warning => [Severity::Error->value => 0, Severity::Warning->value => 0],
            FailThreshold::Advisory => [
                Severity::Error->value => 0,
                Severity::Warning->value => 0,
                Severity::Advisory->value => 0,
            ],
        };

        // The flag never caps the total; the zero per-severity cap is what makes it "fail on any finding at or above X".
        return new self(null, $severityCounts);
    }

    /**
     * Turns the `failureConditions:` block from a user's `.gruff-php.yaml` into a gate - the entry
     * point used whenever a project spells out its own pass/fail policy instead of a bare flag.
     *
     * @param array<array-key, mixed> $failureConditions - The decoded `failureConditions:` block, as read from the user's config.
     * @throws ConfigException When a key, severity name, or value in the block is invalid.
     *
     * @return self - A gate matching the caps the user wrote in their config.
     */
    public static function fromConfig(array $failureConditions): self
    {
        // Only this outermost block may hold a `newFindings` sub-gate; the `true` is what permits it, and it is never passed on deeper.
        return self::parseConditions($failureConditions, 'failureConditions', true);
    }

    /**
     * The shared parser behind both the top-level block and any nested sub-gate: validate the keys,
     * then read the total, per-severity, and new-findings caps into one gate.
     *
     * @param array<array-key, mixed> $conditions - The decoded block being parsed, either the top level or a nested `newFindings` block.
     * @param string                  $keyPath - Dotted config path (e.g. `failureConditions.newFindings`) quoted back in any error the user sees.
     * @param bool                    $allowsNewFindings - Whether a nested `newFindings` sub-gate is permitted here; false once already inside one.
     * @throws ConfigException When a key, severity name, or value in the block is invalid.
     *
     * @return self - The gate described by this block.
     */
    private static function parseConditions(array $conditions, string $keyPath, bool $allowsNewFindings): self
    {
        self::assertKnownKeys($conditions, $keyPath, $allowsNewFindings);

        return new self(
            self::parseTotal($conditions, $keyPath),
            self::parseSeverityThresholds($conditions, $keyPath),
            self::parseNewFindingsGate($conditions, $keyPath, $allowsNewFindings),
        );
    }

    /**
     * Guards against typos and unsupported options in a user's config block, failing on the first
     * unrecognised key so a misspelled `severetyThresholds` is caught rather than silently ignored.
     *
     * @param array<array-key, mixed> $conditions - Decoded conditions block whose keys are being validated.
     * @param string                  $keyPath - Dotted config path quoted back in the error the user sees.
     * @param bool                    $allowsNewFindings - Whether the `newFindings` key is allowed here; false excludes it from the permitted set.
     * @throws ConfigException When an unsupported key is present.
     *
     * @return void
     */
    private static function assertKnownKeys(array $conditions, string $keyPath, bool $allowsNewFindings): void
    {
        $allowedKeys = $allowsNewFindings ? ['total', 'severityThresholds', 'newFindings'] : ['total', 'severityThresholds'];
        // Inspect every key the user actually wrote so an unsupported one is caught, not quietly dropped.
        foreach (array_keys($conditions) as $key) {
            // This key is not one the block understands, so stop and name it so the user can correct their config.
            if (!in_array($key, $allowedKeys, true)) {
                throw new ConfigException(sprintf('Unknown config key "%s.%s".', $keyPath, (string) $key));
            }
        }
    }

    /**
     * Reads the optional `total:` cap from the block - the ceiling on how many findings of any
     * severity a user will tolerate before the run is failed.
     *
     * @param array<array-key, mixed> $conditions - Decoded conditions block that may or may not carry a `total` key.
     * @param string                  $keyPath - Dotted config path quoted back in the error the user sees.
     * @throws ConfigException When the total value is not a non-negative integer.
     *
     * @return int|null - The total cap the user set; null when they omitted `total`, leaving the total uncapped.
     */
    private static function parseTotal(array $conditions, string $keyPath): ?int
    {
        // A user who left `total` out wants no overall cap, so return the null the constructor reads as "unlimited".
        if (!array_key_exists('total', $conditions)) {
            return null;
        }

        $totalValue = $conditions['total'];
        // The key is present, so it must be a real count; a string or a negative here is a config mistake to surface.
        if (!is_int($totalValue) || $totalValue < 0) {
            throw new ConfigException(sprintf('Config key "%s.total" must be a non-negative integer.', $keyPath));
        }

        return $totalValue;
    }

    /**
     * Reads the optional `severityThresholds:` map - the per-severity ceilings that let a user, say,
     * tolerate a handful of advisories but zero errors.
     *
     * @param array<array-key, mixed> $conditions - Decoded conditions block that may or may not carry a `severityThresholds` key.
     * @param string                  $keyPath - Dotted config path quoted back in the error the user sees.
     * @throws ConfigException When a severity name or its cap value is invalid.
     *
     * @return array<string, int> - Caps keyed by severity value; an empty map when the user set no per-severity limits.
     */
    private static function parseSeverityThresholds(array $conditions, string $keyPath): array
    {
        // A user who omitted the map wants no per-severity ceilings, so hand back an empty set of caps.
        if (!array_key_exists('severityThresholds', $conditions)) {
            return [];
        }

        $thresholds = $conditions['severityThresholds'];
        // The key is here but isn't an array at all, so the user wrote a scalar or null where an object belongs (a YAML list would pass this and fail on the severity name below instead).
        if (!is_array($thresholds)) {
            throw new ConfigException(sprintf('Config key "%s.severityThresholds" must be an object.', $keyPath));
        }

        $severityCounts = [];
        // Walk each `severity: cap` pair the user wrote, checking the name and the number before trusting either.
        foreach ($thresholds as $severity => $cap) {
            $severityKey = (string) $severity;
            // The severity name isn't one gruff knows, so point the user at the three valid values instead of skipping it.
            if (Severity::tryFrom($severityKey) === null) {
                throw new ConfigException(sprintf('Unknown severity "%s" in %s.severityThresholds. Use advisory, warning, or error.', $severityKey, $keyPath));
            }
            // The name is valid but its cap isn't a real non-negative count, so reject that one entry by its severity.
            if (!is_int($cap) || $cap < 0) {
                throw new ConfigException(sprintf('Config key "%s.severityThresholds.%s" must be a non-negative integer.', $keyPath, $severityKey));
            }
            $severityCounts[$severityKey] = $cap;
        }

        return $severityCounts;
    }

    /**
     * Reads the optional nested `newFindings:` block into its own sub-gate - the piece that lets a
     * user gate newly written code against its own separate caps, apart from the existing baseline.
     *
     * @param array<array-key, mixed> $conditions - Decoded conditions block that may or may not carry a `newFindings` key.
     * @param string                  $keyPath - Dotted config path quoted back in the error the user sees.
     * @param bool                    $allowsNewFindings - Whether a nested `newFindings` sub-gate is permitted here at all.
     * @throws ConfigException When the newFindings block is present but not an object.
     *
     * @return self|null - The parsed sub-gate; null when nesting is disallowed here or the user wrote no `newFindings` block.
     */
    private static function parseNewFindingsGate(array $conditions, string $keyPath, bool $allowsNewFindings): ?self
    {
        // No sub-gate to build when this level forbids nesting or the user simply left `newFindings` out.
        if (!$allowsNewFindings || !array_key_exists('newFindings', $conditions)) {
            return null;
        }

        $newFindings = $conditions['newFindings'];
        // The user wrote `newFindings` but not as a nested block, so it cannot describe a sub-gate.
        if (!is_array($newFindings)) {
            throw new ConfigException(sprintf('Config key "%s.newFindings" must be an object.', $keyPath));
        }

        // Parse the nested block as its own gate, passing `false` so a `newFindings` inside it is refused.
        return self::parseConditions($newFindings, $keyPath . '.newFindings', false);
    }

    /**
     * The heart of the gate: tally the findings and report the first cap they blow past, worst
     * severity first and the total last, so a user learns exactly which limit failed their run.
     *
     * @param list<Finding> $findings - The findings to weigh against the caps, after any baseline has been subtracted.
     *
     * @return ThresholdTrip|null - The first cap that was exceeded; null when nothing tripped, meaning the run passes.
     */
    public function tripsOn(array $findings): ?ThresholdTrip
    {
        $counts = [
            Severity::Error->value => 0,
            Severity::Warning->value => 0,
            Severity::Advisory->value => 0,
        ];

        // Bucket every finding by its severity so each cap can be weighed against a real count.
        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        // Walk the caps in that worst-first order and stop at the first one the run exceeds.
        foreach (self::SEVERITY_ORDER as $severity) {
            $cap = $this->severityCounts[$severity] ?? null;
            // This severity is capped and its count ran past the cap - the first such breach in worst-first order, so report it and stop.
            if ($cap !== null && $counts[$severity] > $cap) {
                return new ThresholdTrip($severity, $counts[$severity], $cap);
            }
        }

        $totalCount = count($findings);
        // No single severity tripped, but the combined count still exceeds the total the user allowed, so fail on that.
        if ($this->total !== null && $totalCount > $this->total) {
            return new ThresholdTrip(ThresholdTrip::KIND_TOTAL, $totalCount, $this->total);
        }

        return null;
    }

    /**
     * Returns a copy of this gate with a different new-findings sub-gate swapped in, used when the
     * baseline workflow attaches (or clears) the separate check on newly introduced findings.
     *
     * @param FailThresholds|null $newFindingsGate - The sub-gate to attach; null clears any existing new-findings gate.
     *
     * @return self - A new gate identical to this one but carrying the given new-findings sub-gate.
     */
    public function withNewFindingsGate(?FailThresholds $newFindingsGate): self
    {
        return new self($this->total, $this->severityCounts, $newFindingsGate);
    }

    /**
     * The gate used on a baseline run: judge newly introduced findings against the sub-gate first,
     * and only then the whole set, so a user hears about code they just wrote before old debt.
     *
     * @param list<Finding> $allFindings - Every post-baseline finding, weighed against this gate's own caps.
     * @param list<Finding> $newFindings - The subset new since the baseline, weighed against the new-findings sub-gate.
     *
     * @return ThresholdTrip|null - The first cap exceeded, tagged NEW when a new finding caused it; null when the run passes.
     */
    public function tripsOnScope(array $allFindings, array $newFindings): ?ThresholdTrip
    {
        // Only weigh the new-findings set when the user actually configured a sub-gate for it.
        if ($this->newFindingsGate instanceof FailThresholds) {
            $newTrip = $this->newFindingsGate->tripsOn($newFindings);
            // The freshly written code already broke the new-findings sub-gate's own limit, so fail on that and tag it NEW so the report points straight at it.
            if ($newTrip instanceof ThresholdTrip) {
                return $newTrip->withScope(ThresholdTrip::SCOPE_NEW);
            }
        }

        // No new-findings breach (or no sub-gate at all), so judge the whole set against the ordinary caps.
        return $this->tripsOn($allFindings);
    }
}
