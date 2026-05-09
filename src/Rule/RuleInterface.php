<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;

interface RuleInterface
{
    public function definition(): RuleDefinition;

    /**
     * @return list<Finding>
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array;
}
