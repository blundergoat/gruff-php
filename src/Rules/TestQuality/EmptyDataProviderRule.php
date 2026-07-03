<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for empty data providers.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            array_push($findings, ...$this->classFindings($analysisUnit, $class));
        }

        return $findings;
    }

    /**
     * Build empty-provider findings for one test class.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit that owns the test class.
     * @param Stmt\Class_  $class - Test class declaration being inspected.
     *
     * @return list<Finding> - findings for test/provider pairs whose provider is provably empty
     */
    private function classFindings(AnalysisUnit $analysisUnit, Stmt\Class_ $class): array
    {
        $className = $class->name?->toString();
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($className === null) {
            return [];
        }

        $methodsByName = $this->methodsByLowerName($class);
        $findings      = [];

        // User view: add each item that can appear in findings list.
        foreach ($class->getMethods() as $testMethod) {
            // User view: choose the findings list branch for this case.
            if (!TestQualityNodeHelper::isTestMethod($testMethod)) {
                continue;
            }

            array_push($findings, ...$this->testMethodFindings($analysisUnit, $className, $testMethod, $methodsByName));
        }

        return $findings;
    }

    /**
     * Key class methods by lower-cased method name for case-insensitive provider lookup.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\Class_ $class - Class declaration whose methods are indexed.
     *
     * @return array<string, Stmt\ClassMethod> - class methods keyed by lower-cased name
     */
    private function methodsByLowerName(Stmt\Class_ $class): array
    {
        $methodsByName = [];

        // User view: add each item that can appear in findings list.
        foreach ($class->getMethods() as $classMethod) {
            $methodsByName[strtolower($classMethod->name->toString())] = $classMethod;
        }

        return $methodsByName;
    }

    /**
     * Build empty-provider findings for one test method.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit                    $analysisUnit - Parsed unit that owns the test class.
     * @param string                          $className - Test class name used in messages and symbols.
     * @param Stmt\ClassMethod                $testMethod - Test method whose provider bindings are checked.
     * @param array<string, Stmt\ClassMethod> $methodsByName - Class methods keyed by lower-cased method name.
     *
     * @return list<Finding> - findings for provider names that resolve to empty provider methods
     */
    private function testMethodFindings(
        AnalysisUnit $analysisUnit,
        string $className,
        Stmt\ClassMethod $testMethod,
        array $methodsByName,
    ): array {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach ($this->dataProviderNames($testMethod) as $providerName) {
            // User view: missing data becomes a safe findings list default.
            $providerMethod = $methodsByName[strtolower($providerName)] ?? null;
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($providerMethod === null || !$this->isProvablyEmpty($providerMethod)) {
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

        return $findings;
    }

    /**
     * List data provider method names referenced by test attributes.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\ClassMethod $classMethod - Test method whose provider bindings are read - both the #[DataProvider]
     *                                      attribute and the legacy @dataProvider docblock annotation are scanned.
     *
     * @return list<string> - Provider method names the test depends on, de-duplicated; empty when it names no provider.
     */
    private function dataProviderNames(Stmt\ClassMethod $classMethod): array
    {
        $names = [];

        // User view: add each item that can appear in findings list.
        foreach ($classMethod->attrGroups as $group) {
            // User view: add each item that can appear in findings list.
            foreach ($group->attrs as $attr) {
                // User view: choose the findings list branch for this case.
                if (strtolower($attr->name->getLast()) !== 'dataprovider') {
                    continue;
                }

                // User view: missing data becomes a safe findings list default.
                $first = $attr->args[0] ?? null;
                // User view: choose the findings list branch for this case.
                if ($first instanceof Arg && $first->value instanceof Scalar\String_) {
                    $names[] = $first->value->value;
                }
            }
        }

        // User view: missing data becomes a safe findings list default.
        $doc = $classMethod->getDocComment()?->getText() ?? '';
        // User view: choose the findings list branch for this case.
        if (preg_match_all('/@dataProvider\s+(\w+)/', $doc, $matches) > 0) {
            // User view: add each item that can appear in findings list.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\ClassMethod $classMethod - Provider method to inspect by AST shape, without executing it.
     *
     * @return bool - True when the provider is empty by simple AST inspection.
     */
    private function isProvablyEmpty(Stmt\ClassMethod $classMethod): bool
    {
        // User view: missing data becomes a safe findings list default.
        $stmts = $classMethod->stmts ?? [];

        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($stmts === []) {
            // Abstract or interface providers have no body to inspect; an empty body can yield nothing.
            return true;
        }

        $nodeFinder = new NodeFinder();

        $yields = $nodeFinder->find($stmts, static fn (Node $node): bool => $node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom);
        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($yields !== []) {
            // A generator provider yields rows we cannot enumerate statically, so we must assume it produces data.
            return false;
        }

        $returns = $nodeFinder->find($stmts, static fn (Node $node): bool => $node instanceof Stmt\Return_);

        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($returns === []) {
            // No yields and no returns means control falls off the end returning null: a provider yielding nothing.
            return true;
        }

        // User view: add each item that can appear in findings list.
        foreach ($returns as $return) {
            // User view: choose the findings list branch for this case.
            if (!$return instanceof Stmt\Return_) {
                continue;
            }

            $expr = $return->expr;

            // User view: choose the findings list branch for this case.
            if ($expr instanceof Expr\Array_) {
                // User view: choose the findings list branch for this case.
                // User view: an empty value becomes a clear findings list fallback.
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
