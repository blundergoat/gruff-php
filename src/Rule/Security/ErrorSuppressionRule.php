<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

final class ErrorSuppressionRule implements RuleInterface
{
    public const ID = 'security.error-suppression';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Error suppression operator',
            pillar: Pillar::Security,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            secondaryPillars: [Pillar::Modernisation],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Expr\ErrorSuppress::class) as $node) {
            $findings[] = new Finding(
                ruleId: self::ID,
                message: 'Error suppression operator hides failures.',
                filePath: $unit->file->displayPath,
                line: $node->getStartLine(),
                severity: Severity::Warning,
                pillar: Pillar::Security,
                tier: RuleTier::V01,
                confidence: Confidence::High,
                remediation: 'Handle the specific failure mode explicitly instead of suppressing errors with @.',
                secondaryPillars: [Pillar::Modernisation],
            );
        }

        return $findings;
    }
}
