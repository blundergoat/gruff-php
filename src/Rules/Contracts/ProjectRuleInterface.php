<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Contracts;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;

/**
 * Defines the contract for rules that inspect parsed units together across files.
 * Each rule receives the supplied unit list and can compare declarations or references across units.
 * The registry merges its findings into the same report as per-file findings.
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
     * Runs the rule across the supplied parsed units and returns project-wide findings.
     *
     * @param list<AnalysisUnit> $units - Parsed units available to the project-level rule.
     * @param RuleContext        $ruleContext - Supplies the project root, effective configuration, and per-rule settings for this pass.
     *
     * @return list<Finding> - Findings from the supplied units; empty when no issues are reported.
     */
    public function analyseProject(array $units, RuleContext $ruleContext): array;
}
