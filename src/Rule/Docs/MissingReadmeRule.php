<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;

final readonly class MissingReadmeRule implements RuleInterface
{
    public const ID = 'docs.missing-readme';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Missing README',
            pillar: Pillar::Documentation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $readmePath = $context->projectRoot . '/README.md';

        if (file_exists($readmePath)) {
            return [];
        }

        $definition = $this->definition();

        return [
            new Finding(
                ruleId: $definition->id,
                message: 'Project root has no README.md.',
                filePath: 'README.md',
                line: null,
                severity: $definition->defaultSeverity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                remediation: 'Add a README.md describing the project.',
            ),
        ];
    }
}
