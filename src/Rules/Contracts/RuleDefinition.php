<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Contracts;

use GruffPhp\Engine\Config\SeverityThreshold;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use InvalidArgumentException;

/**
 * The immutable description of one rule: identity, quality metadata, configuration defaults, and help text.
 * Rules return it from definition() so the registry, findings, and rule listings share the same metadata.
 *
 * The constructor validates ids, rejects blank threshold and option names, and forbids mixing threshold forms (ADR-008).
 */
final readonly class RuleDefinition
{
    /**
     * Builds a validated rule definition, rejecting a malformed id or a rule that mixes threshold forms.
     *
     * @param string     $id - Stable rule identifier used in findings and config.
     * @param string     $name - Human-readable rule name and fallback display description.
     * @param Pillar     $pillar - Primary quality pillar for the rule.
     * @param RuleTier   $tier - Rule catalogue tier.
     * @param Severity   $defaultSeverity - Severity used when config does not override it.
     * @param Confidence $confidence - Default confidence assigned to rule findings.
     *
     * @param array<string, int|float> $defaultThresholds - Named numeric defaults; mutually exclusive with $severityThreshold.
     * @param list<Pillar>             $secondaryPillars - Additional pillars affected by the rule.
     * @param bool                     $isEnabledByDefault - Whether registry defaults enable this rule.
     *
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $defaultOptions - Default values keyed by option name.
     *
     * @param string                 $description - Display description; an empty string makes description() return $name.
     * @param SeverityThreshold|null $severityThreshold - Single threshold/severity default; mutually exclusive with $defaultThresholds.
     * @param array<string, string>  $optionDescriptions - Help text keyed by option name for rule listings.
     *
     * @param list<array{shape: string, mitigation: string}> $falsePositiveShapes - Known false-positive patterns and their mitigations.
     *
     * @throws InvalidArgumentException - For invalid ids, blank threshold or option names, or mixed threshold forms.
     */
    public function __construct(
        public string             $id,
        public string             $name,
        public Pillar             $pillar,
        public RuleTier           $tier,
        public Severity           $defaultSeverity,
        public Confidence         $confidence = Confidence::High,
        public array              $defaultThresholds = [],
        public array              $secondaryPillars = [],
        public bool               $isEnabledByDefault = true,
        public array              $defaultOptions = [],
        public string             $description = '',
        public ?SeverityThreshold $severityThreshold = null,
        public array              $optionDescriptions = [],
        public array              $falsePositiveShapes = [],
    ) {
        // Enforce the dotted slug format used by config, baselines, and reporters.
        if (!preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/', $id)) {
            throw new InvalidArgumentException(sprintf('Invalid rule id "%s".', $id));
        }

        // A rule must pick one threshold form, not both (ADR-008).
        if ($severityThreshold instanceof SeverityThreshold && $defaultThresholds !== []) {
            throw new InvalidArgumentException(sprintf(
                                                   'Rule "%s" declares both severityThreshold and defaultThresholds; use one form.',
                                                   $id,
                                               ));
        }

        // Reject any blank threshold name, which config could never address.
        foreach (array_keys($defaultThresholds) as $name) {
            if ($name === '') {
                throw new InvalidArgumentException(sprintf('Rule "%s" has an invalid threshold name.', $id));
            }
        }

        // Reject any blank option name, which config could never address.
        foreach (array_keys($defaultOptions) as $name) {
            if ($name === '') {
                throw new InvalidArgumentException(sprintf('Rule "%s" has an invalid option name.', $id));
            }
        }
    }

    /**
     * Returns the display description, falling back to the rule name when none was configured.
     *
     * @return string - Display text for rule listings and reports.
     */
    public function description(): string
    {

        return $this->description !== '' ? $this->description : $this->name;
    }
}
