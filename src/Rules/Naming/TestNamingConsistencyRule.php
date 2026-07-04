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
 * Flags a test class that names some of its test methods in camelCase and others in snake_case, so the user
 * sees the split and settles on one convention instead of leaving a mixed, harder-to-scan suite.
 *
 * Fires only when a class has at least two test methods and both styles actually appear. Advisory but high
 * confidence: the mix is unambiguous, yet which single style to adopt is the team's call.
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
     * Reports a test class that mixes camelCase and snake_case method names.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for inconsistent test names.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $classes    = NodeIndex::nodesOf($analysisUnit, Class_::class);

        $findings = [];

        // Check every class declared in the file.
        foreach ($classes as $class) {
            /** @var Class_ $class Finder predicate restricts results to class declarations. */
            $testMethods = $this->testMethods($class);

            // A single test method cannot disagree with itself, so there is nothing to compare.
            if (count($testMethods) < 2) {
                continue;
            }

            $counts = $this->namingCounts($testMethods);

            // Both styles present in one class is exactly the inconsistency to report.
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

        // Look at each statement in the class body.
        foreach ($class->stmts as $stmt) {
            // A method whose name starts with "test" counts as a test method.
            if ($stmt instanceof ClassMethod && str_starts_with($stmt->name->toString(), 'test')) {
                $testMethods[] = $stmt;
            }
        }

        return $testMethods;
    }

    /**
     * Counts the camelCase and snake_case test method names.
     *
     * @param list<ClassMethod> $testMethods - Test methods being classified by naming style.
     *
     * @return array{camelCase: int, snake_case: int} - method counts keyed by naming style
     */
    private function namingCounts(array $testMethods): array
    {
        $counts = ['camelCase' => 0, 'snake_case' => 0];

        // Classify each test method by the style of the part after "test".
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
