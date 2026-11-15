<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Flags classes whose declared `test`-prefixed methods mix suffixes with and without underscores.
 * The prefix check is case-sensitive; attributes and inheritance do not determine which methods count.
 *
 * Emits one advisory, high-confidence finding per mixed class.
 * Nonempty suffixes containing an underscore count as snake_case; other nonempty suffixes count as camelCase.
 */
final readonly class TestNamingConsistencyRule implements RuleInterface
{
    /**
     * Stable rule identifier for inconsistent test naming findings.
     */
    public const ID = 'naming.test-naming-consistency';

    /**
     * Describes the test-naming-consistency rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Confidence is High because the underscore split is deterministic; severity is Advisory because teams choose the enforced style.
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
     * Reports classes that mix `test`-prefixed method suffixes with and without underscores.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose class declarations are inspected.
     * @param RuleContext $ruleContext - Required by the rule interface; unused by this rule.
     *
     * @return list<Finding> - one finding per mixed class; empty when no class has both nonempty suffix categories
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $classes    = NodeIndex::nodesOf($analysisUnit, Class_::class);

        $findings = [];


        foreach ($classes as $class) {
            /** @var Class_ $class - The Class_ query restricts results to class declarations. */
            $testMethods = $this->testMethods($class);

            // A single test method cannot disagree with itself, so there is nothing to compare.
            if (count($testMethods) < 2) {
                continue;
            }

            $counts = $this->namingCounts($testMethods);


            if ($counts['camelCase'] > 0 && $counts['snake_case'] > 0) {
                $className = $class->name?->toString() ?? sprintf('class@anonymous:%d', $class->getStartLine());

                $findings[] = new Finding(
                    ruleId:  $definition->id,
                    message: sprintf(
                        '%s mixes camelCase (%d) and snake_case (%d) test method naming.',
                        $className,
                        $counts['camelCase'],
                        $counts['snake_case'],
                    ),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $class->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      $className,
                    remediation: 'Pick one naming style for test methods and apply it consistently.',
                    metadata:    $counts,
                );
            }
        }

        return $findings;
    }

    /**
     * Collects the test methods declared directly on a class.
     *
     * @param Class_ $class - Class declaration whose statement list is scanned.
     *
     * @return list<ClassMethod> - methods whose names start with `test`, in declaration order
     */
    private function testMethods(Class_ $class): array
    {
        $testMethods = [];


        foreach ($class->stmts as $stmt) {

            if ($stmt instanceof ClassMethod && str_starts_with($stmt->name->toString(), 'test')) {
                $testMethods[] = $stmt;
            }
        }

        return $testMethods;
    }

    /**
     * Classifies nonempty suffixes after `test` by whether they contain an underscore.
     *
     * @param list<ClassMethod> $testMethods - Test methods being classified by naming style.
     *
     * @return array{camelCase: int, snake_case: int} - suffix counts; underscores select snake_case, other suffixes select camelCase
     */
    private function namingCounts(array $testMethods): array
    {
        $counts = ['camelCase' => 0, 'snake_case' => 0];


        foreach ($testMethods as $method) {
            $afterTest = substr($method->name->toString(), 4);

            // A bare "test" with nothing after it has no style to classify.
            if ($afterTest === '') {
                continue;
            }

            // An underscore marks snake_case; anything else is treated as camelCase.
            if (str_contains($afterTest, '_')) {
                $counts['snake_case']++;
            } else {
                $counts['camelCase']++;
            }
        }

        return $counts;
    }
}
