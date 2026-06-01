<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

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
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Detects data providers that return no test cases.
 */
final readonly class EmptyDataProviderRule implements RuleInterface
{
    /**
     * Stable rule identifier for empty data provider findings.
     */
    public const ID = 'test-quality.empty-data-provider';

    /**
     * Describe the empty data provider rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Error: a provider yielding no rows silently skips its test, so the suite passes while asserting nothing.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Empty data provider',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Error,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find tests linked to data providers that cannot yield any rows.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for empty data providers.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $nodeFinder = new NodeFinder();
        $findings   = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            $className = $class->name?->toString();
            if ($className === null) {
                continue;
            }

            $methodsByName = [];
            foreach ($class->getMethods() as $classMethod) {
                $methodsByName[strtolower($classMethod->name->toString())] = $classMethod;
            }

            foreach ($class->getMethods() as $testMethod) {
                if (!TestQualityNodeHelper::isTestMethod($testMethod)) {
                    continue;
                }

                foreach ($this->dataProviderNames($testMethod) as $providerName) {
                    $providerMethod = $methodsByName[strtolower($providerName)] ?? null;
                    if ($providerMethod === null) {
                        continue;
                    }

                    if (!$this->isProvablyEmpty($providerMethod)) {
                        continue;
                    }

                    $findings[] = new Finding(
                        ruleId:  self::ID,
                        message: sprintf(
                            '%s::%s() uses data provider %s() that yields no rows.',
                            $className,
                            $testMethod->name->toString(),
                            $providerName,
                        ),
                        filePath:    $analysisUnit->file->displayPath,
                        line:        $testMethod->getStartLine(),
                        severity:    Severity::Error,
                        pillar:      Pillar::TestQuality,
                        tier:        RuleTier::V01,
                        confidence:  Confidence::High,
                        symbol:      sprintf('%s::%s()', $className, $testMethod->name->toString()),
                        remediation: 'Add at least one data row to the provider, or remove the unused #[DataProvider] / @dataProvider link.',
                        metadata:    ['provider' => $providerName],
                    );
                }
            }
        }

        // One finding per test/provider pair where the provider is statically guaranteed to be empty.
        return $findings;
    }

    /**
     * List data provider method names referenced by test attributes.
     *
     * @param Stmt\ClassMethod $classMethod Test method whose provider bindings are read - both the #[DataProvider]
     *                                      attribute and the legacy @dataProvider docblock annotation are scanned.
     * @return list<string> Provider method names the test depends on, de-duplicated; empty when it names no provider.
     */
    private function dataProviderNames(Stmt\ClassMethod $classMethod): array
    {
        $names = [];

        foreach ($classMethod->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (strtolower($attr->name->getLast()) !== 'dataprovider') {
                    continue;
                }

                $first = $attr->args[0] ?? null;
                if ($first instanceof Arg && $first->value instanceof Scalar\String_) {
                    $names[] = $first->value->value;
                }
            }
        }

        $doc = $classMethod->getDocComment()?->getText() ?? '';
        if (preg_match_all('/@dataProvider\s+(\w+)/', $doc, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }

        // De-duplicate because a test may name the same provider via both an attribute and the legacy docblock.
        return array_values(array_unique($names));
    }

    /**
     * Determine whether a provider method is statically guaranteed to produce no rows.
     *
     * Conservative: only returns true when the AST proves emptiness; anything dynamic is treated as possibly non-empty
     * so the Error-severity finding cannot fire on a false positive.
     *
     * @param Stmt\ClassMethod $classMethod Provider method to inspect by AST shape, without executing it.
     * @return bool True when the provider is empty by simple AST inspection.
     */
    private function isProvablyEmpty(Stmt\ClassMethod $classMethod): bool
    {
        $stmts = $classMethod->stmts ?? [];

        if ($stmts === []) {
            // Abstract or interface providers have no body to inspect; an empty body can yield nothing.
            return true;
        }

        $nodeFinder = new NodeFinder();

        $yields = $nodeFinder->find($stmts, static fn (Node $node): bool => $node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom);
        if ($yields !== []) {
            // A generator provider yields rows we cannot enumerate statically, so we must assume it produces data.
            return false;
        }

        $returns = $nodeFinder->find($stmts, static fn (Node $node): bool => $node instanceof Stmt\Return_);

        if ($returns === []) {
            // No yields and no returns means control falls off the end returning null: a provider yielding nothing.
            return true;
        }

        foreach ($returns as $return) {
            if (!$return instanceof Stmt\Return_) {
                continue;
            }

            $expr = $return->expr;

            if ($expr instanceof Expr\Array_) {
                if ($expr->items !== []) {
                    // A returned array literal with at least one element supplies real rows, so not empty.
                    return false;
                }
                continue;
            }

            // A non-array return (variable, method call, etc.) is opaque to static inspection; assume it yields rows.
            return false;
        }

        // Every return seen was a provably empty array literal, so the provider cannot produce a single row.
        return true;
    }
}
