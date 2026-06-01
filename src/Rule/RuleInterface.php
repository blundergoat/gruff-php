<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;

/**
 * Defines the contract for rules that analyse one parsed file at a time.
 */
interface RuleInterface
{
    /**
     * Describe this source-file rule for configuration and reporting.
     *
     * @return RuleDefinition - this rule's identity, category, severity, and default config used by the registry and reports
     */
    public function definition(): RuleDefinition;

    /**
     * Analyse one parsed source file with this rule.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - findings raised by this rule for the unit; empty when the file is clean
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array;
}
