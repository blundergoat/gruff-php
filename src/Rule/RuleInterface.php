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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition;

    /**
     * Analyse one parsed source file with this rule.
     *
     * @return list<Finding>
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array;
}
