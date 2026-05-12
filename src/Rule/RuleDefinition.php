<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

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
     * @param array<string, int|float> $defaultThresholds
     * @param list<Pillar> $secondaryPillars
     * @param array<string, mixed> $defaultOptions
     */
    public function __construct(
        public string $id,
        public string $name,
        public Pillar $pillar,
        public RuleTier $tier,
        public Severity $defaultSeverity,
        public Confidence $confidence = Confidence::High,
        public array $defaultThresholds = [],
        public array $secondaryPillars = [],
        public bool $defaultEnabled = true,
        public array $defaultOptions = [],
        public string $description = '',
    ) {
        if (!preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/', $id)) {
            throw new InvalidArgumentException(sprintf('Invalid rule id "%s".', $id));
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
     * @return string Display text for rule listings and reports.
     */
    public function description(): string
    {
        return $this->description !== '' ? $this->description : $this->name;
    }
}
