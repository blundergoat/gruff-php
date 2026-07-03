<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Contracts;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;

/**
 * Defines the contract for rules that analyse a complete project at once.
 */
interface ProjectRuleInterface
{
    /**
     * Describe this project-level rule for configuration and reporting.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - identity, configurable defaults, and severity the analyser uses to load and report this rule
     */
    public function definition(): RuleDefinition;

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param list<AnalysisUnit> $units - Parsed units available to the project-level rule.
     * @param RuleContext        $ruleContext - Project-level rule context for this analysis pass.
     *
     * @return list<Finding> - findings raised across the whole project; empty when the rule is satisfied
     */
    public function analyseProject(array $units, RuleContext $ruleContext): array;
}
