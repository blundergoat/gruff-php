<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Detects test classes that mix camelCase and snake_case test method names.
 */
final readonly class TestNamingConsistencyRule implements RuleInterface
{
    /**
     * Stable rule identifier for inconsistent test naming findings.
     */
    public const ID = 'naming.test-naming-consistency';

    /**
     * Describe the test naming consistency rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Confidence High but Advisory severity: mixed casing is unambiguous, yet teams pick the style to enforce.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Test method naming consistency',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find test method names that do not follow the configured convention.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for inconsistent test names.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $classes    = NodeIndex::nodesOf($analysisUnit, Class_::class);

        $findings = [];

        foreach ($classes as $class) {
            /** @var Class_ $class Finder predicate restricts results to class declarations. */
            $testMethods = [];

            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof ClassMethod && str_starts_with($stmt->name->toString(), 'test')) {
                    $testMethods[] = $stmt;
                }
            }

            if (count($testMethods) < 2) {
                continue;
            }

            $camelCount = 0;
            $snakeCount = 0;

            foreach ($testMethods as $method) {
                $name      = $method->name->toString();
                $afterTest = substr($name, 4);

                if ($afterTest === '') {
                    continue;
                }

                if (str_contains($afterTest, '_')) {
                    $snakeCount++;
                } else {
                    $camelCount++;
                }
            }

            if ($camelCount > 0 && $snakeCount > 0) {
                $className = $class->name?->toString() ?? sprintf('class@anonymous:%d', $class->getStartLine());

                $findings[] = new Finding(
                    ruleId:  $definition->id,
                    message: sprintf(
                        '%s mixes camelCase (%d) and snake_case (%d) test method naming.',
                        $className,
                        $camelCount,
                        $snakeCount,
                    ),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $class->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      $className,
                    remediation: 'Pick one naming style for test methods and apply it consistently.',
                    metadata:    ['camelCase' => $camelCount, 'snake_case' => $snakeCount],
                );
            }
        }

        // Hand back one finding per class that mixed both casings; consistent classes contribute nothing.
        return $findings;
    }
}
