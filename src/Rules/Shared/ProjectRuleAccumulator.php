<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Shared;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;

/**
 * Streaming variant of ProjectRuleInterface that accumulates per-unit state.
 *
 * RuleRegistry calls accumulate() once per analysis unit during the main
 * per-unit loop. Rules that implement this interface keep only the
 * project-level summary they need (typed-name maps, declaration registers,
 * etc.) instead of holding every AST reference until the project pass. The
 * orchestrator can release each unit's AST/source/tokens immediately after
 * accumulate returns when every enabled project rule is an accumulator,
 * which keeps peak memory close to one unit's worth instead of the whole
 * project.
 */
interface ProjectRuleAccumulator
{
    /**
     * Describe the rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata.
     */
    public function definition(): RuleDefinition;

    /**
     * Reset any accumulated state at the start of a project pass.
     *
     * @param RuleContext $ruleContext - Rule context carrying config and settings.
     *
     * @return void
     */
    public function startProject(RuleContext $ruleContext): void;

    /**
     * Extract project-level data from one analysis unit.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to accumulate.
     * @param RuleContext  $ruleContext - Rule context carrying config and settings.
     *
     * @return void Implementations should store what they need on `$this` or
     *              an internal collector; the unit may be released by the
     *              orchestrator immediately after this call returns.
     */
    public function accumulate(AnalysisUnit $analysisUnit, RuleContext $ruleContext): void;

    /**
     * Produce project-level findings from the accumulated state and clear it.
     *
     * @param RuleContext $ruleContext - Rule context carrying config and settings.
     *
     * @return list<Finding> - Findings emitted from the accumulated summary.
     */
    public function finishProject(RuleContext $ruleContext): array;
}
