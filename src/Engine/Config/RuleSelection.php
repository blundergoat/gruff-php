<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Rules\Contracts\RuleDefinition;

/**
 * The user's rule-selection filters - which tiers, pillars, and rules to include, and which to exclude.
 *
 * A user narrows what gruff checks through config or `--include`/`--exclude` flags, and this captures
 * that choice. `allows()` applies it to each rule: an empty include set means "run everything", any
 * include list means "only these", and exclusions always win. It is what lets someone run just the
 * security rules, or everything except documentation, without touching the rule catalog.
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
     * Decides whether one rule survives the user's include and exclude filters - the gate every rule
     * passes through before it is allowed to run.
     *
     * @param RuleDefinition $definition - Rule definition to test against the selection filters.
     *
     * @return bool - True keeps the rule enabled; false drops it (no include matched, or an exclude filter vetoed it).
     */
    public function allows(RuleDefinition $definition): bool
    {
        // With no include filters set at all, every rule is included by default.
        $included = $this->tiers === [] && $this->pillars === [] && $this->rules === [];

        // An include list naming this rule's tier pulls it in.
        if (!$included && in_array($definition->tier->value, $this->tiers, true)) {
            $included = true;
        }

        // An include list naming this rule's pillar pulls it in.
        if (!$included && in_array($definition->pillar->value, $this->pillars, true)) {
            $included = true;
        }

        // An include list naming this rule by id pulls it in.
        if (!$included && in_array($definition->id, $this->rules, true)) {
            $included = true;
        }

        // Some include filter was set but none of them matched, so this rule is silently left out.
        if (!$included) {
            // No include filter matched this rule, so an explicit include list silently drops it.
            return false;
        }

        // An excluded pillar overrides any include, switching the whole pillar off.
        if (in_array($definition->pillar->value, $this->excludePillars, true)) {
            // An excluded pillar wins over any include, so the whole pillar stays off.
            return false;
        }

        // Included and not pillar-excluded: keep the rule unless its id is named on the exclude list.
        return !in_array($definition->id, $this->excludeRules, true);
    }

    /**
     * Flattens the selection into the report array shape, so a run's active filters show up in output.
     *
     * @return array<string, list<string>> - selection keyed by filter name (tiers, pillars, rules, excludePillars, excludeRules); each value is the
     *                       list for that filter, empty when unset.
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
