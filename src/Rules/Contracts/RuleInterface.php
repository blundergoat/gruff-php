<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Contracts;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;

/**
 * The contract every per-file rule implements, so the analyser can run each check against one parsed
 * file at a time.
 *
 * This is the common case: a rule sees a single file's parsed unit (via analyse) and raises findings
 * for anything wrong in it. The registry loads the rule by its definition() and merges its findings
 * into the run's report alongside every other rule's.
 */
interface RuleInterface
{
    /**
     * Describes this source-file rule for the registry, config, and reports.
     *
     * @return RuleDefinition - this rule's identity, category, severity, and default config used by the registry and reports.
     */
    public function definition(): RuleDefinition;

    /**
     * Runs the rule against one parsed source file and returns whatever it finds.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - findings raised by this rule for the unit; empty when the file is clean.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array;
}
