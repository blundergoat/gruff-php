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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition;

    /**
     * @param list<AnalysisUnit> $units   Parsed units available to the project-level rule.
     * @param RuleContext        $ruleContext Project-level rule context for this analysis pass.
     * @return list<Finding>
     */
    public function analyseProject(array $units, RuleContext $ruleContext): array;
}
