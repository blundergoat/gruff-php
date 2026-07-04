<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Contracts;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;

/**
 * The contract every project-wide rule implements, so the analyser can run cross-file checks the same
 * way it runs per-file ones.
 *
 * A project rule sees every parsed file at once (via analyseProject), which lets it answer questions no
 * single-file rule can - "is this private method called from anywhere?", "is this constant ever used?".
 * The registry loads it by its definition() and folds whatever findings it raises into the same report
 * the user reads.
 */
interface ProjectRuleInterface
{
    /**
     * Describes this project rule for the registry, config, and reports.
     *
     * @return RuleDefinition - identity, configurable defaults, and severity the analyser uses to load and report this rule.
     */
    public function definition(): RuleDefinition;

    /**
     * Runs the rule across every parsed file in the project and returns whatever it finds.
     *
     * @param list<AnalysisUnit> $units - Parsed units available to the project-level rule.
     * @param RuleContext        $ruleContext - Project-level rule context for this analysis pass.
     *
     * @return list<Finding> - findings raised across the whole project; empty when the rule is satisfied.
     */
    public function analyseProject(array $units, RuleContext $ruleContext): array;
}
