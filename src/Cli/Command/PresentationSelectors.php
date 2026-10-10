<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

/**
 * The four family flags that decide what a report shows, kept apart from the ones that decide what runs.
 *
 * `--show-rule`, `--hide-rule`, `--show-pillar` and `--hide-pillar` never change execution, the score, a baseline, or a
 * hook's blocking input. Grouping them says that in the type: a caller holding this object is holding presentation
 * choices and nothing else.
 */
final readonly class PresentationSelectors
{
    /**
     * Captures the four presentation choices exactly as the user gave them; every list defaults to "unset".
     *
     * @param list<string> $showRules   - Rule IDs from `--show-rule`; empty means the report shows every rule that ran.
     * @param list<string> $hideRules   - Rule IDs from `--hide-rule`; empty means the report hides none.
     * @param list<string> $showPillars - Pillars from `--show-pillar`; empty means the report shows every pillar.
     * @param list<string> $hidePillars - Pillars from `--hide-pillar`; empty means the report hides none.
     */
    public function __construct(
        public array $showRules = [],
        public array $hideRules = [],
        public array $showPillars = [],
        public array $hidePillars = [],
    ) {
    }
}
