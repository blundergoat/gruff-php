<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;

/**
 * Defines the contract for rules that analyse a complete project at once.
 */
interface ProjectRuleInterface
{
    /**
     * Describe this project-level rule for configuration and reporting.
     *
     * @return RuleDefinition - identity, configurable defaults, and severity the analyser uses to load and report this rule
     */
    public function definition(): RuleDefinition;

    /**
     * @param list<AnalysisUnit> $units - Parsed units available to the project-level rule.
     * @param RuleContext        $ruleContext - Project-level rule context for this analysis pass.
     *
     * @return list<Finding> - findings raised across the whole project; empty when the rule is satisfied
     */
    public function analyseProject(array $units, RuleContext $ruleContext): array;
}
