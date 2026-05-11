<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;

interface ProjectRuleInterface
{
    public function definition(): RuleDefinition;

    /**
     * @param list<AnalysisUnit> $units
     * @return list<Finding>
     */
    public function analyseProject(array $units, RuleContext $context): array;
}
