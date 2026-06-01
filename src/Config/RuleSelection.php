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
     * @param list<string> $tiers - Included tier names; empty means no tier filter.
     * @param list<string> $pillars - Included pillar names; empty means no pillar include filter.
     * @param list<string> $rules - Included rule ids; empty means no rule include filter.
     * @param list<string> $excludePillars - Pillar names to remove after include filters are applied.
     * @param list<string> $excludeRules - Rule ids to remove after include filters are applied.
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
     * @param RuleDefinition $definition - Rule definition to test against selection filters.
     *
     * @return bool - true keeps the rule enabled; false drops it (no include matched, or an exclude filter vetoed it)
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
     * @return array<string, list<string>> - selection keyed by filter name (tiers, pillars, rules, excludePillars, excludeRules); each value is the
     *                       list for that filter, empty when unset
     */
    public function toArray(): array
    {
        return [
            'tiers'          => $this->tiers,
            'pillars'        => $this->pillars,
            'rules'          => $this->rules,
            'excludePillars' => $this->excludePillars,
            'excludeRules'   => $this->excludeRules,
        ];
    }
}
