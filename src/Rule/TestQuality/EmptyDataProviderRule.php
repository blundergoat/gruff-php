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
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final readonly class EmptyDataProviderRule implements RuleInterface
{
    public const ID = 'test-quality.empty-data-provider';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Empty data provider',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Error,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Stmt\Class_::class) as $class) {
            $className = $class->name?->toString();
            if ($className === null) {
                continue;
            }

            $methodsByName = [];
            foreach ($class->getMethods() as $method) {
                $methodsByName[strtolower($method->name->toString())] = $method;
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
                        ruleId: self::ID,
                        message: sprintf(
                            '%s::%s() uses data provider %s() that yields no rows.',
                            $className,
                            $testMethod->name->toString(),
                            $providerName,
                        ),
                        filePath: $unit->file->displayPath,
                        line: $testMethod->getStartLine(),
                        severity: Severity::Error,
                        pillar: Pillar::TestQuality,
                        tier: RuleTier::V01,
                        confidence: Confidence::High,
                        symbol: sprintf('%s::%s()', $className, $testMethod->name->toString()),
                        remediation: 'Add at least one data row to the provider, or remove the unused #[DataProvider] / @dataProvider link.',
                        metadata: ['provider' => $providerName],
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function dataProviderNames(Stmt\ClassMethod $method): array
    {
        $names = [];

        foreach ($method->attrGroups as $group) {
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

        $doc = $method->getDocComment()?->getText() ?? '';
        if (preg_match_all('/@dataProvider\s+(\w+)/', $doc, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function isProvablyEmpty(Stmt\ClassMethod $method): bool
    {
        $stmts = $method->stmts ?? [];

        if ($stmts === []) {
            return true;
        }

        $finder = new NodeFinder();

        $yields = $finder->find($stmts, static fn (Node $node): bool => $node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom);
        if ($yields !== []) {
            return false;
        }

        $returns = $finder->find($stmts, static fn (Node $node): bool => $node instanceof Stmt\Return_);

        if ($returns === []) {
            return true;
        }

        foreach ($returns as $return) {
            if (!$return instanceof Stmt\Return_) {
                continue;
            }

            $expr = $return->expr;

            if ($expr instanceof Expr\Array_) {
                if ($expr->items !== []) {
                    return false;
                }
                continue;
            }

            return false;
        }

        return true;
    }
}
