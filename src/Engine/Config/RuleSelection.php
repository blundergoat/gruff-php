<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Rules\Contracts\RuleDefinition;

/**
 * The user's rule-selection filters - which tiers, pillars, and rules to include, and which to exclude.
 *
 * Any matching tier, pillar, or rule include admits a rule; with all three include lists empty, every rule is included.
 * Excluded pillars and rule ids always win, while per-rule enablement is checked separately.
 */
final readonly class RuleSelection
{
    /**
     * Captures the include and exclude lists that together decide which rules run.
     *
     * @param list<string> $tiers - Included tier names; empty means no tier filter.
     * @param list<string> $pillars - Included pillar names; empty means no pillar include filter.
     * @param list<string> $rules - Included rule ids; empty means no rule include filter.
     * @param list<string> $excludePillars - Pillar names to remove after the include filters are applied.
     * @param list<string> $excludeRules - Rule ids to remove after the include filters are applied.
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
     * Reports whether a rule passes the selection filters; any include can match, and exclusions take precedence.
     *
     * @param RuleDefinition $definition - Rule definition to test against the selection filters.
     *
     * @return bool - True when an include matches or none are set, unless the pillar or rule id is excluded.
     */
    public function allows(RuleDefinition $definition): bool
    {
        // With no include filters set at all, every rule is included by default.
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

            return false;
        }

        // An excluded pillar overrides any include, switching the whole pillar off.
        if (in_array($definition->pillar->value, $this->excludePillars, true)) {

            return false;
        }


        return !in_array($definition->id, $this->excludeRules, true);
    }

    /**
     * Flattens the selection into the report array shape, so a run's active filters show up in output.
     *
     * @return array<string, list<string>> - All filter lists keyed by their property names, including empty lists.
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
