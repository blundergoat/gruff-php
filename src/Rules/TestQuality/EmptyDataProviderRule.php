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
use GruffPhp\Support\DeclarationLine;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Checks test methods for empty data providers declared in the same named class.
 * Reads provider yield and return nodes without executing the provider.
 * Reports findings with Error severity and high confidence.
 */
final readonly class EmptyDataProviderRule implements RuleInterface
{
    /**
     * Stable rule identifier for empty data provider findings.
     */
    public const ID = 'test-quality.empty-data-provider';

    /**
     * Describes the empty-data-provider rule for the registry and reports.
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
     * Reports tests linked to data providers that cannot yield any rows.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - Findings for empty data providers.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            array_push($findings, ...$this->classFindings($analysisUnit, $class));
        }

        return $findings;
    }

    /**
     * Builds the empty-provider findings for one test class.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit that owns the test class.
     * @param Stmt\Class_  $class - Test class declaration being inspected.
     *
     * @return list<Finding> - findings for test/provider pairs whose provider is provably empty
     */
    private function classFindings(AnalysisUnit $analysisUnit, Stmt\Class_ $class): array
    {
        $className = $class->name?->toString();
        // An anonymous class has no name to report against.
        if ($className === null) {
            return [];
        }

        $methodsByName = $this->methodsByLowerName($class);
        $findings      = [];


        foreach ($class->getMethods() as $testMethod) {

            if (!TestQualityNodeHelper::isTestMethod($testMethod)) {
                continue;
            }

            array_push($findings, ...$this->testMethodFindings($analysisUnit, $className, $testMethod, $methodsByName));
        }

        return $findings;
    }

    /**
     * Indexes class methods by lower-cased name for case-insensitive provider lookup.
     *
     * @param Stmt\Class_ $class - Class declaration whose methods are indexed.
     *
     * @return array<string, Stmt\ClassMethod> - class methods keyed by lower-cased name
     */
    private function methodsByLowerName(Stmt\Class_ $class): array
    {
        $methodsByName = [];


        foreach ($class->getMethods() as $classMethod) {
            $methodsByName[strtolower($classMethod->name->toString())] = $classMethod;
        }

        return $methodsByName;
    }

    /**
     * Builds the empty-provider findings for one test method.
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


        foreach ($this->dataProviderNames($testMethod) as $providerName) {
            $providerMethod = $methodsByName[strtolower($providerName)] ?? null;

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
                line:        DeclarationLine::of($testMethod),
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
     * Collects provider-name strings from recognized attribute and annotation spellings on a test method.
     *
     * @param Stmt\ClassMethod $classMethod - Test method whose attributes named DataProvider and @dataProvider annotations are read.
     *
     * @return list<string> - Provider names extracted from matched attribute and annotation spellings, de-duplicated; empty when neither names one.
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
        // The legacy annotation spelling participates in the same emptiness check as the attribute spelling.
        if (preg_match_all('/@dataProvider\s+(\w+)/', $doc, $matches) > 0) {

            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }

        // De-duplicate because a test may name the same provider via both an attribute and the legacy docblock.
        return array_values(array_unique($names));
    }

    /**
     * Reports whether a provider method is statically guaranteed to produce no rows.
     *
     * Conservative: only returns true when the AST proves emptiness; anything dynamic is treated as possibly non-empty
     * so the Error-severity finding cannot fire on a false positive.
     *
     * @param Stmt\ClassMethod $classMethod - Provider method to inspect by AST shape, without executing it.
     *
     * @return bool - True when the provider is empty by simple AST inspection.
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
            // Guard the finder's loose node type before reading the returned value.
            if (!$return instanceof Stmt\Return_) {
                continue;
            }

            $expr = $return->expr;

            // A returned array is empty only when it has no elements.
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
