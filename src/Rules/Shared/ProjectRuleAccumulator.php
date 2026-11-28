<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Shared;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;

/**
 * Collects per-unit summaries for project rules without retaining every AST.
 * RuleRegistry feeds eligible analysis units to accumulate() during the per-unit pass.
 * Streaming callers can release units when every enabled project rule supports this interface.
 */
interface ProjectRuleAccumulator
{
    /**
     * Describes the rule for the registry and reports.
     *
     * @return RuleDefinition - Definition consumed by the rule registry and report builders.
     */
    public function definition(): RuleDefinition;

    /**
     * Resets any accumulated state at the start of a project pass.
     *
     * @param RuleContext $ruleContext - Rule context carrying config and settings.
     *
     * @return void - Resets the rule's accumulated state for a new project pass.
     */
    public function startProject(RuleContext $ruleContext): void;

    /**
     * Extracts the project-level data this rule needs from one unit, so the unit can then be released.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose project-level data is retained before release.
     * @param RuleContext $ruleContext - Rule context carrying config and settings.
     *
     * @return void - Updates retained project data; the caller may release the unit immediately afterward.
     */
    public function accumulate(AnalysisUnit $analysisUnit, RuleContext $ruleContext): void;

    /**
     * Produces the project-level findings from the accumulated state and clears it.
     *
     * @param RuleContext $ruleContext - Rule context carrying config and settings.
     *
     * @return list<Finding> - Findings emitted from the accumulated summary.
     */
    public function finishProject(RuleContext $ruleContext): array;
}
