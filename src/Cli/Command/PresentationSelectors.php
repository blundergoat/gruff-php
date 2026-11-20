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
     * Stores presentation selectors after the caller has parsed and normalised their values.
     *
     * @param list<string> $showRules - Rule IDs selected by --show-rule; empty adds no rule allowlist.
     * @param list<string> $hideRules - Rule IDs excluded by --hide-rule; empty excludes no rules through this selector.
     * @param list<string> $showPillars - Pillars selected by --show-pillar; empty adds no pillar allowlist.
     * @param list<string> $hidePillars - Pillars excluded by --hide-pillar; empty excludes no pillars through this selector.
     */
    public function __construct(
        public array $showRules = [],
        public array $hideRules = [],
        public array $showPillars = [],
        public array $hidePillars = [],
    ) {
    }
}
