<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Rule\RuleDefinition;

/**
 * Represents include and exclude filters for rule execution.
 */
final readonly class RuleSelection
{
    /**
     * Store rule-selection include and exclude lists.
     *
     * @param list<string> $tiers
     * @param list<string> $pillars
     * @param list<string> $rules
     * @param list<string> $excludePillars
     * @param list<string> $excludeRules
     */
    public function __construct(
        public array $tiers = [],
        public array $pillars = [],
        public array $rules = [],
        public array $excludePillars = [],
        public array $excludeRules = [],
    ) {
    }

    /**
     * Decide whether a rule definition passes the include and exclude filters.
     *
     * @param RuleDefinition $definition Rule definition to test against selection filters.
     * @return bool True when the rule should remain enabled for the selection.
     */
    public function allows(RuleDefinition $definition): bool
    {
        $included = $this->tiers === [] && $this->pillars === [] && $this->rules === [];

        if (!$included && in_array($definition->tier->value, $this->tiers, true)) {
            $included = true;
        }

        if (!$included && in_array($definition->pillar->value, $this->pillars, true)) {
            $included = true;
        }

        if (!$included && in_array($definition->id, $this->rules, true)) {
            $included = true;
        }

        if (!$included) {
            // No include filter matched this rule, so an explicit include list silently drops it.
            return false;
        }

        if (in_array($definition->pillar->value, $this->excludePillars, true)) {
            // An excluded pillar wins over any include, so the whole pillar stays off.
            return false;
        }

        // Included and not pillar-excluded: keep the rule unless its id is named on the exclude list.
        return !in_array($definition->id, $this->excludeRules, true);
    }

    /**
     * Serialize this value object into the array shape used by reports.
     *
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        // Keys mirror the YAML selection schema so reports round-trip back into config unchanged.
        return [
            'tiers' => $this->tiers,
            'pillars' => $this->pillars,
            'rules' => $this->rules,
            'excludePillars' => $this->excludePillars,
            'excludeRules' => $this->excludeRules,
        ];
    }
}
