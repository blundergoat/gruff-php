<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final readonly class TestNamingConsistencyRule implements RuleInterface
{
    public const ID = 'test-quality.naming-consistency';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Test naming consistency',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Stmt\Class_::class) as $class) {
            $camelCount = 0;
            $snakeCount = 0;

            foreach ($class->getMethods() as $method) {
                if (!TestQualityNodeHelper::isTestMethod($method)) {
                    continue;
                }

                $afterTest = substr($method->name->toString(), 4);
                if ($afterTest === '') {
                    continue;
                }

                if (str_contains($afterTest, '_')) {
                    $snakeCount++;
                } else {
                    $camelCount++;
                }
            }

            if ($camelCount === 0 || $snakeCount === 0) {
                continue;
            }

            $className = $class->name?->toString() ?? sprintf('anonymous@%d', $class->getStartLine());
            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf('%s mixes camelCase (%d) and snake_case (%d) test method naming.', $className, $camelCount, $snakeCount),
                filePath: $unit->file->displayPath,
                line: $class->getStartLine(),
                severity: Severity::Advisory,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::High,
                symbol: $className,
                remediation: 'Pick one naming style for test methods and apply it consistently.',
                metadata: ['camelCase' => $camelCount, 'snake_case' => $snakeCount],
            );
        }

        return $findings;
    }
}
