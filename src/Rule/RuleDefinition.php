<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Config\SeverityThreshold;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use InvalidArgumentException;

/**
 * Describes rule metadata, defaults, thresholds, and reporting text.
 */
final readonly class RuleDefinition
{
    /**
     * @param string                                                                       $id - Stable rule identifier used in
     *                                                                                                          findings and config.
     * @param string                                                                       $name - Human-readable rule name.
     * @param Pillar                                                                       $pillar - Primary quality pillar for the rule.
     * @param RuleTier                                                                     $tier - Rule catalogue tier.
     * @param Severity                                                                     $defaultSeverity - Severity used when config does not
     *                                                                                                          override it.
     * @param Confidence                                                                   $confidence - Default confidence assigned to rule
     *                                                                                                          findings.
     * @param array<string, int|float>                                                     $defaultThresholds - Named numeric thresholds for rule
     *                                                                                                          settings (legacy tiered shape; mutually exclusive with
     *                                                                                                          $severityThreshold per ADR-008).
     * @param list<Pillar>                                                                 $secondaryPillars - Additional pillars affected by the
     *                                                                                                          rule.
     * @param bool                                                                         $isEnabledByDefault - Whether the rule runs unless disabled
     *                                                                                                          by config.
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $defaultOptions - Rule-specific default option values.
     * @param string                                                                       $description - Longer display description for rule
     *                                                                                                          listings.
     * @param SeverityThreshold|null                                                       $severityThreshold - Single threshold + severity default
     *                                                                                                          (ADR-008/ADR-009 shape); mutually
     *                                                                                                          exclusive with $defaultThresholds.
     * @param array<string, string>                                                        $optionDescriptions - One-line semantic description per
     *                                                                                                          option key; surfaced by `list-rules <id>`.
     * @param list<array{shape: string, mitigation: string}>                               $falsePositiveShapes - Documented false-positive patterns and
     *                                                                                                          how to mitigate them; surfaced by
     *                                                                                                          `list-rules <id>`. Empty when no
     *                                                                                                          patterns have been catalogued yet.
     *
     * @throws InvalidArgumentException When the rule id, threshold names, or option names are invalid.
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

        if ($severityThreshold instanceof SeverityThreshold && $defaultThresholds !== []) {
            throw new InvalidArgumentException(sprintf(
                                                   'Rule "%s" declares both severityThreshold and defaultThresholds; use one form.',
                                                   $id,
                                               ));
        }

        foreach (array_keys($defaultThresholds) as $name) {
            if ($name === '') {
                throw new InvalidArgumentException(sprintf('Rule "%s" has an invalid threshold name.', $id));
            }
        }

        foreach (array_keys($defaultOptions) as $name) {
            if ($name === '') {
                throw new InvalidArgumentException(sprintf('Rule "%s" has an invalid option name.', $id));
            }
        }
    }

    /**
     * Return the configured description or fall back to the rule name.
     *
     * @return string - Display text for rule listings and reports.
     */
    public function description(): string
    {
        // An empty description means none was configured, so the name doubles as the display text.
        return $this->description !== '' ? $this->description : $this->name;
    }
}
